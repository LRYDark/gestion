<?php
/*
 * Endpoint d'association automatique des BL a l'OUVERTURE d'un ticket.
 * Appele en arriere-plan (fire-and-forget) par scripts_gestion.js apres le rendu
 * de la fiche ticket => zero ralentissement de l'affichage.
 * Idempotent : le dedoublonnage par bl_number garantit qu'un BL n'est jamais
 * associe/insere deux fois.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');

$ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);

if ($ticket_id <= 0 || !class_exists('PluginGestionTicket')) {
   echo json_encode(['ok' => false]);
   exit;
}

// L'utilisateur doit pouvoir lire le ticket (il est de toute facon sur la fiche).
$ticket = new Ticket();
if (!$ticket->can($ticket_id, READ)) {
   echo json_encode(['ok' => false, 'error' => 'forbidden']);
   exit;
}

try {
   PluginGestionTicket::autoAssociateBl($ticket_id);
} catch (Throwable $e) {
   // Silencieux : l'association ne doit jamais perturber l'affichage du ticket.
}

echo json_encode(['ok' => true]);
