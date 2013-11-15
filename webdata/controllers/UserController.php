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

            $data_set = DataSet::find($set->getEAV('data_from'));
            if (!$data_line = DataLine::search(array('set_id' => $data_set->set_id, 'id' => $row['data_id']))->first()) {
                $columns = array('錯誤', 'data_id');
                $values = array('找不到這筆資料', $data_id);
                return $this->json(array('error' => false, 'columns' => $columns, 'values' => $values));
            }
            return $this->json(array('error' => false, 'columns' => json_decode($data_set->getEAV('columns')), 'values' => json_decode($data_line->data)));
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
            $count = Importer_JSON::import($github_options);
        } elseif (preg_match('#\.csv$#', $path)) {
            $count = Importer_CSV::import($github_options);
        } else {
            return $this->json(array('error' => true, 'message' => '不確定檔案格式，無法匯入'));
        }
        return $this->json(array('error' => false, 'count' => $count));
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
