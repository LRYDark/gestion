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
function update() {
   global $DB;

   // Vérifier si la colonne EntitiesExtractValue existe déjà
   $checkQuery = "SHOW COLUMNS FROM `glpi_plugin_gestion_configs` LIKE 'EntitiesExtractValue'";
   $result = $DB->query($checkQuery);

   if ($result->num_rows == 0) {
      // Si la colonne n'existe pas, on l'ajoute
      $query = "ALTER TABLE `glpi_plugin_gestion_configs`
               ADD COLUMN `EntitiesExtractValue` VARCHAR(255) NULL;";
      $DB->query($query) or die($DB->error());
   }
}
  
?>
