<?php
/**
 * Valide un BL remis au transporteur et l'envoie à ZenDoc.
 *
 * POST /plugins/gestion/api/bl_transport_dispatch.php
 * {
 *   "bl": "BL209061",
 *   "request_id": "hub-departure-123-BL209061",
 *   "carrier": "GLS",
 *   "tracking": "00LAWEO4",
 *   "departed_at": "2026-09-18 14:36:00"
 * }
 *
 * Un BL déjà signé par un client est volontairement ignoré : sa signature est
 * la preuve officielle et le renvoyer créerait un risque de double facturation.
 */
$_cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_cors_allowed = getenv('GLPI_API_CORS_ORIGIN') ?: '*';
header('Access-Control-Allow-Origin: ' . ($_cors_allowed === '*' ? '*' : ($_cors_origin === $_cors_allowed ? $_cors_origin : $_cors_allowed)));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
   http_response_code(200);
   exit;
}

if (!defined('NOLOGIN')) define('NOLOGIN', 1);
if (!defined('NOHEADER')) define('NOHEADER', 1);
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);

require_once GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? '/glpi'), '/');

function api_transport_end(int $status, array $payload): void
{
   if (class_exists('PluginGestionLogger')) {
      PluginGestionLogger::apiResponse($status, $payload);
   }
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_transport_input(): array
{
   $input = $_POST;
   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw = PluginGestionApiAuth::getRawInputBody();
      $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
      $json = json_decode($raw, true);
      if (is_array($json)) {
         $input = array_merge($input, $json);
      }
   }
   return $input;
}

/** La migration reste rejouable ici pour qu'un déploiement de fichiers soit sûr avant le clic « Mettre à jour ». */
function api_transport_ensure_schema(): void
{
   $migration = PLUGIN_GESTION_DIR . '/install/update_181_182.php';
   if (is_file($migration)) {
      require_once $migration;
      if (function_exists('update_181_182')) {
         update_181_182();
      }
   }
}

