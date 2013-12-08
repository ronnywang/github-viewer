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

        $options = array_merge($options, $this->getPixelTextByBBox($bbox, intval($options['width'])));
        $layer_data = json_decode($layers);
        if ($layer_data->type == 'csvmap') {
            return $this->getCSVClickZone(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'geojson') {
            return $this->getGeoJSONClickZone(intval($layer_data->set_id), $options);
        }
    }

    public function getPixelTextByBBox($bbox, $width)
    {
        $options = array();
        list($min_lng, $min_lat, $max_lng, $max_lat) = array_map('floatval', explode(',', $bbox));
        $options['min_lng'] = $min_lng;
        $options['max_lng'] = $max_lng;
        $options['min_lat'] = $min_lat;
        $options['max_lat'] = $max_lat;


        if ($max_lng < $min_lng) {
            $left_mid_lng = (-180 + $max_lng) / 2;
            $right_mid_lng = (180 + $min_lng) / 2;
            $options['text'] = "ST_GeogFromText('MULTIPOLYGON(
                ((-180 {$min_lat},-180 {$max_lat},{$left_mid_lng} {$max_lat},{$left_mid_lng} {$min_lat},-180 {$min_lat})),
                (({$left_mid_lng} {$min_lat},{$left_mid_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$left_mid_lng} {$min_lat})),
                (({$right_mid_lng} {$min_lat},{$right_mid_lng} {$max_lat},180 {$max_lat},180 {$min_lat},{$right_mid_lng} {$min_lat})),
                (({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$right_mid_lng} {$max_lat},{$right_mid_lng} {$min_lat},{$min_lng} {$min_lat}))
            )')";
            $options['pixel'] = abs((360 + $max_lng - $min_lng) / $width);
            //$res = DataGeometry::getDb()->query("SELECT ST_AsGeoJSON({$options['text']})");
            //echo $res->fetch_array()[0];
            //exit;
        } else {
            $mid_lng = ($max_lng + $min_lng) / 2;
            $options['text'] = "ST_GeogFromText('MULTIPOLYGON("
                . "(({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$mid_lng} {$max_lat},{$mid_lng} {$min_lat},{$min_lng} {$min_lat})),"
                . "(({$mid_lng} {$min_lat},{$mid_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$mid_lng} {$min_lat}))"
            .")')";
            $options['pixel'] = abs(($max_lng - $min_lng) / $width);
        }
        return $options;
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
        $options = array_merge($options, $this->getPixelTextByBBox($bbox, intval($options['width'])));

        $layer_data = json_decode($layers);

        if ($layer_data->type == 'csvmap') {
            return $this->drawCSV(intval($layer_data->set_id), $options);
        } elseif ($layer_data->type == 'colormap') {
            return $this->drawColorMap(intval($layer_data->set_id), $options, $layer_data->tab);
        } elseif ($layer_data->type == 'geojson') {
            return $this->drawGeoJSON(intval($layer_data->set_id), $options);
        }
    }

    protected function getGeoJSONClickZone($set_id, $options)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->json(0);
        }

        $pixel = $options['pixel'];
        if ($options['min_lng'] > $options['max_lng']) {
            $bbox = array();
            $bbox[] = "ST_GeogFromText('POLYGON(
                (-180 {$options['min_lat']},-180 {$options['max_lat']},{$options['max_lng']} {$options['max_lat']},{$options['max_lng']} {$options['min_lat']},-180 {$options['min_lat']})
            )')";
            $bbox[] = "ST_GeogFromText('POLYGON(
                ({$options['min_lng']} {$options['min_lat']},{$options['min_lng']} {$options['max_lat']},180 {$options['max_lat']},180 {$options['min_lat']},{$options['min_lng']} {$options['min_lat']})
            )')";
            $polygons = array();
            foreach ($bbox as $b) {
                $sql = "SELECT ST_AsGeoJSON(ST_Intersection(ST_UnaryUnion(ST_Collect(ST_Buffer(ST_Simplify(geo::geometry, {$pixel}), {$pixel} * 2))), ({$b})::geometry)) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && {$b}";
                $res = DataGeometry::getDb()->query($sql);
                $ret = $res->fetch_assoc();
                $polygons[] = json_decode($ret['geojson']);
            }
            $json = new StdClass;
            $json->type = 'GeometryCollection';
            $json->geometries = $polygons;
        } else {
            $sql = "SELECT ST_AsGeoJSON(ST_Intersection(ST_UnaryUnion(ST_Collect(ST_Buffer(ST_Simplify(geo::geometry, {$pixel}), {$pixel} * 2))), ({$options['text']})::geometry)) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && {$options['text']}";
            $res = DataGeometry::getdb()->query($sql);
            $ret = $res->fetch_assoc();
            $json = json_decode($ret['geojson']);
        }

        return $this->json($json);
    }

    protected function getColor($row, $tab_info)
    {
        if (property_exists($tab_info, 'column_id')) {
            $min_value1 = floatval($tab_info->min);
            $max_value1 = floatval($tab_info->max);
            $color1 = $color2 = $tab_info->color;

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
        } else {
            $max_rate = 1;
            $min_rate = 0;
            $color1 = $tab_info->color1;
            $color2 = $tab_info->color2;
            $rate = 1.0 * floatval($row[0]) / (floatval($row[0]) + floatval($row[1]));
            $rgb = array();
            if ($rate > 0.5) {
                $rate = ($rate - 0.5) / ($max_rate - 0.5);
                for ($i = 0; $i < 3; $i ++) {
                    $rgb[$i] = floor($color1[$i] - ($color1[$i] - 255) * (1 - $rate));
                }
            } else {
                $rate = (0.5 - $rate) / (0.5 - $min_rate);
                for ($i = 0; $i < 3; $i ++) {
                    $rgb[$i] = floor($color2[$i] - ($color2[$i] - 255) * (1 - $rate));
                }
            }
        }

        return $rgb;
    }

    protected function drawColorMap($set_id, $options, $tab_id)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->emptyImage();
        }

        if (!$mapset = DataSet::find($dataset->getEAV('map_from'))) {
            return $this->emptyImage();
        }

        $config = json_decode($dataset->getEAV('config'));
        if (!property_exists($config->tabs, $tab_id)) {
            return $this->emptyImage();
        }
        $tab_info = $config->tabs->{$tab_id};

        $pixel = $options['pixel'];

        $sql = "SELECT id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$pixel})) AS geojson FROM data_geometry WHERE set_id= {$mapset->set_id} AND geo && {$options['text']}";
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

        if (property_exists($config->tabs->{$tab_id}, 'column_id')) {
            $column_id = $config->tabs->{$tab_id}->column_id;
            $sql = "SELECT id, data->>{$column_id} FROM data_line WHERE id IN (" . implode(",", $data_ids) .")";
        } else {
            $column1_id = $config->tabs->{$tab_id}->column1_id;
            $column2_id = $config->tabs->{$tab_id}->column2_id;
            $sql = "SELECT id, data->>{$column1_id}, data->>{$column2_id} FROM data_line WHERE id IN (" . implode(",", $data_ids) .")";
        }
        $res = DataLine::getDb()->query($sql);

        while ($row = $res->fetch_array()){
            $id = array_shift($row);

            $rgb = $this->getColor($row, $tab_info);

            $feature = new StdClass;
            $feature->type = 'Feature';
            $feature->properties = array(
                'background_color' => $rgb,
                'border_size' => 0,
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

        $pixel = $options['pixel'];

        $sql = "SELECT id, ST_AsGeoJSON(ST_Simplify(geo::geometry, {$pixel})) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && {$options['text']}";

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
                'background_color' => array(254, 254, 254),
                'border_color' => array(0, 0, 0),
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
        $pixel = $options['pixel'];
        $radius = 5 * $pixel;

        $sql = "SELECT ST_AsGeoJSON(ST_Simplify(ST_UnaryUnion(ST_Collect(ST_Buffer(geom, {$radius}))), $pixel)) AS geojson FROM (SELECT ST_SnapToGrid(geo::geometry, {$pixel}) AS geom FROM geo_point WHERE group_id = {$set_id} AND geo && {$options['text']} GROUP BY geom) AS t";
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

        $pixel = $options['pixel'];

        $sql = "SELECT data_id, ST_AsGeoJSON(ST_SnapToGrid(geo::geometry, {$pixel})) AS geojson FROM geo_point WHERE group_id = {$set_id} AND geo && {$options['text']}";

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
            $feature->properties = array(
                'background_color' => array(255, 128, 128),
                'border_color' => array(0, 0, 0),
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
