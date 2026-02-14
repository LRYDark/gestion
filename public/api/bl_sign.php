<?php
// ============ CORS Headers ============
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token');
header('Content-Type: application/json; charset=UTF-8');

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', realpath(__DIR__ . '/../../..'));
}

if (!defined('PLUGIN_GESTION_DIR')) {
   define('PLUGIN_GESTION_DIR', realpath(__DIR__ . '/../..'));
}

define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

function api_sign_end(int $status, array $payload): void
{
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_sign_bool($value): bool
{
   if (is_bool($value)) {
      return $value;
   }
   $txt = strtolower(trim((string)$value));
   return in_array($txt, ['1', 'true', 'yes', 'on'], true);
}

function api_sign_input(): array
{
   $input = $_POST;
   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw = PluginGestionApiAuth::getRawInputBody();
      if ($raw !== false && $raw !== '') {
         // Handle UTF-8 BOM (common with Windows PowerShell Out-File -Encoding utf8).
         $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
         $json = json_decode($raw, true);
         if (is_array($json)) {
            $input = array_merge($input, $json);
         }
      }
   }
   return $input;
}

function api_sign_find_survey(DBmysql $DB, int $survey_id, string $bl): ?array
{
   if ($survey_id > 0) {
      $res = $DB->doQuery("SELECT id, bl, signed FROM `glpi_plugin_gestion_surveys` WHERE id = $survey_id LIMIT 1");
      if ($res && $DB->numrows($res) === 1) {
         return $DB->fetchassoc($res);
      }
      return null;
   }

   $bl = strtoupper(trim($bl));
   if ($bl === '') {
      return null;
   }

   $bl_esc = $DB->escape($bl);
   $bl_like = $DB->escape($bl . '\\_%');
   $res = $DB->doQuery("SELECT id, bl, signed
                        FROM `glpi_plugin_gestion_surveys`
                        WHERE UPPER(`bl`) = '$bl_esc'
                           OR UPPER(`bl`) LIKE '$bl_like' ESCAPE '\\\\'
                        ORDER BY signed ASC, id DESC
                        LIMIT 1");
   if ($res && $DB->numrows($res) === 1) {
      return $DB->fetchassoc($res);
   }

   return null;
}

function api_sign_call_traitement(array $payload, string $rootdoc, array $cfg): array
{
   $url_base = trim((string)($cfg['url_base'] ?? ''));
   if ($url_base === '') {
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
      $url_base = $scheme . '://' . $host . rtrim($rootdoc, '/');
   }

   $target = rtrim($url_base, '/') . '/plugins/gestion/front/traitement.php';

   $fallback = static function () use ($payload): array {
      $backup_post = $_POST;
      $backup_get = $_GET;
      $cwd = getcwd();
      $response = '';

      $_POST = $payload;
      $_GET = [];

      ob_start();
      @chdir(PLUGIN_GESTION_DIR . '/front');
      include PLUGIN_GESTION_DIR . '/front/traitement.php';
      $response = (string)ob_get_clean();

      if ($cwd !== false) {
         @chdir($cwd);
      }
      $_POST = $backup_post;
      $_GET = $backup_get;

      return [
         'ok'        => true,
         'http_code' => 200,
         'response'  => $response,
      ];
   };

   if (function_exists('curl_init')) {
      $ch = curl_init($target);
      $headers = [
         'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
         'X-Requested-With: XMLHttpRequest',
      ];
      if (!empty($payload['_glpi_csrf_token'])) {
         $headers[] = 'X-Glpi-Csrf-Token: ' . (string)$payload['_glpi_csrf_token'];
      }

      $cookie = '';
      $sid = session_id();
      if ($sid !== '') {
         $cookie = session_name() . '=' . $sid;
      }

      curl_setopt_array($ch, [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POST => true,
         CURLOPT_POSTFIELDS => http_build_query($payload),
         CURLOPT_HTTPHEADER => $headers,
         CURLOPT_TIMEOUT => 30,   // ✅ 30 secondes max
         CURLOPT_CONNECTTIMEOUT => 5,  // ✅ Ajout connexion rapide
         CURLOPT_FOLLOWLOCATION => true,
      ]);
      if ($cookie !== '') {
         curl_setopt($ch, CURLOPT_COOKIE, $cookie);
      }

      $response = curl_exec($ch);
      if ($response === false) {
         $error = (string)curl_error($ch);
         curl_close($ch);
         try {
            $fallback_res = $fallback();
            $fallback_res['fallback'] = 'include_after_curl_error';
            $fallback_res['curl_error'] = $error;
            return $fallback_res;
         } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'curl_failed', 'message' => $error];
         }
      }
      $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      return [
         'ok'        => ($http_code >= 200 && $http_code < 500),
         'http_code' => $http_code,
         'response'  => (string)$response,
      ];
   }

   return $fallback();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
   api_sign_end(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1) {
   api_sign_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_sign_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$input = api_sign_input();
$survey_id = (int)($input['survey_id'] ?? $input['id_document'] ?? 0);
$bl = trim((string)($input['bl'] ?? ''));
$signer_name = trim((string)($input['signer_name'] ?? $input['name'] ?? ''));
$signer_email = trim((string)($input['signer_email'] ?? $input['email'] ?? ''));
$signature = (string)($input['signature'] ?? $input['url'] ?? '');
$mail_to_client = api_sign_bool($input['mail_to_client'] ?? $input['mailtoclient'] ?? ($signer_email !== '' ? '1' : '0'));
$comment = trim((string)($input['comment'] ?? $input['comments'] ?? $input['commentaire'] ?? $input['note'] ?? ''));
$counter_invoice_client = api_sign_bool(
   $input['counter_invoice_client']
   ?? $input['CounterInvoiceClient']
   ?? $input['counter_invoice']
   ?? $input['signed_at_counter']
   ?? $input['signed_counter']
   ?? $input['signed_comptoir']
   ?? $input['comptoir']
   ?? $input['counter']
   ?? '0'
);

if ($survey_id <= 0 && $bl === '') {
   api_sign_end(422, ['ok' => false, 'error' => 'missing_survey_or_bl']);
}
if ($signer_name === '') {
   api_sign_end(422, ['ok' => false, 'error' => 'missing_signer_name']);
}
if ($signature === '') {
   api_sign_end(422, ['ok' => false, 'error' => 'missing_signature']);
}

$survey = api_sign_find_survey($DB, $survey_id, $bl);
if ($survey === null) {
   api_sign_end(404, ['ok' => false, 'error' => 'survey_not_found']);
}

if ((int)($survey['signed'] ?? 0) === 1) {
   api_sign_end(200, [
      'ok'             => true,
      'already_signed' => true,
      'id'             => (int)$survey['id'],
      'bl'             => (string)$survey['bl'],
   ]);
}

$technician_login = trim((string)($auth['tech_login'] ?? ''));
if ($technician_login === '') {
   api_sign_end(500, ['ok' => false, 'error' => 'missing_technician_login']);
}

$csrf = Session::getNewCSRFToken();
$_SESSION['glpicsrftoken'] = $csrf;

$doc_name = preg_replace('/\.pdf$/i', '', (string)$survey['bl']);
$payload = [
   'REPORT_ID'         => '0',
   'DOC'               => $doc_name,
   'id_document'       => (string)$survey['id'],
   'url'               => $signature,
   'name'              => $signer_name,
   'email'             => $signer_email,
   'mailtoclient'      => ($mail_to_client && $signer_email !== '') ? '1' : '0',
   'technician'        => $technician_login,
   'CounterInvoiceClient' => $counter_invoice_client ? '1' : '0',
   '_glpi_csrf_token'  => $csrf,
];

if ($comment !== '') {
   $payload['comment'] = $comment;
}

if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close(); // Liberer le verrou de session avant l'appel long.
}

