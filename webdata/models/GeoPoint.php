<?php

class GeoPoint extends Pix_Table
{
    public function init()
    {
        $this->_name = 'geo_point';

        $this->_primary = array('point_id');

        $this->_columns['point_id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['group_id'] = array('type' => 'int');
        $this->_columns['geo'] = array('type' => 'geography', 'modifier' => array('point', 4326));
        $this->_columns['data_id'] = array('type' => 'int');

        // GeoPoint::getDb()->query("CREATE INDEX geo_point_geo ON geo_point USING GIST(geo)");
    }

    public function _getDb()
    {
        return DataLine::getDb();
    }
}
