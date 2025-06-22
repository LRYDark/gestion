<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 gestion plugin for GLPI
 Copyright (C) 2016-2022 by the gestion Development Team.

 https://github.com/pluginsglpi/gestion
 -------------------------------------------------------------------------

 LICENSE

 This file is part of gestion.

 gestion is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 gestion is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with gestion. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!isset($_GET["id"])) {
   $_GET["id"] = "";
}

$config = new PluginGestionConfig();
$survey = new PluginGestionSurvey();
$doc = new Document();

function message($msg, $msgtype){
    Session::addMessageAfterRedirect(
        __($msg, 'gestion'),
        true,
        $msgtype
    );
}

if (isset($_POST["add"])) {
   $survey->check(-1, CREATE, $_POST);
 
   $valid = false;
   $NewDoc = 0;
   $tickets_id = $_POST['tickets_id'];
   $entities_id = $_POST['entities_id'];
   $pdf_filename = $_POST['pdf_filename'];
   $pdf_folder = $_POST['pdf_folder'];
   $search_pdf = $_POST['search_pdf'];
   $pdf_save = $_POST['pdf_save'];

   $pdf_filename = $DB->escape($pdf_filename); // sécurise la requête SQL

   $query = "SELECT bl, id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$pdf_filename' LIMIT 1";
   $result = $DB->query($query);

   if ($result && $result->num_rows > 0) {
      $row = $DB->fetchassoc($result);
      message('Document déjà ajouté : <a href="survey.form.php?id='. $row['id'] .'">Gestion - ID '.  $row['id'] .'</a>.', INFO);
   }else{
      if ($pdf_save == 'Local'){
         $valid = true;
         $input = ['name'        => addslashes(str_replace("?", "°", $pdf_filename)),
                  'filename'    => addslashes($pdf_filename),
                  'filepath'    => addslashes($search_pdf),
                  'mime'        => 'application/pdf',
                  'users_id'    => Session::getLoginUserID(),
                  'entities_id' => 0,
                  'tickets_id'  => 0,
                  'is_recursive'=> 1];

         $NewDoc = $doc->add($input);
         $doc_url = 'document.send.php?docid='.$NewDoc;
      }
      if ($pdf_save == 'SharePoint'){
         $valid = true;
      }
   }
         
   if ($valid == true){
      $query= "INSERT INTO `glpi_plugin_gestion_surveys` (`tickets_id`, `entities_id`, `url_bl`, `bl`, `doc_id`, `doc_url`, `save`) VALUES ($tickets_id, $entities_id, '$pdf_folder', '$pdf_filename', $NewDoc, '$doc_url', '$pdf_save');";
      if($DB->doQuery($query)){
         $idsurvey = $DB->query("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$pdf_filename'")->fetch_object();
         $idsurvey = $idsurvey->id;
         message('Document ajouté : <a href="survey.form.php?id='.$idsurvey.'">Gestion - ID '.$idsurvey.'</a>.', INFO);
      }else{
         message("Erreur de l'ajout du document", ERROR);
      }
   }

   Html::back();

}else if (isset($_POST["purge"])) {
   $survey->check($_POST['id'], PURGE);
   $survey->delete($_POST);
   $survey->redirectToList();

} else if (isset($_POST["update"])) {
   $survey->check($_POST['id'], UPDATE);
   $survey->update($_POST);

   Html::back();

} else {
   $survey->checkGlobal(READ);
   Html::header(PluginGestionSurvey::getTypeName(2), '', "management", "plugingestionmenu", "gestion");
   $survey->display(['id' => $_GET['id']]);
   Html::footer();
}