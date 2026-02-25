<?php
/**
 * plugins/gestion/ajax/request_poll.php
 * Côté PC : interroge le statut d'une demande de signature (terminée ou non).
 *
 * @since 1.7.0_alpha1 — Suppression colonnes device_id / device_token
 *                       (remplacées par device_serial).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/inc/includes.php';
@ini_set('display_errors', '0');

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();

if (empty($config->fields['RemoteSignatureOn'])) {
    exit;
}

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

$TABLE          = 'glpi_plugin_gestion_remote_sign_requests';
$SIG_CANDIDATES = ['signature_base64', 'signature_base', 'signature_data', 'signature'];

function rp_json_end(int $status, array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rp_find_sig_col(DBmysql $DB, string $table, array $candidates): ?string
{
    foreach ($candidates as $c) {
        $res = $DB->doQuery(
            "SHOW COLUMNS FROM `" . $DB->escape($table) . "` LIKE '" . $DB->escape($c) . "'"
        );
        if ($res && $DB->numrows($res) > 0) {
            return $c;
        }
    }
    return null;
}

// ── Lecture des paramètres (GET, POST, JSON body) ────────────────────────────
$jsonData = [];
$rawInput = (string)(file_get_contents('php://input') ?: '');
if ($rawInput !== '' && str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $parsed = json_decode($rawInput, true);
    if (is_array($parsed)) {
        $jsonData = $parsed;
    }
}

$allData      = array_merge($_GET ?? [], $_POST ?? [], $jsonData);
$ticketId     = (int)($allData['ticket_id'] ?? $allData['tickets_id'] ?? $allData['id'] ?? 0);
// device_serial optionnel (affine la recherche si présent)
$deviceSerial = trim((string)($allData['device_serial'] ?? ''));

if ($ticketId <= 0) {
    rp_json_end(400, ['ok' => false, 'ready' => false, 'error' => 'missing_ticket_id']);
}

// ── Colonne signature ─────────────────────────────────────────────────────────
$sigCol = rp_find_sig_col($DB, $TABLE, $SIG_CANDIDATES);
if ($sigCol === null) {
    rp_json_end(500, [
        'ok'    => false,
        'ready' => false,
        'error' => 'signature_column_not_found',
        'table' => $TABLE,
        'tried' => $SIG_CANDIDATES,
    ]);
}

// ── Requête SQL ──────────────────────────────────────────────────────────────
$where = "`tickets_id` = {$ticketId}";
if ($deviceSerial !== '') {
    $where .= " AND `device_serial` = '" . $DB->escape($deviceSerial) . "'";
}

$sql = "SELECT `id`, `tickets_id`, `status`, `signer_name`, `signer_email`, `{$sigCol}`
        FROM `{$TABLE}`
        WHERE {$where}
        ORDER BY `id` DESC
        LIMIT 1";

$res = $DB->doQuery($sql);

if (!$res) {
    rp_json_end(500, ['ok' => false, 'ready' => false, 'error' => 'sql_error']);
}

if ($DB->numrows($res) === 0) {
    rp_json_end(200, ['ok' => true, 'ready' => false, 'none' => true, 'status' => 'no_request']);
}

$row    = $DB->fetchassoc($res);
$status = (string)$row['status'];
$signer = (string)($row['signer_name'] ?? '');
$email  = (string)($row['signer_email'] ?? '');
$sign64 = (string)($row[$sigCol] ?? '');

$ready = ($status === 'done' && $sign64 !== '');

$response = [
    'ok'           => true,
    'ready'        => $ready,
    'status'       => $status,
    'signer_name'  => $signer,
    'signer_email' => $email,
    'none'         => false,
];

if ($ready) {
    $response['signature']        = $sign64;
    $response['signature_base64'] = $sign64;
}

rp_json_end(200, $response);
