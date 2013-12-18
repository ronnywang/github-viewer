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
        $objs = array();
        foreach (json_decode($this->getEAV('config'))->tabs as $id => $tab_info) {
            $objs[] = array(
                $id,
                getenv('CDN_PREFIX') . '/wms?Request=GetMap&Layers=' . urlencode($this->getLayerID($id)),
                getenv('CDN_PREFIX') . '/user/meter?Layers=' . urlencode($this->getLayerID($id)),
            );
        }
        return json_encode($objs);
    }

    public function getLayerID($opt = null)
    {
        $version = '2013121900';

        if ($this->getEAV('data_type') == 'geojson') {
            return json_encode(array(
                'type' => 'geojson',
                'set_id' => $this->set_id,
                'version' => $version,
            ));
        } elseif ($this->getEAV('data_type') == 'colormap') {
            $config = json_decode($this->getEAV('config'));

            $data = array(
                'type' => 'colormap',
                'data_from' => $this->getEAV('data_from'),
                'map_from' => $this->getEAV('map_from'),
                'data_columns' => $config->data_columns,
                'map_columns' => $config->map_columns,
                'version' => $version,
            );
            if (!is_null($opt)) {
                $data['color_config'] = ColorLib::getColorConfig($config, $opt);
                $data['column_id'] = $config->tabs->{$opt}->column_id;
            }
            return json_encode($data);
        } elseif ($this->getEAV('data_type') == 'csvmap') {
            return json_encode(array(
                'type' => 'csvmap',
                'set_id' => $this->set_id,
                'version' => $version,
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
        $this->_columns['user'] = array('type' => 'varchar', 'size' => 32);
        $this->_columns['repository'] = array('type' => 'varchar', 'size' => 32);
        $this->_columns['path'] = array('type' => 'varchar', 'size' => 255);
        $this->_columns['commit'] = array('type' => 'char', 'size' => 40);
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['updated_at'] = array('type' => 'int');

        $this->_hooks['eavs'] = array('get' => 'getEAVs');

        $this->_relations['lines'] = array('rel' => 'has_many', 'type' => 'DataLine', 'foreign_key' => 'set_id', 'delete' => true);
        // EAV:
        // data_type: geojson
        // data_type: csv

        $this->addRowHelper('Pix_Table_Helper_EAV', array('getEAV', 'setEAV'));
        $this->addIndex('user_repository_path_commit', array('user', 'repository', 'path', 'commit'), 'unique');
    }

    public function findByOptions($github_options)
    {
        // 處理 commit
        if (!$github_options['commit']) {
            $branch = $github_options['branch'];

            if ($map = FileBranchMap::find(array($github_options['user'], $github_options['repository'], $github_options['path'], $branch))) {
                $commit = $map->commit;
            } else {
                if (preg_match('#^[0-9a-f]*$#', $branch)) {
                    $files = DataSet::search(array(
                        'user' => $github_options['user'],
                        'repository' => $github_options['repository'],
                        'path' => $github_options['path'],
                    ))->search("\"commit\" LIKE '{$branch}%'");

                    if (count($files) == 1) {
                        return $files->first();
                    }
                }

                return null;
            }
        } else {
            $commit = $github_options['commit'];
        }

        return DataSet::search(array(
            'user' => $github_options['user'],
            'repository' => $github_options['repository'],
            'path' => $github_options['path'],
            'commit' => $commit,
        ))->first();
    }

    public function createByOptions($github_options)
    {
        if (!$github_options['commit']) {
            throw new Exception('no commit');
        }

        return DataSet::insert(array(
            'user' => $github_options['user'],
            'repository' => $github_options['repository'],
            'path' => $github_options['path'],
            'commit' => $github_options['commit'],
        ));
    }
}
