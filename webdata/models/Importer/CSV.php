<?php

class Importer_CSV
{
    public function import($github_options, $job)
    {
        $github_obj = GithubObject::getObject($github_options, $job);
        if ($set = $github_obj->getDataSet()) {
            // 沒改變，不需要重新整理
            return 0;
        }

        $file_path = $github_obj->file_path;

        $fp = fopen($file_path, 'r');
        $columns = fgetcsv($fp);
        if (strlen(implode(",", $columns)) > 1024) {
            throw new Importer_Exception("columns line is too long");
        }

        $set = $github_obj->getDataSet(true);

        $set->setEAV('columns', json_encode($columns));
        $num_columns = array_fill_keys(array_keys($columns), 1);

        DataLine::getDb()->query("DELETE FROM data_line WHERE set_id = {$set->set_id}");

        $insert_rows = array();
        $c = 0;
        while ($row = fgetcsv($fp)){
            $c ++;
            $insert_rows[] = array($set->set_id, json_encode($row));
            foreach ($row as $id => $n) {
                $num_columns[$id] &= is_numeric($n);
            }
        }
        if ($insert_rows) {
            DataLine::bulkInsert(array('set_id', 'data'), $insert_rows);
        }
        $set->setEAV('numeric_columns', json_encode($num_columns));
        $github_obj->updateBranch();
        return $c;
    }
}
