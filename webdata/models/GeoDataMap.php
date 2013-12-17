<?php

class GeoDataMap extends Pix_Table
{
    public function init()
    {
        $this->_name = 'geo_data_map';
        $this->_primary = array('data_set_id', 'map_set_id', 'column_map_crc32');

        $this->_columns['data_set_id'] = array('type' => 'int');
        $this->_columns['map_set_id'] = array('type' => 'int');
        $this->_columns['column_map_crc32'] = array('type' => 'bigint');
        $this->_columns['map'] = array('type' => 'json');
        $this->_columns['info'] = array('type' => 'json');
        $this->_columns['created_at'] = array('type' => 'int');
    }

    public static function getMap($map_set_id, $data_set_id, $map_columns, $data_columns)
    {
        $map = GeoDataMap::find(array(
            'data_set_id' => $data_set_id,
            'map_set_id' => $map_set_id,
            'column_map_crc32' => crc32(json_encode($map_columns) . json_encode($data_columns)),
        ));

        if ($map) {
            return $map;
        }
        if (!$datafile_set = DataSet::find($data_set_id)) {
            throw new Exception("DataSet {$data_set_id} is not found");
        }
        $datafile_columns = json_decode($datafile_set->getEAV('columns'));

        if (!$mapfile_set = DataSet::find($map_set_id)){
            throw new Exception("DataSet {$map_set_id} is not found");
        }
        $mapfile_columns = json_decode($mapfile_set->getEAV('columns'));

        $map_column_ids = array();
        foreach ($map_columns as $map_column) {
            if (false === ($id = array_search(strval($map_column), $mapfile_columns))) {
                throw new Exception("map has no column: " . $map_column);
            }
            $map_column_ids[] = $id;
        }

        $data_column_ids = array();
        foreach ($data_columns as $data_column) {
            if (false === ($id = array_search(strval($data_column), $datafile_columns))) {
                throw new Exception("data has no column: " . $data_column);
            }
            $data_column_ids[] = $id;
        }

        if (count($data_column_ids) != count($map_column_ids)) {
            throw new Exception("data_columns size must be equal map_columns size");
        }

        $sql = "SELECT id, " . implode(', ', array_map(function($i){ return 'data->>' . $i; }, $data_column_ids)) . " FROM data_line WHERE set_id = {$datafile_set->set_id}";
        $res = DataLine::getDb()->query($sql);
        $data_ids = array();
        while ($row = $res->fetch_array()) {
            $id = array_shift($row);
            $data_ids[json_encode($row)] = $id;
        }
        $res->free_result();

        $sql = "SELECT id, " . implode(', ', array_map(function($i){ return 'data->>' . $i; }, $map_column_ids)) . " FROM data_line WHERE set_id = {$mapfile_set->set_id}";
        $res = DataLine::getDb()->query($sql);
        $id_map = array(array(), array());
        $id_miss = array(array(), array());

        while ($row = $res->fetch_array()) {
            $id = array_shift($row);
            $location_id = json_encode($row);

            if (array_key_exists($location_id, $data_ids)) {
                $id_map[0][] = $id;
                $id_map[1][] = $data_ids[$location_id];
                unset($data_ids[$location_id]);
            } else {
                $id_miss[0][] = implode(",", $row);
            }
        }

        $id_miss[1] = array_map(function($i){ return implode(',', json_decode($i)); }, array_keys($data_ids));

        $map = GeoDataMap::insert(array(
            'data_set_id' => $datafile_set->set_id,
            'map_set_id' => $mapfile_set->set_id,
            'column_map_crc32' => crc32(json_encode($map_columns) . json_encode($data_columns)),
            'map' => json_encode($id_map),
            'info' => json_encode($id_miss),
            'created_at' => time(),
        ));
        return $map;
    }
}
