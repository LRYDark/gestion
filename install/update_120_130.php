<?php
//include ('../../../inc/includes.php');
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

/**
 * Update from 2.1.4 to 2.1.5
 *
 * @return bool for success (will die for most error)
 * */
function update120to130() {
   global $DB;

   /*
    * Colonne par colonne, et plus en bloc : l'ancien ALTER ajoutait TOUTES les
    * colonnes dès qu'une seule manquait. Depuis l'abandon de SharePoint (1.8.4),
    * `extract`, `MailTracker`, `MailTrackerYesNo`, `EntitiesExtract`,
    * `gabarit_tracker` et `LastCronTask` sont supprimées : l'ajout groupé aurait
    * recréé ExtractYesNo et formulaire, déjà là, et la mise à jour serait morte
    * sur « Duplicate column ». Ces colonnes ne sont plus créées, ni le gabarit
    * « Gestion Mail PDF (Tracker) » qui ne servait qu'à leur tâche cron.
    */
   $columns = [
      'ExtractYesNo' => "TINYINT NOT NULL DEFAULT '0'",
      'formulaire'   => "INT(10) NOT NULL DEFAULT '0'",
   ];
   foreach ($columns as $column => $definition) {
      if (!$DB->fieldExists('glpi_plugin_gestion_configs', $column, false)) {
         $DB->doQuery("ALTER TABLE `glpi_plugin_gestion_configs` ADD COLUMN `$column` $definition") or die($DB->error());
      }
   }

   $table = 'glpi_plugin_gestion_tickets';
   if($DB->tableExists($table)){
      $query = "RENAME TABLE glpi_plugin_gestion_tickets TO glpi_plugin_gestion_surveys;";
      $DB->doQuery($query) or die($DB->error());

      $query= "ALTER TABLE glpi_plugin_gestion_surveys
         ADD COLUMN `tracker` VARCHAR(255) NULL,
         ADD COLUMN `doc_url` TEXT NULL,
         ADD COLUMN `doc_date` TIMESTAMP NULL;";
      $DB->doQuery($query) or die($DB->error());
   }
}
  
?>
