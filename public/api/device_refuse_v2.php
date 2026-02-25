<?php
/**
 * POST /plugins/gestion/public/api/device_refuse_v2.php
 *
 * L'app kiosque refuse une requête de signature.
 * Passe le statut de 'pending' à 'cancelled'.
 *
 * Authentification : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth).
 *
 * Request Headers :
 *   Content-Type: application/json
 *   Authorization: Bearer <token>           (v2) OU App-Token + User-Token (v1) (obligatoire)
 *   X-Device-Serial: <serial>
 *
 * Request Body (JSON) :
 *   { "request_id": 42, "reason": "..." }   (reason optionnel)
 *
 * Responses :
 *   200 { ok:true,  status:"cancelled", row_id:42 }
 *   400 { ok:false, error:"missing_fields" }
 *   403 { ok:false, error:"device_unknown|device_banned|request_not_found" }
 *   409 { ok:false, error:"already_done", status:"..." }
 *   500 { ok:false, error:"db_error" }
 *
 * @package PluginGestion
 * @since   1.7.0_alpha1
 */

declare(strict_types=1);

define('NOLOGIN',        1);
define('NOHEADER',       1);
define('NOTOKENRENEWAL', 1);

$GLPI_ROOT = $_SERVER['DOCUMENT_ROOT'] . ($_SERVER['CONTEXT_PREFIX'] ?? '');
if (!defined('PLUGIN_GESTION_DIR')) {
    define('PLUGIN_GESTION_DIR', realpath(__DIR__ . '/../..'));
}
require $GLPI_ROOT . '/inc/includes.php';
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

function dref_jexit(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dref_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v !== '') {
            return trim(explode(',', $v)[0]);
        }
    }
    return '0.0.0.0';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dref_jexit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── Authentification GLPI (Token v1/v2) ─────────────────────────────────────
$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    dref_jexit(
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
$clientIp = dref_client_ip();

if ($serial === '') {
    dref_jexit(400, ['ok' => false, 'error' => 'missing_serial']);
}

// ─── Body ────────────────────────────────────────────────────────────────────
$rawBody = (string) (file_get_contents('php://input') ?: '');
$body    = [];
if ($rawBody !== '') {
    $parsed = json_decode($rawBody, true);
    if (is_array($parsed)) {
        $body = $parsed;
    }
}

if (empty($body['request_id'])) {
    dref_jexit(400, ['ok' => false, 'error' => 'missing_fields', 'fields' => ['request_id']]);
}

$requestId = (int) $body['request_id'];
$esc       = fn(string $v): string => $DB->escape($v);

// ─── Validation device ───────────────────────────────────────────────────────
$devRes = $DB->doQuery(
    "SELECT `id`, `status` FROM `glpi_plugin_gestion_devices`
     WHERE `serial` = '" . $esc($serial) . "' LIMIT 1"
);
if (!$devRes || $DB->numrows($devRes) === 0) {
    dref_jexit(403, ['ok' => false, 'error' => 'device_unknown', 'serial' => $serial]);
}
$devRow = $DB->fetchassoc($devRes);
if ($devRow['status'] === 'banned') {
    dref_jexit(403, ['ok' => false, 'error' => 'device_banned', 'serial' => $serial]);
}

// ─── Validation requête ───────────────────────────────────────────────────────
$reqTable = 'glpi_plugin_gestion_remote_sign_requests';
$reqRes = $DB->doQuery(
    "SELECT `id`, `status`, `device_serial`
     FROM `$reqTable`
     WHERE `id` = $requestId LIMIT 1"
);
if (!$reqRes || $DB->numrows($reqRes) === 0) {
    dref_jexit(403, ['ok' => false, 'error' => 'request_not_found', 'request_id' => $requestId]);
}
$reqRow = $DB->fetchassoc($reqRes);

if ($reqRow['device_serial'] !== $serial) {
    dref_jexit(403, ['ok' => false, 'error' => 'request_not_found', 'request_id' => $requestId]);
}

if ($reqRow['status'] !== 'pending') {
    dref_jexit(409, [
        'ok'     => false,
        'error'  => 'already_done',
        'status' => $reqRow['status'],
    ]);
}

// ─── UPDATE → cancelled ───────────────────────────────────────────────────────
$ok = $DB->update($reqTable, [
    'status'   => 'cancelled',
    'date_mod' => date('Y-m-d H:i:s'),
], ['id' => $requestId]);

if (!$ok) {
    dref_jexit(500, ['ok' => false, 'error' => 'db_error', 'detail' => $DB->error()]);
}

$DB->update('glpi_plugin_gestion_devices', [
    'ip'        => $clientIp,
    'last_seen' => date('Y-m-d H:i:s'),
], ['id' => (int) $devRow['id']]);

dref_jexit(200, [
    'ok'     => true,
    'status' => 'cancelled',
    'row_id' => $requestId,
]);
