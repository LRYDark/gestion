<?php
// ============ CORS Headers ============
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
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

function api_prepare_end(int $status, array $payload): void
{
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_prepare_preview_url(string $doc_id, string $rootdoc): string
{
   $secret = defined('GLPI_PDF_PREVIEW_SECRET')
       ? GLPI_PDF_PREVIEW_SECRET
       : (getenv('GLPI_PDF_PREVIEW_SECRET') ?: hash('sha256', realpath(__DIR__ . '/../..') . 'gestion_pdf_preview'));
   $token = hash('sha256', $doc_id . date('Y-m-d') . $secret);
   return rtrim($rootdoc, '/') . '/plugins/gestion/view_pdf.php?id=' . rawurlencode($doc_id) . '&token=' . $token;
}

function api_prepare_preview_from_row(array $row, string $rootdoc): string
{
   $save = strtolower((string)($row['save'] ?? ''));
   if ($save === 'sage' && !empty($row['url_bl'])) {
      return api_prepare_preview_url((string)$row['url_bl'], $rootdoc);
   }

   $preview = trim((string)($row['doc_url'] ?? ''));
   if ($preview === '') {
      return '';
   }

   if (str_contains($preview, 'document.send.php')) {
      if (preg_match('#^https?://#i', $preview)) {
         return $preview;
      }
      $rootdoc = rtrim($rootdoc, '/');
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

   return $preview;
}

if (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1) {
   api_prepare_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_prepare_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$raw_bl = trim((string)($_GET['bl'] ?? $_POST['bl'] ?? ''));
if ($raw_bl === '') {
   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw_json = PluginGestionApiAuth::getRawInputBody();
      if ($raw_json !== '') {
         // Handle UTF-8 BOM (common with Windows PowerShell Out-File -Encoding utf8).
         $raw_json = preg_replace('/^\xEF\xBB\xBF/', '', $raw_json) ?? $raw_json;
         $decoded = json_decode($raw_json, true);
         if (is_array($decoded)) {
            $raw_bl = trim((string)($decoded['bl'] ?? ''));
         }
      }
   }
}
if ($raw_bl === '') {
   api_prepare_end(422, ['ok' => false, 'error' => 'missing_bl']);
}

$bl = strtoupper(preg_replace('/\s+/', '', $raw_bl));
if (preg_match('/^[0-9]{4,}$/', $bl)) {
   $bl = 'BL' . $bl;
}
if (!preg_match('/^[A-Z]{2,}[0-9]{4,}$/', $bl)) {
   api_prepare_end(422, ['ok' => false, 'error' => 'invalid_bl_format']);
}

$amount_ht = null;
$amount_ttc = null;
try {
   if (function_exists('Montant')) {
      $amounts = Montant($bl);
      if (is_array($amounts)) {
         $amount_ht = $amounts['HT'] ?? $amounts['ht'] ?? null;
         $amount_ttc = $amounts['TTC'] ?? $amounts['ttc'] ?? null;
      }
   }
} catch (Throwable $e) {
   // Non bloquant
}

$bl_esc = $DB->escape($bl);
$bl_like = $DB->escape($bl . '\\_%');

$sql_existing = "SELECT id, bl, signed, doc_url, url_bl, save, relatedInvoiceToBL
                 FROM `glpi_plugin_gestion_surveys`
                 WHERE UPPER(`bl`) = '$bl_esc'
                    OR UPPER(`bl`) LIKE '$bl_like' ESCAPE '\\\\'
                 ORDER BY `signed` ASC, `id` DESC
                 LIMIT 1";
$res_existing = $DB->doQuery($sql_existing);
if ($res_existing && $DB->numrows($res_existing) === 1) {
   $row = $DB->fetchassoc($res_existing);
   $is_signed = (int)($row['signed'] ?? 0) === 1;
   $preview_url = api_prepare_preview_from_row($row, $rootdoc);

   api_prepare_end(200, [
      'ok'                 => true,
      'exists'             => true,
      'already_signed'     => $is_signed,
      'id'                 => (int)$row['id'],
      'bl'                 => (string)($row['bl'] ?? $bl),
      'preview_url'        => $preview_url,
      'relatedInvoiceToBL' => $row['relatedInvoiceToBL'] ?? null,
      'amount_ht'          => $amount_ht,
      'amount_ttc'         => $amount_ttc,
      'technician_login'   => (string)($auth['tech_login'] ?? ''),
      'technician_label'   => (string)($auth['tech_label'] ?? ''),
   ]);
}

$http_status = null;
$exists = function_exists('documentExiste') ? documentExiste($bl, $http_status) : false;
if (!$exists) {
   api_prepare_end(404, ['ok' => false, 'error' => 'bl_not_found', 'bl' => $bl]);
}

$pdf_filename = $bl;
$tracker = null;
$related = null;
try {
   if (function_exists('parseDocument')) {
      $fields = parseDocument($bl);
      if (is_array($fields)) {
         if (!empty($fields['client'])) {
            $pdf_filename = $bl . '_' . str_replace(' ', '_', (string)$fields['client']);
         }
         if (!empty($fields['tracker'])) {
            $tracker = (string)$fields['tracker'];
         }
         if (!empty($fields['relatedInvoiceToBL'])) {
            $related = (string)$fields['relatedInvoiceToBL'];
         }
      }
   }
} catch (Throwable $e) {
   $pdf_filename = $bl;
}

$preview_url = api_prepare_preview_url($bl, $rootdoc);

$tracker_sql = $tracker !== null && $tracker !== '' ? ("'" . $DB->escape($tracker) . "'") : 'NULL';
$related_sql = $related !== null && $related !== '' ? ("'" . $DB->escape($related) . "'") : 'NULL';

$sql_insert = "INSERT INTO `glpi_plugin_gestion_surveys`
(`tickets_id`, `entities_id`, `tracker`, `relatedInvoiceToBL`, `url_bl`, `bl`, `signed`, `doc_id`, `doc_url`, `save`, `date_creation`, `doc_date`)
VALUES
(0, 0, $tracker_sql, $related_sql, '" . $DB->escape($bl) . "', '" . $DB->escape($pdf_filename) . "', 0, 0, '" . $DB->escape($preview_url) . "', 'Sage', NOW(), NULL)";

if (!$DB->doQuery($sql_insert)) {
   api_prepare_end(500, ['ok' => false, 'error' => 'db_insert_failed']);
}

$new_id = (int)$DB->insertId();
if ($new_id <= 0) {
   $res_id = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '" . $DB->escape($pdf_filename) . "' ORDER BY id DESC LIMIT 1");
   if ($res_id && $DB->numrows($res_id) === 1) {
      $row_id = $DB->fetchassoc($res_id);
      $new_id = (int)($row_id['id'] ?? 0);
   }
}

if ($new_id <= 0) {
   api_prepare_end(500, ['ok' => false, 'error' => 'db_select_failed']);
}

api_prepare_end(200, [
   'ok'                 => true,
   'exists'             => false,
   'already_signed'     => false,
   'id'                 => $new_id,
   'bl'                 => $pdf_filename,
   'preview_url'        => $preview_url,
   'relatedInvoiceToBL' => $related,
   'amount_ht'          => $amount_ht,
   'amount_ttc'         => $amount_ttc,
   'technician_login'   => (string)($auth['tech_login'] ?? ''),
   'technician_label'   => (string)($auth['tech_label'] ?? ''),
]);
