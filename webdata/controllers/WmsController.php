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

        $version = $this->getParam('version');
        $layers = $this->getParam('layers');
        $styles = $this->getParam('styles');
        $srs = $this->getParam('srs');
        // minx, miny, maxx, maxy
        $bbox = $this->getParam('bbox');
        $width = $this->getParam('width');
        $height = $this->getParam('height');
        $format = $this->getParam('format');

        $db = GeoPoint::getDb();
        list($min_lng, $min_lat, $max_lng, $max_lat) = explode(',', $bbox);
        $min_lng = floatval($min_lng);
        $max_lng = floatval($max_lng);
        $min_lat = floatval($min_lat);
        $max_lat = floatval($max_lat);
        $text = "POLYGON(({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$min_lng} {$min_lat}))";
        $group_id = json_decode($layers);
        if (!$dataset = DataSet::find($group_id)) {
            echo '404';
            return $this->noview();
        }
        $group_id = intval($group_id);
        $lng_delta = $max_lng - $min_lng;

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

        $sql = "SELECT data_id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$tolerance})) AS geojson FROM geo_point WHERE group_id = {$group_id} AND geo && ST_GeomFromText('$text')";
        //$sql = "SELECT MIN(data_id) AS data_id, ST_AsGeoJSON(ST_Centroid(ST_Collect(geo::geometry))) AS geojson FROM (SELECT kmeans(ARRAY[ST_X(geo::geometry), ST_Y(geo::geometry)], 1000) OVER (), geo, data_id FROM geo_point WHERE group_id = {$group_id} AND geo && ST_GeomFromText('$text')) AS ksub GROUP BY kmeans";
        //error_log($sql);
        $res = $db->query($sql);
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
        $obj->setSize($width);
        $obj->setBoundry(array($min_lng, $max_lng, $min_lat, $max_lat));
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
