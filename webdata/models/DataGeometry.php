<?php

class DataGeometry extends Pix_Table
{
    public function init()
    {
        $this->_name = 'data_geometry';

        $this->_primary = array('id');

        $this->_columns['id'] = array('type' => 'int');
        $this->_columns['set_id'] = array('type' => 'int');
        $this->_columns['geo'] = array('type' => 'geography');
    }
}
