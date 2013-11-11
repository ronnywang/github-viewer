<?php

class UserController extends Pix_Controller
{
    public function indexAction($params)
    {
        $this->view->user = $params['user'];
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
}
