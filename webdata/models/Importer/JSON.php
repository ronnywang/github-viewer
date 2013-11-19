<?php

class Importer_JSON
{
    public function getSetAndUpdateSHA($github_options, $sha)
    {
        try {
            $set = DataSet::createByOptions($github_options);
        } catch (Pix_Table_DuplicateException $e) {
            $set = DataSet::findByOptions($github_options);
        }
        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");
        DataGeometry::getDb()->query("DELETE FROM data_geometry WHERE set_id={$set->set_id}");
        $set->lines->delete();
        $set->setEAV('sha', $sha);
        return $set;
    }

    public function import($github_options)
    {
        $content_obj = Importer::getContent($github_options);
        if ($set = DataSet::findByOptions($github_options) and $content_obj->sha == $set->getEAV('sha')) {
            // 沒改變，不需要重新整理
            if (!$_GET['force']) {
                return 0;
            }
        }

        if ($content_obj->content) {
            $content = base64_decode($content_obj->content);
            $file_path = Helper::getTmpFile();
            file_put_contents($file_path, $content);
        } else {
            $file_path = Importer::getFullBodyFilePath($content_obj);
        }

        $script_file = __DIR__ . '/../../scripts/geojson_parse.js';
        $cmd = "node " . escapeshellarg($script_file) . " get_type " . escapeshellarg($file_path);
        exec($cmd, $outputs, $ret);

        if ($ret) {
            throw new Importer_Exception("geojson_parse.js get_type failed");
        }
        $type = implode("\n", $outputs);

        $inserted = 0;
        switch ($type) {
        case 'ColorMap':
            if (!$json = json_decode(file_get_contents($file_path))) {
                throw new Importer_Exception("Invalid ColorMap JSON");
            }

            // 檢查 map_file
            list($user, $repository, $path) = explode("/", $json->map_file, 3);
            if (!preg_match('#json$#', $path)) {
                throw new Importer_Exception("map_file must be json");
            }
            $mapfile_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
            );
            Importer_JSON::import($mapfile_github_options);
            $mapfile_set = DataSet::findByOptions($mapfile_github_options);
            $mapfile_columns = json_decode($mapfile_set->getEAV('columns'));
            $map_columns = is_array($json->map_columns) ? $json->map_columns : array($json->map_columns);
            $map_column_ids = array();
            foreach ($map_columns as $map_column) {
                if (false === ($id = array_search(strval($map_column), $mapfile_columns))) {
                    throw new Importer_Exception("data must be lat column name");
                }
                $map_column_ids[] = $id;
            }

            // 檢查 data_file
            list($user, $repository, $path) = explode("/", $json->data_file, 3);
            if (!preg_match('#\.csv$#', $path)) {
                throw new Importer_Exception("data_file must be csv");
            }
            $datafile_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
            );
            Importer_CSV::import($datafile_github_options);
            $datafile_set = DataSet::findByOptions($datafile_github_options);
            $datafile_columns = json_decode($datafile_set->getEAV('columns'));
            $data_columns = is_array($json->data_columns) ? $json->data_columns : array($json->data_columns);
            $data_column_ids = array();
            foreach ($data_columns as $data_column) {
                if (false === ($id = array_search(strval($data_column), $datafile_columns))) {
                    throw new Importer_Exception("data must be lat column name");
                }
                $data_column_ids[] = $id;
            }

            if (count($data_column_ids) != count($map_column_ids)) {
                throw new Importer_Exception("data_columns size must be equal map_columns size");
            }

            $sql = "SELECT id, " . implode(', ', array_map(function($i){ return 'data->>' . $i; }, $data_column_ids)) . " FROM data_line WHERE set_id = {$datafile_set->set_id}";
            $res = DataLine::getDb()->query($sql);
            $data_ids = array();
            while ($row = $res->fetch_array()) {
                $id = array_shift($row);
                $data_ids[json_encode($row)] = $id;
            }
            $res->free_result();

            $sql = "SELECT id, " . implode(', ', array_map(function($i){ return 'data->>' . $i; }, $map_column_ids)) . " FROM data_line WHERE set_id = {$mapfile_set->set_id}";
            $res = DataLine::getDb()->query($sql);
            $id_map = array(array(), array());
            $id_miss = array(array(), array());

            while ($row = $res->fetch_array()) {
                $id = array_shift($row);
                $location_id = json_encode($row);

                if (array_key_exists($location_id, $data_ids)) {
                    $id_map[0][] = $id;
                    $id_map[1][] = $data_ids[$location_id];
                    unset($data_ids[$location_id]);
                } else {
                    $id_miss[0][] = implode(",", $row);
                }
            }
            $id_miss[1] = array_map(function($i){ return implode(',', json_decode($i)); }, array_keys($data_ids));

            if (false === ($id = array_search(strval($json->value1->column), $datafile_columns))) {
                throw new Importer_Exception("data must be lat column name");
            }
            $json->value1->column_id = $id;

            $set = self::getSetAndUpdateSHA($github_options, $content_obj->sha);
            $set->setEAV('data_from', $datafile_set->set_id);
            $set->setEAV('map_from', $mapfile_set->set_id);
            $set->setEAV('id_miss', json_encode($id_miss));
            $set->setEAV('id_map', json_encode($id_map));
            $set->setEAV('config', json_encode($json));
            $set->setEAV('data_type', 'colormap');
            return count($id_map[0]);

