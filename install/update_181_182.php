<?php
/**
 * Migration 1.8.1 -> 1.8.2
 *
 * Distingue une signature client d'une validation automatique au départ du
 * transporteur et garde la preuve de l'envoi du document vers ZenDoc.
 */
function update_181_182(): void
{
   global $DB;

   $table = 'glpi_plugin_gestion_surveys';
   if (!$DB->tableExists($table)) {
      return;
   }

   $columns = [
      'completion_type'         => "VARCHAR(40) NULL AFTER `signed`",
      'transport_dispatched_at' => "TIMESTAMP NULL AFTER `completion_type`",
      'transport_carrier'       => "VARCHAR(100) NULL AFTER `transport_dispatched_at`",
      'transport_tracking'      => "VARCHAR(190) NULL AFTER `transport_carrier`",
      'transport_request_id'    => "VARCHAR(190) NULL AFTER `transport_tracking`",
      'zendoc_sent_at'          => "TIMESTAMP NULL AFTER `transport_request_id`",
   ];

   foreach ($columns as $name => $definition) {
      if ($DB->fieldExists($table, $name)) {
         continue;
      }
      try {
         $DB->doQuery("ALTER TABLE `$table` ADD `$name` $definition");
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin-gestion',
            "1.8.2 : échec ajout colonne $name : " . $e->getMessage() . "\n"
         );
      }
   }

   try {
      $indexes = $DB->doQuery("SHOW INDEX FROM `$table` WHERE Key_name = 'uniq_transport_request'");
      if (!$indexes || $DB->numrows($indexes) === 0) {
         $DB->doQuery("ALTER TABLE `$table` ADD UNIQUE KEY `uniq_transport_request` (`transport_request_id`)");
      }
   } catch (\Throwable $e) {
      Toolbox::logInFile(
         'plugin-gestion',
         "1.8.2 : échec ajout index transport_request_id : " . $e->getMessage() . "\n"
      );
   }
}
