<?php
/**
 * Actions de signature disponibles pour un ticket (bouton flottant du ticket).
 *
 * Relais du plugin RP : utilisé uniquement quand RP est inactif, donc limité au
 * périmètre de Gestion — signature du bon de livraison associé au ticket.
 *
 * Entrée : ticket_id
 * Sortie : { ok, ticket_id, notice, actions: [ ... ] }
 */

ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
   ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

global $DB, $CFG_GLPI;

function gestion_ticket_actions_end(array $payload, int $status = 200): void {
   while (ob_get_level() > 0) {
      ob_end_clean();
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

$ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
   gestion_ticket_actions_end(['ok' => false, 'error' => 'missing_ticket_id'], 422);
}

$ticket = new Ticket();
if (!$ticket->getFromDB($ticket_id) || !$ticket->canViewItem()) {
   gestion_ticket_actions_end(['ok' => false, 'error' => 'forbidden'], 403);
}

$webdir      = defined('PLUGIN_GESTION_WEBDIR') ? PLUGIN_GESTION_WEBDIR : Plugin::getWebDir('gestion');
$can_sign_bl = Session::haveRight('plugin_gestion_survey', READ);

$actions = [];
$notice  = '';

if ($can_sign_bl && $DB->tableExists('glpi_plugin_gestion_surveys')) {
   $rows = $DB->request([
      'SELECT' => ['id', 'bl', 'signed'],
      'FROM'   => 'glpi_plugin_gestion_surveys',
      'WHERE'  => ['tickets_id' => $ticket_id],
      'ORDER'  => ['signed ASC', 'id DESC'],
      'LIMIT'  => 10,
   ]);

   $signed_count = 0;
   foreach ($rows as $row) {
      if ((int)$row['signed'] === 1) {
         $signed_count++;
         continue;
      }
      $actions[] = [
         'key'     => 'bl',
         'label'   => __('Signer le bon de livraison', 'gestion'),
         'hint'    => (string)$row['bl'],
         'icon'    => 'ti ti-signature',
         'mode'    => 'gestion',
         'bl_id'   => (int)$row['id'],
         'primary' => count($actions) === 0,
      ];
   }

   if (count($actions) === 0 && $signed_count > 0) {
      $notice = ($signed_count === 1)
         ? __('Le bon de livraison de ce ticket est déjà signé.', 'gestion')
         : sprintf(__('Les %d bons de livraison de ce ticket sont déjà signés.', 'gestion'), $signed_count);
   }
}

gestion_ticket_actions_end([
   'ok'             => true,
   'ticket_id'      => $ticket_id,
   'ticket_name'    => (string)$ticket->fields['name'],
   'notice'         => $notice,
   'gestion_webdir' => $webdir,
   'actions'        => $actions,
]);
