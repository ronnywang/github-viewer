<?php

class DataLine extends Pix_Table
{
    public function init()
    {
        $this->_name = 'data_line';
        $this->_primary = array('id');

        // 序號
        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        // 集合 ID
        $this->_columns['set_id'] = array('type' => 'int');
        // 資料內容
        $this->_columns['data'] = array('type' => 'text');

        $this->addIndex('setid_id', array('set_id', 'id'), 'unique');
    }

    public function _getDb()
    {
        if (!preg_match('#pgsql://([^:]*):([^@]*)@([^/]*)/(.*)#', strval(getenv('PGSQL_DATABASE_URL')), $matches)) {
            die('pgsql only');
        }
        $options = array(
            'host' => $matches[3],
            'user' => $matches[1],
            'password' => $matches[2],
            'dbname' => $matches[4],
        );
        return new Pix_Table_Db_Adapter_PgSQL($options);
    }
}
