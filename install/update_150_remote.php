<?php
function update_150_remote() {
   global $DB;

   // --- 1) Tables ------------------------------------------------------------
   $create_remote_sign_requests = "
   CREATE TABLE IF NOT EXISTS `glpi_plugin_gestion_remote_sign_requests` (
     `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
     `tickets_id` int(10) unsigned NOT NULL,
     `device_id` varchar(191) NOT NULL,
     `device_token` varchar(191) NOT NULL,
     `parameters` longtext DEFAULT NULL,
     `status` enum('pending','done','cancelled') NOT NULL DEFAULT 'pending',
     `signature_base64` longtext DEFAULT NULL,
     `signer_name` varchar(191) DEFAULT NULL,
     `signer_email` varchar(191) DEFAULT NULL,
     `date_creation` datetime NOT NULL DEFAULT current_timestamp(),
     `date_mod` datetime DEFAULT NULL ON UPDATE current_timestamp(),
     PRIMARY KEY (`id`),
     KEY `idx_ticket_status` (`tickets_id`,`status`),
     KEY `idx_date_creation` (`date_creation`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ";

   $create_signaturedevices = "
   CREATE TABLE IF NOT EXISTS `glpi_plugin_gestion_signaturedevices` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `device_id` varchar(190) NOT NULL,
     `serial` varchar(190) DEFAULT NULL,
     `device_token` varchar(128) NOT NULL,
     `is_active` tinyint(4) NOT NULL DEFAULT 1,
     `date_mod` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
     PRIMARY KEY (`id`),
     UNIQUE KEY `device_id` (`device_id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ";

   $DB->queryOrDie($create_remote_sign_requests, "Erreur lors de la création de la table glpi_plugin_gestion_remote_sign_requests");
   $DB->queryOrDie($create_signaturedevices, "Erreur lors de la création de la table glpi_plugin_gestion_signaturedevices");

   // --- 2) Colonnes manquantes ----------------------------------------------
   $res = $DB->query("SHOW COLUMNS FROM `glpi_plugin_gestion_configs`");
   $columns = [];
   if ($res) { while ($row = $res->fetch_assoc()) { $columns[] = $row['Field']; } }
   $required_columns = ['RemoteSignatureOn','RemoteSignatureUsers'];
   $missing = array_diff($required_columns, $columns);
   if (!empty($missing)) {
      $query= "ALTER TABLE `glpi_plugin_gestion_configs`
               ADD COLUMN `RemoteSignatureOn` TINYINT(4) NOT NULL DEFAULT '0',
               ADD COLUMN `RemoteSignatureUsers` LONGTEXT NULL;";
      $DB->query($query) or die($DB->error());
   }

   // --- 3) (Essayer) d'activer l'Event Scheduler -----------------------------
   try {
      $r = $DB->query("SHOW VARIABLES LIKE 'event_scheduler'");
      $current = null;
      if ($r && ($row = $r->fetch_assoc())) { $current = strtoupper($row['Value']); }

      if ($current !== 'ON') {
         if (!$DB->query("SET GLOBAL event_scheduler = ON")) {
            if (class_exists('Toolbox')) {
               Toolbox::logInFile('plugin_gestion',
                  'WARN: impossible d’activer event_scheduler (droits manquants ?): '.$DB->error().PHP_EOL);
            }
         }
      }
   } catch (\Throwable $e) {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_gestion', 'WARN: check/enable event_scheduler a échoué: '.$e->getMessage().PHP_EOL);
      }
   }

   // --- 4) Créer l’évènement quotidien (03:00) -------------------------------
   // NB: l’évènement s’exécutera uniquement si event_scheduler=ON
   $dbName = null;
   if ($r = $DB->query("SELECT DATABASE() AS db")) {
      if ($row = $r->fetch_assoc()) { $dbName = $row['db']; }
   }
   if (!$dbName) { $dbName = 'glpi'; }

   // Recrée l'event proprement
   $DB->query("DROP EVENT IF EXISTS `ev_cleanup_remote_sign_requests`");

   $eventSql = "
   CREATE EVENT `ev_cleanup_remote_sign_requests`
   ON SCHEDULE EVERY 1 DAY
     STARTS (CURRENT_DATE + INTERVAL 3 HOUR)  -- 03:00 heure serveur
   DO
     DELETE FROM `{$dbName}`.`glpi_plugin_gestion_remote_sign_requests`
     WHERE `date_creation` < NOW() - INTERVAL 1 DAY;
   ";

   if (!$DB->query($eventSql)) {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile(
            'plugin_gestion',
            'WARN: création event ev_cleanup_remote_sign_requests échouée (droit EVENT ?): '.$DB->error().PHP_EOL
         );
      }
   }
}
