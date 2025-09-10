<?php
function update_151_next() {
   global $DB;

   // Vérifier si les colonnes existent déjà
   $columns = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_gestion_configs`")->fetch_all(MYSQLI_ASSOC);

   // Liste des colonnes à vérifier
   $required_columns = [
      'CounterInvoice',
      'CounterInvoiceUsers',
      'CounterInvoiceMail',
      'CounterInvoicePdf'
   ];

   // Liste pour les colonnes manquantes
   $missing_columns = array_diff($required_columns, array_column($columns, 'Field'));

   if (!empty($missing_columns)) {
      $query= "ALTER TABLE glpi_plugin_gestion_configs
               ADD COLUMN `CounterInvoice` TINYINT(4) NOT NULL DEFAULT '0',
               ADD COLUMN `CounterInvoiceUsers` TEXT NULL,
               ADD COLUMN `CounterInvoiceMail` TEXT NULL,
               ADD COLUMN `CounterInvoicePdf` TINYINT(4) NOT NULL DEFAULT '0';";
      $DB->doQuery($query) or die($DB->error());
   }
}
