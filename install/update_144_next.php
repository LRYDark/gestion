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
 * Update from 1.4.4 to next version
 *
 * @return bool for success (will die for most error)
 * */
function update_144_next() {
   global $DB;

   /*
    * Colonne par colonne, et plus en bloc : `SharePointOn` et `SharePointSearch`
    * sont supprimées avec SharePoint en 1.8.4 (update_183_184). L'ancien ALTER
    * groupé les aurait recréées avec toutes les autres, déjà présentes, et la
    * mise à jour serait morte sur « Duplicate column ».
    *
    * `mode` naît à 2 (Local) : l'ancien défaut 0 désignait SharePoint.
    */
   $columns = [
      'SageToken'   => "VARCHAR(255) NULL",
      'SageUrlApi'  => "VARCHAR(255) NULL",
      'SageSearch'  => "TINYINT NOT NULL DEFAULT '0'",
      'LocalSearch' => "TINYINT NOT NULL DEFAULT '0'",
      'SageOn'      => "TINYINT NOT NULL DEFAULT '0'",
      'mode'        => "TINYINT NOT NULL DEFAULT '2'",
   ];
   foreach ($columns as $column => $definition) {
      if (!$DB->fieldExists('glpi_plugin_gestion_configs', $column, false)) {
         $DB->doQuery("ALTER TABLE `glpi_plugin_gestion_configs` ADD COLUMN `$column` $definition") or die($DB->error());
      }
   }

//////////////////////////////////////////////////////////////////////////////////////////////////////

   // Vérifier si les colonnes existent déjà
   $columns = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_gestion_surveys`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'save'
   ];

   // Liste pour les colonnes manquantes
   $missing_columns = array_diff($required_columns, array_column($columns, 'Field'));

   if (!empty($missing_columns)) {
      $query= "ALTER TABLE glpi_plugin_gestion_surveys
               ADD COLUMN `save` VARCHAR(15) NULL;";
      $DB->doQuery($query) or die($DB->error());
   }
}
  
?>
