<?php

class FileBranchMap extends Pix_Table
{
    public function init()
    {
        $this->_name = 'file_branch_map';
        $this->_primary = array('user', 'repository', 'path', 'branch');

        $this->_columns['user'] = array('type' => 'varchar', 'size' => 32);
        $this->_columns['repository'] = array('type' => 'varchar', 'size' => 32);
        $this->_columns['path'] = array('type' => 'varchar', 'size' => 255);
        $this->_columns['branch'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['created_at'] = array('type' => 'int', 'default' => 0);
        $this->_columns['updated_at'] = array('type' => 'int', 'default' => 0);

        $this->_columns['commit'] = array('type' => 'char', 'size' => 40);
    }
}

