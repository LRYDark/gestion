<?php
function update_164_next() {
   global $DB;

   $table = 'glpi_plugin_gestion_configs';
   if (!$DB->tableExists($table)) {
      return;
   }

   $columns = $DB->doQuery("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
   $existing = array_column($columns, 'Field');

   if (!in_array('CombinedMailMode', $existing, true)) {
      $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `CombinedMailMode` TINYINT NOT NULL DEFAULT '0'") or die($DB->error());
   }

   // Default value
   $DB->doQuery("UPDATE `$table` SET `CombinedMailMode` = 0 WHERE `CombinedMailMode` IS NULL");
}
