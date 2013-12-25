<?php

include(__DIR__ . '/../init.inc.php');
Pix_Table::$_save_memory = true;
Pix_Table::disableCache(Pix_Table::CACHE_ALL);

class ImportWorker
{
    public function main()
    {
        while (true) {
            $job = ImportJob::getJob();
            if (!$job) {
                usleep(10000);
                continue;
            }

            error_log($job->info);
            $github_options = (array)json_decode($job->info);

            try {
                if (preg_match('#json$#', $github_options['path'])) {
                    // JSON
                    $count = Importer_JSON::import($github_options, $job);
                } elseif (preg_match('#\.csv$#', $path)) {
                    $count = Importer_CSV::import($github_options, $job);
                } else {
                    throw new Exception("Unsupported file format");
                }
                $job->updateStatus('finish', 'done');
            } catch (Importer_Exception $e) {
                $job->updateStatus('error', $e->getMessage());
            }

            $job->finish();
        }
    }
}

$worker = new ImportWorker;
$worker->main();
