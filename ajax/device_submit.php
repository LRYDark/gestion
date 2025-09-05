<?php
// plugins/gestion/ajax/device_submit.php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/inc/includes.php';
@ini_set('display_errors', '0');

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

// If missing, go to login
if ($config->fields['RemoteSignatureOn'] == 0) {
   exit;
}

// --------- Table & columns ----------
$TABLE                    = 'glpi_plugin_gestion_remote_sign_requests';
$COL_ID                   = 'id';
$COL_DEVICE_ID            = 'device_id';
$COL_DEVICE_TOKEN         = 'device_token';
$COL_STATUS               = 'status';           // enum('pending','done','cancelled')
$COL_SIGNER_NAME          = 'signer_name';
$COL_SIGNER_EMAIL         = 'signer_email';    // AJOUT EMAIL
$COL_DATE_MOD             = 'date_mod';
$SIG_CANDIDATES           = ['signature_base64','signature_base','signature_data','signature'];

// --------- helpers ----------
function json_end(int $status, array $payload): void {
   if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   exit;
}
function dbg_log_path(): string {
   if (defined('GLPI_LOG_DIR') && GLPI_LOG_DIR) $base = GLPI_LOG_DIR;
   elseif ($t = ini_get('sys_temp_dir'))        $base = $t;
   elseif (function_exists('sys_get_temp_dir')) $base = sys_get_temp_dir();
   else                                         $base = '/var/tmp';
   return rtrim($base, '/').'/device_submit_dbg.log';
}
function raw_body(): string { static $r=null; if ($r===null) $r = (string)(file_get_contents('php://input') ?: ''); return $r; }
function find_signature_col(DBmysql $DB, string $table, array $candidates): ?string {
   foreach ($candidates as $c) {
      $res = $DB->doQuery("SHOW COLUMNS FROM `".$DB->escape($table)."` LIKE '".$DB->escape($c)."'");
      if ($res && $DB->numrows($res) > 0) return $c;
   }
   return null;
}

// --------- method & inputs ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_end(405, ['ok'=>false,'error'=>'method_not_allowed']);

// normalize payload
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ct, 'application/json') !== false) {
   $data = json_decode(raw_body(), true);
   if (is_array($data)) foreach (['_glpi_csrf_token','device_id','token','request_id','signer_name','signer_email','signature'] as $k) if (!isset($_POST[$k]) && isset($data[$k])) $_POST[$k] = $data[$k];
}
if (empty($_POST)) {
   $tmp=[]; parse_str(raw_body(), $tmp); if ($tmp) $_POST = $tmp;
}
if (empty($_POST['_glpi_csrf_token'])) {
   $hdrs = function_exists('getallheaders') ? getallheaders() : [];
   foreach (['X-GLPI-CSRF-Token','X-CSRF-Token','X-CSRF'] as $hk) if (!empty($hdrs[$hk])) { $_POST['_glpi_csrf_token'] = $hdrs[$hk]; break; }
}

// csrf - AUCUN LOG si succès
$__log = dbg_log_path();
$sess_token = $_SESSION['glpicsrftoken'] ?? null;
$post_token = $_POST['_glpi_csrf_token'] ?? null;

// Log SEULEMENT si erreur CSRF
if (!$sess_token || !$post_token || !hash_equals((string)$sess_token,(string)$post_token)) {
   @file_put_contents($__log, json_encode(['phase'=>'csrf_error','sid'=>session_id(),'sess_token'=>$sess_token,'post_token'=>$post_token,'time'=>date('c')], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
   json_end(403, ['ok'=>false,'error'=>'invalid_csrf']);
}

// read fields
$device_id    = (string)($_POST['device_id'] ?? '');
$token        = (string)($_POST['token'] ?? '');
$request_id   = (string)($_POST['request_id'] ?? '');
$signer_name  = trim((string)($_POST['signer_name'] ?? ''));
$signer_email = trim((string)($_POST['signer_email'] ?? ''));
$signature    = (string)($_POST['signature'] ?? '');
if ($device_id==='' || $token==='' || $request_id==='' || $signature==='') json_end(422, ['ok'=>false,'error'=>'missing_fields']);

// dataURL → base64
$b64 = $signature;
if (preg_match('#^data:([a-z0-9+./-]+);base64,#i', $signature)) {
   $b64 = substr($signature, strpos($signature, ',')+1);
}

// detect signature column
global $DB;
$SIG_COL = find_signature_col($DB, $TABLE, $SIG_CANDIDATES);
if ($SIG_COL === null) json_end(500, ['ok'=>false,'error'=>'signature_column_not_found','table'=>$TABLE]);

// UPDATE
$data_to_set = [
   $COL_STATUS        => 'done',
   $COL_SIGNER_NAME   => $signer_name,
   $COL_SIGNER_EMAIL  => $signer_email,
   $SIG_COL           => $b64,
   $COL_DATE_MOD      => date('Y-m-d H:i:s'),
];
$where = [
   $COL_ID            => (int)$request_id,
   $COL_DEVICE_ID     => $device_id,
   $COL_DEVICE_TOKEN  => $token,
];

$ok=false; $err=null;
try {
   $ok = $DB->update($TABLE, $data_to_set, $where);
   if (!$ok && method_exists($DB, 'error')) $err = $DB->error();
} catch (Throwable $e) { $err = 'exception: '.$e->getMessage(); }

// Log SEULEMENT si erreur de base de données
if (!$ok) {
   @file_put_contents($__log, json_encode(['phase'=>'db_write_error','table'=>$TABLE,'row_id'=>(int)$request_id,'where'=>$where,'error'=>$err,'time'=>date('c')], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
   json_end(500, ['ok'=>false,'error'=>'db_write_failed','db_error'=>$err,'where'=>$where]);
}

// Succès - AUCUN LOG
json_end(200, ['ok'=>true,'status'=>'saved','row_id'=>(int)$request_id]);