<?php

class Importer_JSON
{
    public function import($github_options, $job)
    {
        $github_obj = GithubObject::getObject($github_options, $job);
        if ($set = $github_obj->getDataSet() and in_array($set->getEAV('data_type'), array('geojson'))) {
            // 沒改變，不需要重新整理
            if (!$_GET['force']) {
                return 0;
            }
        }

        $file_path = $github_obj->file_path;

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

            // 檢查 map_repo
            $user = $json->map_repo->user;
            $repository = $json->map_repo->repository;
            $path = $json->map_repo->path;
            $branch = $json->map_repo->branch ?: 'master';
            if (!preg_match('#json$#', $path)) {
                throw new Importer_Exception("map_repo->path must be *json");
            }
            $mapfile_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
                'branch' => 'master',
            );
            Importer_JSON::import($mapfile_github_options, $job);
            $mapfile_set = DataSet::findByOptions($mapfile_github_options);

            // 檢查 data_repo
            $user = $json->data_repo->user;
            $repository = $json->data_repo->repository;
            $path = $json->data_repo->path;
            $branch = $json->data_repo->branch ?: 'master';
            if (!preg_match('#\.csv$#', $path)) {
                throw new Importer_Exception("data_repo->file must be *.csv");
            }
            $datafile_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
                'branch' => 'master',
            );
            Importer_CSV::import($datafile_github_options, $job);
            $datafile_set = DataSet::findByOptions($datafile_github_options);

            try {
                GeoDataMap::getMap($mapfile_set->set_id, $datafile_set->set_id, $json->map_columns, $json->data_columns, true);
            } catch (Exception $e){
                throw new Importer_Exception($e->getMessage());
            }
            if (!$datafile_set = DataSet::find($datafile_set->set_id)) {
                throw new Exception("DataSet {$datafile_set->set_id} is not found");
            }
            $datafile_columns = json_decode($datafile_set->getEAV('columns'));

            foreach ($json->tabs as $tab_id => $tab_info) {
                if (property_exists($tab_info, 'column')) {
                    if (false === ($id = array_search(strval($tab_info->column), $datafile_columns))) {
                        throw new Importer_Exception("data has no column: " . $tab_info->column);
                    }
                    $json->tabs->{$tab_id}->column_id = $id;
                } else {
                    throw new Importer_Exception("no column");
                }
            }
            $set = $github_obj->getDataSet(true);
            $set->setEAV('data_from', $datafile_set->set_id);
            $set->setEAV('map_from', $mapfile_set->set_id);
            $set->setEAV('config', json_encode($json));
            $set->setEAV('data_type', 'colormap');
            $github_obj->updateBranch();
            return $set;

        case 'CSVMap':
            if (!$json = json_decode(file_get_contents($file_path))) {
                throw new Importer_Exception("Invalid CSVMap JSON");
            }

            $user = $json->repo->user;
            $repository = $json->repo->repository;
            $path = $json->repo->path;
            $branch = $json->repo->branch ?: 'master';
            if (!preg_match('#\.csv$#', $path)) {
                throw new Importer_Exception("path must be csv");
            }
            $data_github_options = array(
                'user' => $user,
                'repository' => $repository,
                'path' => $path,
                'branch' => $branch,
            );
            Importer_CSV::import($data_github_options, $job);
            $data_set = DataSet::findByOptions($data_github_options);
            $data_columns = json_decode($data_set->getEAV('columns'));
            if (false === ($lat_id = array_search(strval($json->latlng[0]), $data_columns))) {
                throw new Importer_Exception("data has no column: " . $json->latlng[0]);
            }
            if (false === ($lng_id = array_search(strval($json->latlng[1]), $data_columns))) {
                throw new Importer_Exception("data has no column: " . $json->latlng[1]);
            }

            $set = $github_obj->getDataSet(true);
            $set->setEAV('data_from', DataSet::findByOptions($data_github_options)->set_id);
            $set->setEAV('data_type', 'csvmap');

            GeoPoint::getDb()->query("DELETE FROM geo_point WHERE group_id = {$set->set_id}");
            GeoPoint::getDb()->query("INSERT INTO geo_point (group_id, geo, data_id) SELECT {$set->set_id}, ST_Point((data->>{$lng_id})::numeric, (data->>{$lat_id})::numeric), id FROM data_line WHERE set_id = {$data_set->set_id}");
            $set->countBoundary();
            $github_obj->updateBranch();
            return 1;

        case 'Topology':
            $set = $github_obj->getDataSet(true);

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

            $set = $github_obj->getDataSet(true);

            $feature_count = count(glob($target_path . '/*.json'));
            $i = 0;
            foreach (glob($target_path . '/*.json') as $feature_file) {
                $i ++;
                if ($i % 100 == 0) {
                    $job->updateStatus('import-feature', "{$i}/{$feature_count}");
                }
                $feature = json_decode(file_get_contents($feature_file));
                $inserted += self::importGeoJSON($feature, $set, null, $columns);
            }
            break;

        default:
            $json = file_get_contents($file_path);
            $set = $github_obj->getDataSet(true);
            $inserted = self::importGeoJSON(json_decode($json), $set, null, $columns);
            break;
        }

        $set->setEAV('columns', json_encode($columns));
        $set->setEAV('data_type', 'geojson');
        $set->countBoundary();
        $github_obj->updateBranch();
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
