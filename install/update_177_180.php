<?php
/**
 * Migration 1.7.7 -> 1.8.0 (mise à jour unique)
 *
 *  - table `glpi_plugin_gestion_userprefs` : préférences personnelles
 *    d'affichage des boutons flottants (accueil et ticket)
 *  - droit de profil `plugin_gestion_boutons` (bit READ = bouton d'accueil,
 *    bit UPDATE = bouton sur les tickets), activé pour les profils qui
 *    utilisent déjà les bons de livraison
 *
 * Ces boutons ne s'affichent que si le plugin RP est inactif : quand les deux
 * plugins tournent, c'est RP qui les fournit.
 *
 * Idempotente : chaque étape teste l'existant avant d'agir.
 */
function update_177_180() {
   global $DB;

   // --- 1) Préférences personnelles ---
   if (!$DB->tableExists('glpi_plugin_gestion_userprefs')) {
      $query = "CREATE TABLE `glpi_plugin_gestion_userprefs` (
         `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
         `users_id` INT UNSIGNED NOT NULL,
         `fab_home` TINYINT NOT NULL DEFAULT 1,
         `fab_ticket` TINYINT NOT NULL DEFAULT 1,
         `fab_home_tabs` TINYINT NOT NULL DEFAULT 3,
         PRIMARY KEY (`id`),
         UNIQUE KEY `users_id` (`users_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
      if (!$DB->doQuery($query)) {
         Toolbox::logInFile('plugin-gestion', "1.8.0 : échec création glpi_plugin_gestion_userprefs : " . $DB->error() . "\n");
      }
   }

   // --- 2) Droit des boutons flottants ---
   // La ligne est créée à 0 pour tous les profils par PluginGestionProfile::initProfile() ;
   // on l'ouvre (accueil + ticket) là où les BL sont déjà accessibles.
   $DB->doQuery("UPDATE `glpi_profilerights` pr_new
                 INNER JOIN `glpi_profilerights` pr_survey
                    ON pr_survey.`profiles_id` = pr_new.`profiles_id`
                   AND pr_survey.`name` = 'plugin_gestion_survey'
                 SET pr_new.`rights` = 3
                 WHERE pr_new.`name` = 'plugin_gestion_boutons'
                   AND pr_new.`rights` = 0
                   AND pr_survey.`rights` > 0");
}
