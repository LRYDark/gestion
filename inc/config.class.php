<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionConfig extends CommonDBTM
{
   static private $_instance = null;

   function __construct()
   {
      global $DB;

      if ($DB->tableExists($this->getTable())) {
         $this->getFromDB(1);
      }
   }

   static function canCreate()
   {
      return Session::haveRight('config', UPDATE);
   }

   static function canView()
   {
      return Session::haveRight('config', READ);
   }

   static function canUpdate()
   {
      return Session::haveRight('config', UPDATE);
   }

   static function getTypeName($nb = 0)
   {
      return __("Chrono / Temps de trajet ", "gestion");
   }

   static function getInstance()
   {
      if (!isset(self::$_instance)) {
         self::$_instance = new self();
         if (!self::$_instance->getFromDB(1)) {
            self::$_instance->getEmpty();
         }
      }
      return self::$_instance;
   }

   static function getConfig($update = false)
   {
      static $config = null;
      if (is_null(self::$config)) {
         $config = new self();
      }
      if ($update) {
         $config->getFromDB(1);
      }
      return $config;
   }

   static function showConfigForm() //formulaire de configuration du plugin
   {
      $config = new self();
      $config->getFromDB(1);

      $config->showFormHeader(['colspan' => 4]);
      echo "<tr><th colspan='2'>" . __('Gestion', 'rp') . "</th></tr>";

      // Mode de configuration de récupération
         $values = [
            0 => __('Dossier Local','gestion'),
            1 => __('SharePoint (Graph)','gestion'),
         ];
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Mode de configuration des Sauvegardes/Récupérations des PDF", "gestion") . "</td><td>";
               Dropdown::showFromArray(
                  'ConfigModes',
                  $values,
                  [
                     'value' => $config->fields['ConfigModes']
                  ]
               );
            echo "</td>";
         echo "</tr>";
      // -----------------------------------------------------------------------

      if($config->fields['ConfigModes'] == 1){
         echo "<tr><th colspan='2'>" . __('Configuration de SharePoint (API Graph)', 'rp') . "</th></tr>";
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Tenant ID", "gestion") . "</td><td>";
               echo Html::input('TenantID', ['value' => $config->TenantID(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Client ID", "gestion") . "</td><td>";
               echo Html::input('ClientID', ['value' => $config->ClientID(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Client Secret", "gestion") . "</td><td>";
               echo Html::input('ClientSecret', ['value' => $config->ClientSecret(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("URL du Site", "gestion") . "</td><td>";
               echo Html::input('SiteUrl', ['value' => $config->SiteUrl(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Nom d’hôte", "gestion") . "</td><td>";
               echo Html::input('Hostname', ['value' => $config->Hostname(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Chemin du Site", "gestion") . "</td><td>";
               echo Html::input('SitePath', ['value' => $config->SitePath(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";
      }

      $config->showFormButtons(['candel' => false]);
      return false;
   }

   // return fonction (retourn les values enregistrées en bdd)
   function TenantID()
   {
      return ($this->fields['TenantID']);
   }
   function ClientID()
   {
      return ($this->fields['ClientID']);
   }
   function ClientSecret()
   {
      return ($this->fields['ClientSecret']);
   }
   function SiteUrl()
   {
      return ($this->fields['SiteUrl']);
   }
   function Hostname()
   {
      return ($this->fields['Hostname']);
   }
   function SitePath()
   {
      return ($this->fields['SitePath']);
   }
   // return fonction

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         return __("Gestion BL", "gestion");
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         self::showConfigForm();
      }
      return true;
   }

   static function install(Migration $migration)
   {
      global $DB;

      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

      $table = self::getTable();
      $config = new self();
      if ($DB->tableExists($table)) {
         $query = "DROP TABLE $table";
         $DB->query($query) or die($DB->error());
      }
      if (!$DB->tableExists($table)) {

         $migration->displayMessage("Installing $table");

         $query = "CREATE TABLE IF NOT EXISTS $table (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `ConfigModes` TINYINT NOT NULL DEFAULT '0',
                  `TenantID` VARCHAR(255) NULL,
                  `ClientID` VARCHAR(255) NULL,
                  `ClientSecret` VARCHAR(255) NULL,
                  `SiteUrl` VARCHAR(255) NULL,
                  `Hostname` VARCHAR(255) NULL,
                  `SitePath` VARCHAR(255) NULL,
                  PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());
         $config->add(['id' => 1,]);
      }
   }

   static function uninstall(Migration $migration)
   {
      global $DB;

      $table = self::getTable();
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
