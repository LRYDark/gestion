<?php
/**
 * plugins/gestion/public/api/device_direct_sign.php
 * Signature directe depuis la borne kiosque (sans demande PC préalable).
 *
 * Auth   : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth) + X-Device-Serial.
 * Méthode: POST
 * Params : type (bl|ticket), reference, signer_name, signer_email, signature_base64
 *
 * Stocke dans glpi_plugin_gestion_remote_sign_requests avec status='done'.
 * Le champ `parameters` contient un JSON {"type":..., "bl_number":...} ou
 * {"type":"ticket","ticket_id":...}.
 *
 * @since 1.7.0_alpha1
 */

declare(strict_types=1);

if (!defined('NOLOGIN'))        { define('NOLOGIN',        1); }
if (!defined('NOHEADER'))       { define('NOHEADER',       1); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', 1); }

require GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';

global $DB;
header('Content-Type: application/json; charset=UTF-8');

// ─── CORS ─────────────────────────────────────────────────────────────────────
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

// ── Helper ────────────────────────────────────────────────────────────────────

function ds_json(int $status, array $payload): void
{
    if (class_exists('PluginGestionLogger')) {
        PluginGestionLogger::apiResponse($status, $payload);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ds_find_sig_col(DBmysql $DB, string $table): ?string
{
    foreach (['signature_base64', 'signature_base', 'signature_data', 'signature'] as $col) {
        $res = $DB->doQuery(
            "SHOW COLUMNS FROM `" . $DB->escape($table) . "` LIKE '" . $DB->escape($col) . "'"
        );
        if ($res && $DB->numrows($res) > 0) {
            return $col;
        }
    }
    return null;
}

// ── Authentification GLPI (Token v1/v2) ──────────────────────────────────────

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    ds_json(
        (int)($auth['code'] ?? 401),
        ['ok' => false, 'error' => (string)($auth['error'] ?? 'auth_failed'), 'message' => (string)($auth['message'] ?? 'Authentification API invalide')]
    );
}

// ── Auth : X-Device-Serial ────────────────────────────────────────────────────

$serial = trim((string)($_SERVER['HTTP_X_DEVICE_SERIAL'] ?? ''));
if ($serial === '') {
    ds_json(401, ['ok' => false, 'error' => 'missing_device_serial']);
}

$devRes = $DB->doQuery(
    "SELECT `id`, `status`
     FROM `glpi_plugin_gestion_devices`
     WHERE `serial` = '" . $DB->escape($serial) . "'
     LIMIT 1"
);

if (!$devRes || $DB->numrows($devRes) === 0) {
    ds_json(403, ['ok' => false, 'error' => 'device_unknown', 'serial' => $serial]);
}

$dev = $DB->fetchAssoc($devRes);
if ($dev['status'] === 'banned') {
    ds_json(403, ['ok' => false, 'error' => 'device_banned']);
}

// ── Mise à jour last_seen ─────────────────────────────────────────────────────

$DB->update('glpi_plugin_gestion_devices', [
    'last_seen' => date('Y-m-d H:i:s'),
], ['id' => (int)$dev['id']]);

// ── Paramètres POST ───────────────────────────────────────────────────────────

$type         = trim((string)($_POST['type']         ?? ''));
$reference    = trim((string)($_POST['reference']    ?? ''));
$signerName   = trim((string)($_POST['signer_name']  ?? ''));
$signerEmail  = trim((string)($_POST['signer_email'] ?? ''));
$signatureB64 = trim((string)($_POST['signature_base64'] ?? $_POST['signature'] ?? ''));

// Support JSON body
$rawInput = (string)(file_get_contents('php://input') ?: '');
if ($rawInput !== '' && str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $parsed = json_decode($rawInput, true);
    if (is_array($parsed)) {
        if ($type      === '' && isset($parsed['type']))             { $type         = trim((string)$parsed['type']); }
        if ($reference === '' && isset($parsed['reference']))        { $reference    = trim((string)$parsed['reference']); }
        if ($signerName === '' && isset($parsed['signer_name']))     { $signerName   = trim((string)$parsed['signer_name']); }
        if ($signerEmail === '' && isset($parsed['signer_email']))   { $signerEmail  = trim((string)$parsed['signer_email']); }
        if ($signatureB64 === '' && isset($parsed['signature_base64'])) { $signatureB64 = trim((string)$parsed['signature_base64']); }
        if ($signatureB64 === '' && isset($parsed['signature']))     { $signatureB64 = trim((string)$parsed['signature']); }
    }
}

// ── Validation ────────────────────────────────────────────────────────────────

if (!in_array($type, ['bl', 'ticket'], true)) {
    ds_json(400, ['ok' => false, 'error' => 'invalid_type', 'accepted' => ['bl', 'ticket']]);
}
if ($reference === '') {
    ds_json(400, ['ok' => false, 'error' => 'missing_reference']);
}
if ($signerName === '') {
    ds_json(400, ['ok' => false, 'error' => 'missing_signer_name']);
}
if ($signatureB64 === '') {
    ds_json(400, ['ok' => false, 'error' => 'missing_signature']);
}

// ── Colonne signature ─────────────────────────────────────────────────────────

$TABLE  = 'glpi_plugin_gestion_remote_sign_requests';
$sigCol = ds_find_sig_col($DB, $TABLE);
if ($sigCol === null) {
    ds_json(500, ['ok' => false, 'error' => 'signature_column_not_found']);
}

// ── Préparer les données ──────────────────────────────────────────────────────

$ticketsId = 0;

if ($type === 'bl') {
    $params = ['type' => 'bl', 'bl_number' => $reference];
} else {
    $ticketId  = (int)$reference;
    $ticketsId = $ticketId > 0 ? $ticketId : 0;
    $params    = ['type' => 'ticket', 'ticket_id' => $ticketsId];
}

$parametersJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ── Insérer dans remote_sign_requests ─────────────────────────────────────────

$ok = $DB->insert($TABLE, [
    'tickets_id'    => $ticketsId,
    'device_serial' => $serial,
    'status'        => 'done',
    'signer_name'   => $signerName,
    'signer_email'  => $signerEmail,
    $sigCol         => $signatureB64,
    'parameters'    => $parametersJson,
    'date_creation' => date('Y-m-d H:i:s'),
]);

if (!$ok) {
    ds_json(500, ['ok' => false, 'error' => 'insert_failed']);
}

ds_json(201, [
    'ok'      => true,
    'id'      => (int)$DB->insertId(),
    'message' => 'Signature directe enregistrée',
]);

