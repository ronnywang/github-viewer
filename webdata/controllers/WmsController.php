<?php

class WmsController extends Pix_Controller
{
    public function init()
    {
        $this->actionName = (strtolower(strval($this->getParam('REQUEST'))));
    }

    protected $_lower_params = null;

    protected function getParam($key)
    {
        if (is_null($this->_lower_params)) {
            $this->_lower_params = array();
            foreach ($_GET as $k => $v) {
                $this->_lower_params[strtolower($k)] = $v;
            }
        }
        return $this->_lower_params[strtolower($key)];
    }

    public function getclickzoneAction()
    {
        $options = array();
        $layers = $this->getParam('layers');
        // minx, miny, maxx, maxy
        $bbox = $this->getParam('bbox');
        $options['width'] = $this->getParam('width');
        $options['height'] = $this->getParam('height');

        list($min_lng, $min_lat, $max_lng, $max_lat) = explode(',', $bbox);
        $options['min_lng'] = floatval($min_lng);
        $options['max_lng'] = floatval($max_lng);
        $options['min_lat'] = floatval($min_lat);
        $options['max_lat'] = floatval($max_lat);
        $options['text'] = "POLYGON(({$options['min_lng']} {$options['min_lat']},{$options['min_lng']} {$options['max_lat']},{$options['max_lng']} {$options['max_lat']},{$options['max_lng']} {$options['min_lat']},{$options['min_lng']} {$options['min_lat']}))";

        $layer_data = json_decode($layers);
        if ($layer_data->type == 'csvmap') {
            return $this->getCSVClickZone(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'geojson') {
            // GeoJSON 好像不需要 click zone
            return $this->json(0);
        }
    }

    public function getmapAction()
    {
        $time = array();
        $time[0] = microtime(true);

        $options = array();

        $version = $this->getParam('version');
        $layers = $this->getParam('layers');
        $styles = $this->getParam('styles');
        $srs = $this->getParam('srs');
        // minx, miny, maxx, maxy
        $bbox = $this->getParam('bbox');
        $options['width'] = $this->getParam('width');
        $options['height'] = $this->getParam('height');
        $format = $this->getParam('format');

        list($min_lng, $min_lat, $max_lng, $max_lat) = explode(',', $bbox);
        $options['min_lng'] = floatval($min_lng);
        $options['max_lng'] = floatval($max_lng);
        $options['min_lat'] = floatval($min_lat);
        $options['max_lat'] = floatval($max_lat);
        $options['text'] = "POLYGON(({$options['min_lng']} {$options['min_lat']},{$options['min_lng']} {$options['max_lat']},{$options['max_lng']} {$options['max_lat']},{$options['max_lng']} {$options['min_lat']},{$options['min_lng']} {$options['min_lat']}))";
        $layer_data = json_decode($layers);
        if ($layer_data->type == 'csvmap') {
            return $this->drawCSV(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'geojson') {
            return $this->drawGeoJSON(intval($layer_data->set_id), $options);
        }
    }

    protected function drawGeoJSON($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            echo '404';
            return $this->noview();
        }

        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];

        $sql = "SELECT id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$pixel})) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        //$sql = "SELECT MIN(data_id) AS data_id, ST_AsGeoJSON(ST_Centroid(ST_Collect(geo::geometry))) AS geojson FROM (SELECT kmeans(ARRAY[ST_X(geo::geometry), ST_Y(geo::geometry)], 1000) OVER (), geo, data_id FROM geo_point WHERE group_id = {$group_id} AND geo && ST_GeomFromText('$text')) AS ksub GROUP BY kmeans";
        //error_log($sql);
        $res = DataGeometry::getDb()->query($sql);

        $json = new StdClass;
        $json->type = 'FeatureCollection';

        $features = array();
        //echo $sql . "\n";
        $geojsons = array();
        while ($row = $res->fetch_assoc()) {
            $geojsons[$row['id']] = $row['geojson'];
        }
        $res->free_result();

        if (!count($geojsons)) {
            return $this->noview();
        }

        foreach ($geojsons as $id => $geojson) {
            $feature = new StdClass;
            $feature->type = 'Feature';
            $feature->properties = array(
                'background_color' => array(0,0,0),
                'border_color' => array(100, 0, 0),
                'border_size' => 2,
            );
            $feature->geometry = json_decode($geojson);
            $features[] = $feature;
        }
        $json->features = $features;

        $obj = new GeoJSON2Image($json);
        $obj->setSize($options['width']);
        $obj->setBoundry(array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']));
        $obj->draw();

        $time[3] = microtime(true);

        /*error_log(sprintf("total: %f, pgsql: %f(%d), mysql: %f(%d), png: %f, bbox: %s",
            $time[3] - $time[0],
            $time[1] - $time[0],
            count($geojsons),
            $time[2] -$time[1],
            count($records),
            $time[3] - $time[2],
            $bbox));
         */

        return $this->noview();
    }

    protected function getCSVClickZone($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            echo '404';
            return $this->noview();
        }
        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];
        $radius = 5 * $pixel;

