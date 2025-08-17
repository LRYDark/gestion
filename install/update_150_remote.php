<?php
function update_150_remote() {
   global $DB;

   // Création de la table glpi_plugin_gestion_remote_sign_requests
   $create_remote_sign_requests = "
   CREATE TABLE IF NOT EXISTS `glpi_plugin_gestion_remote_sign_requests` (
   `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
   `tickets_id` int(10) unsigned NOT NULL,
   `device_id` varchar(191) NOT NULL,
   `device_token` varchar(191) NOT NULL,
   `parameters` TEXT NULL,
   `status` enum('pending','done','cancelled') NOT NULL DEFAULT 'pending',
   `signature_base64` longtext DEFAULT NULL,
   `signer_name` varchar(191) DEFAULT NULL,
   `signer_email` varchar(191) DEFAULT NULL,
   `date_creation` datetime NOT NULL DEFAULT current_timestamp(),
   `date_mod` datetime DEFAULT NULL ON UPDATE current_timestamp(),
   PRIMARY KEY (`id`),
   KEY `idx_ticket_status` (`tickets_id`,`status`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ";

   // Création de la table glpi_plugin_gestion_signaturedevices
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

   // Exécution des requêtes
   $DB->queryOrDie($create_remote_sign_requests, "Erreur lors de la création de la table glpi_plugin_gestion_remote_sign_requests");
   $DB->queryOrDie($create_signaturedevices, "Erreur lors de la création de la table glpi_plugin_gestion_signaturedevices");

   // Vérifier si les colonnes existent déjà
   $columns = $DB->query("SHOW COLUMNS FROM `glpi_plugin_gestion_configs`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'RemoteSignatureOn',
      'RemoteSignatureUsers'
   ];

   // Liste pour les colonnes manquantes
   $missing_columns = array_diff($required_columns, array_column($columns, 'Field'));

   if (!empty($missing_columns)) {
      $query= "ALTER TABLE glpi_plugin_gestion_configs
               ADD COLUMN `RemoteSignatureOn` TINYINT(4) NOT NULL DEFAULT '0',
               ADD COLUMN `RemoteSignatureUsers` LONGTEXT NULL;";
      $DB->query($query) or die($DB->error());
   }
}
