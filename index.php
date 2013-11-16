<?php

include(__DIR__ . '/webdata/init.inc.php');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

Pix_Controller::addCommonHelpers();
Pix_Controller::addDispatcher(function($url){
    list($uri, $params) = explode('&', $url, 2);
    $terms = explode('/', $uri);

    if (in_array($terms[1], array('user', 'wms'))) {
        return;
    }
    if (!$terms[2]) {
        # /ronnywang
        return array('user', 'index', array(
            'user' => $terms[1],
        ));
    }

    if (!$terms[3]) {
        # /ronnywang/[some repo]
        return array('user', 'tree', array(
            'user' =>$terms[1], 
            'repository' => $terms[2],
            'path' => '',
            'branch' => 'master',
        ));
    }

    # ronnywang/maps.nlsc.gov.tw/blob/master/landmark/country/a.csv
    if ($terms[3] == 'blob') {
        return array('user', 'blob', array(
            'user' => $terms[1],
            'repository' => $terms[2],
            'branch' => $terms[4],
            'path' => urldecode(implode('/', array_slice($terms, 5))),
        ));
    }

    if ($terms[3] == 'map') {
        return array('user', 'map', array(
            'user' => $terms[1],
            'repository' => $terms[2],
            'branch' => $terms[4],
            'path' => urldecode(implode('/', array_slice($terms, 5))),
        ));
    }

    if ($terms[3] == 'tree') {
        return array('user', 'tree', array(
            'user' => $terms[1],
            'repository' => $terms[2],
            'branch' => $terms[4],
            'path' => urldecode(implode('/', array_slice($terms, 5))),
        ));
    }

    return null;

});
Pix_Controller::dispatch(__DIR__ . '/webdata/');
