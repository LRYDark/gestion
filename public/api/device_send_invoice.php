<?php
/**
 * POST /plugins/gestion/public/api/device_send_invoice.php
 *
 * Envoie par mail un document scanné depuis l'app kiosque APPAPPLETAB.
 * Le fichier reçu est transféré en pièce jointe aux destinataires configurés
 * dans la config du plugin (champ « Envoi facture par mail » = InvoiceMail).
 * Aucun stockage GLPI : le fichier temporaire est supprimé après l'envoi.
 *
 * Identification : serial (header X-Device-Serial) + statut appareil.
 * Authentification : Token GLPI v1 (App-Token + User-Token) ou v2 (Bearer OAuth).
 *
 * Request Headers :
 *   Authorization: Bearer <token>   (v2)  OU  App-Token + User-Token (v1)  (obligatoire)
 *   X-Device-Serial: <serial>                                              (obligatoire)
 *   Content-Type: multipart/form-data    (recommandé)  OU  application/json
 *
 * Request Body — 2 formats acceptés :
 *   1) multipart/form-data :  file=<binaire>  [+ filename=<nom souhaité>]
 *   2) application/json     :  { "file_base64": "data:image/jpeg;base64,...", "filename": "..." }
 *
 * Types autorisés : pdf, jpg/jpeg, png  (détection MIME réelle, le nom client n'est pas fiable).
 * Taille max : 15 Mo (= limite pièce jointe de MailSend).
 *
 * Responses :
 *   200 { ok:true,  sent_to:[...], filename:"...", size:1234, mime:"..." }
 *   400 { ok:false, error:"missing_serial|missing_file|upload_failed|empty_file" }
 *   403 { ok:false, error:"device_unknown|device_banned" }
 *   405 { ok:false, error:"method_not_allowed" }
 *   413 { ok:false, error:"file_too_large" }
 *   422 { ok:false, error:"no_recipient_configured|invalid_file_type|invalid_base64" }
 *   500 { ok:false, error:"tmp_dir_failed|tmp_write_failed|mail_failed" }   (erreur interne / exception)
 *   502 { ok:false, error:"mail_failed" }                                  (échec d'envoi SMTP réel)
 *
 * @package PluginGestion
 * @since   1.7.4
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
function dsi_jexit(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dsi_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v !== '') {
            return trim(explode(',', $v)[0]);
        }
    }
    return '0.0.0.0';
}

// Types acceptés : MIME détecté => extension canonique
const DSI_ALLOWED   = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
const DSI_MAX_BYTES = 15 * 1024 * 1024; // 15 Mo

// ─── Méthode ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dsi_jexit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── Authentification GLPI (Token v1/v2) — identique aux autres requêtes ──────
$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
    dsi_jexit(
        (int)($auth['code'] ?? 401),
        ['ok' => false, 'error' => (string)($auth['error'] ?? 'auth_failed'), 'message' => (string)($auth['message'] ?? 'Authentification API invalide')]
    );
}

global $DB;

// ─── Serial appareil ─────────────────────────────────────────────────────────
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headersCi  = [];
foreach ($allHeaders as $k => $v) {
    $headersCi[strtolower($k)] = $v;
}
$serial   = trim($headersCi['x-device-serial'] ?? '');
$clientIp = dsi_client_ip();
if ($serial === '') {
    dsi_jexit(400, ['ok' => false, 'error' => 'missing_serial']);
}

$esc = fn(string $v): string => $DB->escape($v);

$devRes = $DB->doQuery(
    "SELECT `id`, `status`
     FROM `glpi_plugin_gestion_devices`
     WHERE `serial` = '" . $esc($serial) . "'
     LIMIT 1"
);
if (!$devRes || $DB->numrows($devRes) === 0) {
    dsi_jexit(403, ['ok' => false, 'error' => 'device_unknown', 'serial' => $serial]);
}
$devRow = $DB->fetchassoc($devRes);
if (($devRow['status'] ?? '') === 'banned') {
    dsi_jexit(403, ['ok' => false, 'error' => 'device_banned', 'serial' => $serial]);
}

// ─── Destinataires configurés (champ « Envoi facture par mail ») ─────────────
$config     = PluginGestionConfig::getInstance();
$recipients = trim((string)($config->fields['InvoiceMail'] ?? ''));

// Validation : au moins une adresse syntaxiquement valide (même règle que parse_emails
// dans MailSend : FILTER_VALIDATE_EMAIL + dédoublonnage insensible à la casse). Évite un
// envoi vide et garantit que recipients_count reflète les destinataires réels.
$recipientList = [];
foreach (preg_split('/[,\s;]+/u', $recipients, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $r) {
    $r = trim((string)$r);
    if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
        $recipientList[strtolower($r)] = $r;
    }
}
$recipientList = array_values($recipientList);
if ($recipientList === []) {
    dsi_jexit(422, [
        'ok'      => false,
        'error'   => 'no_recipient_configured',
        'message' => 'Aucun destinataire valide configuré (config plugin : « Envoi facture par mail »)',
    ]);
}

// ─── Réception du fichier : multipart prioritaire, sinon base64 JSON ─────────
$fileBytes  = null;
$clientName = '';

if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
    // --- multipart/form-data ---
    $f   = $_FILES['file'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        dsi_jexit(413, ['ok' => false, 'error' => 'file_too_large']);
    }
    if ($err !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file((string)$f['tmp_name'])) {
        dsi_jexit(400, ['ok' => false, 'error' => 'upload_failed', 'php_error' => $err]);
    }
    if ((int)($f['size'] ?? 0) > DSI_MAX_BYTES) {
        dsi_jexit(413, ['ok' => false, 'error' => 'file_too_large']);
    }
    $fileBytes  = file_get_contents((string)$f['tmp_name']);
    $clientName = (string)($f['name'] ?? '');
} else {
    // --- application/json + base64 (fallback) ---
    $rawBody = (string)(file_get_contents('php://input') ?: '');
    $body    = [];
    if ($rawBody !== '') {
        $rawBody = preg_replace('/^\xEF\xBB\xBF/', '', $rawBody) ?? $rawBody; // BOM
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    $b64        = (string)($body['file_base64'] ?? $body['file'] ?? '');
    $clientName = trim((string)($body['filename'] ?? ''));
    if ($b64 === '') {
        dsi_jexit(400, ['ok' => false, 'error' => 'missing_file', 'message' => 'Fournir un fichier (multipart "file" ou JSON "file_base64")']);
    }
    if (preg_match('#^data:[^;]+;base64,#i', $b64)) {
        $b64 = substr($b64, strpos($b64, ',') + 1);
    }
    $b64 = preg_replace('/\s+/', '', $b64) ?? $b64;
    // Borne avant decode (base64 ≈ 4/3 du binaire) pour éviter une décompression mémoire abusive
    if (strlen($b64) > (int)(DSI_MAX_BYTES * 4 / 3) + 1024) {
        dsi_jexit(413, ['ok' => false, 'error' => 'file_too_large']);
    }
    $fileBytes = base64_decode($b64, true);
    if ($fileBytes === false) {
        dsi_jexit(422, ['ok' => false, 'error' => 'invalid_base64']);
    }
}

if ($fileBytes === null || $fileBytes === false || $fileBytes === '') {
    dsi_jexit(400, ['ok' => false, 'error' => 'empty_file']);
}
if (strlen($fileBytes) > DSI_MAX_BYTES) {
    dsi_jexit(413, ['ok' => false, 'error' => 'file_too_large']);
}

// ─── Détection MIME réelle (on ne fait pas confiance au client) ──────────────
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->buffer($fileBytes);
if (!isset(DSI_ALLOWED[$mime])) {
    dsi_jexit(422, [
        'ok'       => false,
        'error'    => 'invalid_file_type',
        'detected' => $mime,
        'allowed'  => array_keys(DSI_ALLOWED),
    ]);
}
$ext = DSI_ALLOWED[$mime];

// ─── Nom de pièce jointe sûr (anti path-traversal) ───────────────────────────
$base = $clientName !== '' ? pathinfo($clientName, PATHINFO_FILENAME) : '';
$base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? '';
$base = trim($base, '._-');
if ($base === '') {
    $base = 'facture_scan_' . date('Ymd-His');
}
$base     = substr($base, 0, 80);
$safeName = $base . '.' . $ext; // extension imposée d'après le MIME détecté

// ─── Écriture dans un dossier temporaire isolé ───────────────────────────────
$tmpRoot = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
$workDir = rtrim((string)$tmpRoot, '/\\') . '/gestion_invoice_' . bin2hex(random_bytes(8));
if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    dsi_jexit(500, ['ok' => false, 'error' => 'tmp_dir_failed']);
}
$tmpPath = $workDir . '/' . $safeName;

$cleanup = static function () use ($tmpPath, $workDir): void {
    if (is_file($tmpPath)) {
        @unlink($tmpPath);
    }
    if (is_dir($workDir)) {
        @rmdir($workDir);
    }
};

if (file_put_contents($tmpPath, $fileBytes) === false) {
    $cleanup();
    dsi_jexit(500, ['ok' => false, 'error' => 'tmp_write_failed']);
}

// ─── Envoi mail via MailSend (pièce jointe + multi-destinataires gérés) ──────
require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';

$subject  = 'Facture scannée';
$bodyHtml = 'Veuillez trouver ci-joint la facture scannée depuis la tablette.';

$mailOk = null;
try {
    $sharepoint = new PluginGestionSharepoint();
    // MailSend($EMAIL, $gabarit_id=0 -> utilise Subject/Body fournis, $outputPath=pièce jointe, ...)
    $mailOk = $sharepoint->MailSend(
        implode(',', $recipientList), // 1ʳᵉ = TO, suivantes = CC
        0,
        $tmpPath,
        'Facture scannée envoyée',
        null, null, null, null,
        $subject,
        $bodyHtml
    );
} catch (Throwable $e) {
    // Détail journalisé côté serveur uniquement (pas de fuite d'info au client).
    $cleanup();
    Toolbox::logInFile('gestion', 'device_send_invoice mail exception: ' . $e->getMessage() . "\n");
    dsi_jexit(500, ['ok' => false, 'error' => 'mail_failed']);
}

$cleanup();

if ($mailOk === false) {
    // Échec d'envoi réel (transport SMTP injoignable/refusé). GLPIMailer a déjà journalisé
    // le détail dans le log 'mail-error'. La tablette conserve la source et peut réessayer.
    dsi_jexit(502, ['ok' => false, 'error' => 'mail_failed']);
}

// ─── Mise à jour last_seen device ────────────────────────────────────────────
$DB->update('glpi_plugin_gestion_devices', [
    'ip'        => $clientIp,
    'last_seen' => date('Y-m-d H:i:s'),
], ['id' => (int)$devRow['id']]);

dsi_jexit(200, [
    'ok'               => true,
    'recipients_count' => count($recipientList),
    'filename'         => $safeName,
    'size'             => strlen($fileBytes),
    'mime'             => $mime,
]);
