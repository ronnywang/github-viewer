<?php

class Importer
{
    public function getContent($github_options)
    {
        $user = $github_options['user'];
        $repository = $github_options['repository'];
        $path = $github_options['path'];

        $url = 'https://api.github.com/repos/' . urlencode($user) . '/' . urlencode($repository) . '/contents/' . urlencode($path);
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

        return $json;
    }

    public function getFullBodyFilePath($content)
    {
        $url = $content->git_url;
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
}
