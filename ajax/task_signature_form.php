<?php
include('../../../inc/includes.php');

Html::header_nocache();
Session::checkLoginUser();

global $DB;

$ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
$mode      = trim((string)($_POST['mode'] ?? $_GET['mode'] ?? 'bl'));
$bl_id     = (int)($_POST['bl_id'] ?? $_GET['bl_id'] ?? 0);

if ($ticket_id <= 0) {
   echo "<div class='alert alert-danger'>Ticket invalide.</div>";
   exit;
}

$config = PluginGestionConfig::getInstance();
$authorized = in_array((int)Session::getLoginUserID(), $config->TaskSignatureUsers(), true);
if (!$authorized) {
   echo "<div class='alert alert-danger'>Accès refusé.</div>";
   exit;
}

// Validation BL si nécessaire
if ($mode !== 'rp') {
   if ($bl_id <= 0) {
      echo "<div class='alert alert-danger'>BL invalide.</div>";
      exit;
   }
   $bl_row = $DB->request([
      'FROM'   => 'glpi_plugin_gestion_surveys',
      'WHERE'  => [
         'id'         => $bl_id,
         'tickets_id' => $ticket_id,
         'signed'     => 0
      ],
      'LIMIT' => 1
   ])->current();
   if (!$bl_row) {
      echo "<div class='alert alert-warning'>BL introuvable ou déjà signé.</div>";
      exit;
   }
}

if ($mode === 'rp' || $mode === 'both') {
   if (!Plugin::isPluginActive('rp') || !class_exists('PluginRpCri')) {
      echo "<div class='alert alert-danger'>Le plugin RP n'est pas disponible.</div>";
      exit;
   }
}

switch ($mode) {
   case 'rp':
      $_POST['modal'] = 'form_rapport';
      $rp = new PluginRpCri();
      $rp->showForm($ticket_id, ['modal' => 'form_rapport']);
      break;

   case 'both':
      $cri = new PluginGestionCri();
      $cri->showCombinedForm($ticket_id, $bl_id, []);
      break;

   case 'bl':
   default:
      $_POST['modal'] = $bl_id;
      $cri = new PluginGestionCri();
      $cri->showForm($ticket_id, ['modal' => $bl_id, 'root_modal' => 'ticket-form']);
      break;
}
