<?php
/**
 * POST /plugins/gestion/public/api/device_submit_v2.php
 *
 * Soumet la signature depuis l'app kiosque APPAPPLETAB.
 * Identification : serial (header X-Device-Serial) + validation IP.
 * Authentification : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth).
 *
 * Request Headers :
 *   Content-Type: application/json
 *   Authorization: Bearer <token>           (v2) OU App-Token + User-Token (v1) (obligatoire)
 *   X-Device-Serial: <serial>
 *
 * Request Body (JSON) :
 *   {
 *     "request_id":   42,             (int, obligatoire)
 *     "signer_name":  "Jean Dupont",  (string, obligatoire)
 *     "signature":    "data:image/png;base64,...",  (string, obligatoire)
 *     "signer_email": "jean@example.com"            (string, optionnel)
 *   }
 *
 * Responses :
 *   200 { ok:true,  status:"saved", row_id:42 }
 *   400 { ok:false, error:"missing_fields", fields:[...] }
 *   403 { ok:false, error:"device_unknown|device_banned|request_not_found" }
 *   409 { ok:false, error:"already_done", status:"done|cancelled" }
 *   422 { ok:false, error:"invalid_signature" }
 *   500 { ok:false, error:"db_error", detail:"..." }
 *
 * @package PluginGestion
 * @since   1.7.0_alpha1
 */

declare(strict_types=1);

define('NOLOGIN',        1);
define('NOHEADER',       1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ─── CORS ────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, X-Device-Serial, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function dsub_jexit(int $code, array $payload): never
{
    if (class_exists('PluginGestionLogger')) {
        PluginGestionLogger::apiResponse($code, $payload);
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dsub_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v !== '') {
            return trim(explode(',', $v)[0]);
        }
    }
    return '0.0.0.0';
}

// ─── Méthode ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dsub_jexit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── Authentification GLPI (Token v1/v2) ─────────────────────────────────────
$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    dsub_jexit(
        (int)($auth['code'] ?? 401),
        ['ok' => false, 'error' => (string)($auth['error'] ?? 'auth_failed'), 'message' => (string)($auth['message'] ?? 'Authentification API invalide')]
    );
}

global $DB;

// ─── Serial ──────────────────────────────────────────────────────────────────
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headersCi  = [];
foreach ($allHeaders as $k => $v) {
    $headersCi[strtolower($k)] = $v;
}

$serial   = trim($headersCi['x-device-serial'] ?? '');
$clientIp = dsub_client_ip();

if ($serial === '') {
    dsub_jexit(400, ['ok' => false, 'error' => 'missing_serial']);
}

// ─── Body JSON ───────────────────────────────────────────────────────────────
$rawBody = (string) (file_get_contents('php://input') ?: '');
$body    = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

// Champs obligatoires
$missing = [];
foreach (['request_id', 'signer_name', 'signature'] as $field) {
    if (empty($body[$field]) && $body[$field] !== 0) {
        $missing[] = $field;
    }
}
if (!empty($missing)) {
    dsub_jexit(400, ['ok' => false, 'error' => 'missing_fields', 'fields' => $missing]);
}

$requestId   = (int) $body['request_id'];
$signerName  = trim((string) $body['signer_name']);
$signerEmail = trim((string) ($body['signer_email'] ?? ''));
$signature   = (string) $body['signature'];

// Valider signature base64
if (!preg_match('#^(data:[a-z0-9+/.-]+;base64,)?[A-Za-z0-9+/=]+$#', $signature)) {
    dsub_jexit(422, ['ok' => false, 'error' => 'invalid_signature']);
}

// Extraire base64 pur
$signatureB64 = $signature;
if (preg_match('#^data:[^;]+;base64,#', $signature)) {
    $signatureB64 = substr($signature, strpos($signature, ',') + 1);
}

$esc = fn(string $v): string => $DB->escape($v);

// ─── Validation device ───────────────────────────────────────────────────────
$devRes = $DB->doQuery(
    "SELECT `id`, `status`
     FROM `glpi_plugin_gestion_devices`
     WHERE `serial` = '" . $esc($serial) . "'
     LIMIT 1"
);
if (!$devRes || $DB->numrows($devRes) === 0) {
    dsub_jexit(403, ['ok' => false, 'error' => 'device_unknown', 'serial' => $serial]);
}
$devRow = $DB->fetchassoc($devRes);
if ($devRow['status'] === 'banned') {
    dsub_jexit(403, ['ok' => false, 'error' => 'device_banned', 'serial' => $serial]);
}

// ─── Validation requête ───────────────────────────────────────────────────────
$reqTable = 'glpi_plugin_gestion_remote_sign_requests';
$reqRes = $DB->doQuery(
    "SELECT `id`, `status`, `device_serial`
     FROM `$reqTable`
     WHERE `id` = $requestId
     LIMIT 1"
);
if (!$reqRes || $DB->numrows($reqRes) === 0) {
    dsub_jexit(403, ['ok' => false, 'error' => 'request_not_found', 'request_id' => $requestId]);
}
$reqRow = $DB->fetchassoc($reqRes);

// Vérifier que la requête appartient bien à cet appareil
if ($reqRow['device_serial'] !== $serial) {
    dsub_jexit(403, ['ok' => false, 'error' => 'request_not_found', 'request_id' => $requestId]);
}

// Vérifier statut
if ($reqRow['status'] !== 'pending') {
    dsub_jexit(409, [
        'ok'     => false,
        'error'  => 'already_done',
        'status' => $reqRow['status'],
    ]);
}

// ─── Détecter colonne signature (compatibilité) ──────────────────────────────
$sigCol     = 'signature_base64';
$candidates = ['signature_base64', 'signature_base', 'signature_data', 'signature'];
foreach ($candidates as $candidate) {
    $chkRes = $DB->doQuery("SHOW COLUMNS FROM `$reqTable` LIKE '" . $esc($candidate) . "'");
    if ($chkRes && $DB->numrows($chkRes) > 0) {
        $sigCol = $candidate;
        break;
    }
}

// ─── UPDATE ──────────────────────────────────────────────────────────────────
$updateData = [
    'status'       => 'done',
    'signer_name'  => $signerName,
    'date_mod'     => date('Y-m-d H:i:s'),
    $sigCol        => $signatureB64,
];
if ($signerEmail !== '') {
    $updateData['signer_email'] = $signerEmail;
}

$ok = $DB->update($reqTable, $updateData, ['id' => $requestId]);
if (!$ok) {
    dsub_jexit(500, ['ok' => false, 'error' => 'db_error', 'detail' => $DB->error()]);
}

// Mise à jour last_seen device
$DB->update('glpi_plugin_gestion_devices', [
    'ip'        => $clientIp,
    'last_seen' => date('Y-m-d H:i:s'),
], ['id' => (int) $devRow['id']]);

dsub_jexit(200, [
    'ok'     => true,
    'status' => 'saved',
    'row_id' => $requestId,
]);
