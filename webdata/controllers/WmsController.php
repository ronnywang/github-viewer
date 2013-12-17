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


        $polygons = array();
        if ($max_lng < $min_lng) {
            if ($max_lng + 180 > 180) {
                $left_mid_lng = (-180 + $max_lng) / 2;
                $polygons[] = "((-180 {$min_lat},-180 {$max_lat},{$left_mid_lng} {$max_lat},{$left_mid_lng} {$min_lat},-180 {$min_lat}))";
                $polygons[] = "(({$left_mid_lng} {$min_lat},{$left_mid_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$left_mid_lng} {$min_lat}))";
            } else {
                $polygons[] = "((-180 {$min_lat},-180 {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},-180 {$min_lat}))";
            }

            if ($min_lng < 0) {
                $right_mid_lng = (180 + $min_lng) / 2;
                $polygons[] = "(({$right_mid_lng} {$min_lat},{$right_mid_lng} {$max_lat},180 {$max_lat},180 {$min_lat},{$right_mid_lng} {$min_lat}))";
                $polygons[] = "(({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$right_mid_lng} {$max_lat},{$right_mid_lng} {$min_lat},{$min_lng} {$min_lat}))";
            } else {
                $polygons[] = "(({$min_lng} {$min_lat},{$min_lng} {$max_lat},180 {$max_lat},180 {$min_lat},{$min_lng} {$min_lat}))";
            }
            $options['pixel'] = abs((360 + $max_lng - $min_lng) / $width);
        } else {
            if ($max_lng - $min_lng > 180) {
                $mid_lng = ($max_lng + $min_lng) / 2;
                $polygons[] = "(({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$mid_lng} {$max_lat},{$mid_lng} {$min_lat},{$min_lng} {$min_lat}))";
                $polygons[] = "(({$mid_lng} {$min_lat},{$mid_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$mid_lng} {$min_lat}))";
            } else {
                $polygons[] = "(({$min_lng} {$min_lat},{$min_lng} {$max_lat},{$max_lng} {$max_lat},{$max_lng} {$min_lat},{$min_lng} {$min_lat}))";
            }
            $options['pixel'] = abs(($max_lng - $min_lng) / $width);
        }
        $options['text'] = "ST_GeogFromText('MULTIPOLYGON(" . implode(",", $polygons) . ")')";
        $options['polygons'] = $polygons;
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
            return $this->drawColorMap(intval($layer_data->set_id), $options, $layer_data);
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
        $polygons = array();
        foreach ($options['polygons'] as $polygon) {
            $b = "ST_GeogFromText('POLYGON" . $polygon . "')";
            $sql = "SELECT ST_AsGeoJSON(ST_Intersection(ST_UnaryUnion(ST_Collect(ST_Buffer(ST_Simplify(geo::geometry, {$pixel}), {$pixel} * 2))), ({$b})::geometry)) AS geojson FROM data_geometry WHERE set_id= {$set_id} AND geo && {$b}";
            $res = DataGeometry::getDb()->query($sql);
            $ret = $res->fetch_assoc();
            if ($json = json_decode($ret['geojson'])) {
                $polygons[] = $json;
            }
        }
        $json = new StdClass;
        $json->type = 'GeometryCollection';
        $json->geometries = $polygons;

        return $this->json($json);
    }

    protected function drawColorMap($set_id, $options, $layer_data)
    {
        if (!$dataset = DataSet::find($set_id)) {
            return $this->emptyImage();
        }

        if (!$mapset = DataSet::find($dataset->getEAV('map_from'))) {
            return $this->emptyImage();
        }

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

        if (!property_exists($layer_data, 'column_id')) {
            return $this->emptyImage();
        }
        $column_id = intval($layer_data->column_id);

        $sql = "SELECT id, data->>{$column_id} FROM data_line WHERE id IN (" . implode(",", $data_ids) .")";

        $res = DataLine::getDb()->query($sql);

        while ($row = $res->fetch_array()){
            $id = array_shift($row);

            $color_config = $layer_data->color_config;
            $rgb = ColorLib::getColor($row[0], $color_config);

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
                'background_color' => false,
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
