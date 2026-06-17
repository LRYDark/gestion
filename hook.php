<?php

function plugin_gestion_install() { // fonction installation du plugin
   global $DB;
   
   //*********************************************************************************************** */
      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
      $table = 'glpi_plugin_gestion_surveys';

      if (!$DB->tableExists($table)) {
         $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL auto_increment,
                     `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `users_id` int {$default_key_sign} NULL,
                     `users_ext` VARCHAR(255) NULL,
                     `tracker` VARCHAR(255) NULL,
                     `url_bl` VARCHAR(255) NULL,
                     `bl` VARCHAR(255) NULL,
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     `doc_url` TEXT NULL,
                     `doc_date` TIMESTAMP NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());
      }
   //*********************************************************************************************** */
   
   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/Documents";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/DocumentsSigned";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $migration = new Migration(PLUGIN_GESTION_VERSION);

   // Parse inc directory
   foreach (glob(dirname(__FILE__).'/inc/*') as $filepath) {
      // Load *.class.php files and get the class name
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginGestion' . ucfirst($matches[1]);
         include_once($filepath);
         // If the install method exists, load it
         if (method_exists($classname, 'install')) {
            $classname::install($migration);
         }
      }
   }
   $migration->executeMigration();

   // ── Migrations spécifiques par lot (version cible) ──────────────────────
   // update_150_remote : remote_sign_requests + signaturedevices (ancienne structure)
   $update150 = dirname(__FILE__) . '/install/update_150_remote.php';
   if (file_exists($update150)) {
      require_once $update150;
      if (function_exists('update_150_remote')) {
         update_150_remote();
      }
   }

   // update_153_next : baseitems (sera droppé par update_170_alpha1)
   $update153 = dirname(__FILE__) . '/install/update_153_next.php';
   if (file_exists($update153)) {
      require_once $update153;
      // Note: update_153_next crée baseitems uniquement si absente.
      // update_170_alpha1 la supprime ensuite → ordre correct.
      if (function_exists('update_153_next')) {
         update_153_next();
      }
   }

   // update_170_alpha1 : migration 1.7.0_alpha1
   //   - Crée glpi_plugin_gestion_devices (serial+ip+last_seen+status)
   //   - Migre remote_sign_requests (device_serial remplace device_id/token)
   //   - Supprime glpi_plugin_gestion_baseitems (Base Description / Info)
   //   - Supprime glpi_plugin_gestion_signaturedevices (ancien schéma token)
   $update170 = dirname(__FILE__) . '/install/update_170_alpha1.php';
   if (file_exists($update170)) {
      require_once $update170;
      if (function_exists('update_170_alpha1')) {
         update_170_alpha1();
      }
   }

   PluginGestionProfile::initProfile();
   PluginGestionProfile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);

   CronTask::Register(PluginGestionReminder::class, PluginGestionReminder::CRON_TASK_NAME, DAY_TIMESTAMP);
   return true;
}

function plugin_gestion_uninstall() { // fonction desintallation du plugin

   // Suppression du dossier de documents : NON bloquante.
   // Sur certains FS (disque reseau/mappe Windows), chmod echoue et GLPI 11 l'enveloppe
   // dans Safe\chmod() qui leve une exception -> sans ce try/catch, toute la
   // desinstallation s'interrompt (les tables ne seraient jamais supprimees).
   $rep_files_rp = GLPI_PLUGIN_DOC_DIR . "/gestion";
   try {
      if (file_exists($rep_files_rp)) {
         Toolbox::deleteDir($rep_files_rp);
      }
   } catch (Throwable $e) {
      // On poursuit la desinstallation meme si le dossier n'a pas pu etre supprime.
   }

   $migration = new Migration(PLUGIN_GESTION_VERSION);

   // Parse inc directory
   foreach (glob(dirname(__FILE__).'/inc/*') as $filepath) {
      // Load *.class.php files and get the class name
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginGestion' . ucfirst($matches[1]);
         include_once($filepath);
         // If the install method exists, load it
         if (method_exists($classname, 'uninstall')) {
            $classname::uninstall($migration);
         }
      }
   }

   $migration->executeMigration();

      //Delete rights associated with the plugin
      $profileRight = new ProfileRight();
      foreach (PluginGestionProfile::getAllRights() as $right) {
         $profileRight->deleteByCriteria(['name' => $right['field']]);
      }
      PluginGestionProfile::removeRightsFromSession();
      PluginGestionMenu::removeRightsFromSession();
   
      CronTask::Register(PluginGestionReminder::class, PluginGestionReminder::CRON_TASK_NAME, DAY_TIMESTAMP);

   return true;
}



