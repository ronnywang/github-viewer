<?php

class Importer_CSV
{
    public function import($github_options)
    {
        $content_obj = Importer::getContent($github_options);
        if ($set = DataSet::findByOptions($github_options) and $content_obj->sha == $set->getEAV('sha')) {
            // 沒改變，不需要重新整理
            return 0;
        }

        if ($content_obj->content) {
            $content = base64_decode($content_obj->content);
            $file_path = Helper::getTmpFile();
            file_put_contents($file_path, $content);
        } else {
            $file_path = Importer::getFullBodyFilePath($content_obj);
        }

        if (!$set) {
            $set = DataSet::createByOptions($github_options);
        }
        $set->setEAV('sha', $content_obj->sha);

        $fp = fopen($file_path, 'r');
        $columns = fgetcsv($fp);
        $set->setEAV('columns', json_encode($columns));

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");

        $insert_rows = array();
        $c = 0;
        while ($row = fgetcsv($fp)){
            $c ++;
            $insert_rows[] = array($set->set_id, json_encode($row));
        }
        if ($insert_rows) {
            DataLine::bulkInsert(array('set_id', 'data'), $insert_rows);
        }
        return $c;
    }
}
