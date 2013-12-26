<?php

class ImportJobRow extends Pix_Table_Row
{
    public function finish()
    {
        // XXX: log here
        $this->delete();
    }

    public function updateStatus($stage, $info)
    {
        error_log($stage . ' ' . json_encode($info));
        if (!$job_status = ImportJobStatus::find($this->id)) {
            $status = new StdClass;
            $status->current_stage = null;
            $status->stage_status = array();
            $job_status = ImportJobStatus::insert(array(
                'id' => $this->id,
                'updated_at' => time(),
                'status' => json_encode($status),
            ));
        }
        $status = json_decode($job_status->status);

        if (is_null($status->current_stage)) {
            $status->current_stage = 0;
        } elseif ($status->stage_status[$status->current_stage][0] == $stage) {
        } else {
            $status->current_stage ++;
        }
        $status->stage_status[$status->current_stage] = array(
            $stage,
            time(),
            $info,
        );
        $job_status->update(array(
            'updated_at' => time(),
            'status' => json_encode($status),
        ));
    }
}

class ImportJob extends Pix_Table
{
    public function init()
    {
        $this->_name = 'import_job';
        $this->_rowClass = 'ImportJobRow';

        $this->_primary = 'id';
        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['created_at'] = array('type' => 'int');
        // 0 - waiting, {id} - working id
        $this->_columns['running'] = array('type' => 'int');
        $this->_columns['info'] = array('type' => 'json');
    }

    public static function addJob($info)
    {
        $q = ImportJob::insert(array(
            'created_at' => time(),
            'running' => 0,
            'info' => json_encode($info),
        ));
        return $q;
    }

    public static function getJob()
    {
        $running_id = rand(0, 1000000);
        ImportJob::getDb()->query("UPDATE import_job SET \"running\" = {$running_id} WHERE \"id\" = (SELECT id FROM import_job WHERE \"running\" = 0 LIMIT 1)");
        return ImportJob::search(array('running' => $running_id))->first();
    }
}
