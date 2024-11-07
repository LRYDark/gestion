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
      echo "<tr><th colspan='2'>" . __('Chronomètre', 'rp') . "</th></tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Affichage du chronomètre dans le ticket", "gestion") . "</td><td>";
            Dropdown::showYesNo('showtimer', $config->showTimer(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Activation automatique du chronomètre à l'ouverture du ticket", "gestion") . "</td><td>";
            Dropdown::showYesNo('showactivatetimer', $config->showactivatetimer(), -1);
            if ($config->showactivatetimer() == 0){
               echo ' Un bouton Play/Pause apparaitra pour lancé le chronomètre';
            }
         echo "</td>";
      echo "</tr>";

      if ($config->showactivatetimer() == 1){
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Possibilité de mettre sur Play/Pause et remettre à zéro le chronomètre", "gestion") . "</td><td>";
               Dropdown::showYesNo('showPlayPauseButton', $config->showPlayPauseButton(), -1);
            echo "</td>";
         echo "</tr>";
      }

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Couleur du text du chronomètre", "gestion") . "</td><td>";
            echo '<input type="color" name="showColorTimer" value="'.$config->showColorTimer().'">';
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Couleur de fond du chronomètre", "gestion") . "</td><td>";
            echo '<input type="color" name="showBackgroundTimer" value="'.$config->showBackgroundTimer().'">';
         echo "</td>";
      echo "</tr>";

      if ($config->showactivatetimer() == 0 || $config->showPlayPauseButton() == 1){
         $values = [
            0 => __('Blanc (default)','gestion'),
            1 => __('Noir','gestion'),
         ];
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Couleur des boutons Play/Pause", "gestion") . "</td><td>";
               Dropdown::showFromArray(
                  'showcolorbutton',
                  $values,
                  [
                     'value' => $config->fields['showcolorbutton']
                  ]
               );
            echo "</td>";
         echo "</tr>";
      }

      /*echo "<tr class='tab_bg_1'>";
      echo "<td>" . __("Appliquée les couleurs par défaut", "gestion") . "</td><td>";
      echo Html::submit('defaut', ['name' => 'default', 'value' => 'Defaut', 'class' => 'btn btn-info me-2']); // bouton / config par defaut
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_2 center'><td colspan='2'>";
      echo Html::submit(_sx('button', 'Save'), ['name' => 'update_config', 'class' => 'btn btn-primary']); // bouton save
      echo "</td></tr>";*/

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Affichage du recapitulatif des durées dans le ticket.", "gestion") . "</td><td>";
            Dropdown::showYesNo('showtime', $config->showTime(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Affichage de l'onglet temps de trajet dans le ticket.", "gestion") . "</td><td>";
            Dropdown::showYesNo('fromonglettrajet', $config->fromOngletTrajet(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Token GitHub.", "gestion") . "</td><td>";
            echo Html::input('token', ['value' => $config->showToken(), 'size' => 60, 'maxlength' => 80]);// bouton configuration du bas de page line 1
         echo "</td>";
      echo "</tr>";

      $config->showFormButtons(['candel' => false]);
      return false;
   }

   // return fonction (retourn les values enregistrées en bdd)
   function fromOngletTrajet()
   {
      return ($this->fields['fromonglettrajet'] ? true : false);
   }
   function showTime()
   {
      return ($this->fields['showtime'] ? true : false);
   }
   function showTimer()
   {
      return ($this->fields['showtimer'] ? true : false);
   }
   function showColorTimer()
   {
      return ($this->fields['showColorTimer']);
   }
   function showBackgroundTimer()
   {
      return ($this->fields['showBackgroundTimer']);
   }
   function showactivatetimer()
   {
      return ($this->fields['showactivatetimer'] ? true : false);
   }
   function showPlayPauseButton()
   {
      return ($this->fields['showPlayPauseButton'] ? true : false);
   }
   function showToken()
   {
      return ($this->fields['token']);
   }
   // return fonction

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         return __("Chrono / Temps de trajet ", "gestion");
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
                  `showtimer` TINYINT NOT NULL DEFAULT '1',
                  `showColorTimer` VARCHAR(255) NOT NULL DEFAULT '#000000',
                  `showBackgroundTimer` VARCHAR(255) NOT NULL DEFAULT '#fec95c',
                  `showcolorbutton` TINYINT NOT NULL DEFAULT '0',
                  `showactivatetimer` TINYINT NOT NULL DEFAULT '1',
                  `showPlayPauseButton` TINYINT NOT NULL DEFAULT '1',
                  `fromonglettrajet` TINYINT NOT NULL DEFAULT '1',
                  `showtime` TINYINT NOT NULL DEFAULT '1',
                  `token` VARCHAR(255) NULL,
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
