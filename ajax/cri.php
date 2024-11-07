<?php
include('../../../inc/includes.php');

Html::header_nocache();
Session::checkLoginUser();


switch ($_POST['action']) {//action bouton généré PDF formulaire ticket
   case 'showCriForm' :
      $PluginGestionCri = new PluginGestionCri();
      $params                  = $_POST["params"];
      $PluginGestionCri->showForm($params["job"], ['modal' => $_POST["modal"]]);
      break;
}
