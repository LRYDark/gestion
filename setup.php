<?php
define('PLUGIN_GESTION_VERSION', '1.3.2'); // version du plugin

// Minimal GLPI version,
define("PLUGIN_GESTION_MIN_GLPI", "10.0.3");
// Maximum GLPI version,
define("PLUGIN_GESTION_MAX_GLPI", "10.2.0");

define("PLUGIN_GESTION_WEBDIR", Plugin::getWebDir("gestion"));
define("PLUGIN_GESTION_DIR", Plugin::getPhpDir("gestion"));
define("PLUGIN_GESTION_NOTFULL_DIR", Plugin::getPhpDir("gestion",false));
define("PLUGIN_GESTION_NOTFULL_WEBDIR", Plugin::getWebDir("gestion",false));

function plugin_init_gestion() { // fonction glpi d'initialisation du plugin
   global $PLUGIN_HOOKS, $CFG_GLPI;

   $PLUGIN_HOOKS['csrf_compliant']['gestion'] = true;
   $PLUGIN_HOOKS['change_profile']['gestion'] = [PluginGestionProfile::class, 'initProfile'];

   $plugin = new Plugin();
   if ($plugin->isActivated('gestion')){ // verification si le plugin gestion est installé et activé

      if (Session::getLoginUserID()) {
         Plugin::registerClass('PluginGestionProfile', ['addtabon' => 'Profile']);
      }

      if (Session::haveRight('plugin_gestion_survey', READ)) {
         $PLUGIN_HOOKS["menu_toadd"]['gestion'] = ['management' => PluginGestionMenu::class];
      }

      if (isset($_SESSION['glpiactiveprofile']['interface'])
         && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
         $PLUGIN_HOOKS['add_javascript']['gestion'] = ['scripts/scripts-gestion.js'];
      }

      Plugin::registerClass('PluginGestionTicket', ['addtabon' => 'Ticket']);

      $PLUGIN_HOOKS['config_page']['gestion'] = 'front/config.form.php'; // initialisation de la page config
      Plugin::registerClass('PluginGestionConfig', ['addtabon' => 'Config']); // ajout de la de la class config dans glpi

      $PLUGIN_HOOKS['post_show_item']['gestion'] = ['PluginGestionTicket', 'postShowItemNewTicketGESTION']; // initialisation de la class
      $PLUGIN_HOOKS['pre_show_item']['gestion'] = ['PluginGestionTicket', 'postShowItemNewTaskGESTION']; // initialisation de la class
   }
}

function plugin_version_gestion() { // fonction version du plugin (verification et affichage des infos de la version)
   return [
      'name'           => _n('Gestion signature PDF', 'Gestion signature PDF', 2, 'gestion'),
      'version'        => PLUGIN_GESTION_VERSION,
      'author'         => 'REINERT Joris',
      'homepage'       => 'https://www.jcd-groupe.fr',
      'requirements'   => [
         'glpi' => [
            'min' => PLUGIN_GESTION_MIN_GLPI,
            'max' => PLUGIN_GESTION_MAX_GLPI,
         ]
      ]
   ];
}

/**
 * @return bool
 */
function plugin_gestion_check_prerequisites() {
   return true;
}
