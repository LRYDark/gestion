<?php
/**
 * Reprise des bons de livraison pris par un AUTRE ticket.
 *
 * L'association automatique (PluginGestionTicket::autoAssociateBl) refuse de
 * toucher un bon deja rattache a un ticket : c'est ce qui garantit l'absence de
 * doublon, mais cela bloque le cas ou un ticket cree par megarde a capte les
 * bons avant le vrai. Cet endpoint est la sortie manuelle de cette situation :
 * le technicien voit, depuis le bouton flottant du ticket, quels bons cites par
 * son ticket sont ailleurs, et les rapatrie.
 *
 * GET  ?ticket_id=N            -> liste des bons reprenables (lecture seule)
 * POST ticket_id, ids[] (opt.) -> bascule ; sans ids, tous les bons reprenables
 *
 * Entree : ticket_id, ids[]
 * Sortie : { ok, ticket_id, claimable: [...], moved: [...], skipped: [...] }
 */

ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
   ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

function gestion_claim_bl_end(array $payload, int $status = 200): void {
   while (ob_get_level() > 0) {
      ob_end_clean();
   }
   http_response_code($status);
   if (class_exists('PluginGestionLogger')) {
      PluginGestionLogger::apiResponse($status, $payload);
   }
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

$is_write  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);

if ($ticket_id <= 0) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'missing_ticket_id'], 422);
}
if (!class_exists('PluginGestionTicket')) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'plugin_unavailable'], 500);
}

/*
 * Deux droits, pas un.
 *
 * Deplacer un bon le retire d'un ticket et l'ajoute a un autre : c'est une
 * ecriture sur les bons (plugin_gestion_survey UPDATE) ET une modification de
 * ce que porte CE ticket. On exige donc aussi de pouvoir le modifier, sinon un
 * simple lecteur reorganiserait les bons d'un ticket qui ne lui appartient pas.
 */
$ticket = new Ticket();
if (!$ticket->getFromDB($ticket_id) || !$ticket->canViewItem()) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'forbidden'], 403);
}
if (!Session::haveRight('plugin_gestion_survey', READ)) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'forbidden'], 403);
}

if (!$is_write) {
   gestion_claim_bl_end([
      'ok'        => true,
      'ticket_id' => $ticket_id,
      'claimable' => PluginGestionTicket::findClaimableBl($ticket_id),
   ]);
}

/*
 * Le jeton est cherche dans l'EN-TETE avant le corps.
 *
 * Sous GLPI 11, `CheckCsrfListener` controle le jeton avant que ce script ne
 * s'execute. Pour une requete signalee AJAX il lit `X-Glpi-Csrf-Token` et le
 * CONSERVE ; sinon il lit le corps et le CONSOMME — et le controle ci-dessous
 * echouait alors sur un jeton que GLPI venait lui-meme de retirer. Lire les
 * deux sources rend ce fichier indifferent a la façon dont il est appele.
 *
 * `validateCSRF` et non `checkCSRF` : ce dernier leve une exception que GLPI
 * rend en PAGE HTML « Accès refusé », la ou le panneau attend du JSON — il
 * afficherait « erreur de communication » au lieu de la vraie cause.
 */
$csrf_token = (string)($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? ($_POST['_glpi_csrf_token'] ?? ''));
if (!Session::validateCSRF(['_glpi_csrf_token' => $csrf_token], true)) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'invalid_csrf'], 403);
}

if (!Session::haveRight('plugin_gestion_survey', UPDATE) || !$ticket->canUpdateItem()) {
   gestion_claim_bl_end(['ok' => false, 'error' => 'forbidden'], 403);
}

$ids = [];
foreach ((array)($_POST['ids'] ?? []) as $id) {
   $id = (int)$id;
   if ($id > 0) {
      $ids[] = $id;
   }
}

try {
   $done = PluginGestionTicket::claimBl($ticket_id, $ids);
} catch (Throwable $e) {
   PluginGestionLogger::error('claim-bl', 'Ticket #' . $ticket_id . ' : ' . $e->getMessage());
   gestion_claim_bl_end(['ok' => false, 'error' => 'claim_failed'], 500);
}

gestion_claim_bl_end([
   'ok'        => true,
   'ticket_id' => $ticket_id,
   'moved'     => $done['moved'],
   'skipped'   => $done['skipped'],
   // Ce qui RESTE ailleurs apres la bascule : le panneau se redessine dessus
   // plutot que de deviner, et un bon signe ailleurs y reste donc visible.
   'claimable' => PluginGestionTicket::findClaimableBl($ticket_id),
]);
