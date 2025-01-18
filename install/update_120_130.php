<?php
include ('../../../inc/includes.php');
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

   $query= "ALTER TABLE glpi_plugin_gestion_configs
            ADD COLUMN `extract` VARCHAR(255) NULL,
            ADD COLUMN `ExtractYesNo` TINYINT NOT NULL DEFAULT '0',
            ADD COLUMN `MailTracker` VARCHAR(255) NULL,
            ADD COLUMN `MailTrackerYesNo` TINYINT NOT NULL DEFAULT '0',
            ADD COLUMN `EntitiesExtract` TINYINT NOT NULL DEFAULT '0',
            ADD COLUMN `gabarit_tracker` INT(10) NOT NULL DEFAULT '0',
            ADD COLUMN `LastCronTask` TIMESTAMP DEFAULT NULL;";
   $DB->query($query) or die($DB->error());   

   $query= "RENAME DATABASE glpi_plugin_gestion_ticket TO glpi_plugin_gestion_surveys;";
   $DB->query($query) or die($DB->error()); 

   $query= "ALTER TABLE glpi_plugin_gestion_surveys
            ADD COLUMN `tracker` VARCHAR(255) NULL,
            ADD COLUMN `doc_url` TEXT NULL,
            ADD COLUMN `doc_date` TIMESTAMP NULL;";
   $DB->query($query) or die($DB->error()); 

   require_once PLUGIN_GESTION_DIR.'/install/MailContent2.php';
   $content_html2 = $ContentHtml2;

   // Échapper le contenu HTML
   $content_html2_escaped = Toolbox::addslashes_deep($content_html2);

   // Construire la requête d'insertion
   $insertQuery1 = "INSERT INTO `glpi_notificationtemplates` (`name`, `itemtype`, `date_mod`, `comment`, `css`, `date_creation`) VALUES ('Gestion Mail PDF (Tracker)', 'Ticket', NULL, 'Created by the plugin gestion (Tracker)', '', NULL);";
   // Exécuter la requête
   $DB->query($insertQuery1);

   // Construire la requête d'insertion
   $insertQuery2 = "INSERT INTO `glpi_notificationtemplatetranslations` 
      (`notificationtemplates_id`, `language`, `subject`, `content_text`, `content_html`) 
      VALUES (LAST_INSERT_ID(), 'fr_FR', '[GLPI] | Document ##gestion.tracker## généré', '', '{$content_html2_escaped}')";
   // Exécuter la requête
   $DB->query($insertQuery2);

   $ID = $DB->query("SELECT id FROM glpi_notificationtemplates WHERE NAME = 'Gestion Mail PDF (Tracker)' AND comment = 'Created by the plugin gestion (Tracker)'")->fetch_object();

   $query= "UPDATE glpi_plugin_gestion_configs SET gabarit_tracker = $ID->id WHERE id=1;";
   $DB->query($query) or die($DB->error());   
}
  
?>