function api_transport_find_survey(DBmysql $DB, string $bl): ?array
{
   $esc = $DB->escape($bl);
   $like = $DB->escape($bl . '\\_%');
   $res = $DB->doQuery("SELECT *
                        FROM `glpi_plugin_gestion_surveys`
                        WHERE UPPER(`bl`) = '$esc'
                           OR UPPER(`bl`) LIKE '$like' ESCAPE '\\\\'
                        -- Un BL déjà signé gagne toujours sur un éventuel doublon
                        -- non signé : une seule preuve officielle, une seule facture.
                        ORDER BY `signed` DESC, `id` DESC
                        LIMIT 1");
   return ($res && $DB->numrows($res) === 1) ? $DB->fetchassoc($res) : null;
}

function api_transport_call_traitement(array $payload, string $rootdoc, array $cfg): array
{
   $url_base = trim((string)($cfg['url_base'] ?? ''));
   if ($url_base === '') {
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
      $url_base = $scheme . '://' . $host . $rootdoc;
   }
   $target = rtrim($url_base, '/') . '/plugins/gestion/front/traitement.php';

   $fallback = static function () use ($payload): array {
      $post = $_POST;
      $get = $_GET;
      $cwd = getcwd();
      $_POST = $payload;
      $_GET = [];
      ob_start();
      @chdir(PLUGIN_GESTION_DIR . '/front');
      include PLUGIN_GESTION_DIR . '/front/traitement.php';
      $response = (string)ob_get_clean();
      if ($cwd !== false) {
         @chdir($cwd);
      }
      $_POST = $post;
      $_GET = $get;
      return ['ok' => true, 'http_code' => 200, 'response' => $response, 'fallback' => true];
   };

   if (!function_exists('curl_init')) {
      return $fallback();
   }

   $ch = curl_init($target);
   $headers = [
      'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
      'X-Requested-With: XMLHttpRequest',
   ];
   if (!empty($payload['_glpi_csrf_token'])) {
      $headers[] = 'X-Glpi-Csrf-Token: ' . (string)$payload['_glpi_csrf_token'];
   }
   curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query($payload),
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_FOLLOWLOCATION => true,
   ]);
   if (session_id() !== '') {
      curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
   }
   $response = curl_exec($ch);
   if ($response === false) {
      $error = (string)curl_error($ch);
      curl_close($ch);
      try {
         $result = $fallback();
         $result['curl_error'] = $error;
         return $result;
      } catch (Throwable $e) {
         return ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => $error . ' — ' . $e->getMessage()];
      }
   }
   $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);
   return [
      'ok' => $code >= 200 && $code < 300,
      'http_code' => $code,
      'response' => (string)$response,
   ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
   api_transport_end(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_transport_end((int)($auth['code'] ?? 401), [
      'ok' => false,
      'error' => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

if (empty($config->fields) || (int)($config->fields['RemoteSignatureOn'] ?? 0) !== 1) {
   api_transport_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}
if (trim((string)($config->fields['ZenDocMail'] ?? '')) === '') {
   api_transport_end(422, ['ok' => false, 'error' => 'zendoc_mail_not_configured']);
}

api_transport_ensure_schema();
$input = api_transport_input();
$bl = strtoupper(preg_replace('/\s+/', '', trim((string)($input['bl'] ?? ''))));
if (preg_match('/^[0-9]{4,}$/', $bl)) {
   $bl = 'BL' . $bl;
}
if (!preg_match('/^[A-Z]{2,}[0-9]{4,}$/', $bl)) {
   api_transport_end(422, ['ok' => false, 'error' => $bl === '' ? 'missing_bl' : 'invalid_bl_format']);
}

$request_id = trim((string)($input['request_id'] ?? ''));
if ($request_id === '' || strlen($request_id) > 190 || !preg_match('/^[A-Za-z0-9:._-]+$/', $request_id)) {
   api_transport_end(422, ['ok' => false, 'error' => 'invalid_request_id']);
}

// Sérialise les départs d'un même BL. Deux événements distincts (signature du
// lot et changement manuel quasi simultané) ne peuvent ainsi jamais expédier
// deux fois le document avant que le premier ait enregistré son résultat.
$transport_lock_name = 'gest_bl_' . substr(hash('sha256', $bl), 0, 48);
try {
   $lock_name_sql = $DB->escape($transport_lock_name);
   $lock_result = $DB->doQuery("SELECT GET_LOCK('$lock_name_sql', 12) AS acquired");
   $lock_row = $lock_result ? $DB->fetchassoc($lock_result) : null;
   if ((int)($lock_row['acquired'] ?? 0) !== 1) {
      api_transport_end(409, [
         'ok' => false,
         'error' => 'transport_dispatch_busy',
         'message' => 'Un traitement est déjà en cours pour ce BL. Réessayez dans quelques instants.',
      ]);
   }
} catch (Throwable $e) {
   api_transport_end(503, [
      'ok' => false,
      'error' => 'transport_lock_unavailable',
      'message' => 'Impossible de sécuriser le traitement unique du BL.',
   ]);
}
register_shutdown_function(static function () use ($transport_lock_name): void {
   global $DB;
   try {
      $lock_name_sql = $DB->escape($transport_lock_name);
      $DB->doQuery("SELECT RELEASE_LOCK('$lock_name_sql')");
   } catch (Throwable $e) {
      // La fermeture de la connexion MySQL libère également le verrou.
   }
});

$carrier = mb_substr(trim((string)($input['carrier'] ?? 'Transporteur')), 0, 100);
$tracking = mb_substr(trim((string)($input['tracking'] ?? '')), 0, 190);
$departed_raw = trim((string)($input['departed_at'] ?? ''));
try {
   $departed = $departed_raw !== '' ? new DateTimeImmutable($departed_raw) : new DateTimeImmutable();
} catch (Throwable $e) {
   api_transport_end(422, ['ok' => false, 'error' => 'invalid_departed_at']);
}
$departed_at = $departed->format('Y-m-d H:i:s');

$survey = api_transport_find_survey($DB, $bl);
if ($survey === null) {
   api_transport_end(404, ['ok' => false, 'error' => 'survey_not_found', 'bl' => $bl]);
}

$completion = trim((string)($survey['completion_type'] ?? ''));
if ((int)($survey['signed'] ?? 0) === 1 && $completion !== 'transport_dispatch') {
   api_transport_end(200, [
      'ok' => true,
      'skipped' => true,
      'reason' => 'already_client_signed',
      'bl' => $bl,
      'id' => (int)$survey['id'],
   ]);
}
if ((int)($survey['signed'] ?? 0) === 1
    && $completion === 'transport_dispatch'
    && !empty($survey['zendoc_sent_at'])) {
   api_transport_end(200, [
      'ok' => true,
      'already_dispatched' => true,
      'bl' => $bl,
      'id' => (int)$survey['id'],
      'zendoc_sent_at' => (string)$survey['zendoc_sent_at'],
   ]);
}

$csrf = Session::getNewCSRFToken();
$_SESSION['glpicsrftoken'] = $csrf;
$dispatch_token = bin2hex(random_bytes(32));
$_SESSION['gestion_transport_dispatch_token'] = $dispatch_token;

$doc_name = preg_replace('/\.pdf$/i', '', (string)$survey['bl']);
$payload = [
   'REPORT_ID' => '0',
   'DOC' => $doc_name,
   'id_document' => (string)$survey['id'],
   // PNG transparent 1x1 : le traitement dessine lui-même le tampon texte.
   'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5WQAAAABJRU5ErkJggg==',
   'name' => 'Envoyé par transporteur',
   'email' => 'vide',
   'mailtoclient' => '0',
   'technician' => (string)($auth['tech_login'] ?? ''),
   'sign_uid' => hash('sha256', 'transport|' . $request_id),
   'sign_captured_at' => $departed_at,
   'transport_dispatch' => '1',
   'transport_dispatch_token' => $dispatch_token,
   'transport_request_id' => $request_id,
   'transport_departed_at' => $departed_at,
   'transport_carrier' => $carrier !== '' ? $carrier : 'Transporteur',
   'transport_tracking' => $tracking,
   '_glpi_csrf_token' => $csrf,
];

if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}
$call = api_transport_call_traitement($payload, $rootdoc, $CFG_GLPI);

$survey = api_transport_find_survey($DB, $bl);
if ($survey === null) {
   api_transport_end(500, ['ok' => false, 'error' => 'survey_lost_after_dispatch']);
}
if ((int)($survey['signed'] ?? 0) !== 1 || (string)($survey['completion_type'] ?? '') !== 'transport_dispatch') {
   api_transport_end(500, [
      'ok' => false,
      'error' => 'transport_dispatch_not_persisted',
      'traitement_http' => (int)($call['http_code'] ?? 0),
      'traitement_response' => mb_substr((string)($call['response'] ?? $call['error'] ?? ''), 0, 500),
   ]);
}
if (empty($survey['zendoc_sent_at'])) {
   api_transport_end(502, [
      'ok' => false,
      'error' => 'zendoc_send_failed',
      'message' => 'Le BL a été préparé mais son envoi à ZenDoc n’a pas été confirmé.',
      'bl' => $bl,
   ]);
}

api_transport_end(200, [
   'ok' => true,
   'bl' => $bl,
   'id' => (int)$survey['id'],
   'completion_type' => 'transport_dispatch',
   'zendoc_sent_at' => (string)$survey['zendoc_sent_at'],
   'transport_dispatched_at' => (string)($survey['transport_dispatched_at'] ?? $departed_at),
]);
