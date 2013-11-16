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
            return $this->getGeoJSONClickZone(intval($layer_data->set_id), $options);
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
        } elseif ($layer_data->type == 'colormap') {
            return $this->drawColorMap(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'geojson') {
            return $this->drawGeoJSON(intval($layer_data->set_id), $options);
        }
    }

    protected function getGeoJSONClickZone($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->json(0);
        }

        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];

        $sql = "SELECT ST_AsGeoJSON(ST_UnaryUnion(ST_Collect(ST_Buffer(ST_Simplify(geo::geometry, {$pixel}), {$pixel} * 2)))) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        $res = DataGeometry::getDb()->query($sql);
        $ret = $res->fetch_assoc();
        $json = json_decode($ret['geojson']);

        return $this->json($json);
    }

    protected function drawColorMap($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->emptyImage();
        }

        if (!$mapset = DataSet::find($dataset->getEAV('map_from'))) {
            return $this->emptyImage();
        }

        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];

        $sql = "SELECT id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$pixel})) AS geojson FROM data_geometry WHERE set_id= {$mapset->set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        $res = DataGeometry::getDb()->query($sql);

        $id_map = json_decode($dataset->getEAV('id_map'));
        $id_map = array_combine($id_map[0], $id_map[1]);

        $json = new StdClass;
        $json->type = 'FeatureCollection';

        $features = array();
        $geojsons = array();
        $data_ids = array();
        while ($row = $res->fetch_assoc()) {
            if (array_key_exists($row['id'], $id_map)) {
                $geojsons[$id_map[$row['id']]] = $row['geojson'];
                $data_ids[] = $id_map[$row['id']];
            }
        }
        $res->free_result();

        if (!count($geojsons)) {
            return $this->emptyImage();
        }

        $config = json_decode($dataset->getEAV('config'));
        $min_value1 = floatval($config->value1->min);
        $max_value1 = floatval($config->value1->max);
        $color1 = $color2 = $config->value1->color;

        $sql = "SELECT id, data->>{$config->value1->column_id} FROM data_line WHERE id IN (" . implode(",", $data_ids) .")";
        $res = DataLine::getDb()->query($sql);

        while ($row = $res->fetch_array()){
            $id = array_shift($row);
            if (floatval($row[0]) < 0) {
                $rate  = 1.0 * floatval($row[0] - $min_value1) / ($max_value1 - $min_value1);
                $color = $color2;
            } else {
                $rate  = 1.0 * floatval($row[0] - $min_value1) / ($max_value1 - $min_value1);
                $color = $color1;
            }
            $rgb = array();
            for ($i = 0; $i < 3; $i ++) {
                $rgb[$i] = floor(255 - (255 - intval($color[$i])) * $rate);
            }

            $feature = new StdClass;
            $feature->type = 'Feature';
            $feature->properties = array(
                'background_color' => $rgb,
                'border_color' => array(100, 0, 0),
                'border_size' => 1,
            );
            $feature->geometry = json_decode($geojsons[$id]);
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
    protected function drawGeoJSON($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->emptyImage();
        }

        $boundry = array($options['min_lng'], $options['max_lng'], $options['min_lat'], $options['max_lat']);
        $pixel = ($boundry[1] - $boundry[0]) / $options['width'];

        $sql = "SELECT id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$pixel})) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        $res = DataGeometry::getDb()->query($sql);

        $json = new StdClass;
        $json->type = 'FeatureCollection';

        $features = array();
        $geojsons = array();
        while ($row = $res->fetch_assoc()) {
            $geojsons[$row['id']] = $row['geojson'];
        }
        $res->free_result();

        if (!count($geojsons)) {
            return $this->emptyImage();
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

        $sql = "SELECT ST_AsGeoJSON(ST_Simplify(ST_UnaryUnion(ST_Collect(ST_Buffer(geom, {$radius}))), $pixel)) AS geojson FROM (SELECT ST_SnapToGrid(geo::geometry, {$pixel}) AS geom FROM geo_point WHERE group_id = {$set_id} AND geo && ST_GeomFromText('{$options['text']}') GROUP BY geom) AS t";
        $res = GeoPoint::getDb()->query($sql);
        $ret = $res->fetch_assoc();
        $json = json_decode($ret['geojson']);

        return $this->json($json);
    }

    protected function drawCSV($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->emptyImage();
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
            return $this->emptyImage();
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

    protected function emptyImage()
    {
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=');
        return $this->noview();
    }
}
