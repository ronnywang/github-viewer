<?php

class UserController extends Pix_Controller
{
    public function indexAction($params)
    {
        $this->view->user = $params['user'];
    }

    public function importgeoAction()
    {
        // TODO: 要線上產生
        $sql = "DELETE FROM geo_point WHERE group_id = 8;";
        $sql = "INSERT INTO geo_point (group_id, geo, data_id) SELECT set_id, ST_Point((data->>7)::numeric, (data->>6)::numeric), id FROM data_line WHERE set_id = 8;";
    }

    public function getdatafrompointAction()
    {
        $layer = json_decode($_GET['layer']);
        $lat = floatval($_GET['lat']);
        $lng = floatval($_GET['lng']);

        if ($layer->type == 'geojson') {
            if (!$set = DataSet::find(intval($layer->set_id))) {
                return $this->redirect('/');
            }
            $sql = "SELECT id FROM data_geometry WHERE set_id = {$set->set_id} AND geo && ST_PointFromText('POINT({$lng} {$lat})', 4326)";
            $res = DataGeometry::getDb()->query($sql);
            if (!$row = $res->fetch_assoc()) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }
            if (!$data_line = DataLine::search(array('set_id' => $set->set_id, 'id' => $row['id']))->first()) {
                $columns = array('錯誤', 'data_id');
                $values = array('找不到這筆資料', $row['id']);
                return $this->json(array('error' => false, 'columns' => $columns, 'values' => $values));
            }
            return $this->json(array('error' => false, 'columns' => json_decode($set->getEAV('columns')), 'values' => json_decode($data_line->data)));
        } else {
            if (!$set = DataSet::find(intval($layer->set_id))) {
                return $this->redirect('/');
            }
            $sql = "SELECT data_id FROM geo_point WHERE group_id = {$set->set_id} ORDER BY geo::geometry <-> ST_PointFromText('POINT({$lng} {$lat})', 4326) LIMIT 1";
            $db = GeoPoint::getDb();

            $res = $db->query($sql);
            if (!$row = $res->fetch_assoc()) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }

