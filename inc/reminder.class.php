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
      
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      try {
         // Étape 1 : Récupérer les fichiers récents
         $folderPath = '';
         $recentFiles = $sharepoint->getRecentFilesRecursive($folderPath, 3);
         $addedFiles = [];

         foreach ($recentFiles as $file) {
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
               // Extraire le nom du fichier sans extension
               $fileName = pathinfo($file['name'], PATHINFO_FILENAME);
               $createdDateTime = $sharepoint->convertFromISO8601($file['createdDateTime']);
               $webUrl = $file['webUrl'];
               $FileNameBdd = $file['name'];

               // Vérifier si le dossier existe déjà
               $query = "SELECT COUNT(*) AS count
               FROM `glpi_plugin_gestion_survey_surveys`
               WHERE `bl` = '$fileName';";

               $result = $DB->query($query);
               $row = $DB->fetchassoc($result);

               if ($row['count'] == 0) {
                  // Ajouter le fichier en base s'il n'existe pas
                  $DB->query("INSERT INTO glpi_plugin_gestion_survey_surveys (bl, doc_url, doc_date) VALUES ('$fileName', '".$DB->escape($webUrl)."', '$createdDateTime')");                  
                  $addedFiles[] = $fileName;
               }
            }
         }

         // Résultat de la synchronisation
         if (!empty($addedFiles)) {
            echo "Fichiers ajoutés en base : " . implode(", ", $addedFiles) . "\n";
         } else {
            echo "Aucun nouveau fichier à ajouter.\n";
         }
      } catch (Exception $e) {
         Session::addMessageAfterRedirect(__($e->getMessage(), 'gestion'), false, ERROR);
            echo "Erreur : " . $e->getMessage() . "\n";
      }
   }
}
