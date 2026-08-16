<?php
/**
 * Enregistrement des préférences personnelles du plugin Gestion
 * (onglet « Gestion » des Préférences GLPI).
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

/**
 * Le formulaire est rendu dans un onglet chargé en AJAX et porte un jeton
 * autonome dédié, validé sans être consommé (l'onglet n'est pas re-rendu après
 * l'enregistrement, il doit rester utilisable pour un second enregistrement).
 */
function pluginGestionUserprefCheckCSRF(array $data): void {
   if (!empty($data['plugin_gestion_userpref_csrf_token'])) {
      Session::checkCSRF([
         '_glpi_csrf_token' => (string)$data['plugin_gestion_userpref_csrf_token']
      ], true);
      return;
   }

   Session::checkCSRF($data, true);
}

if (isset($_POST['update_gestion_prefs'])) {
   pluginGestionUserprefCheckCSRF($_POST);

   if (PluginGestionUserpref::saveForUser($_POST)) {
      Session::addMessageAfterRedirect(__('Préférences enregistrées.', 'gestion'), true, INFO);
   } else {
      Session::addMessageAfterRedirect(__("Échec de l'enregistrement des préférences.", 'gestion'), true, ERROR);
   }
}

Html::back();
