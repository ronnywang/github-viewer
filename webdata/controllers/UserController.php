<?php

class UserController extends Pix_Controller
{
    public function indexAction($params)
    {
        $this->view->user = $params['user'];
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

        $content = base64_decode($ret->content);
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

        // TODO: change to bulk delete
        $set->lines->delete();

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
    }
}
