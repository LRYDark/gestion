<?php
/**
 * Enregistrement des préférences personnelles du plugin Gestion
 * (onglet « Boutons flottants » des Préférences GLPI).
 */

Session::checkLoginUser();

// GLPI 12 : la protection CSRF est assurée par le noyau (en-têtes Sec-Fetch-Site / Origin), sans jeton.
if (isset($_POST['update_gestion_prefs'])) {

   if (PluginGestionUserpref::saveForUser($_POST)) {
      Session::addMessageAfterRedirect(__('Préférences enregistrées.', 'gestion'), true, INFO);
   } else {
      Session::addMessageAfterRedirect(__("Échec de l'enregistrement des préférences.", 'gestion'), true, ERROR);
   }
}

Html::back();
