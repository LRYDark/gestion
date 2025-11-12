<?php
// plugins/gestion/ajax/ajax_ticket_info.php
// Returns ticket info (title, description, tasks) for quick ticket signature on tablet

define('GLPI_ROOT', realpath(__DIR__ . '/../../..'));
define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
header('Content-Type: application/json; charset=UTF-8');

global $DB, $CFG_GLPI;
$config  = PluginGestionConfig::getInstance();

function tjson(int $status, array $payload) {
   if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

if (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1) {
   tjson(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

// RP plugin is required for ticket report
if (!Plugin::isPluginActive('rp') || !class_exists('PluginRpConfig')) {
   tjson(200, ['ok' => false, 'error' => 'rp_plugin_missing']);
}

$device_id = trim((string)($_GET['device_id'] ?? $_POST['device_id'] ?? ''));
$token     = trim((string)($_GET['token']     ?? $_POST['token']     ?? ''));
$id_param  = trim((string)($_GET['id']        ?? $_POST['id']        ?? ''));
$ticket_id = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? $id_param);

if ($device_id === '' || $token === '') {
   tjson(422, ['ok' => false, 'error' => 'missing_device']);
}
if ($ticket_id <= 0) {
   tjson(422, ['ok' => false, 'error' => 'missing_ticket_id']);
}

// Validate device
$did = $DB->escape($device_id);
$tok = $DB->escape($token);
$sql_dev = "SELECT id FROM `glpi_plugin_gestion_signaturedevices` WHERE `is_active` = 1 AND `device_id` = '$did' AND `device_token` = '$tok' LIMIT 1";
$res_dev = $DB->query($sql_dev);
if (!$res_dev || $DB->numrows($res_dev) !== 1) {
   tjson(403, ['ok' => false, 'error' => 'invalid_device']);
}

// Ticket + entity
$sql_ticket = "SELECT t.id, t.name AS title, t.content AS description, t.entities_id, e.name AS entity_name
               FROM glpi_tickets t
               LEFT JOIN glpi_entities e ON e.id = t.entities_id
               WHERE t.id = ".(int)$ticket_id." LIMIT 1";
$res_ticket = $DB->query($sql_ticket);
if (!$res_ticket || $DB->numrows($res_ticket) === 0) {
   tjson(404, ['ok' => false, 'error' => 'ticket_not_found']);
}
$trow = $DB->fetchassoc($res_ticket);

// Respect RP config for public tasks only if configured
$use_publictask = 0;
try {
   $rp_config = PluginRpConfig::getInstance();
   if (isset($rp_config->fields['use_publictask'])) {
      $use_publictask = (int)$rp_config->fields['use_publictask'];
   }
} catch (Throwable $e) {}
$is_private_sql = ($use_publictask === 1) ? " AND is_private = 0" : '';

// Tasks list
$tasks = [];
$sql_tasks = "SELECT tt.id, tt.content, tt.date, u.name AS author, tt.actiontime AS time
              FROM glpi_tickettasks tt
              LEFT JOIN glpi_users u ON u.id = tt.users_id
              WHERE tt.tickets_id = ".(int)$ticket_id.$is_private_sql.
              " ORDER BY tt.id ASC";
$res_tasks = $DB->query($sql_tasks);
if ($res_tasks) {
   while ($row = $DB->fetchassoc($res_tasks)) {
      $tasks[] = [
         'id'      => (int)$row['id'],
         'content' => (string)($row['content'] ?? ''),
         'date'    => (string)($row['date'] ?? ''),
         'author'  => (string)($row['author'] ?? ''),
         'time'    => (int)($row['time'] ?? 0)
      ];
   }
}

// Try to get a reasonable client email to pre-fill
$client_email = '';
try {
   $sql_email = "SELECT GROUP_CONCAT(email SEPARATOR ',') AS emails FROM (
                   SELECT DISTINCT u.email AS email
                   FROM glpi_useremails u
                   JOIN glpi_users us ON us.id = u.users_id
                   JOIN glpi_tickets t ON t.id = ".(int)$ticket_id." 
                   WHERE us.entities_id = t.entities_id AND u.email IS NOT NULL AND u.email <> '' AND us.is_deleted = 0
                   UNION
                   SELECT DISTINCT e.email
                   FROM glpi_entities e
                   JOIN glpi_tickets t ON t.entities_id = e.id
                   WHERE t.id = ".(int)$ticket_id." AND e.email IS NOT NULL AND e.email <> ''
                ) AS mails";
   $rem = $DB->query($sql_email);
   if ($rem && $DB->numrows($rem) > 0) {
      $em = $DB->fetchassoc($rem);
      if (!empty($em['emails'])) {
         $parts = explode(',', $em['emails']);
         $first = trim((string)($parts[0] ?? ''));
         if ($first !== '') $client_email = $first;
      }
   }
} catch (Throwable $e) {}

tjson(200, [
   'ok'                 => true,
   'ticket_id'          => (int)$trow['id'],
   'ticket_title'       => (string)($trow['title'] ?? ''),
   'ticket_description' => (string)($trow['description'] ?? ''),
   'entity_name'        => (string)($trow['entity_name'] ?? ''),
   'client_email'       => $client_email,
   'tasks'              => $tasks
]);

