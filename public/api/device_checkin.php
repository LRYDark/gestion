<?php
/**
 * POST /plugins/gestion/public/api/device_checkin.php
 *
 * Enregistre ou met à jour un appareil kiosque dans glpi_plugin_gestion_devices.
 * Identification : serial (header X-Device-Serial) + IP client.
 * Authentification : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth).
 *
 * Request Headers :
 *   Authorization: Bearer <token>           (v2) OU App-Token + User-Token (v1) (obligatoire)
 *   X-Device-Serial: <serial_ipad>          (obligatoire)
 *
 * Request Body (JSON) :
 *   { "name": "Tablette Accueil RDC" }      (optionnel)
 *
 * Responses :
 *   200 { ok:true,  status:"active",  device_id:42, serial:"...", ip:"...", name:"...", message:"registered|updated" }
 *   400 { ok:false, error:"missing_serial" }
 *   403 { ok:false, error:"device_banned",   serial:"..." }
 *   500 { ok:false, error:"db_error",        detail:"..." }
 *
 * @package PluginGestion
 * @since   1.7.0_alpha1
 */

declare(strict_types=1);

define('NOLOGIN',          1);
define('NOHEADER',         1);
define('NOTOKENRENEWAL',   1);

$GLPI_ROOT = $_SERVER['DOCUMENT_ROOT'] . ($_SERVER['CONTEXT_PREFIX'] ?? '');
if (!defined('PLUGIN_GESTION_DIR')) {
    define('PLUGIN_GESTION_DIR', realpath(__DIR__ . '/../..'));
}
require $GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ─── CORS ─────────────────────────────────────────────────────────────────────
$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($allowedOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, X-Device-Serial, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function dc_jexit(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dc_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $val = $_SERVER[$key] ?? '';
        if ($val !== '') {
            // X-Forwarded-For peut contenir plusieurs IPs séparées par virgule
            return trim(explode(',', $val)[0]);
        }
    }
    return '0.0.0.0';
}

// ─── Méthode ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dc_jexit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── Authentification GLPI (Token v1/v2) ─────────────────────────────────────
$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    dc_jexit(
        (int)($auth['code'] ?? 401),
        ['ok' => false, 'error' => (string)($auth['error'] ?? 'auth_failed'), 'message' => (string)($auth['message'] ?? 'Authentification API invalide')]
    );
}

// ─── Lecture serial ───────────────────────────────────────────────────────────
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
// Normalisation des clés (case-insensitive en PHP != Apache)
$headersCi = [];
foreach ($allHeaders as $k => $v) {
    $headersCi[strtolower($k)] = $v;
}

$serial = trim($headersCi['x-device-serial'] ?? '');
if ($serial === '') {
    dc_jexit(400, ['ok' => false, 'error' => 'missing_serial']);
}

// ─── Lecture body JSON optionnel ─────────────────────────────────────────────
$body = (string) (file_get_contents('php://input') ?: '');
$bodyJson = [];
if ($body !== '' && str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $parsed = json_decode($body, true);
    if (is_array($parsed)) {
        $bodyJson = $parsed;
    }
}

$nameProvided = isset($bodyJson['name']) ? trim((string) $bodyJson['name']) : null;
$clientIp     = dc_client_ip();

// ─── Rate limiting simple : max 1 checkin / 5s par serial ────────────────────
// Utilise APCu si disponible, sinon skip (la DB sert de filet)
if (function_exists('apcu_fetch')) {
    $rateKey = 'gestion_checkin_' . md5($serial);
    if (apcu_fetch($rateKey) !== false) {
        dc_jexit(429, ['ok' => false, 'error' => 'rate_limited']);
    }
    apcu_store($rateKey, 1, 5);
}

// ─── DB ──────────────────────────────────────────────────────────────────────
global $DB;
$table  = 'glpi_plugin_gestion_devices';
$esc    = fn(string $v): string => $DB->escape($v);

// Chercher appareil existant
$res = $DB->doQuery(
    "SELECT `id`, `status`, `name` FROM `$table`
     WHERE `serial` = '" . $esc($serial) . "'
     LIMIT 1"
);

if (!$res) {
    dc_jexit(500, ['ok' => false, 'error' => 'db_error', 'detail' => $DB->error()]);
}

if ($DB->numrows($res) === 0) {
    // ── Nouveau device : INSERT ───────────────────────────────────────────────
    $insertName = $nameProvided ?? null;
    $ok = $DB->insert($table, [
        'serial'        => $serial,
        'ip'            => $clientIp,
        'name'          => $insertName,
        'last_seen'     => date('Y-m-d H:i:s'),
        'status'        => 'active',
        'date_creation' => date('Y-m-d H:i:s'),
    ]);
    if (!$ok) {
        dc_jexit(500, ['ok' => false, 'error' => 'db_error', 'detail' => $DB->error()]);
    }
    $deviceId = $DB->insertId();
    dc_jexit(200, [
        'ok'        => true,
        'status'    => 'active',
        'device_id' => $deviceId,
        'serial'    => $serial,
        'ip'        => $clientIp,
        'name'      => $insertName,
        'message'   => 'registered',
    ]);
} else {
    // ── Appareil connu ────────────────────────────────────────────────────────
    $row      = $DB->fetchassoc($res);
    $deviceId = (int) $row['id'];
    $status   = $row['status'];

    if ($status === 'banned') {
        dc_jexit(403, ['ok' => false, 'error' => 'device_banned', 'serial' => $serial]);
    }

    // UPDATE ip + last_seen (+ name si fourni)
    $updateData = [
        'ip'        => $clientIp,
        'last_seen' => date('Y-m-d H:i:s'),
    ];
    if ($nameProvided !== null) {
        $updateData['name'] = $nameProvided;
    }

    $DB->update($table, $updateData, ['id' => $deviceId]);

    dc_jexit(200, [
        'ok'        => true,
        'status'    => 'active',
        'device_id' => $deviceId,
        'serial'    => $serial,
        'ip'        => $clientIp,
        'name'      => $nameProvided ?? $row['name'],
        'message'   => 'updated',
    ]);
}
