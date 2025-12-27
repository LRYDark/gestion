<?php
function update_160_next() {
   global $DB;

   // Vérifier si les colonnes existent déjà
   $columns = $DB->query("SHOW COLUMNS FROM `glpi_plugin_gestion_surveys`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'paid',
      'comment'
   ];

   // Liste pour les colonnes manquantes
   $missing_columns = array_diff($required_columns, array_column($columns, 'Field'));

    if (!empty($missing_columns)) {
        $query= "ALTER TABLE glpi_plugin_gestion_surveys
                ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT '0',
                ADD COLUMN `comment` TEXT NULL;";
        $DB->query($query) or die($DB->error());
    }
}
