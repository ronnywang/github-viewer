<?php

class DataSetRow extends Pix_Table_Row
{
    public function getEAVs()
    {
        return EAV::search(array('table' => 'DataSet', 'id' => $this->set_id));
    }

    public function countMaxMin()
    {
        $max_rows = array();
        $min_rows = array();
        foreach (DataLine::search(array('set_id' => $this->set_id)) as $data_line) {
            $rows = json_decode($data_line->data);
            foreach ($rows as $id => $row) {
                $max_rows[$id] = array_key_exists($id, $max_rows) ? max($max_rows[$id], $row) : $row;
                $min_rows[$id] = array_key_exists($id, $min_rows) ? min($min_rows[$id], $row) : $row;
            }
        }
        $this->setEAV('max_values', json_encode($max_rows));
        $this->setEAV('min_values', json_encode($min_rows));
    }
}

class DataSet extends Pix_Table
{
    public function init()
    {
        $this->_name = 'data_set';
        $this->_primary  = array('set_id');
        $this->_rowClass = 'DataSetRow';

        $this->_columns['set_id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['path'] = array('type' => 'varchar', 'size' => 128);
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['updated_at'] = array('type' => 'int');

        $this->_hooks['eavs'] = array('get' => 'getEAVs');

        $this->_relations['views'] = array('rel' => 'has_many', 'type' => 'DataView', 'foreign_key' => 'set_id');
        $this->_relations['lines'] = array('rel' => 'has_many', 'type' => 'DataLine', 'foreign_key' => 'set_id');

        $this->addRowHelper('Pix_Table_Helper_EAV', array('getEAV', 'setEAV'));
        $this->addIndex('path', array('path'), 'unique');
    }

    public function findByPath($user, $repository, $path)
    {
        return DataSet::find_by_path('/' . $user . '/' . $repository . '/' . $path);

    }
}
