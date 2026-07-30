<?php
define('PLUGIN_GESTION_VERSION', '1.7.6'); // version du plugin
$_SESSION['PLUGIN_GESTION_VERSION'] = PLUGIN_GESTION_VERSION;

/**
 * Normalise le numero d'un BL/BC : token de tete (BL|BC + chiffres), en MAJUSCULES,
 * sans suffixe (.pdf), nom client ni espaces. Renvoie '' si non reconnu.
 * Sert au dedoublonnage par numero (colonne bl_number + index unique).
 * Ex : "bl206743_SELARL_DENTISTE.pdf" => "BL206743".
 */
if (!function_exists('pluginGestionBlNumber')) {
   function pluginGestionBlNumber($bl): string {
      $bl = (string)$bl;
      if (preg_match('/^\s*(B[LC])\s*(\d+)/i', $bl, $m)) {
         return strtoupper($m[1]) . $m[2];
      }
      return '';
   }
}

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

      // ── DIAGNOSTIC CSRF (temporaire) ────────────────────────────────────────
      // plugin_init s'execute AVANT le CheckCsrfListener du kernel : on trace ici
      // l'etat du token des POST vers traitement*.php pour comprendre les rejets.
      // On ne journalise jamais le token lui-meme (seulement un hash tronque).
      if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
          && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/plugins/gestion/front/traitement') !== false
          && class_exists('PluginGestionLogger')) {
         $diag_tok   = (string)($_POST['_glpi_csrf_token'] ?? '');
         $diag_known = ($diag_tok !== '' && isset($_SESSION['glpicsrftokens'][$diag_tok]));
         PluginGestionLogger::info('csrf-diag', sprintf(
            'POST %s : token %s (connu en session : %s), %d tokens en session, user #%s, ajax=%s',
            basename((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH)),
            $diag_tok === '' ? 'ABSENT' : substr(sha1($diag_tok), 0, 8),
            $diag_known ? 'OUI' : 'NON',
            is_array($_SESSION['glpicsrftokens'] ?? null) ? count($_SESSION['glpicsrftokens']) : 0,
            (string)(Session::getLoginUserID() ?: 'anonyme'),
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ? 'oui' : 'non'
         ));
      }
      // ── FIN DIAGNOSTIC CSRF ────────────────────────────────────────────────

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
      $api_pattern_send_invoice = '#^/api/device_send_invoice\.php(?:/.*)?$#';

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
         $api_pattern_send_invoice,
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

      // Association automatique des BL a la CREATION d'un ticket (item_add).
      // (L'ouverture est geree en arriere-plan via ajax/auto_associate_bl.php + scripts_gestion.js.)
      $PLUGIN_HOOKS['item_add']['gestion'] = ['Ticket' => ['PluginGestionTicket', 'autoAssociateBlOnAdd']];

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

if (!function_exists('_plugin_gestion_pdf_secret')) {
   /**
    * Return the shared secret used to sign PDF preview tokens.
    * Centralised here so every caller uses the same value.
    */
   function _plugin_gestion_pdf_secret(): string {
      if (defined('GLPI_PDF_PREVIEW_SECRET')) {
         return GLPI_PDF_PREVIEW_SECRET;
      }
      $env = getenv('GLPI_PDF_PREVIEW_SECRET');
      if ($env !== false && $env !== '') {
         return $env;
      }
      return hash('sha256', realpath(__DIR__) . 'gestion_pdf_preview');
   }
}

if (!function_exists('plugin_gestion_ensure_pdf_token')) {
   /**
    * If $url points to view_pdf.php, (re)generate a fresh daily token.
    * For any other URL the value is returned unchanged.
    */
   function plugin_gestion_ensure_pdf_token(string $url): string {
      $url = trim($url);
      if ($url === '') {
         return $url;
      }

      $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
      if (strpos($path, '/view_pdf.php') === false) {
         return $url;
      }

      parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $params);
      $doc_id = trim((string)($params['id'] ?? ''));
      if ($doc_id === '') {
         return $url;
      }

      $token = hash('sha256', $doc_id . date('Y-m-d') . _plugin_gestion_pdf_secret());
      $params['token'] = $token;

      $scheme = parse_url($url, PHP_URL_SCHEME);
      $host   = parse_url($url, PHP_URL_HOST);
      $base   = ($scheme && $host) ? ($scheme . '://' . $host) : '';

      return $base . $path . '?' . http_build_query($params);
   }
}
