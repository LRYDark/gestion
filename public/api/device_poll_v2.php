<?php
/**
 * GET /plugins/gestion/public/api/device_poll_v2.php
 *
 * Polled by APPAPPLETAB kiosk app every N seconds.
 * Returns the latest pending signature request for this device, if any.
 *
 * Identification : serial (header X-Device-Serial) + IP validation.
 * Authentification : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth).
 *
 * Response (no pending) :
 *   200 { ok:true, pending:false }
 *
 * Response (pending) :
 *   200 {
 *         ok:true, pending:true,
 *         request_id:42, ticket_id:123, status:"pending",
 *         date_creation:"2026-02-22 10:00:00",
 *         parameters: {
 *           ticket_title:"...", entity_name:"...",
 *           client_email:"...", ticket_tasks:[...]
 *         }
 *       }
 *
 * Errors :
 *   400 { ok:false, error:"missing_serial" }
 *   403 { ok:false, error:"device_unknown|device_banned" }
 *   429 { ok:false, error:"rate_limited" }
 *   503 { ok:false, error:"remote_signature_disabled" }
 *
 * NOTE: Base Description / Info n'est jamais inclus dans la réponse.
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
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, X-Device-Serial, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function dp_jexit(int $code, array $payload): never
{
    if (class_exists('PluginGestionLogger')) {
        PluginGestionLogger::apiResponse($code, $payload);
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dp_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $val = $_SERVER[$key] ?? '';
        if ($val !== '') {
            return trim(explode(',', $val)[0]);
        }
    }
    return '0.0.0.0';
}

// ─── Méthode ─────────────────────────────────────────────────────────────────
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    dp_jexit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── Authentification GLPI (Token v1/v2) ─────────────────────────────────────
$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    dp_jexit(
        (int)($auth['code'] ?? 401),
        ['ok' => false, 'error' => (string)($auth['error'] ?? 'auth_failed'), 'message' => (string)($auth['message'] ?? 'Authentification API invalide')]
    );
}

// ─── Feature flag ─────────────────────────────────────────────────────────────
global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
if (empty($config->fields['RemoteSignatureOn'])) {
    dp_jexit(503, ['ok' => false, 'error' => 'remote_signature_disabled']);
}

// ─── Serial ──────────────────────────────────────────────────────────────────
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headersCi  = [];
foreach ($allHeaders as $k => $v) {
    $headersCi[strtolower($k)] = $v;
}

$serial    = trim($headersCi['x-device-serial'] ?? '');
$clientIp  = dp_client_ip();

if ($serial === '') {
    dp_jexit(400, ['ok' => false, 'error' => 'missing_serial']);
}

// ─── Rate limiting : max 1 poll / 3s par serial ──────────────────────────────
if (function_exists('apcu_fetch')) {
    $rateKey = 'gestion_poll_' . md5($serial);
    if (apcu_fetch($rateKey) !== false) {
        dp_jexit(429, ['ok' => false, 'error' => 'rate_limited']);
    }
    apcu_store($rateKey, 1, 3);
}

// ─── Validation device ───────────────────────────────────────────────────────
$devTable = 'glpi_plugin_gestion_devices';
$esc      = fn(string $v): string => $DB->escape($v);

$devRes = $DB->doQuery(
    "SELECT `id`, `status`, `ip`
     FROM `$devTable`
     WHERE `serial` = '" . $esc($serial) . "'
     LIMIT 1"
);

if (!$devRes || $DB->numrows($devRes) === 0) {
    dp_jexit(403, ['ok' => false, 'error' => 'device_unknown', 'serial' => $serial]);
}

$devRow = $DB->fetchassoc($devRes);

if ($devRow['status'] === 'banned') {
    dp_jexit(403, ['ok' => false, 'error' => 'device_banned', 'serial' => $serial]);
}

// Mise à jour de l'IP et last_seen (si IP changée ou >60s depuis dernier poll)
$DB->update($devTable, [
    'ip'        => $clientIp,
    'last_seen' => date('Y-m-d H:i:s'),
], ['id' => (int) $devRow['id']]);

// ─── Chercher requête en attente ─────────────────────────────────────────────
$reqTable = 'glpi_plugin_gestion_remote_sign_requests';
$sql = "SELECT `id`, `tickets_id`, `status`, `parameters`, `date_creation`
        FROM `$reqTable`
        WHERE `device_serial` = '" . $esc($serial) . "'
          AND `status` = 'pending'
        ORDER BY `id` DESC
        LIMIT 1";

$reqRes = $DB->doQuery($sql);

if (!$reqRes || $DB->numrows($reqRes) === 0) {
    dp_jexit(200, ['ok' => true, 'pending' => false]);
}

$row = $DB->fetchassoc($reqRes);

// Décoder les paramètres JSON
// NOTE: Base Description / Info jamais inclus
$parameters = null;
if (!empty($row['parameters'])) {
    $decoded = json_decode($row['parameters'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        // Supprimer toute clé relative à base_description / base_info si présente par legacy
        unset($decoded['base_description'], $decoded['base_info'], $decoded['base_items']);
        $parameters = $decoded;
    }
}

dp_jexit(200, [
    'ok'            => true,
    'pending'       => true,
    'request_id'    => (int) $row['id'],
    'ticket_id'     => (int) $row['tickets_id'],
    'status'        => $row['status'],
    'parameters'    => $parameters,
    'date_creation' => $row['date_creation'],
]);