        $sql = "SELECT ST_AsGeoJSON(ST_Simplify(ST_Buffer(ST_UnaryUnion(ST_Collect(ST_SnapToGrid(geo::geometry, {$pixel}))), {$radius}, 4), $pixel)) AS geojson FROM geo_point WHERE group_id = {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        $sql = "SELECT ST_AsGeoJSON(ST_Simplify(ST_UnaryUnion(ST_Collect(ST_Buffer(geom, {$radius}))), $pixel)) AS geojson FROM (SELECT ST_SnapToGrid(geo::geometry, {$pixel}) AS geom FROM geo_point WHERE group_id = {$set_id} AND geo && ST_GeomFromText('{$options['text']}') GROUP BY geom) AS t";
        $res = GeoPoint::getDb()->query($sql);
        $ret = $res->fetch_assoc();
        $json = json_decode($ret['geojson']);

        return $this->json($json);
    }

    protected function drawCSV($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            echo '404';
            return $this->noview();
        }
        $time = array(microtime(true));

        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];

        $sql = "SELECT data_id, ST_AsGeoJSON(ST_SnapToGrid(geo::geometry, {$pixel})) AS geojson FROM geo_point WHERE group_id = {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";

        $res = GeoPoint::getDb()->query($sql);
        $time[1] = microtime(true);
        $ret = $res->fetch_assoc();

        $json = new StdClass;
        $json->type = 'FeatureCollection';

        $features = array();
        //echo $sql . "\n";
        $geojsons = array();
        $points = array();
        while ($row = $res->fetch_assoc()) {
            if ($points[crc32($row['geojson'])]) {
                continue;
            }
            $points[crc32($row['geojson'])] = true;
            $geojsons[$row['data_id']] = $row['geojson'];
        }
        $res->free_result();

        if (!count($geojsons)) {
            return $this->noview();
        }

        $time[2] = microtime(true);

        foreach ($geojsons as $id => $geojson) {
            $feature = new StdClass;
            $feature->type = 'Feature';
            $feature->properties = array('background_color' => array(255,0,0));
            $feature->geometry = json_decode($geojson);
            $features[] = $feature;
        }
        $json->features = $features;

        $obj = new GeoJSON2Image($json);
        $obj->setSize($options['width']);
        $obj->setBoundry(array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']));
        $obj->draw();

        $time[3] = microtime(true);

        /*error_log(sprintf("total: %f, pgsql: %f(%d), mysql: %f(%d), png: %f, bbox: %s",
            $time[3] - $time[0],
            $time[1] - $time[0],
            count($geojsons),
            $time[2] -$time[1],
            count($records),
            $time[3] - $time[2],
            $bbox));*/

        return $this->noview();
    }
}
