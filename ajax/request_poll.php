<?php
// plugins/gestion/ajax/request_poll.php
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

// Vérifier la session GLPI pour les appels côté technicien
Session::checkLoginUser();

$TABLE             = 'glpi_plugin_gestion_remote_sign_requests';
$COL_ID            = 'id';
$COL_TICKET_ID     = 'tickets_id';
$COL_DEVICE_ID     = 'device_id';
$COL_DEVICE_TOKEN  = 'device_token';
$COL_STATUS        = 'status';           // enum('pending','done','cancelled')
$COL_SIGNER_NAME   = 'signer_name';
$COL_SIGNER_EMAIL  = 'signer_email';    // AJOUT EMAIL
$SIG_CANDIDATES    = ['signature_base64','signature_base','signature_data','signature'];

function json_end(int $status, array $payload): void {
   if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function dbg_log_path(): string {
   if (defined('GLPI_LOG_DIR') && GLPI_LOG_DIR) $base = GLPI_LOG_DIR;
   elseif ($t = ini_get('sys_temp_dir'))        $base = $t;
   elseif (function_exists('sys_get_temp_dir')) $base = sys_get_temp_dir();
   else                                         $base = '/var/tmp';
   return rtrim($base, '/').'/device_submit_dbg.log';
}

function find_signature_col(DBmysql $DB, string $table, array $candidates): ?string {
   foreach ($candidates as $c) {
      $res = $DB->doQuery("SHOW COLUMNS FROM `".$DB->escape($table)."` LIKE '".$DB->escape($c)."'");
      if ($res && $DB->numrows($res) > 0) return $c;
   }
   return null;
}

$__log = dbg_log_path();

// ---- Collect & normalize params (supporte plusieurs sources : GET, POST, JSON) ----
// Lire le JSON du body si présent
$json_data = [];
$raw_input = file_get_contents('php://input');
if ($raw_input && strpos(($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $parsed = json_decode($raw_input, true);
    if (is_array($parsed)) {
        $json_data = $parsed;
    }
}

// Merger toutes les sources
$all_data = array_merge($_GET ?? [], $_POST ?? [], $json_data);

$ticket_id  = trim((string)($all_data['ticket_id'] ?? $all_data['tickets_id'] ?? ''));
$id_param   = trim((string)($all_data['id'] ?? $all_data['request_id'] ?? ''));
$device_id  = trim((string)($all_data['device_id'] ?? $all_data['device'] ?? $all_data['device_name'] ?? ''));
$token      = trim((string)($all_data['device_token'] ?? $all_data['token'] ?? $all_data['dev_token'] ?? ''));

// Si on reçoit seulement ticket_id ou id, on cherche par ticket
if ($ticket_id === '' && $id_param !== '') {
   $ticket_id = $id_param;
}

// Log uniquement si paramètres manquants ET que c'est vraiment un problème
if ($ticket_id === '' && $id_param === '') {
   @file_put_contents($__log, json_encode([
      'phase'      => 'poll_error_missing_params',
      'time'       => date('c'),
      'method'     => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
      'final_params' => ['id'=>$id_param,'ticket_id'=>$ticket_id,'device_id'=>$device_id,'device_token'=>$token],
   ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
}

global $DB;
$SIG_COL = find_signature_col($DB, $TABLE, $SIG_CANDIDATES);
if ($SIG_COL === null) {
   json_end(500, ['ok'=>false,'ready'=>false,'error'=>'signature_column_not_found','table'=>$TABLE,'tried'=>$SIG_CANDIDATES]);
}

// Si on n'a pas de ticket_id, impossible de continuer
if ($ticket_id === '') {
   json_end(400, ['ok'=>false,'ready'=>false,'error'=>'missing_ticket_id']);
}

// Chercher la demande de signature pour ce ticket (la plus récente)
$where_conditions = [];
$where_conditions[] = $COL_TICKET_ID . " = " . (int)$ticket_id;

// Si on a device_id et token, on peut filtrer plus précisément
if ($device_id !== '' && $token !== '') {
   $where_conditions[] = $COL_DEVICE_ID . " = '" . $DB->escape($device_id) . "'";
   $where_conditions[] = $COL_DEVICE_TOKEN . " = '" . $DB->escape($token) . "'";
}

$where_clause = implode(' AND ', $where_conditions);

$sql = "SELECT $COL_ID, $COL_TICKET_ID, $COL_STATUS, $COL_SIGNER_NAME, $COL_SIGNER_EMAIL, $SIG_COL
        FROM `$TABLE`
        WHERE $where_clause
        ORDER BY $COL_ID DESC 
        LIMIT 1";

$res = $DB->doQuery($sql);

// Log uniquement si erreur SQL
if (!$res) {
   @file_put_contents($__log, json_encode([
      'phase' => 'sql_error',
      'sql' => $sql,
      'error' => $DB->error(),
      'time' => date('c')
   ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
   json_end(500, ['ok'=>false,'ready'=>false,'error'=>'sql_error']);
}

if ($DB->numrows($res) === 0) {
   json_end(200, ['ok'=>true,'ready'=>false,'none'=>true,'status'=>'no_request']);
}

$row = $DB->fetchassoc($res);
$status = (string)$row[$COL_STATUS];
$signer = (string)($row[$COL_SIGNER_NAME] ?? '');
$signer_email = (string)($row[$COL_SIGNER_EMAIL] ?? '');
$sign64 = (string)($row[$SIG_COL] ?? '');

// Ready when status is done AND we have a signature
$ready = ($status === 'done' && $sign64 !== '');

// Log uniquement si problème avec les données récupérées (incohérence)
if ($status === 'done' && $sign64 === '') {
   @file_put_contents($__log, json_encode([
      'phase'      => 'poll_error_done_no_signature',
      'time'       => date('c'),
      'status'     => $status,
      'signer'     => $signer,
      'ticket_id'  => $ticket_id
   ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
}

// Réponse compatible avec le JavaScript côté technicien
$response = [
   'ok'               => true,
   'ready'            => $ready,
   'status'           => $status,
   'signer_name'      => $signer,
   'signer_email'     => $signer_email,
   'none'             => false
];

// Ajouter la signature seulement si ready
if ($ready) {
   $response['signature'] = $sign64;
   $response['signature_base64'] = $sign64;
}

json_end(200, $response);