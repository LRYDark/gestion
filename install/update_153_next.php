<?php
function update_153_next() {
   global $DB;

   $table = 'glpi_plugin_gestion_baseitems';

   if (!$DB->tableExists($table)) {
      $sql = "
         CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `info`        VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
      ";
      $DB->doQuery($sql) or die($DB->error());
   }
}

