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
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? '/glpi'), '/');

function api_combined_end(int $status, array $payload): void
{
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_combined_bool(mixed $value): bool
{
   if (is_bool($value)) {
      return $value;
   }
   $txt = strtolower(trim((string)$value));
   return in_array($txt, ['1', 'true', 'yes', 'on'], true);
}

function api_combined_input(): array
{
   $input = $_POST;
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

function api_combined_get_headers_normalized(): array
{
   $raw = [];
   if (function_exists('getallheaders')) {
      $raw_headers = getallheaders();
      if (is_array($raw_headers)) {
         $raw = $raw_headers;
      }
   }

   if (empty($raw)) {
      foreach ($_SERVER as $key => $value) {
         if (!str_starts_with((string)$key, 'HTTP_')) {
            continue;
         }
         $name = str_replace('_', '-', substr((string)$key, 5));
         $name = implode('-', array_map('ucfirst', explode('-', strtolower($name))));
         $raw[$name] = (string)$value;
      }
   }

   $normalized = [];
   foreach ($raw as $k => $v) {
      $normalized[strtolower((string)$k)] = (string)$v;
   }
   return $normalized;
}

function api_combined_forward_headers(array $headers_norm): array
{
   $forward = ['Accept' => 'application/json'];
   $allowed = [
      'authorization'          => 'Authorization',
      'app-token'              => 'App-Token',
      'session-token'          => 'Session-Token',
      'glpi-entity'            => 'Glpi-Entity',
      'glpi-entity-recursive'  => 'Glpi-Entity-Recursive',
      'glpi-profile'           => 'Glpi-Profile',
   ];

   foreach ($allowed as $src => $dst) {
      if (!empty($headers_norm[$src])) {
         $forward[$dst] = (string)$headers_norm[$src];
      }
   }
   return $forward;
}

function api_combined_base_url(string $rootdoc, array $cfg): string
{
   $url_base = trim((string)($cfg['url_base'] ?? ''));
   if ($url_base !== '') {
      return rtrim($url_base, '/');
   }
   $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
   $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
   return $scheme . '://' . $host . rtrim($rootdoc, '/');
}

function api_combined_http_call(string $method, string $url, array $headers, array $payload = []): array
{
   if (!function_exists('curl_init')) {
      return [
         'ok'       => false,
         'status'   => 0,
         'error'    => 'curl_unavailable',
         'message'  => 'Extension cURL indisponible',
      ];
   }

   $method = strtoupper(trim($method));
   if ($method === 'GET' && !empty($payload)) {
      $query = http_build_query($payload);
      $url .= (str_contains($url, '?') ? '&' : '?') . $query;
   }

   $ch = curl_init($url);
   $header_lines = [];
   foreach ($headers as $k => $v) {
      $header_lines[] = $k . ': ' . $v;
   }

   $opts = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $header_lines,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CUSTOMREQUEST  => $method,
   ];

   if ($method === 'POST') {
      $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=UTF-8';
      $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   }

   curl_setopt_array($ch, $opts);
   $response = curl_exec($ch);
   if ($response === false) {
      $error = (string)curl_error($ch);
      curl_close($ch);
      return [
         'ok'      => false,
         'status'  => 0,
         'error'   => 'http_call_failed',
         'message' => $error,
      ];
   }

   $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);

   $decoded = json_decode((string)$response, true);
   return [
      'ok'      => ($status >= 200 && $status < 300),
      'status'  => $status,
      'json'    => is_array($decoded) ? $decoded : null,
      'raw'     => (string)$response,
   ];
}

