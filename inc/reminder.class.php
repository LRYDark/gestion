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


if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Class PluginGestionSurvey
 *
 * Used to store reminders to send automatically
 */
class PluginGestionReminder extends CommonDBTM {

   static $rightname = "plugin_gestion_survey";
   public $dohistory = true;

   const CRON_TASK_NAME = 'GestionPdf';


   /**
    * Return the localized name of the current Type
    * Should be overloaded in each new class
    *
    * @return string
    **/
   static function getTypeName($nb = 0) {
      return _n('Gestion PDF', 'Gestion PDF', $nb, 'gestion');
   }

   ////// CRON FUNCTIONS ///////

   /**
    * @param $name
    *
    * @return array
    */
   static function cronInfo($name) {

      switch ($name) {
         case self::CRON_TASK_NAME:
            return ['description' => __('Gestion des PDF SharePoint', 'gestion')];   // Optional
            break;
      }
      return [];
   }

   public static function deleteItem(Ticket $ticket) {
      $reminder = new Self;
      if ($reminder->getFromDBByCrit(['tickets_id' => $ticket->fields['id']])) {
         $reminder->delete(['id' => $reminder->fields["id"]]);
      }
   }

   /**
    * Cron action
    *
    * @param  $task for log
    *
    * @global $CFG_GLPI
    *
    * @global $DB
    */
   static function cronGestionPdf($task = NULL) {
      global $DB, $CFG_GLPI;
      $config = new PluginGestionConfig();
      $bibliotheque = $config->Global();
      $encodedFilePath = '';

      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      try {
         // Étape 1 : Déterminer les dates pour la récupération
         if($config->fields['LastCronTask'] == NULL){
            $startDate = NULL;
         }else{
            $startDate = $config->fields['LastCronTask'];
            $datetime = new DateTime($startDate); 
            $startDate = $datetime->format('Y-m-d\TH:i:s\Z');
         }

         $endDate = (new DateTime())->format('Y-m-d\TH:i:s\Z');

         $lastdate = date('Y-m-d H:i:s');
         $DB->query("UPDATE glpi_plugin_gestion_configs SET LastCronTask = '$lastdate' WHERE id = 1");

         // Étape 2 : Récupérer les fichiers récents
         $recentFiles = $sharepoint->searchSharePointCron($startDate, $endDate);

         $requet2 = $DB->query("SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 2 LIMIT 1")->fetch_object();
         $fileDestination = $requet2->folder_name ?? NULL;

         foreach ($recentFiles as $file) {
            $lastModified = $sharepoint->convertFromISO8601($file['lastModifiedDateTime']);

            // Vérifier l'extension du fichier
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
               // Extraire les informations nécessaires
               $fileName = pathinfo($file['name'], PATHINFO_FILENAME);
               $createdDateTime = $sharepoint->convertFromISO8601($file['createdDateTime']);
               $webUrl = $file['webUrl'];

               // Vérifier si le fichier est dans le dossier spécifique ou ses sous-dossiers
               $isSigned = 0;
               $entitiesid = 0;
               if ($fileDestination) {
                  $filePath = $file['webUrl'] ?? '';
                  
                  if($config->EntitiesExtract() == 1){
                     $pattern = $config->EntitiesExtractValue();
                     // Vérifier si "Clients" est présent
                     if (strpos($filePath, $pattern) !== false && preg_match("~" . preg_quote($pattern, '~') . "/([^/]+)/~", $filePath, $matches)) {
                        $entities = $matches[1];
                     }
                     // Si "Clients" n'est pas présent mais "Bl_Signe" est là, récupérer après "Bl_Signe/"
                     elseif (strpos($filePath, $fileDestination) !== false && preg_match("~" . preg_quote($fileDestination, '~') . "/([^/]+)/~", $filePath, $matches)) {
                        $entities = $matches[1];
                     }
                     // Si ni "Clients" ni "Bl_Signe" ne sont trouvés, récupérer après "la bibliotheque/"
                     elseif (preg_match("~" . preg_quote($bibliotheque, "~") . "/([^/]+)/~", $filePath, $matches)) {
                        $entities = $matches[1];
                     }       

                     $entities = $DB->query("SELECT id FROM `glpi_entities` WHERE name = '$entities'")->fetch_object();
                     if (!empty($entities->id)) {
                        $entitiesid = $entities->id;
                     }
                  }

                  if (preg_match("/" . preg_quote($bibliotheque, "/") . "\/(.*)\/[^\/]+\.pdf/", $filePath, $matches)) {
                     $valueAfterRootNotEncode = $matches[1];
                     $valueAfterRoot = empty($valueAfterRootNotEncode) ? '' : implode('/', array_map('rawurlencode', explode('/', $valueAfterRootNotEncode)));
                  }else{
                     $valueAfterRoot = NULL;
                  }

                  if ($fileDestination !== NULL){
                     if (stripos($valueAfterRoot, $fileDestination) !== false) {
                        $isSigned = 1; // "Bl_Signe" est présent, peu importe la casse
                     }
                  }
               }
               
               if($config->ExtractYesNo() == 1){
                  $tracker = $sharepoint->GetTrackerPdfDownload($valueAfterRoot.'/'.$fileName.'.pdf');
                  if (empty($tracker)){
                     $tracker = NULL;
                  }
               }

               // Vérifier si le fichier existe déjà en base
               $query = "SELECT COUNT(*) AS count FROM `glpi_plugin_gestion_surveys` WHERE `bl` = '$fileName';";
               $result = $DB->query($query);
               $row = $DB->fetchassoc($result);
               $id_survey = 0;

               if ($row['count'] == 0) {                  
                  // Ajouter le fichier en base
                  $sql = $isSigned
                     ? "INSERT INTO glpi_plugin_gestion_surveys (entities_id, url_bl, bl, doc_url, doc_date, signed, tracker) VALUES ($entitiesid, '$valueAfterRoot', '$fileName', '".$DB->escape($webUrl)."', '$createdDateTime', $isSigned, '$tracker')"
                     : "INSERT INTO glpi_plugin_gestion_surveys (entities_id, url_bl, bl, doc_url, doc_date, tracker) VALUES ($entitiesid, '$valueAfterRoot', '$fileName', '".$DB->escape($webUrl)."', '$createdDateTime', '$tracker')";
                     
                  if ($DB->query($sql)) {
                     // Récupérer l'ID de la dernière ligne insérée avec LAST_INSERT_ID()
                     $result = $DB->query("SELECT LAST_INSERT_ID() AS id");
                     if ($result) {
                        $row = $result->fetch_assoc();
                        $id_survey = $row['id'];
                     } 
                  } else {
                     Session::addMessageAfterRedirect(__('Insertion failed for file: ' . $fileName . '. Error: ' . $DB->error(), 'gestion'), false, ERROR);
                  }
               }

               if($config->MailTrackerYesNo() == 1 && !empty($config->MailTracker())){
                  // Requête pour récupérer les enregistrements ayant params = 5
                  $query = "SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 5";
                  $result = $DB->query($query);

                  if ($result || $DB->numrows($result) != 0) {
                     // Vérification des correspondances
                     while ($data = $DB->fetchAssoc($result)) {
                        $folder_name = $data['folder_name'];
                        if (stripos($tracker, $folder_name) !== false && $isSigned != 1) { 
                           $sharepoint->MailSend($config->fields['MailTracker'], $config->fields['gabarit_tracker'], $outputPath = NULL, $message = NULL, $id_survey, $tracker, $webUrl, $fileName);
                        } 
                     }
                  }
               }
            }
         }
      } catch (Exception $e) {
         Session::addMessageAfterRedirect(__($e->getMessage(), 'gestion'), false, ERROR);
      }
   }     
}
