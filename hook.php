<?php

function plugin_gestion_install() { // fonction installation du plugin
   
   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/signed";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/unsigned";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint";
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
   return true;
}



