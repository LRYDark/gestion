<?php
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
$rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? '/glpi'), '/');

function api_ticket_bls_end(int $status, array $payload): void
{
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_ticket_bls_preview_from_row(array $row, string $rootdoc): string
{
   $rootdoc = rtrim($rootdoc, '/');
   $preview = trim((string)($row['doc_url'] ?? ''));
   if ($preview === '') {
      return '';
   }

   if (str_contains($preview, 'document.send.php')) {
      if (preg_match('#^https?://#i', $preview)) {
         return $preview;
      }
      if (str_starts_with($preview, $rootdoc . '/')) {
         return $preview;
      }
      if (str_starts_with($preview, '/')) {
         return $rootdoc . $preview;
      }
      return $rootdoc . '/front/' . ltrim($preview, '/');
   }

   $preview = str_replace('/ajax/view_pdf.php', '/view_pdf.php', $preview);
   $preview = str_replace('/plugins/gestion/public/view_pdf.php', '/plugins/gestion/view_pdf.php', $preview);
   if ($rootdoc !== '') {
      $preview = str_replace($rootdoc . $rootdoc . '/plugins/gestion/view_pdf.php', $rootdoc . '/plugins/gestion/view_pdf.php', $preview);
   }

   // Regenerate a fresh token for view_pdf.php URLs (token may be expired or absent in DB)
   if (function_exists('plugin_gestion_ensure_pdf_token')) {
      $preview = plugin_gestion_ensure_pdf_token($preview);
   }

   return $preview;
}

function api_ticket_bls_input_ticket_id(): int
{
   $ticket_id = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 0);
   if ($ticket_id > 0) {
      return $ticket_id;
   }

   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw = PluginGestionApiAuth::getRawInputBody();
      if ($raw !== '') {
         $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
         $json = json_decode($raw, true);
         if (is_array($json)) {
            return (int)($json['ticket_id'] ?? 0);
         }
      }
   }

   return 0;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
   api_ticket_bls_end(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_ticket_bls_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$ticket_id = api_ticket_bls_input_ticket_id();
if ($ticket_id <= 0) {
   api_ticket_bls_end(422, ['ok' => false, 'error' => 'missing_ticket_id']);
}

$sql = "SELECT id, bl, signed, doc_url, doc_date, date_creation
        FROM `glpi_plugin_gestion_surveys`
        WHERE tickets_id = $ticket_id
        ORDER BY id DESC";
$res = $DB->doQuery($sql);
if ($res === false) {
   api_ticket_bls_end(500, ['ok' => false, 'error' => 'db_query_failed']);
}

$bls = [];
$bl_numbers = [];
while ($row = $DB->fetchassoc($res)) {
   $bl_value = trim((string)($row['bl'] ?? ''));
   $preview = api_ticket_bls_preview_from_row($row, $rootdoc);

   $bls[] = [
      'survey_id'      => (int)($row['id'] ?? 0),
      'bl'             => $bl_value,
      'signed'         => ((int)($row['signed'] ?? 0) === 1),
      'doc_date'       => (string)($row['doc_date'] ?? ''),
      'date_creation'  => (string)($row['date_creation'] ?? ''),
      'doc_url'        => (string)($row['doc_url'] ?? ''),
      'preview_url'    => $preview,
   ];

   if ($bl_value !== '') {
      $bl_numbers[] = $bl_value;
   }
}

$bl_numbers = array_values(array_unique($bl_numbers));

api_ticket_bls_end(200, [
   'ok'               => true,
   'ticket_id'        => $ticket_id,
   'count'            => count($bls),
   'bl_count'         => count($bl_numbers),
   'bl_numbers'       => $bl_numbers,
   'bls'              => $bls,
   'technician_login' => (string)($auth['tech_login'] ?? ''),
   'technician_label' => (string)($auth['tech_label'] ?? ''),
]);
