<?php
include('../../../inc/includes.php');

Html::header_nocache();
Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');

global $DB;

function json_end($status, $payload) {
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

$ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
$state     = (int)($_POST['state'] ?? $_GET['state'] ?? -1);

if ($ticket_id <= 0) {
   json_end(400, ['ok' => false, 'error' => 'missing_ticket_id']);
}

$config = PluginGestionConfig::getInstance();
$authorized_users = $config->TaskSignatureUsers();
$trigger_states   = $config->TaskSignatureTriggerStates();

$authorized = in_array((int)Session::getLoginUserID(), $authorized_users, true);
$state_ok   = in_array((int)$state, $trigger_states, true);

// Récupérer les BL non signés liés au ticket
$bls = [];
foreach ($DB->request([
   'SELECT' => ['id', 'bl'],
   'FROM'   => 'glpi_plugin_gestion_surveys',
   'WHERE'  => ['tickets_id' => $ticket_id, 'signed' => 0],
   'ORDER'  => ['id DESC'],
]) as $row) {
   $bls[] = [
      'id'    => (int)$row['id'],
      'label' => (string)($row['bl'] ?? ('BL#'.$row['id']))
   ];
}

$rp_active = Plugin::isPluginActive('rp') && class_exists('PluginRpCri');
$show      = $authorized && $state_ok && count($bls) > 0;

json_end(200, [
   'ok'            => true,
   'ticket_id'     => $ticket_id,
   'show'          => $show,
   'authorized'    => $authorized,
   'state_ok'      => $state_ok,
   'rp_active'     => $rp_active,
   'bls'           => $bls,
   'default_mode'  => $rp_active ? 'both' : 'bl',
   'default_bl_id' => count($bls) ? (int)$bls[0]['id'] : 0
]);