function api_combined_find_survey(DBmysql $DB, int $survey_id, string $bl): ?array
{
   if ($survey_id > 0) {
      $res = $DB->doQuery("SELECT id, tickets_id, bl, signed
                           FROM `glpi_plugin_gestion_surveys`
                           WHERE id = $survey_id
                           LIMIT 1");
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
   $res = $DB->doQuery("SELECT id, tickets_id, bl, signed
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

function api_combined_find_survey_by_ticket(DBmysql $DB, int $ticket_id): ?array
{
   if ($ticket_id <= 0) {
      return null;
   }

   $res = $DB->doQuery("SELECT id, tickets_id, bl, signed
                        FROM `glpi_plugin_gestion_surveys`
                        WHERE tickets_id = $ticket_id
                        ORDER BY signed ASC, id DESC
                        LIMIT 1");
   if ($res && $DB->numrows($res) === 1) {
      return $DB->fetchassoc($res);
   }
   return null;
}

function api_combined_mode(string $raw): string
{
   $mode = strtolower(trim($raw));
   $mode = str_replace(['-', ' '], '_', $mode);
   return match ($mode) {
      '', 'auto' => 'auto',
      'bl', 'gestion' => 'bl',
      'rp', 'report', 'rapport' => 'report',
      'both', 'all', 'combined', 'fusion' => 'both',
      default => '',
   };
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
   api_combined_end(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_combined_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$input = api_combined_input();
$requested_mode = api_combined_mode((string)($input['mode'] ?? 'auto'));
if ($requested_mode === '') {
   api_combined_end(422, ['ok' => false, 'error' => 'invalid_mode']);
}

$survey_id_input = (int)($input['survey_id'] ?? $input['id_document'] ?? 0);
$ticket_id_input = (int)($input['ticket_id'] ?? 0);
$bl_input = trim((string)($input['bl'] ?? ''));

$signer_name = trim((string)($input['signer_name'] ?? $input['name'] ?? ''));
$signer_email = trim((string)($input['signer_email'] ?? $input['email'] ?? ''));
$signature = (string)($input['signature'] ?? $input['url'] ?? '');
$technician_override = trim((string)($input['technician_login'] ?? $input['technician'] ?? ''));
$mail_to_client = api_combined_bool($input['mail_to_client'] ?? $input['mailtoclient'] ?? ($signer_email !== '' ? '1' : '0'));
$comment = trim((string)($input['comment'] ?? $input['comments'] ?? $input['commentaire'] ?? ''));
$counter_invoice_client = api_combined_bool(
   $input['counter_invoice_client']
   ?? $input['CounterInvoiceClient']
   ?? $input['counter_invoice']
   ?? $input['signed_at_counter']
   ?? $input['signed_counter']
   ?? $input['signed_comptoir']
   ?? $input['comptoir']
   ?? '0'
);
$document_type = trim((string)($input['document_type'] ?? 'intervention_report'));

$rp_available = Plugin::isPluginActive('rp') && class_exists('PluginRpConfig');

$survey = api_combined_find_survey($DB, $survey_id_input, $bl_input);
if ($survey === null && $ticket_id_input > 0) {
   $survey = api_combined_find_survey_by_ticket($DB, $ticket_id_input);
}

$resolved_ticket_id = $ticket_id_input > 0 ? $ticket_id_input : (int)($survey['tickets_id'] ?? 0);

if ($ticket_id_input > 0 && $survey !== null) {
   $survey_ticket_id = (int)($survey['tickets_id'] ?? 0);
   if ($survey_ticket_id > 0 && $survey_ticket_id !== $ticket_id_input) {
      api_combined_end(422, [
         'ok'      => false,
         'error'   => 'ticket_bl_mismatch',
         'message' => 'Le BL fourni n est pas associe au ticket fourni',
      ]);
   }
}

$has_bl = $survey !== null;
$has_ticket = $resolved_ticket_id > 0;

$effective_mode = $requested_mode;
if ($requested_mode === 'auto') {
   if ($has_bl && $has_ticket && $rp_available) {
      $effective_mode = 'both';
   } elseif ($has_bl) {
      $effective_mode = 'bl';
   } elseif ($has_ticket && $rp_available) {
      $effective_mode = 'report';
   } else {
      api_combined_end(422, [
         'ok'      => false,
         'error'   => 'cannot_resolve_mode',
         'message' => 'Impossible de determiner le mode (bl/report/both)',
      ]);
   }
}

$needs_bl = in_array($effective_mode, ['bl', 'both'], true);
$needs_report = in_array($effective_mode, ['report', 'both'], true);

if ($needs_bl && (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1)) {
   api_combined_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

if ($needs_report && !$rp_available) {
   api_combined_end(503, [
      'ok'      => false,
      'error'   => 'rp_unavailable',
      'message' => 'Le plugin RP doit etre installe et active',
   ]);
}

$headers_norm = api_combined_get_headers_normalized();
$forward_headers = api_combined_forward_headers($headers_norm);
$base_url = api_combined_base_url($rootdoc, $CFG_GLPI);

if ($needs_bl && !$has_bl && $bl_input !== '') {
   $prepare = api_combined_http_call(
      'GET',
      $base_url . '/plugins/gestion/api/bl_prepare.php',
      $forward_headers,
      ['bl' => $bl_input]
   );
   if ($prepare['ok'] && is_array($prepare['json']) && !empty($prepare['json']['id'])) {
      $survey = api_combined_find_survey($DB, (int)$prepare['json']['id'], '');
      $has_bl = $survey !== null;
      if ($resolved_ticket_id <= 0) {
         $resolved_ticket_id = (int)($survey['tickets_id'] ?? 0);
      }
   }
}

if ($needs_bl && !$has_bl) {
   api_combined_end(422, [
      'ok'      => false,
      'error'   => 'missing_bl_or_survey',
      'message' => 'BL/survey introuvable pour la signature',
   ]);
}

if ($needs_report && $resolved_ticket_id <= 0) {
   api_combined_end(422, [
      'ok'      => false,
      'error'   => 'missing_ticket_id',
      'message' => 'ticket_id requis pour la generation du rapport',
   ]);
}

if ($effective_mode === 'both') {
   $survey_ticket_id = (int)($survey['tickets_id'] ?? 0);
   if ($survey_ticket_id <= 0 || $survey_ticket_id !== $resolved_ticket_id) {
      api_combined_end(422, [
         'ok'      => false,
         'error'   => 'missing_ticket_bl_association',
         'message' => 'Le mode both requiert un ticket associe au BL',
      ]);
   }
}

if ($needs_bl) {
   if ($signer_name === '') {
      api_combined_end(422, ['ok' => false, 'error' => 'missing_signer_name']);
   }
   if ($signature === '') {
      api_combined_end(422, ['ok' => false, 'error' => 'missing_signature']);
   }
}

if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}

$out = [
   'ok'                => true,
   'mode'              => $effective_mode,
   'requested_mode'    => $requested_mode,
   'association'       => [
      'ticket_id' => $resolved_ticket_id,
      'survey_id' => (int)($survey['id'] ?? 0),
      'bl'        => (string)($survey['bl'] ?? $bl_input),
      'linked'    => ($survey !== null && (int)($survey['tickets_id'] ?? 0) === $resolved_ticket_id && $resolved_ticket_id > 0),
   ],
   'technician_login'  => (string)($auth['tech_login'] ?? ''),
   'technician_label'  => (string)($auth['tech_label'] ?? ''),
];

if ($needs_bl) {
   $bl_payload = [
      'survey_id'              => (int)$survey['id'],
      'bl'                     => (string)($survey['bl'] ?? ''),
      'signer_name'            => $signer_name,
      'signer_email'           => $signer_email,
      'signature'              => $signature,
      'mail_to_client'         => $mail_to_client ? 1 : 0,
      'comment'                => $comment,
      'counter_invoice_client' => $counter_invoice_client ? 1 : 0,
   ];
   if ($technician_override !== '') {
      $bl_payload['technician_login'] = $technician_override;
      $bl_payload['technician'] = $technician_override;
   }

   $bl_call = api_combined_http_call(
      'POST',
      $base_url . '/plugins/gestion/api/bl_sign.php',
      $forward_headers,
      $bl_payload
   );

   $out['bl_result'] = [
      'http' => (int)($bl_call['status'] ?? 0),
      'data' => $bl_call['json'] ?? null,
   ];

   $bl_ok = $bl_call['ok'] && is_array($bl_call['json']) && !empty($bl_call['json']['ok']);
   if (!$bl_ok) {
      api_combined_end($bl_call['status'] > 0 ? (int)$bl_call['status'] : 500, [
         'ok'       => false,
         'error'    => 'bl_sign_failed',
         'mode'     => $effective_mode,
         'partial'  => $out,
         'message'  => is_array($bl_call['json']) ? (string)($bl_call['json']['message'] ?? $bl_call['json']['error'] ?? 'BL signature failed') : 'BL signature failed',
         'raw'      => substr((string)($bl_call['raw'] ?? ''), 0, 500),
      ]);
   }
}

if ($needs_report) {
   $report_payload = [
      'ticket_id'               => $resolved_ticket_id,
      'document_type'           => $document_type !== '' ? $document_type : 'intervention_report',
      'signer_name'             => $signer_name !== '' ? $signer_name : (string)($auth['tech_label'] ?? $auth['tech_login'] ?? '-'),
      'signer_email'            => $signer_email,
      'mail_to_client'          => $mail_to_client ? 1 : 0,
      'signature'               => $signature,
   ];

   $passthrough = [
      'task_ids',
      'followup_ids',
      'include_followups',
      'show_total_time',
      'include_task_images',
      'include_followup_images',
      'description',
      'entity_group',
      'users_id_tech',
   ];
   foreach ($passthrough as $key) {
      if (array_key_exists($key, $input)) {
         $report_payload[$key] = $input[$key];
      }
   }

   $report_call = api_combined_http_call(
      'POST',
      $base_url . '/plugins/rp/api/ticket_generate.php',
      $forward_headers,
      $report_payload
   );

   $out['report_result'] = [
      'http' => (int)($report_call['status'] ?? 0),
      'data' => $report_call['json'] ?? null,
   ];

   $report_ok = $report_call['ok'] && is_array($report_call['json']) && !empty($report_call['json']['ok']);
   if (!$report_ok) {
      api_combined_end($report_call['status'] > 0 ? (int)$report_call['status'] : 500, [
         'ok'       => false,
         'error'    => 'report_generate_failed',
         'mode'     => $effective_mode,
         'partial'  => $out,
         'message'  => is_array($report_call['json']) ? (string)($report_call['json']['message'] ?? $report_call['json']['error'] ?? 'Report generation failed') : 'Report generation failed',
         'raw'      => substr((string)($report_call['raw'] ?? ''), 0, 500),
      ]);
   }
}

api_combined_end(200, $out);
