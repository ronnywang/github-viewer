<?php

class GithubObject
{
    public static function getObject($github_options)
    {
        return new GithubObject($github_options);
    }

    protected $_github_options = null;

    protected $_content_data = null;

    public function updateBranch()
    {
        if (!$this->branch) {
            return;
        }

        $now = time();
        try {
            FileBranchMap::insert(array(
                'user' => $this->user,
                'repository' => $this->repository,
                'path' => $this->path,
                'branch' => $this->branch,
                'commit' => $this->commit,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        } catch (Pix_Table_DuplicateException $e) {
            FileBranchMap::search(array(
                'user' => $this->user,
                'repository' => $this->repository,
                'path' => $this->path,
                'branch' => $this->branch,
            ))->update(Array(
                'commit' => $this->commit,
                'updated_at' => $now,
            ));
        }
    }

    public function getDataSet($auto_create = false)
    {
        $data_set = DataSet::search(array(
            'user' => $this->user,
            'repository' => $this->repository,
            'path' => $this->path,
            'commit' => $this->commit,
        ))->first();

        if ($auto_create and !$data_set) {
            $now = time();
            $data_set = DataSet::insert(array(
                'user' => $this->user,
                'repository' => $this->repository,
                'path' => $this->path,
                'commit' => $this->commit,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }
        return $data_set;
    }

    public function getContentData()
    {
        if (!is_null($this->_content_data)) {
            return;
        }

        $github_options = $this->_github_options;
        $user = $github_options['user'];
        $repository = $github_options['repository'];
        $path = $github_options['path'];
        $branch = $github_options['branch'];

        $url = 'https://api.github.com/repos/' . urlencode($user) . '/' . urlencode($repository) . '/contents/' . urlencode($path) . '?ref=' . urlencode($branch);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate'); 
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: token ' . getenv('GITHUB_TOKEN')));
        $ret = curl_exec($curl);
        curl_close($curl);

        if (!$json = json_decode($ret)){
            throw new Importer_Exception("Content API failed");
        }

        if ($json->message == 'Not Found') {
            throw new Importer_Exception("File not found on Github");
        }

        $this->_content_data = $json;
    }

    protected $_commit_data = null;

    public function getCommitId()
    {
        if (!is_null($this->_commit_data)) {
            return;
        }

        $github_options = $this->_github_options;
        $user = $github_options['user'];
        $repository = $github_options['repository'];
        $path = $github_options['path'];
        $branch = $github_options['branch'];

        $url = 'https://api.github.com/repos/' . urlencode($user) . '/' . urlencode($repository) . '/commits?sha=' . urlencode($branch) . '&path=' . urlencode($path) . '&per_page=1&page=1';
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate'); 
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: token ' . getenv('GITHUB_TOKEN')));
        $ret = curl_exec($curl);
        curl_close($curl);

        if (!$json = json_decode($ret)){
            throw new Importer_Exception("Commits API failed");
        }

        $this->_commit_data = $json;
    }

    public function __get($type)
    {
        switch ($type) {
        case 'user':
        case 'path':
        case 'repository':
        case 'branch':
            return $this->_github_options[$type];

        case 'commit':
            $this->getCommitId();
            return $this->_commit_data[0]->sha;

        case 'file_path':
            $this->getContentData();
            if (!is_null($this->_content_data->size)) {
                $file = Helper::getTmpFile();
                file_put_contents($file, base64_decode($this->_content_data->content));
                return $file;
            }
            return $this->getFullBodyFilePath($this->_content_data->git_url);

        default:
            throw new Exception("unknown GithubObject type: {$type}");

        }
}

    public function getFullBodyFilePath($url)
    {
        $curl = curl_init($url);
        $download_fp = tmpfile();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FILE, $download_fp);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate'); 
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: token ' . getenv('GITHUB_TOKEN')));
        curl_exec($curl);
        curl_close($curl);
        fflush($download_fp);

        // 這邊解 base64 真的只能求助外部啊 orz
        $result_file = Helper::getTmpFile();
        $script_file = __DIR__ . '/../scripts/geojson_parse.js';
        $cmd = "node " . escapeshellarg($script_file) . " get_content " . escapeshellarg(stream_get_meta_data($download_fp)['uri']) . " > " . escapeshellarg($result_file);
        exec($cmd, $outputs, $ret);
        if ($ret) {
            throw new Importer_Exception("Get content from git_url failed");
        }
        fclose($download_fp);

        return $result_file;
    }

    public function __construct($github_options)
    {
        $this->_github_options = $github_options;
    }
}
