<?php
/**
 * Polled by tablet page. Returns the latest pending request for the device if any.
 * Location: plugins/gestion/ajax/device_poll.php
 */

define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

$GLPI_ROOT = $_SERVER['DOCUMENT_ROOT'].$_SERVER['CONTEXT_PREFIX'];
require $GLPI_ROOT . '/inc/includes.php';

header('Content-Type: application/json; charset=UTF-8');

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

// If missing, go to login
if ($config->fields['RemoteSignatureOn'] == 0) {
   exit;
}

function jexit($arr) { echo json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function bad($msg,$extra=[]) { jexit(array_merge(['ok'=>false,'error'=>$msg],$extra)); }

$device_id = isset($_REQUEST['device_id']) ? trim($_REQUEST['device_id']) : '';
$token     = isset($_REQUEST['token'])     ? trim($_REQUEST['token'])     : '';

if ($device_id === '' || $token === '') {
   bad('Missing device_id or token', ['debug'=>[
      'method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI',
      'has_device'=>$device_id !== '',
      'has_token'=>$token !== ''
   ]]);
}

// Check device row
$did = $DB->escape($device_id);
$tok = $DB->escape($token);
$dev_sql = "SELECT id FROM `glpi_plugin_gestion_signaturedevices`
            WHERE is_active = 1 AND device_id = '$did' AND device_token = '$tok' LIMIT 1";
$dev_res = $DB->doQuery($dev_sql);

if (!$dev_res || $DB->numrows($dev_res) !== 1) {
   bad('Invalid device or token');
}

// MODIFIÉ : Look for pending request for that device (latest one) - AJOUT DES PARAMÈTRES
$sql = "SELECT id, tickets_id, status, parameters, date_creation
        FROM `glpi_plugin_gestion_remote_sign_requests`
        WHERE device_id = '$did' AND device_token = '$tok' AND status = 'pending'
        ORDER BY id DESC LIMIT 1";
$res = $DB->doQuery($sql);
if ($res && $DB->numrows($res) === 1) {
   $row = $DB->fetchassoc($res);
   
   // NOUVEAU : Décoder les paramètres JSON si présents
   $parameters = null;
   if (!empty($row['parameters'])) {
      $decoded = json_decode($row['parameters'], true);
      if (json_last_error() === JSON_ERROR_NONE) {
         $parameters = $decoded;
      }
   }
   
   jexit([
      'ok'=>true,
      'pending'=>true,
      'request_id'=>(int)$row['id'],
      'ticket_id'=>(int)$row['tickets_id'],
      'status'=>$row['status'],
      'parameters'=>$parameters,           // NOUVEAU
      'date_creation'=>$row['date_creation'] // NOUVEAU
   ]);
}

jexit(['ok'=>true,'pending'=>false]);