<?php
define('PLUGIN_GESTION_VERSION', '1.7.0_alpha1'); // version du plugin
$_SESSION['PLUGIN_GESTION_VERSION'] = PLUGIN_GESTION_VERSION;

// Minimal GLPI version,
define("PLUGIN_GESTION_MIN_GLPI", "11.0.0");
// Maximum GLPI version,
define("PLUGIN_GESTION_MAX_GLPI", "11.1.0");

define("PLUGIN_GESTION_WEBDIR", Plugin::getWebDir("gestion"));
define("PLUGIN_GESTION_DIR", Plugin::getPhpDir("gestion"));
define("PLUGIN_GESTION_NOTFULL_DIR", Plugin::getPhpDir("gestion",false));
define("PLUGIN_GESTION_NOTFULL_WEBDIR", Plugin::getWebDir("gestion",false));

function plugin_init_gestion() { // fonction glpi d'initialisation du plugin
   global $PLUGIN_HOOKS, $CFG_GLPI;

   $PLUGIN_HOOKS['csrf_compliant']['gestion'] = true;
   $PLUGIN_HOOKS['change_profile']['gestion'] = [PluginGestionProfile::class, 'initProfile'];

   $plugin = new Plugin();
   if ($plugin->isInstalled('gestion') && $plugin->isActivated('gestion')){  // verification si le plugin gestion est installé et activé
      // ── Endpoints BL (app technicien APPAPPLE) ─────────────────────────────
      $api_pattern_prepare      = '#^/api/bl_prepare\.php(?:/.*)?$#';
      $api_pattern_sign         = '#^/api/bl_sign\.php(?:/.*)?$#';
      $api_pattern_combined     = '#^/api/combined_sign\.php(?:/.*)?$#';
      $api_pattern_ticket_bls   = '#^/api/ticket_bls\.php(?:/.*)?$#';

      // ── Endpoints device kiosque (app APPAPPLETAB) ────────────────────────
      $api_pattern_checkin      = '#^/api/device_checkin\.php(?:/.*)?$#';
      $api_pattern_poll_v2      = '#^/api/device_poll_v2\.php(?:/.*)?$#';
      $api_pattern_submit_v2    = '#^/api/device_submit_v2\.php(?:/.*)?$#';
      $api_pattern_refuse_v2    = '#^/api/device_refuse_v2\.php(?:/.*)?$#';
      $api_pattern_direct_sign  = '#^/api/device_direct_sign\.php(?:/.*)?$#';

      $api_patterns_all = [
         $api_pattern_prepare,
         $api_pattern_sign,
         $api_pattern_combined,
         $api_pattern_ticket_bls,
         $api_pattern_checkin,
         $api_pattern_poll_v2,
         $api_pattern_submit_v2,
         $api_pattern_refuse_v2,
         $api_pattern_direct_sign,
      ];

      foreach ($api_patterns_all as $pattern) {
         \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'gestion',
            $pattern,
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
         );
         \Glpi\Http\SessionManager::registerPluginStatelessPath('gestion', $pattern);
      }

      // ── Aperçu PDF BL (view_pdf.php) ──────────────────────────────────────
      // STRATEGY_NO_CHECK : le pare-feu GLPI 11 (Symfony Kernel) s'exécute AVANT
      // le fichier PHP. Sans cette ligne, il applique STRATEGY_AUTHENTICATED et
      // appelle Session::checkLoginUser() → erreur "session expirée" pour les
      // utilisateurs non connectés, même avec un token valide.
      // Le fichier gère lui-même l'auth :
      //   - token temporaire présent → validation du token (tablette, navigation privée)
      //   - pas de token            → Session::checkLoginUser() (web authentifié)
      // NE PAS enregistrer comme stateless : la session doit rester disponible
      // pour les utilisateurs connectés via leur session GLPI.
      \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
         'gestion',
         '#^/view_pdf\.php$#',
         \Glpi\Http\Firewall::STRATEGY_NO_CHECK
      );

      if (Session::getLoginUserID()) {
         Plugin::registerClass('PluginGestionProfile', ['addtabon' => 'Profile']);

         $PLUGIN_HOOKS['add_css']['gestion'] = ["public/css/signature_gestion.css"];
         $PLUGIN_HOOKS['add_javascript']['gestion'] = ['public/js/scripts_gestion.js'];
      }

      if (Session::haveRight('plugin_gestion_survey', READ)) {
         $PLUGIN_HOOKS["menu_toadd"]['gestion'] = ['management' => PluginGestionMenu::class];
      }

      $PLUGIN_HOOKS['post_item_form']['gestion'] = ['PluginGestionTicket','AddDocForm']; // initialisation de la class formroutetime

      Plugin::registerClass('PluginGestionTicket', ['addtabon' => 'Ticket']);

      $PLUGIN_HOOKS['config_page']['gestion'] = '../../front/config.form.php?forcetab=' . urlencode('PluginGestionConfig$1'); // initialisation de la page config
      Plugin::registerClass('PluginGestionConfig', ['addtabon' => 'Config']); // ajout de la de la class config dans glpi
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
