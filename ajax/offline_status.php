<?php

/**
 * État des signatures mises en file par le navigateur.
 *
 * Interrogé AVANT chaque rejeu. C'est ce qui rend la file auto-réparante : une
 * signature dont l'envoi avait en réalité abouti — seule la réponse s'étant
 * perdue sur un réseau qui passe mal — est vue « done » ici et retirée de la
 * file, au lieu d'être rejouée et de produire un second document.
 *
 * En GET : la méthode est sans corps, donc hors du contrôle CSRF du noyau
 * (cf. CheckCsrfListener). Rien à protéger de plus — la réponse ne dit que ce
 * que l'utilisateur connecté a lui-même mis en file.
 */

/*
 * Le chargement des plugins peut émettre du HTML : on le neutralise pour
 * garantir une réponse JSON valide.
 *
 * On ne redescend JAMAIS sous le niveau de tampon d'entrée.
 *
 * GLPI 11 enveloppe chaque fichier « legacy » dans son propre `ob_start()`
 * (LegacyFileLoadController). Le motif hérité `while (ob_get_level() > 0)` le
 * fermait avec les autres : le contrôleur se retrouvait sans sortie à récupérer,
 * le signalait en avertissement dans php-errors.log, et basculait sur une
 * réponse SANS EN-TÊTES — où le `Content-Type: application/json` posé plus bas
 * était purement et simplement perdu.
 */
$rp_ob_base = ob_get_level();
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > $rp_ob_base) {
   ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');

/*
 * Session obligatoire. Signalée AJAX par l'appelant, une session expirée
 * ressort en 401 JSON plutôt qu'en redirection vers la page de connexion : la
 * file sait alors qu'il faut se reconnecter, et surtout qu'elle ne doit RIEN
 * supprimer.
 */
Session::checkLoginUser();

$uids = $_GET['uids'] ?? $_POST['uids'] ?? [];
if (is_string($uids)) {
   $uids = array_filter(array_map('trim', explode(',', $uids)));
}
if (!is_array($uids)) {
   $uids = [];
}

// Plafond : la file du navigateur est courte par construction, une demande
// portant sur des milliers d'identifiants ne viendrait pas d'elle.
$uids = array_slice($uids, 0, 50);

$states = [];
if (class_exists('PluginGestionOfflinequeue')) {
   $states = PluginGestionOfflinequeue::statesFor($uids);
}

echo json_encode([
   'ok'     => true,
   'plugin' => 'gestion',
   'states' => $states,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
