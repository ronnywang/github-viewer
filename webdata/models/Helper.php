<?php

class Helper
{
    protected static $_tmp_file_used = null;

    public static function getTmpFile()
    {
        if (is_null(self::$_tmp_file_used)) {
            self::$_tmp_file_used = array();
            register_shutdown_function(array('Helper', 'deleteTmpFile'));
        }
        $file_name = tempnam('', 'HelperGetTmpFile-');
        unlink($file_name);
        self::$_tmp_file_used[] = $file_name;
        return $file_name;
    }

    public static function deleteTmpFile()
    {
        foreach (self::$_tmp_file_used as $file) {
            self::deleteFile($file);
        }
    }

    protected static function deleteFile($file)
    {
        if (is_dir($file)) {
            $d = opendir($file);
            while ($f = readdir($d)) {
                if ($f == '.' or $f == '..') {
                    continue;
                }
                self::deleteFile($file . '/' . $f);
            }
            rmdir($file);
        } else if (is_file($file)) {
            unlink($file);
        }
    }
}
