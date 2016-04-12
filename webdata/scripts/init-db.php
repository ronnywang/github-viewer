<?php

foreach (explode(' ', 'DataGeometry DataLine DataSet EAV FileBranchMap GeoDataMap GeoPoint ImportJob ImportJobStatus') as $table) {
    Pix_Table::getTable($table)->createTable();
}

GeoPoint::getDb()->query("CREATE INDEX geo_point_geo ON geo_point USING GIST(geo)");
