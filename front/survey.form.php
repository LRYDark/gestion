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
require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';

global $DB, $CFG_GLPI;

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

function pluginGestionSurveyCheckCSRF(array $data): void {
    if (!empty($data['plugin_gestion_survey_csrf_token'])) {
        Session::checkCSRF(['_glpi_csrf_token' => (string)$data['plugin_gestion_survey_csrf_token']], true);
        return;
    }
    Session::checkCSRF($data, true);
}

if (isset($_POST["add"])) {
   pluginGestionSurveyCheckCSRF($_POST);
   $valid = false;
   $NewDoc = 0;
   $tickets_id = (int)($_POST['tickets_id'] ?? 0);
   $entities_id = (int)($_POST['entities_id'] ?? 0);
   $pdf_filename = trim((string)($_POST['pdf_filename'] ?? ''));
   $pdf_folder = trim((string)($_POST['pdf_folder'] ?? ''));
   $search_pdf = trim((string)($_POST['search_pdf'] ?? ''));
   $pdf_save = trim((string)($_POST['pdf_save'] ?? ''));
   $pdf_signed = (int)($_POST['pdf_signed'] ?? 0);
   $tracker = null;
   $relatedInvoiceToBL = null;

   if ($pdf_save == 'Sage'){
      $fields = parseDocument($search_pdf);
      $pdf_filename = $search_pdf.'_'.str_replace(' ', '_', $fields['client']);
      $tracker = $fields['tracker'];
      $relatedInvoiceToBL = $fields['relatedInvoiceToBL'] ?? null;
   }

   $pdf_filename_db = $DB->escape($pdf_filename);
   // Dedoublonnage par NUMERO de BL (insensible casse/suffixe/nom client).
   $bl_number = pluginGestionBlNumber($pdf_filename);
   if ($bl_number !== '') {
      $bl_number_db = $DB->escape($bl_number);
      $query = "SELECT bl, id, signed FROM `glpi_plugin_gestion_surveys` WHERE bl_number = '$bl_number_db' LIMIT 1";
   } else {
      $query = "SELECT bl, id, signed FROM `glpi_plugin_gestion_surveys` WHERE bl = '$pdf_filename_db' LIMIT 1";
   }
   $result = $DB->doQuery($query);

   if ($result && $result->num_rows > 0) {
      $row = $DB->fetchassoc($result);
      $etat = ((int)($row['signed'] ?? 0) === 1) ? ' (déjà signé)' : '';
      message('Document déjà existant'.$etat.' : <a href="survey.form.php?id='. $row['id'] .'">Gestion - ID '.  $row['id'] .'</a>.', WARNING);
   }else{
      if ($pdf_save == 'Local'){
         $valid = true;
         $tracker = null;
         $input = ['name'       => addslashes(str_replace("?", "°", $pdf_filename)),
                  'filename'    => addslashes($pdf_filename),
                  'filepath'    => addslashes($pdf_folder.$pdf_filename),
                  'mime'        => 'application/pdf',
                  'users_id'    => Session::getLoginUserID(),
                  'entities_id' => 0,
                  'tickets_id'  => 0,
                  'is_recursive'=> 1];

         $NewDoc = $doc->add($input);
         if ($NewDoc) {
            // GLPI 11 blackliste filepath/sha1sum dans Document::add => reecriture directe.
            pluginGestionFixDocumentFile((int)$NewDoc, $pdf_folder.$pdf_filename);
         }
         $doc_url = 'document.send.php?docid='.$NewDoc;
      }
      if ($pdf_save == 'SharePoint'){
         $valid = true;
         $doc_url = $pdf_folder;
         $tracker = null;
      }
      if ($pdf_save == 'Sage'){
         $tracker = $fields['tracker'];
         $valid = true;
         $doc_url = function_exists('plugin_gestion_build_view_pdf_url')
            ? plugin_gestion_build_view_pdf_url($search_pdf, true)
            : (rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/') . '/' . trim((string)PLUGIN_GESTION_NOTFULL_WEBDIR, '/') . "/view_pdf.php?id=" . rawurlencode($search_pdf));
      }
   }
         
   if ($valid == true){
      $doc_date_sql = ((int)$pdf_signed === 1) ? "NOW()" : "NULL";
      $relatedInvoiceSql = ($relatedInvoiceToBL !== null && $relatedInvoiceToBL !== '') ? "'".$DB->escape($relatedInvoiceToBL)."'" : "NULL";
      $trackerSql = ($tracker !== null && $tracker !== '') ? "'" . $DB->escape((string)$tracker) . "'" : "NULL";
      $blNumberSql = ($bl_number !== '') ? "'" . $DB->escape($bl_number) . "'" : "NULL";
      $query= "INSERT INTO `glpi_plugin_gestion_surveys` (`tickets_id`, `entities_id`, `tracker`, `relatedInvoiceToBL`, `url_bl`, `bl`, `bl_number`, `signed`, `doc_id`, `doc_url`, `save`, `date_creation`, `doc_date`) VALUES (" . (int)$tickets_id . ", " . (int)$entities_id . ", $trackerSql, $relatedInvoiceSql, '" . $DB->escape($pdf_folder) . "', '$pdf_filename_db', $blNumberSql, " . (int)$pdf_signed . ", " . (int)$NewDoc . ", '" . $DB->escape((string)$doc_url) . "', '" . $DB->escape($pdf_save) . "', NOW(), $doc_date_sql);";
      if($DB->doQuery($query)){
         $idsurvey = (int)$DB->insertId();
         if ($idsurvey <= 0) {
            $idsurveyObj = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$pdf_filename_db'")->fetch_object();
            $idsurvey = (int)($idsurveyObj->id ?? 0);
         }
         message('Document ajouté : <a href="survey.form.php?id='.$idsurvey.'">Gestion - ID '.$idsurvey.'</a>.', INFO);
      }else{
         message("Erreur de l'ajout du document", ERROR);
      }
   }

   Html::back();

}else if (isset($_POST["purge"])) {
   pluginGestionSurveyCheckCSRF($_POST);
   $survey->check((int)$_POST['id'], PURGE);
   $survey->delete($_POST);
   $survey->redirectToList();

} else if (isset($_POST["update"])) {
   pluginGestionSurveyCheckCSRF($_POST);
   $survey->check((int)$_POST['id'], UPDATE);
   $survey->update($_POST);

   Html::back();

} else {
   $survey->checkGlobal(READ);
   Html::header(PluginGestionSurvey::getTypeName(2), '', "management", "plugingestionmenu", "gestion");
   $survey->display(['id' => (int)($_GET['id'] ?? 0)]);
   Html::footer();
}
