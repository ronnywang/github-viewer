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
}
