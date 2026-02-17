<?php
function update_170_next() {
   global $DB;

   $table = 'glpi_plugin_gestion_configs';
   if (!$DB->tableExists($table)) {
      return;
   }

   $columns = $DB->doQuery("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
   $existing = array_column($columns, 'Field');

   if (!in_array('PlanningBLSignatureOn', $existing, true)) {
      $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `PlanningBLSignatureOn` TINYINT NOT NULL DEFAULT '0'") or die($DB->error());
   }

   $DB->doQuery("UPDATE `$table` SET `PlanningBLSignatureOn` = 0 WHERE `PlanningBLSignatureOn` IS NULL");
}
