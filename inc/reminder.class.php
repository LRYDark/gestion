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

   public static $itemtype = TicketGestion::class;
   public static $items_id = 'ticketgestions_id';

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
  
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();
  
      try {
         // Étape 1 : Déterminer les dates pour la récupération
         $startDate = $config->fields['LastCronTask'] ? $config->fields['LastCronTask'] : null; // Si dernière exécution connue, l'utiliser comme date de début

         Session::addMessageAfterRedirect(__('test : '.$startDate, 'gestion'), false, ERROR);

         /*$datetime = new DateTime($startDate); 
         $startDate = $datetime->format('Y-m-d\TH:i:s\Z');
   
                  Session::addMessageAfterRedirect(__('test2 : '.$startDate, 'gestion'), false, ERROR);*/

         $endDate = (new DateTime())->format('Y-m-d\TH:i:s\Z');

         // Étape 2 : Récupérer les fichiers récents
         $folderPath = '';
         $recentFiles = $sharepoint->getRecentFilesRecursive($folderPath, $startDate, $endDate);

         $addedFiles = [];

         $requet2 = $DB->query("SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 2 LIMIT 1")->fetch_object();
         $fileDestination = $requet2->folder_name ?? null;

                  Session::addMessageAfterRedirect(__($fileDestination, 'gestion'), false, ERROR);

         foreach ($recentFiles as $file) {
            $lastModified = $sharepoint->convertFromISO8601($file['lastModifiedDateTime']);

            // Vérifier l'extension du fichier
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
               // Extraire les informations nécessaires
               $fileName = pathinfo($file['name'], PATHINFO_FILENAME);
               $createdDateTime = $sharepoint->convertFromISO8601($file['createdDateTime']);
               $webUrl = $file['webUrl'];

               Session::addMessageAfterRedirect(__($webUrl, 'gestion'), false, ERROR);

               // Vérifier si le fichier est dans le dossier spécifique ou ses sous-dossiers
               $isSigned = 0;
               if ($fileDestination) {
                  Session::addMessageAfterRedirect(__('signer 1 : '.$isSigned, 'gestion'), false, ERROR);
                     $filePath = $file['parentReference']['path'] ?? '';
                     Session::addMessageAfterRedirect(__('file : '.$filePath, 'gestion'), false, ERROR); 
                     if (strpos($filePath, $fileDestination) !== false) {
                        $isSigned = 1;
                        Session::addMessageAfterRedirect(__('signer 2 : '.$isSigned, 'gestion'), false, ERROR); 
                     }
               }

               Session::addMessageAfterRedirect(__('signer 3 : '.$isSigned, 'gestion'), false, ERROR); 

               // Vérifier si le fichier existe déjà en base
               $query = "SELECT COUNT(*) AS count FROM `glpi_plugin_gestion_survey_surveys` WHERE `bl` = '$fileName';";
               Session::addMessageAfterRedirect(__('TEST 1', 'gestion'), false, ERROR); 
               $result = $DB->query($query);
               Session::addMessageAfterRedirect(__('TEST 2', 'gestion'), false, ERROR); 

               try{
                  $row = $DB->fetchassoc($result);
               }catch (Exception $e) {
                  Session::addMessageAfterRedirect(__($e->getMessage(), 'gestion'), false, ERROR);
                  echo "Erreur : " . $e->getMessage() . "\n";
              }
               



               Session::addMessageAfterRedirect(__('TEST 3', 'gestion'), false, ERROR); 
               Session::addMessageAfterRedirect(__('count : '.$row['count'], 'gestion'), false, ERROR);

               if ($row['count'] == 0) {
                  Session::addMessageAfterRedirect(__('TEST 4', 'gestion'), false, ERROR); 

                     // Ajouter le fichier en base
                     $sql = $isSigned
                        ? "INSERT INTO glpi_plugin_gestion_surveys (bl, doc_url, doc_date, signed) VALUES ('$fileName', '".$DB->escape($webUrl)."', '$createdDateTime', $isSigned)"
                        : "INSERT INTO glpi_plugin_gestion_surveys (bl, doc_url, doc_date) VALUES ('$fileName', '".$DB->escape($webUrl)."', '$createdDateTime')";

                        Session::addMessageAfterRedirect(__('SQL : '.$sql, 'gestion'), false, ERROR); 
                     $DB->query($sql);
                     $addedFiles[] = $fileName;
                     Session::addMessageAfterRedirect(__('filename : '.$fileName, 'gestion'), false, ERROR); 
               }
            }
         }

         // Résultat de la synchronisation
         if (!empty($addedFiles)) {
            echo "Fichiers ajoutés en base : " . implode(", ", $addedFiles) . "\n";
            //$DB->query("UPDATE glpi_plugin_gestion_configs SET LastCronTask = $endDate WHERE id = 1");
         } else {
            echo "Aucun nouveau fichier à ajouter.\n";
         }
      } catch (Exception $e) {
          Session::addMessageAfterRedirect(__($e->getMessage(), 'gestion'), false, ERROR);
          echo "Erreur : " . $e->getMessage() . "\n";
      }
   }     
}
