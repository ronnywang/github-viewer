<?php

class UserController extends Pix_Controller
{
    public function indexAction($params)
    {
        $this->view->user = $params['user'];
    }

    public function meterAction()
    {
        $layers = $_GET['Layers'];
        $layer_data = json_decode($layers);

        $colors = $layer_data->color_config;

        $height = 300;
        $padding = 5;
        $level = 20;

        $min_val = min(array_map(function($a){ return $a[0]; }, $colors));
        $max_val = max(array_map(function($a){ return $a[0]; }, $colors));

        $gd = imagecreatetruecolor(100, $height);
        $bg_color = imagecolorallocate($gd, 254, 254, 254);
        $black = imagecolorallocate($gd, 0, 0, 0);
        imagecolortransparent($gd, $bg_color);
        imagefill($gd, 0, 0, $bg_color);

        for ($i = 0; $i < $level; $i ++) {
            $v = $min_val + $i * ($max_val - $min_val) / ($level - 1);
            $rgb = ColorLib::getColor($v, $colors);
            $color = imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]);
            imagefilledrectangle($gd, 0, floor($padding + ($level - $i) * ($height - 2 * $padding) / $level), 40, floor($padding + ($level - $i - 1) * ($height - 2 * $padding) / $level), $color);
        }
        foreach ($colors as $v_rgb) {
            list($v, $rgb) = $v_rgb;
            imagestring($gd, 0, 45, ($height - $padding) - ($v - $min_val) / ($max_val - $min_val) * ($height - 2 * $padding), $v, $black);
        }

        header('Content-Type: image/png');
        imagepng($gd);

        return $this->noview();
    }

    public function getdatafrompointAction()
    {
        $layer = json_decode($_GET['Layers']);
        $lat = floatval($_GET['lat']);
        $lng = floatval($_GET['lng']);

        $pixel = 0.001;

        if ($layer->type == 'geojson') {
            if (!$set = DataSet::find(intval($layer->set_id))) {
                return $this->redirect('/');
            }
            $sql = "SELECT id, ST_AsGeoJSON(geo) AS json FROM data_geometry WHERE set_id = {$set->set_id} AND geo && ST_PointFromText('POINT({$lng} {$lat})', 4326) ORDER BY ST_Distance(geo, ST_PointFromText('POINT({$lng} {$lat})', 4326)) ASC LIMIT 1";
            $res = DataGeometry::getDb()->query($sql);
            if (!$row = $res->fetch_assoc()) {
                var_dump($row);
                return $this->json(array('error' => true, 'message' => 'not found'));
            }
            if (!$data_line = DataLine::search(array('set_id' => $set->set_id, 'id' => $row['id']))->first()) {
                $columns = array('錯誤', 'data_id');
                $values = array('找不到這筆資料', $row['id']);
                return $this->json(array('error' => false, 'columns' => $columns, 'values' => $values));
            }
            return $this->json(array('error' => false, 'columns' => json_decode($set->getEAV('columns')), 'values' => json_decode($data_line->data)));
        } elseif ($layer->type == 'colormap') {
            $mapset_id = intval($layer->map_from);
            $dataset_id = intval($layer->data_from);

            $sql = "SELECT id, ST_AsGeoJSON(geo) AS json FROM data_geometry WHERE set_id = {$mapset_id} AND geo && ST_PointFromText('POINT({$lng} {$lat})', 4326) ORDER BY ST_Distance(geo, ST_PointFromText('POINT({$lng} {$lat})', 4326)) ASC LIMIT 1";
            $res = DataGeometry::getDb()->query($sql);
            if (!$row = $res->fetch_assoc()) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }

            try {
                $map = GeoDataMap::getMap($mapset_id, $dataset_id, $layer->map_columns, $layer->data_columns);
            } catch (Exception $e) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }
            $id_map = json_decode($map->map);
            $id_map = array_combine($id_map[0], $id_map[1]);

            if (!array_key_exists($row['id'], $id_map)) {
                return $this->json(array('error' => true, 'message' => 'not found'));
            }

            $dataset = DataSet::find($layer->data_from);
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

    public function getimportstatusAction()
    {
        header('Cache-Control: no-cache');

        $id = intval($_GET['id']);

        if ($import_job_status = ImportJobStatus::find($id)) {
            return $this->json(array(
                'status' => 'importing',
                'data' => json_decode(ImportJobStatus::find($id)->status),
            ));
        }

        if ($import_job = ImportJob::find($id)) {
            return $this->json(array(
                'status' => 'waiting',
            ));
        }

        return $this->json(array(
            'status' => 'not_found',
        ));
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

        $job = ImportJob::addJob($github_options);

        return $this->json(array(
            'id' => $job->id,
        ));
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
            $this->view->commit = strval($_GET['commit']);
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
