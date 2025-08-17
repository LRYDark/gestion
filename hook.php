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
         $DB->query($query) or die($DB->error());
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

   PluginGestionProfile::initProfile();
   PluginGestionProfile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);

   CronTask::Register(PluginGestionReminder::class, PluginGestionReminder::CRON_TASK_NAME, DAY_TIMESTAMP);
   return true; 
}

function plugin_gestion_uninstall() { // fonction desintallation du plugin

   $rep_files_rp = GLPI_PLUGIN_DOC_DIR . "/gestion";
   Toolbox::deleteDir($rep_files_rp);

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