$call = api_sign_call_traitement($payload, $rootdoc, $CFG_GLPI);

if (empty($call['ok'])) {
   api_sign_end(500, [
      'ok'      => false,
      'error'   => (string)($call['error'] ?? 'traitement_call_failed'),
      'message' => (string)($call['message'] ?? 'Erreur lors de l appel du traitement'),
   ]);
}

$survey_id = (int)$survey['id'];
$res = $DB->doQuery("SELECT id, bl, signed, doc_date, users_id, users_ext, doc_url
                     FROM `glpi_plugin_gestion_surveys`
                     WHERE id = $survey_id
                     LIMIT 1");
if (!$res || $DB->numrows($res) !== 1) {
   api_sign_end(500, ['ok' => false, 'error' => 'signed_row_not_found']);
}
$row = $DB->fetchassoc($res);

if ((int)($row['signed'] ?? 0) !== 1) {
   api_sign_end(500, [
      'ok'                 => false,
      'error'              => 'signature_not_persisted',
      'traitement_http'    => (int)($call['http_code'] ?? 0),
      'traitement_response' => substr((string)($call['response'] ?? ''), 0, 400),
   ]);
}

api_sign_end(200, [
   'ok'               => true,
   'id'               => (int)$row['id'],
   'bl'               => (string)$row['bl'],
   'signed'           => 1,
   'doc_date'         => (string)($row['doc_date'] ?? ''),
   'users_id'         => (int)($row['users_id'] ?? 0),
   'users_ext'        => (string)($row['users_ext'] ?? ''),
   'doc_url'          => (string)($row['doc_url'] ?? ''),
   'technician_login' => $technician_login,
   'technician_label' => (string)($auth['tech_label'] ?? ''),
   'comment'          => ($comment !== '' ? $comment : null),
   'counter_invoice_client' => $counter_invoice_client ? 1 : 0,
]);
