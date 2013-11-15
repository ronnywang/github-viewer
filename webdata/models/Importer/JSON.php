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
        DataLine::search(array('set_id' => $set->set_id))->delete();
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
