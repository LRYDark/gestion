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
function update_144_next() {
   global $DB;

   // Vérifier si les colonnes existent déjà
   $columns = $DB->query("SHOW COLUMNS FROM `glpi_plugin_gestion_configs`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'SageOn',
      'SharePointOn',
      'SageIp',
      'SageId',
      'SagePwd',
      'mode'
   ];

   // Liste pour les colonnes manquantes
   $missing_columns = array_diff($required_columns, array_column($columns, 'Field'));

   if (!empty($missing_columns)) {
      $query= "ALTER TABLE glpi_plugin_gestion_configs
               ADD COLUMN `SageIp` VARCHAR(255) NULL,
               ADD COLUMN `SageId` VARCHAR(255) NULL,
               ADD COLUMN `SagePwd` VARCHAR(255) NULL,
               ADD COLUMN `SageOn` TINYINT NOT NULL DEFAULT '0',
               ADD COLUMN `SharePointOn` TINYINT NOT NULL DEFAULT '0',
               ADD COLUMN `mode` TINYINT NOT NULL DEFAULT '0';";
      $DB->query($query) or die($DB->error());
   }
}
  
?>
