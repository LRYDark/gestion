<?php
function update_163_next() {
   global $DB;

   $table = 'glpi_plugin_gestion_configs';
   if (!$DB->tableExists($table)) {
      return;
   }

   $columns = $DB->doQuery("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
   $existing = array_column($columns, 'Field');

   $queries = [];
   if (!in_array('TaskSignatureUsers', $existing, true)) {
      $queries[] = "ALTER TABLE `$table` ADD COLUMN `TaskSignatureUsers` TEXT NULL";
   }
   if (!in_array('TaskSignatureTriggerStates', $existing, true)) {
      $queries[] = "ALTER TABLE `$table` ADD COLUMN `TaskSignatureTriggerStates` TEXT NULL";
   }

   foreach ($queries as $q) {
      $DB->doQuery($q) or die($DB->error());
   }

   // Defaults
   $DB->doQuery("UPDATE `$table` SET `TaskSignatureUsers` = '[]' WHERE `TaskSignatureUsers` IS NULL");
   $DB->doQuery("UPDATE `$table` SET `TaskSignatureTriggerStates` = '[2]' WHERE `TaskSignatureTriggerStates` IS NULL OR `TaskSignatureTriggerStates` = ''");
}
