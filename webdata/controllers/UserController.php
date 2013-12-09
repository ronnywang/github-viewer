<?php

class UserController extends Pix_Controller
{
    public function indexAction($params)
    {
        $this->view->user = $params['user'];
    }

    public function getdatafrompointAction()
    {
        $layer = json_decode($_GET['layer']);
        $lat = floatval($_GET['lat']);
        $lng = floatval($_GET['lng']);

        $pixel = 0.001;

        if ($layer->type == 'geojson') {
            if (!$set = DataSet::find(intval($layer->set_id))) {
                return $this->redirect('/');
            }
            $sql = "SELECT id, ST_AsGeoJSON(merge_geo) AS json FROM (SELECT id, ST_Buffer(ST_Union(geo::geometry), {$pixel} * 2) as merge_geo FROM data_geometry WHERE set_id = {$set->set_id} AND geo && ST_PointFromText('POINT({$lng} {$lat})', 4326) GROUP BY id) AS merged_polygon WHERE ST_Contains(merge_geo, ST_GeomFromText('POINT({$lng} {$lat})', 4326))";
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
        } elseif ($layer->type == 'colormap') {
            if (!$set = DataSet::find(intval($layer->set_id))) {
                return $this->redirect('/');
            }
            if (!$mapset = DataSet::find($set->getEAV('map_from'))) {
                return $this->redirect('/');
            }
            if (!$dataset = DataSet::find($set->getEAV('data_from'))){
                return $this->redirect('/');
            }
            $sql = "SELECT id, ST_AsGeoJSON(merge_geo) AS json FROM (SELECT id, ST_Buffer(ST_Union(geo::geometry), {$pixel} * 2) as merge_geo FROM data_geometry WHERE set_id = {$mapset->set_id} AND geo && ST_PointFromText('POINT({$lng} {$lat})', 4326) GROUP BY id) AS merged_polygon WHERE ST_Contains(merge_geo, ST_GeomFromText('POINT({$lng} {$lat})', 4326))";
            $res = DataGeometry::getDb()->query($sql);
            if (!$row = $res->fetch_assoc()) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }

            $id_map = json_decode($set->getEAV('id_map'));
            $id_map = array_combine($id_map[0], $id_map[1]);

            if (!array_key_exists($row['id'], $id_map)) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }

            if (!$data_line = DataLine::find($id_map[$row['id']])) {
                $columns = array('錯誤', 'data_id');
                $values = array('找不到這筆資料', $row['id']);
                return $this->json(array('error' => false, 'columns' => $columns, 'values' => $values));
            }
            return $this->json(array('error' => false, 'columns' => json_decode($dataset->getEAV('columns')), 'values' => json_decode($data_line->data)));

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

        $num_cols = json_decode($set->getEAV('numeric_columns'));
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

    public function importDone($message, $error = true)
    {
        if ($_GET['return_to'] == 'iframe') {
            $path = '/' . urlencode($this->user) . '/' . urlencode($this->repository) . '/iframe/map/' . urlencode($this->branch) . '/' . $this->path . '?commit=' . urlencode($this->commit);
            return $this->alert($message, $path);
        } 
        return $this->json(array('error' => $error, 'message' => $message));
    }

    public function importcsvAction()
    {
        $user = $this->user = $_GET['user'];
        $repository = $this->repository = $_GET['repository'];
        $path = $this->path = $_GET['path'];
        $branch = $this->branch = $_GET['branch'] ?: 'master';
        $commit = $this->commit = $_GET['commit'];

        $github_options = array(
            'user' => $user,
            'repository' => $repository,
            'path' => $path,
            'branch' => $branch,
        );

        try {
            if (preg_match('#json$#', $path)) {
                // JSON
                $count = Importer_JSON::import($github_options);
            } elseif (preg_match('#\.csv$#', $path)) {
                $count = Importer_CSV::import($github_options);
            } else {
                return $this->importDone("Unsupported file format", true);
            }
        } catch (Importer_Exception $e) {
            return $this->importDone($e->getMessage(), true);
        }
        return $this->importDone("Imported successful", false);
    }

    public function iframeAction($params)
    {
        $this->view->user = $params['user'];
        $this->view->repository = $params['repository'];
        $this->view->branch = $params['branch'];
        $this->view->path = $params['path'];
        $this->view->tab = $params['tab'];

        $this->view->set = DataSet::findByOptions(array(
            'user' => $params['user'],
            'repository' => $params['repository'],
            'path' => $params['path'],
            'branch' => $params['branch'],
        ));

        // found set and commit same
        if ($this->view->set and trim($_GET['commit']) and 0 === strpos($this->view->set->commit, trim($_GET['commit']))) {
            header('Cache-Control: max-age=86400');
        } else {
            header('Cache-Control: no-cache');
        }

        if (!$this->view->set) {
            return $this->redraw('/user/import.phtml');
        }

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
}