        case 'CSVMap':
            if (!$json = json_decode(file_get_contents($file_path))) {
                throw new Importer_Exception("Invalid CSVMap JSON");
            }

            list($user, $repository, $path) = explode("/", $json->data, 3);
            if (!preg_match('#\.csv$#', $path)) {
                throw new Importer_Exception("data must be csv");
            }
            $data_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
            );
            Importer_CSV::import($data_github_options);
            $data_set = DataSet::findByOptions($data_github_options);
            $data_columns = json_decode($data_set->getEAV('columns'));
            if (false === ($lat_id = array_search(strval($json->latlng[0]), $data_columns))) {
                throw new Importer_Exception("data must be lat column name");
            }
            if (false === ($lng_id = array_search(strval($json->latlng[1]), $data_columns))) {
                throw new Importer_Exception("data must be lng column name");
            }

            $set = self::getSetAndUpdateSHA($github_options, $content_obj->sha);
            $set->setEAV('data_from', DataSet::findByOptions($data_github_options)->set_id);
            $set->setEAV('data_type', 'csvmap');

            GeoPoint::getDb()->query("DELETE FROM geo_point WHERE group_id = {$set->set_id}");
            GeoPoint::getDb()->query("INSERT INTO geo_point (group_id, geo, data_id) SELECT {$set->set_id}, ST_Point((data->>{$lng_id})::numeric, (data->>{$lat_id})::numeric), id FROM data_line WHERE set_id = {$data_set->set_id}");
            $set->countBoundary();
            return 1;

        case 'Topology':
            $set = self::getSetAndUpdateSHA($github_options, $content_obj->sha);

            $columns[] = '_path';
            // TopoJSON 直接丟，因為應該是不會大到無法處理..
            $json = file_get_contents($file_path);
            $geojsons = GeoTopoJSON::toGeoJSONs(strval($json));
            foreach ($geojsons as $topo_id => $geojson) {
                $inserted = self::importGeoJSON($geojson, $set, $topo_id, $columns);
            }
            break;

        case 'FeatureCollection':
            $columns = array();
            $target_path = Helper::getTmpFile();
            mkdir($target_path);
            $cmd = "node " . escapeshellarg($script_file) . " split_feature " . escapeshellarg($file_path) . ' ' . escapeshellarg($target_path);
            exec($cmd, $outputs, $ret);

            if ($ret) {
                throw new Importer_Exception("geojson_parse split_feature failed");
            }

            $set = self::getSetAndUpdateSHA($github_options, $content_obj->sha);

            foreach (glob($target_path . '/*.json') as $feature_file) {
                $feature = json_decode(file_get_contents($feature_file));
                $inserted += self::importGeoJSON($feature, $set, null, $columns);
            }
            break;

        default:
            $json = file_get_contents($file_path);
            $set = self::getSetAndUpdateSHA($github_options, $content_obj->sha);
            $inserted = self::importGeoJSON(json_decode($json), $set, null, $columns);
            break;
        }

        $set->setEAV('columns', json_encode($columns));
        $set->setEAV('data_type', 'geojson');
        $set->countBoundary();
        return $inserted;
    }

    public static function importGeoJSON($json, $set, $path, &$columns)
    {
        switch ($json->type) {
        case 'Feature':
            $data = array();
            if (!is_null($path)) {
                $data[] = $path;
            }
            if (!$json->geometry->coordinates) {
                // TODO: 這邊要找出原因...
                return 0;
            }
            foreach ($json->properties as $key => $value) {
                if (FALSE === array_search($key, $columns)) {
                    $columns[] = $key;
                }
                $data[array_search($key, $columns)] = $value;
            }

            $data_line = DataLine::insert(array(
                'set_id' => $set->set_id,
                'data' => json_encode($data),
            ));
            $db = DataGeometry::getDb();
            $table = DataGeometry::getTable();
            $sql = "INSERT INTO data_geometry (id, set_id, geo) VALUES ({$data_line->id}, {$set->set_id}, ST_ForceCollection(ST_Force2D(ST_GeomFromGeoJSON(" . $db->quoteWithColumn($table, json_encode($json->geometry)) . "))))";
            $db->query($sql);
            return 1;

        case 'FeatureCollection':
            $c = 0;
            foreach ($json->features as $feature) {
                $c += self::importGeoJSON($feature, $set, $path, $columns);
            }
            return $c;

        case 'Point':
        case 'MultiPoint':
        case 'LineString':
        case 'MultiLineString':
        case 'Polygon':
        case 'MultiPolygon':
            $data = array($path);
            $data_line = DataLine::insert(array(
                'set_id' => $set->set_id,
                'data' => json_encode($data),
            ));
            $db = DataGeometry::getDb();
            $table = DataGeometry::getTable();
            $sql = "INSERT INTO data_geometry (id, set_id, geo) VALUES ({$data_line->id}, {$set->set_id}, ST_ForceCollection(ST_Force2D(ST_GeomFromGeoJSON(" . $db->quoteWithColumn($table, json_encode($json)) . "))))";
            $db->query($sql);
            return 1;

        default:
            throw new Importer_Exception("Unsupport json type {$json->type}");
        }
    }
}
