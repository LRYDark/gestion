<?php
function update_174_next() {
   global $DB;

   $table = 'glpi_plugin_gestion_configs';
   if (!$DB->tableExists($table)) {
      return;
   }

   // Vérifier si la colonne existe déjà (idempotent : ne casse rien si re-jouée)
   $columns  = $DB->doQuery("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
   $existing = array_column($columns, 'Field');

   // InvoiceMail : destinataire(s) du mail "Envoi facture par mail"
   // (envoi d'un document scanné depuis la tablette via device_send_invoice.php)
   if (!in_array('InvoiceMail', $existing, true)) {
      $query = "ALTER TABLE `$table` ADD COLUMN `InvoiceMail` VARCHAR(255) NULL";
      $DB->doQuery($query) or die($DB->error());
   }
}
