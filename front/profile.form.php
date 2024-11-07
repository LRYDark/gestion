<?php
define('GLPI_ROOT', '../../..');
include(GLPI_ROOT . "/inc/includes.php");

Session::checkRight("profile", "gestion");

$prof = new PluginGestionProfile();

//Save profile
if (isset ($_POST['update'])) {
   $prof->update($_POST);
   Html::back();
}

?>