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
        list(, /*user*/, /*getdatafrompoint*/, $id) = explode('/', $this->getURI());

        if (!$set = DataSet::find(intval($id))) {
            return $this->redirect('/');
        }
        $lat = floatval($_GET['lat']);
        $lng = floatval($_GET['lng']);
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

        $url = 'https://api.github.com/repos/' . urlencode($user) . '/' . urlencode($repository) . '/contents/' . urlencode($path);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $ret = curl_exec($curl);

        if (!$ret = json_decode($ret)){
            return $this->json(array('message' => 'failed', 'error' => 1));
        }

        if ($ret->content) {
            $content = base64_decode($ret->content);
        } else {
            $url = $ret->git_url;
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $ret = curl_exec($curl);

            if (!$ret = json_decode($ret)) {
                return $this->json(array('message' => 'failed', 'error' => 1));
            }
            $content = base64_decode($ret->content);
        }
        $fp = fopen('php://temp', 'r+');
        fputs($fp, $content);
        rewind($fp);

        $db_path = '/' . $user . '/' . $repository . '/' . $path;
        try {
            $set = DataSet::insert(array(
                'path' => $db_path,
            ));
        } catch (Pix_Table_DuplicateException $e){
            $set = DataSet::find_by_path($db_path);
        }
        if (0 === strpos($content, '{')) {
            $this->importJSON($content, $set);
        } else {
            $this->importCSV($fp, $set);
        }
    }

    protected function importGeoJSON($json, $set, $path)
    {
        $columns = array('_path');

        switch ($json->type) {
        case 'FeatureCollection':
            foreach ($json->features as $feature) {
                $data = array($path);
                if (!$feature->geometry->coordinates) {
                    // TODO: 這邊要找出原因...
                    continue;
                }
                foreach ($feature->properties as $key => $value) {
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
                $sql = "INSERT INTO data_geometry (id, set_id, geo) VALUES ({$data_line->id}, {$set->set_id}, ST_ForceCollection(ST_GeomFromGeoJSON(" . $db->quoteWithColumn($table, json_encode($feature->geometry)) . ")))";
                $db->query($sql);
            }
            break;

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
            $sql = "INSERT INTO data_geometry (id, set_id, geo) VALUES ({$data_line->id}, {$set->set_id}, ST_ForceCollection(ST_GeomFromGeoJSON(" . $db->quoteWithColumn($table, json_encode($json)) . ")))";
            $db->query($sql);
            break;

        default:
            return $this->json(array('error' => true, 'message' => "Unsupport json type {$json->type}"));
        }

        $set->setEAV('columns', json_encode($columns));
        $set->setEAV('data_type', 'geojson');
    }

    protected function importJSON($json, $set)
    {
        if (!$json = json_decode($json)) {
            return $this->json(array('error' => true, 'message' => 'Invalid JSON'));
        }

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");
        DataGeometry::getDb()->query("DELETE FROM data_geometry WHERE set_id = {$set->set_id}");

        if ($json->type == 'Topology') {
            $geojsons = GeoTopoJSON::toGeoJSONs($json);
            foreach ($geojsons as $topo_id => $geojson) {
                $this->importGeoJSON($geojson, $set, $topo_id);
            }
            return $this->json(array('error' => 0));
        } else {
            $this->importGeoJSON($json, $set, '');
        }
        return $this->json(array('error' => 0));
    }

    protected function importCSV($fp, $set)
    {
        $columns = fgetcsv($fp);
        $set->setEAV('columns', json_encode($columns));

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");

        $insert_rows = array();
        while ($row = fgetcsv($fp)){
            $insert_rows[] = array($set->set_id, json_encode($row));
        }
        DataLine::bulkInsert(array('set_id', 'data'), $insert_rows);
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
