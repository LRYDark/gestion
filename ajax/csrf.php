<?php

/**
 * Jeton CSRF frais pour la file d'attente des signatures.
 *
 * Pourquoi ce point d'entrée alors que GLPI pose déjà un jeton dans
 * `<meta property="glpi:csrf_token">` de chaque page ?
 *
 * Parce que la file rejoue depuis une page qui peut être ouverte depuis des
 * heures — la page mobile du technicien, restée à l'écran toute l'intervention.
 * Le jeton de son `<meta>` peut alors avoir été évincé : GLPI n'en conserve que
 * 500 par session, en file (cf. Session::cleanCSRFTokens). Un rejeu partirait
 * en 403 sans qu'on sache si c'est le jeton ou les droits.
 *
 * En GET : méthode sans corps, donc exemptée du contrôle CSRF du noyau — c'est
 * la seule façon d'obtenir un jeton sans déjà en avoir un. Ça n'ouvre rien : la
 * réponse n'est lisible que par une page de MÊME ORIGINE, exactement comme la
 * balise `<meta>` que GLPI sert déjà dans chaque page.
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
header('Cache-Control: no-store');

/*
 * Session obligatoire. Signalée AJAX par l'appelant, une session expirée
 * ressort en 401 JSON plutôt qu'en redirection vers la page de connexion : la
 * file sait alors qu'il faut se reconnecter, et surtout qu'elle ne doit RIEN
 * supprimer.
 */
Session::checkLoginUser();

echo json_encode([
   'ok'    => true,
   // `standalone` : un jeton à part, qui ne remplace pas celui de la page en
   // cours. Sans cela, le formulaire déjà affiché verrait le sien changer sous
   // ses pieds.
   'token' => Session::getNewCSRFToken(true),
], JSON_UNESCAPED_SLASHES);
