<?php
function update_160_next() {
   global $DB;

   // Vérifier si les colonnes existent déjà
   $columns = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_gestion_surveys`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'paid',
      'comment'
   ];

   // Liste pour les colonnes manquantes
   $existing = array_column($columns, 'Field');

    if (!in_array('paid', $existing)) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_gestion_surveys` ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT '0'") or die($DB->error());
    }
    if (!in_array('comment', $existing)) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_gestion_surveys` ADD COLUMN `comment` TEXT NULL") or die($DB->error());
    }
}
