<?php
/**
 * Tracker d'un BL, en lecture seule.
 *
 * GET .../plugins/gestion/api/bl_tracker.php?bl=BL202852
 *   → {"ok":true,"bl":"BL202852","tracker":"PI + EC","source":"glpi"}
 *
 * Contrairement à bl_prepare.php, cet endpoint n'écrit RIEN en base : il lit la
 * colonne `tracker` de glpi_plugin_gestion_surveys si le BL y est déjà connu,
 * sinon il lit le PDF dans Sage (parseDocument). `refresh=1` force la lecture
 * Sage même si le BL est connu.
 *
 * `tracker` vaut null quand le BL existe mais que son PDF ne porte pas de ligne
 * « Tracker : ».
 */
$_cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_cors_allowed = getenv('GLPI_API_CORS_ORIGIN') ?: '*';
if ($_cors_allowed === '*' || $_cors_origin === $_cors_allowed) {
    header('Access-Control-Allow-Origin: ' . ($_cors_allowed === '*' ? '*' : $_cors_origin));
} else {
    header('Access-Control-Allow-Origin: ' . $_cors_allowed);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
   http_response_code(200);
   exit;
}

define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

global $DB;

function api_bl_tracker_end(int $status, array $payload): void
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

function api_bl_tracker_input(): array
{
   $input = array_merge($_GET, $_POST);
   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw = PluginGestionApiAuth::getRawInputBody();
      if ($raw !== '') {
         $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
         $json = json_decode($raw, true);
         if (is_array($json)) {
            $input = array_merge($input, $json);
         }
      }
   }
   return $input;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
   api_bl_tracker_end(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_bl_tracker_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$input = api_bl_tracker_input();
$raw_bl = trim((string)($input['bl'] ?? ''));
$refresh = in_array(strtolower(trim((string)($input['refresh'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);

if ($raw_bl === '') {
   api_bl_tracker_end(422, ['ok' => false, 'error' => 'missing_bl']);
}

// Même normalisation que bl_prepare.php : « 202852 » → « BL202852 ».
$bl = strtoupper(preg_replace('/\s+/', '', $raw_bl));
if (preg_match('/^[0-9]{4,}$/', $bl)) {
   $bl = 'BL' . $bl;
}
if (!preg_match('/^[A-Z]{2,}[0-9]{4,}$/', $bl)) {
   api_bl_tracker_end(422, ['ok' => false, 'error' => 'invalid_bl_format']);
}

// 1) Tracker déjà enregistré en base (colonne `bl` = « BL202852 » ou « BL202852_CLIENT »).
if (!$refresh) {
   $bl_esc = $DB->escape($bl);
   $bl_like = $DB->escape($bl . '\\_%');
   $res = $DB->doQuery("SELECT tracker
                        FROM `glpi_plugin_gestion_surveys`
                        WHERE (UPPER(`bl`) = '$bl_esc'
                               OR UPPER(`bl`) LIKE '$bl_like' ESCAPE '\\\\')
                          AND `tracker` IS NOT NULL
                          AND `tracker` <> ''
                        ORDER BY `id` DESC
                        LIMIT 1");
   if ($res && $DB->numrows($res) === 1) {
      $row = $DB->fetchassoc($res);
      api_bl_tracker_end(200, [
         'ok'      => true,
         'bl'      => $bl,
         'tracker' => (string)$row['tracker'],
         'source'  => 'glpi',
      ]);
   }
}

// 2) Lecture du PDF dans Sage.
try {
   $fields = parseDocument($bl);
} catch (Throwable $e) {
   // parseDocument ne remonte pas le code HTTP : on le redemande pour distinguer
   // « BL inexistant » (404) d'une panne Sage.
   $http_status = null;
   if (!documentExiste($bl, $http_status) && (int)$http_status === 404) {
      api_bl_tracker_end(404, ['ok' => false, 'error' => 'bl_not_found', 'bl' => $bl]);
   }
   api_bl_tracker_end(502, [
      'ok'      => false,
      'error'   => 'sage_error',
      'bl'      => $bl,
      'message' => $e->getMessage(),
   ]);
}

$tracker = trim((string)($fields['tracker'] ?? ''));

api_bl_tracker_end(200, [
   'ok'      => true,
   'bl'      => $bl,
   'tracker' => $tracker !== '' ? $tracker : null,
   'source'  => 'sage',
]);
