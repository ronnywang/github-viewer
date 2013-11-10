<?php

class DataViewRow extends Pix_Table_Row
{
    public function getConfig()
    {
        return json_decode($this->config);
    }

    public function getLayerID()
    {
        $config = $this->getConfig();
        if (!$config->max_value1) {
            $max_values = json_decode($this->set->getEAV('max_values'));
            $config->max_value1 = $max_values[$config->number1];
        }
        if (!$config->min_value1) {
            $max_values = json_decode($this->set->getEAV('min_values'));
            $config->min_value1 = $max_values[$config->number1];
        }
        if (!$config->max_value2) {
            $max_values = json_decode($this->set->getEAV('max_values'));
            $config->max_value2 = $max_values[$config->number2];
        }
        if (!$config->min_value2) {
            $max_values = json_decode($this->set->getEAV('min_values'));
            $config->min_value2 = $max_values[$config->number2];
        }

        return json_encode(array(
            $this->set->getMatchGroup()->group_id,
            $this->set_id,
            $this->updated_at,
            $config->number1,
            $config->number2,
            explode(',', $config->color1),
            explode(',', $config->color2),
            $config->max_value1,
            $config->min_value1,
            $config->max_value2,
            $config->min_value2,
        ));
    }
}

class DataView extends Pix_Table
{
    public function init()
    {
        $this->_name = 'data_view';
        $this->_primary = 'id';
        $this->_rowClass = 'DataViewRow';

        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['set_id'] = array('type' => 'int');
        $this->_columns['updated_at'] = array('type' => 'int');
        $this->_columns['config'] = array('type' => 'text');

        $this->_relations['set'] = array('rel' => 'has_one', 'type' => 'DataSet', 'foreign_key' => 'set_id');

        $this->addIndex('set_id', array('set_id'));
    }

    public function getColors()
    {
        return array(
            '255,0,0' => '紅',
            '0,255,0' => '綠',
            '0,0,255' => '藍',
            '255,255,0' => '黃',
            '255,0,255' => '紫',
            '0,0,0' => '黑',
            '255,255,255' => '白',
        );
    }
}