            if (!$data_line = DataLine::search(array('set_id' => $set->set_id, 'id' => $row['data_id']))->first()) {
                $columns = array('錯誤', 'data_id');
                $values = array('找不到這筆資料', $data_id);
                return $this->json(array('error' => false, 'columns' => $columns, 'values' => $values));
            }
            return $this->json(array('error' => false, 'columns' => json_decode($set->getEAV('columns')), 'values' => json_decode($data_line->data)));
        }
    }

    public function getdataAction()
    {
        list(, /*data*/, /*getdata*/, $id) = explode('/', $this->getURI());
        if (!$set = DataSet::find(intval($id))) {
            return $this->redirect('/');
        }
        $data_lines = DataLine::search(array('set_id' => $set->set_id));

        $page = intval($_GET['page']);
        $limit = intval($_GET['rows']);
        $sidx = intval($_GET['sidx']);
        $sord = 'asc' == $_GET['sord'] ? 'asc' : 'desc';

        $ret = new StdClass;
        $ret->page = $page;
        $ret->total = ceil(count($data_lines) / $limit);
        $ret->records = count($data_lines);
        $ret->rows = array();

        $num_cols = json_decode($set->getEAV('num_cols'));
        if (1 == $num_cols[$sidx]) {
            $order_string = "(data->>{$sidx})::numeric {$sord}";
        } else {
            $order_string = "(data->>{$sidx}) {$sord}";
        }

        $sql = "SELECT * FROM \"data_line\" WHERE (\"set_id\" = {$set->set_id})";
        $sql .= " ORDER BY {$order_string}";
        $sql .= " LIMIT {$limit}";
        $sql .= " OFFSET " . ($limit * $page - $limit);
        $res = DataLine::getDb()->query($sql);
        while ($db_row = $res->fetch_assoc()) {
            $row = array('id' => $db_row['id'], 'cell' => json_decode($db_row['data']));
            $ret->rows[] = $row;
        }
        return $this->json($ret);
    }

    public function importcsvAction()
    {
        $user = $_GET['user'];
        $repository = $_GET['repository'];
        $path = $_GET['path'];
        $db_path = '/' . $user . '/' . $repository . '/' . $path;
        $github_options = array(
            'user' => $user,
            'repository' => $repository,
            'path' => $path,
        );

        if (preg_match('#json$#', $path)) {
            // JSON
        } elseif (preg_match('#\.csv$#', $path)) {
            // CSV
        } else {
            return $this->json(array('error' => true, 'message' => '不確定檔案格式，無法匯入'));
        }

        $ret = Importer::getContent($github_options);

        try {
            $set = DataSet::insert(array(
                'path' => $db_path,
            ));
        } catch (Pix_Table_DuplicateException $e){
            $set = DataSet::find_by_path($db_path);
        }

        if ($set->getEAV('sha') == $ret->sha) {
            return $this->json(array('message' => '檔案未修改', 'error' => true));
        }
        $set->setEAV('sha', $ret->sha);

        if ($ret->content) {
            $content = base64_decode($ret->content);
            $file_path = Helper::getTmpFile();
            file_put_contents($file_path, $content);
        } else {
            $file_path = Importer::getFullBodyFilePath($ret);
        }


        if (preg_match('#json$#', $path)) {
            $this->importJSON($file_path, $set);
        } elseif (preg_match('#\.csv$#', $path)) {
            $this->importCSV($file_path, $set);
        }
    }

    protected function importGeoJSON($json, $set, $path, &$columns)
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
                $c += $this->importGeoJSON($feature, $set, $path, $columns);
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
            return $this->json(array('error' => true, 'message' => "Unsupport json type {$json->type}"));
        }
    }

    protected function importJSON($file_path, $set)
    {
        $script_file = __DIR__ . '/../scripts/geojson_parse.js';
        $cmd = "node " . escapeshellarg($script_file) . " get_type " . escapeshellarg($file_path);
        exec($cmd, $outputs, $ret);

        if ($ret) {
            return $this->json(array('error' => true, 'message' => 'Invalid JSON'));
        }
        $type = implode("\n", $outputs);

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");
        DataGeometry::getDb()->query("DELETE FROM data_geometry WHERE set_id = {$set->set_id}");

        $columns = array();
        if ($type == 'Topology') {
            $columns[] = '_path';
            // TopoJSON 直接丟，因為應該是不會大到無法處理..
            $json = file_get_contents($file_path);
            $geojsons = GeoTopoJSON::toGeoJSONs(strval($json));
            foreach ($geojsons as $topo_id => $geojson) {
                $inserted = $this->importGeoJSON($geojson, $set, $topo_id, $columns);
            }
        } elseif ($type == 'FeatureCollection') {
            $target_path = Helper::getTmpFile();
            mkdir($target_path);
            $cmd = "node " . escapeshellarg($script_file) . " split_feature " . escapeshellarg($file_path) . ' ' . escapeshellarg($target_path);
            exec($cmd, $outputs, $ret);

            if ($ret) {
                return $this->json(array('error' => true, 'message' => 'Invalid FeatureCollection JSON'));
            }

            foreach (glob($target_path . '/*.json') as $feature_file) {
                $feature = json_decode(file_get_contents($feature_file));
                $inserted += $this->importGeoJSON($feature, $set, null, $columns);
            }
        } else {
            // 其他的直接 json_decode 就好了
            $json = file_get_contents($file_path);
            $inserted = $this->importGeoJSON(json_decode($json), $set, null, $columns);
        }

        $set->setEAV('columns', json_encode($columns));
        $set->setEAV('data_type', 'geojson');
        $set->countBoundary();

        return $this->json(array('error' => 0, 'count' => $inserted, 'columns' => $columns));
    }

    protected function importCSV($file_path, $set)
    {
        $fp = fopen($file_path, 'r');
        $columns = fgetcsv($fp);
        $set->setEAV('columns', json_encode($columns));

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");

        $insert_rows = array();
        while ($row = fgetcsv($fp)){
            $insert_rows[] = array($set->set_id, json_encode($row));
        }
        if ($insert_rows) {
            DataLine::bulkInsert(array('set_id', 'data'), $insert_rows);
        }
        return $this->json(array('error' => 0));
    }

    public function blobAction($params)
    {
        $this->view->user = $params['user'];
        $this->view->repository = $params['repository'];
        $this->view->branch = $params['branch'];
        $this->view->path = $params['path'];
    }

    public function treeAction($params)
    {
        $this->view->user = $params['user'];
        $this->view->repository = $params['repository'];
        $this->view->path = $params['path'];
        $this->view->branch = $params['branch'];
    }

    public function mapAction($params)
    {
        $this->view->user = $params['user'];
        $this->view->repository = $params['repository'];
        $this->view->branch = $params['branch'];
        $this->view->path = $params['path'];

        if (!$set = DataSet::findByPath($params['user'], $params['repository'], $params['path'])) {
            return $this->redirect('/');
        }

        $this->view->data_set = $set;
    }
}
