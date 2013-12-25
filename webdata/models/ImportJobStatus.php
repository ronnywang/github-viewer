<?php

class ImportJobStatus extends Pix_Table
{
    public function init()
    {
        $this->_name = 'import_job_status';
        $this->_primary = 'id';

        $this->_columns['id'] = array('type' => 'int');
        $this->_columns['updated_at'] = array('type' => 'int');
        $this->_columns['status'] = array('type' => 'json');
    }
}
