<?php

class DataSetRow extends Pix_Table_Row
{
    public function getEAVs()
    {
        return EAV::search(array('table' => 'DataSet', 'id' => $this->set_id));
    }

    public function countBoundary()
    {
        if ($this->getEAV('data_type') == 'geojson') {
            $res = DataGeometry::GetDb()->query("SELECT ST_Extent(geo::geometry) AS boundary FROM data_geometry WHERE set_id = {$this->set_id}");
            $row = $res->fetch_assoc();
        } elseif ($this->getEAV('data_type') == 'csvmap') {
            $data_set_id = $this->getEAV('data_from');
            $res = GeoPoint::GetDb()->query("SELECT ST_Extent(geo::geometry) AS boundary FROM geo_point WHERE group_id = {$data_set_id}");
            $row = $res->fetch_assoc();
        }
        if (!preg_match('#BOX\(([-0-9\.]*) ([-0-9\.]*),([-0-9\.]*) ([-0-9\.]*)\)#', $row['boundary'], $matches)) {
            error_log("DataSet id={$this->set_id} countBoundary failed");
            return;
        }
        $this->setEAV('boundary', json_encode(array(
            'min_lng' => $matches[1],
            'max_lng' => $matches[3],
            'min_lat' => $matches[2],
            'max_lat' => $matches[4],
        )));
    }

    public function getWmsSet()
    {
        // 給 ColorMap 專用的
        $obj = new StdClass;
        foreach (json_decode($this->getEAV('config'))->tabs as $id => $tab_info) {
            $obj->{$id} = getenv('CDN_PREFIX') . '/wms?Request=GetMap&Layers=' . urlencode($this->getLayerID($id));
        }
        return json_encode($obj);
    }

    public function getLayerID($opt = null)
    {
        if ($this->getEAV('data_type') == 'geojson') {
            return json_encode(array(
                'type' => 'geojson',
                'set_id' => $this->set_id,
            ));
        } elseif ($this->getEAV('data_type') == 'colormap') {
            $data = array(
                'type' => 'colormap',
                'set_id' => $this->set_id,
            );
            if (!is_null($opt)) {
                $data['tab'] = $opt;
            }
            return json_encode($data);
        } elseif ($this->getEAV('data_type') == 'csvmap') {
            return json_encode(array(
                'type' => 'csvmap',
                'set_id' => $this->set_id,
            ));
        }
        return json_encode(array(
            'type' => 'csv',
            'set_id' => $this->set_id,
        ));
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

        $this->_relations['views'] = array('rel' => 'has_many', 'type' => 'DataView', 'foreign_key' => 'set_id', 'delete' => true);
        $this->_relations['lines'] = array('rel' => 'has_many', 'type' => 'DataLine', 'foreign_key' => 'set_id', 'delete' => true);
        // EAV:
        // data_type: geojson
        // data_type: csv

        $this->addRowHelper('Pix_Table_Helper_EAV', array('getEAV', 'setEAV'));
        $this->addIndex('path', array('path'), 'unique');
    }

    public function getIdByOptions($github_options)
    {
        $user = $github_options['user'];
        $repository = $github_options['repository'];
        $path = $github_options['path'];
        // TODO: add branch

        return '/' . $user . '/' . $repository . '/' . $path;
    }

    public function findByOptions($github_options)
    {
        return DataSet::find_by_path(self::getIdByOptions($github_options));
    }

    public function createByOptions($github_options)
    {
        return DataSet::insert(array(
            'path' => self::getIdByOptions($github_options),
        ));
    }
}
