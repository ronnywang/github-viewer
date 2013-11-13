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
        if ($layer_data->type == 'csv') {
            return $this->drawCSV(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'geojson') {
        }
    }

    protected function drawGeoJSON($set_id, $options)
    {
    }

    protected function drawCSV($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            echo '404';
            return $this->noview();
        }
        $lng_delta = $options['max_lng'] - $options['min_lng'];

        if ($lng_delta < 0.01) {
            $tolerance = "0.000001";
        } else if ($lng_delta < 0.1) {
            $tolerance = "0.00001";
        } else if ($lng_delta < 1) {
            $tolerance = "0.0001";
        } else if ($lng_delta < 10) {
            $tolerance = "0.001";
        } else {
            $tolerance = "0.01";
        }

        $sql = "SELECT data_id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$tolerance})) AS geojson FROM geo_point WHERE group_id = {$set_id} AND geo && ST_GeomFromText('{$options['text']}')";
        //$sql = "SELECT MIN(data_id) AS data_id, ST_AsGeoJSON(ST_Centroid(ST_Collect(geo::geometry))) AS geojson FROM (SELECT kmeans(ARRAY[ST_X(geo::geometry), ST_Y(geo::geometry)], 1000) OVER (), geo, data_id FROM geo_point WHERE group_id = {$group_id} AND geo && ST_GeomFromText('$text')) AS ksub GROUP BY kmeans";
        //error_log($sql);
        $res = GeoPoint::getDb()->query($sql);
        $time[1] = microtime(true);

        $json = new StdClass;
        $json->type = 'FeatureCollection';

        $features = array();
        //echo $sql . "\n";
        $geojsons = array();
        while ($row = $res->fetch_assoc()) {
            $geojsons[$row['data_id']] = $row['geojson'];
        }
        $res->free_result();

        if (!count($geojsons)) {
            return $this->noview();
        }

        $records = array();
        foreach (DataLine::search(array('set_id' => $dataset->set_id))->searchIn('id', array_keys($geojsons)) as $data_line) {
            $records[$data_line->id] = json_decode($data_line->data);
        }
        $time[2] = microtime(true);

        $max_rate = 1;
        $min_rate = 0;

        foreach ($geojsons as $id => $geojson) {
            if (!$record = $records[$id]) {
                continue;
            }

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
            $bbox));
         */

        return $this->noview();
    }
}
