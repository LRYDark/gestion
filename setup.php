<?php
define('PLUGIN_GESTION_VERSION', '1.7.1'); // version du plugin
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

if (!function_exists('plugin_gestion_build_view_pdf_url')) {
   /**
    * Build the preview URL for a Sage PDF.
    * Uses url_base + PLUGIN_GESTION_NOTFULL_WEBDIR to avoid duplicating root_doc.
    */
   function plugin_gestion_build_view_pdf_url(string $doc_id, bool $absolute = true): string {
      global $CFG_GLPI;

      $suffix = '/view_pdf.php?id=' . rawurlencode(trim($doc_id));
      $full_webdir = '/' . trim((string)PLUGIN_GESTION_WEBDIR, '/');
      $plain_webdir = '/' . trim((string)PLUGIN_GESTION_NOTFULL_WEBDIR, '/');
      $fullPath = $full_webdir . $suffix;

      if (!$absolute) {
         return $fullPath;
      }

      $url_base = trim((string)($CFG_GLPI['url_base'] ?? ''));
      if ($url_base !== '') {
         return rtrim($url_base, '/') . $plain_webdir . $suffix;
      }

      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host   = trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
      if ($host !== '') {
         $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
         return $scheme . '://' . $host . $root_doc . $plain_webdir . $suffix;
      }

      return $fullPath;
   }
}

if (!function_exists('plugin_gestion_normalize_view_pdf_url')) {
   /**
    * Normalize known legacy preview URL variants saved in DB.
    */
   function plugin_gestion_normalize_view_pdf_url(string $url): string {
      global $CFG_GLPI;

      $url = trim($url);
      if ($url === '') {
         return $url;
      }

      $url = str_replace('/ajax/view_pdf.php', '/view_pdf.php', $url);
      $url = str_replace('/plugins/gestion/public/view_pdf.php', '/plugins/gestion/view_pdf.php', $url);

      $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
      if ($root_doc !== '') {
         $url = str_replace(
            $root_doc . $root_doc . '/plugins/gestion/view_pdf.php',
            $root_doc . '/plugins/gestion/view_pdf.php',
            $url
         );
      }

      return $url;
   }
}
