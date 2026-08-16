<?php
/**
 * Résolution des recherches et des scans du bouton flottant (plugin Gestion).
 *
 * Ce service est le relais du plugin RP : il n'est utilisé que lorsque RP est
 * inactif, et se limite donc au périmètre de Gestion — bons de livraison et
 * tickets associés.
 *
 * Entrées : q=<texte scanné ou saisi>, ou caps=1
 * Sortie JSON : { ok, results: [ { type, title, subtitle, badge, actions[] } ] }
 */

// Le chargement des plugins peut émettre du HTML : on le neutralise pour
// garantir une réponse JSON valide.
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
   ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

global $DB, $CFG_GLPI;

$rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
$webdir  = defined('PLUGIN_GESTION_WEBDIR') ? PLUGIN_GESTION_WEBDIR : Plugin::getWebDir('gestion');

function gestion_scan_end(array $payload, int $status = 200): void {
   while (ob_get_level() > 0) {
      ob_end_clean();
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

$can_read_bl     = Session::haveRight('plugin_gestion_survey', READ);
$can_read_ticket = Session::haveRight('ticket', READ)
   || Session::haveRight('ticket', Ticket::READMY)
   || Session::haveRight('ticket', Ticket::READGROUP)
   || Session::haveRight('ticket', Ticket::READASSIGN);

// Capacités : permet à l'interface d'adapter ses libellés
if (!empty($_REQUEST['caps'])) {
   gestion_scan_end([
      'ok'   => true,
      'caps' => ['bl' => $can_read_bl, 'ticket' => $can_read_ticket],
   ]);
}

$q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
if ($q === '') {
   gestion_scan_end(['ok' => true, 'results' => []]);
}

// QR code contenant une URL du même GLPI : on suit le lien
if (preg_match('#^https?://#i', $q)) {
   $base_host = parse_url((string)($CFG_GLPI['url_base'] ?? ''), PHP_URL_HOST);
   $scan_host = parse_url($q, PHP_URL_HOST);
   if ($base_host !== null && $scan_host !== null && strcasecmp($base_host, $scan_host) === 0) {
      gestion_scan_end(['ok' => true, 'redirect' => $q]);
   }
   gestion_scan_end(['ok' => false, 'error' => __('Ce QR code ne correspond pas à ce GLPI.', 'gestion')]);
}

$results = [];

/**
 * Ligne de résultat pour un bon de livraison.
 */
function gestion_scan_bl_result(array $row, string $webdir, string $rootdoc): array {
   $survey_id  = (int)$row['id'];
   $signed     = (int)($row['signed'] ?? 0);
   $tickets_id = (int)($row['tickets_id'] ?? 0);

   $actions = [[
      'label'   => $signed === 1 ? __('Voir le BL signé', 'gestion') : __('Signer le BL', 'gestion'),
      'url'     => $webdir . '/front/survey.form.php?id=' . $survey_id,
      'icon'    => $signed === 1 ? 'ti ti-eye' : 'ti ti-signature',
      'primary' => $signed !== 1,
   ]];

   $subtitle = __('Aucun ticket associé', 'gestion');
   if ($tickets_id > 0) {
      $ticket = new Ticket();
      if ($ticket->getFromDB($tickets_id) && $ticket->canViewItem()) {
         $subtitle  = '#' . sprintf('%07d', $tickets_id) . ' — ' . (string)$ticket->fields['name'];
         $actions[] = [
            'label'   => __('Ouvrir le ticket', 'gestion'),
            'url'     => $rootdoc . '/front/ticket.form.php?id=' . $tickets_id,
            'icon'    => 'ti ti-ticket',
            'primary' => $signed === 1,
         ];
      } else {
         $subtitle = '#' . sprintf('%07d', $tickets_id);
      }
   }

   return [
      'type'     => 'bl',
      'title'    => (string)($row['bl'] ?? ''),
      'subtitle' => $subtitle,
      'badge'    => $signed === 1 ? ['label' => __('Signé', 'gestion'), 'style' => 'ok']
                                  : ['label' => __('À signer', 'gestion'), 'style' => 'warn'],
      'actions'  => $actions,
   ];
}

/**
 * Ligne de résultat pour un ticket (+ son BL éventuel).
 */
function gestion_scan_ticket_result(Ticket $ticket, string $webdir, string $rootdoc, bool $can_read_bl): array {
   global $DB;

   $ticket_id = (int)$ticket->fields['id'];
   $actions   = [[
      'label'   => __('Ouvrir le ticket', 'gestion'),
      'url'     => $rootdoc . '/front/ticket.form.php?id=' . $ticket_id,
      'icon'    => 'ti ti-ticket',
      'primary' => true,
   ]];

   $badge    = null;
   $subtitle = Dropdown::getDropdownName('glpi_entities', (int)$ticket->fields['entities_id']);

   if ($can_read_bl && $DB->tableExists('glpi_plugin_gestion_surveys')) {
      $bl = $DB->request([
         'SELECT' => ['id', 'bl', 'signed'],
         'FROM'   => 'glpi_plugin_gestion_surveys',
         'WHERE'  => ['tickets_id' => $ticket_id],
         'ORDER'  => ['signed ASC', 'id DESC'],
         'LIMIT'  => 1,
      ])->current();
      if ($bl) {
         $signed    = (int)$bl['signed'];
         $subtitle .= ' — ' . (string)$bl['bl'];
         $badge     = $signed === 1 ? ['label' => __('BL signé', 'gestion'), 'style' => 'ok']
                                    : ['label' => __('BL à signer', 'gestion'), 'style' => 'warn'];
         $actions[] = [
            'label'   => $signed === 1 ? __('Voir le BL signé', 'gestion') : __('Signer le BL', 'gestion'),
            'url'     => $webdir . '/front/survey.form.php?id=' . (int)$bl['id'],
            'icon'    => $signed === 1 ? 'ti ti-eye' : 'ti ti-signature',
            'primary' => false,
         ];
      }
   }

   return [
      'type'     => 'ticket',
      'title'    => '#' . sprintf('%07d', $ticket_id) . ' — ' . (string)$ticket->fields['name'],
      'subtitle' => $subtitle,
      'badge'    => $badge,
      'actions'  => $actions,
   ];
}

// ---- 1) Numéro de BL ----
$bl_number = '';
if (preg_match('/\bB[LC]\s?0*(\d{3,8})\b/i', $q, $m)) {
   $bl_number = 'BL' . $m[1];
   if (function_exists('pluginGestionBlNumber')) {
      $normalized = pluginGestionBlNumber($bl_number);
      if ($normalized !== '') {
         $bl_number = $normalized;
      }
   }
}

if ($bl_number !== '' && $can_read_bl) {
   $rows = $DB->request([
      'SELECT' => ['id', 'bl', 'signed', 'tickets_id'],
      'FROM'   => 'glpi_plugin_gestion_surveys',
      'WHERE'  => [
         'OR' => [
            ['bl_number' => $bl_number],
            ['bl'        => ['LIKE', $bl_number . '%']],
         ],
      ],
      'ORDER'  => ['signed ASC', 'id DESC'],
      'LIMIT'  => 10,
   ]);
   foreach ($rows as $row) {
      $results[] = gestion_scan_bl_result($row, $webdir, $rootdoc);
   }
}

// ---- 2) Numéro de ticket (saisi seul ou repéré dans un texte OCR) ----
$ticket_id = 0;
if (preg_match('/^#?\s*0*(\d{1,10})$/', $q, $m)) {
   $ticket_id = (int)$m[1];
} elseif (preg_match('/\bTICKET\s*(?:N\s*[°ºo]?)?\s*[:#-]?\s*0*(\d{2,10})\b/iu', $q, $m)) {
   $ticket_id = (int)$m[1];
}

if ($can_read_ticket && $ticket_id > 0) {
   $ticket = new Ticket();
   if ($ticket->getFromDB($ticket_id) && $ticket->canViewItem()) {
      $results[] = gestion_scan_ticket_result($ticket, $webdir, $rootdoc, $can_read_bl);
   }
}

// ---- 3) Recherche libre sur le titre du ticket ----
if ($can_read_ticket && count($results) === 0 && mb_strlen($q) >= 3) {
   $found = $DB->request([
      'SELECT' => ['id'],
      'FROM'   => 'glpi_tickets',
      'WHERE'  => [
         'is_deleted' => 0,
         'name'       => ['LIKE', '%' . $q . '%'],
      ] + getEntitiesRestrictCriteria('glpi_tickets'),
      'ORDER'  => ['id DESC'],
      'LIMIT'  => 8,
   ]);
   foreach ($found as $row) {
      $ticket = new Ticket();
      if ($ticket->getFromDB((int)$row['id']) && $ticket->canViewItem()) {
         $results[] = gestion_scan_ticket_result($ticket, $webdir, $rootdoc, $can_read_bl);
      }
   }
}

gestion_scan_end(['ok' => true, 'results' => $results]);
