<?php
function update_175_next() {
   global $DB;

   // =========================================================================
   // 1.7.5 (a) — NoTaskSignMode sur glpi_plugin_gestion_configs
   // =========================================================================
   $table = 'glpi_plugin_gestion_configs';
   if ($DB->tableExists($table)) {
      $columns  = $DB->doQuery("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
      $existing = array_column($columns, 'Field');

      // NoTaskSignMode : comportement onglet « Gestion BL » quand le ticket n'a aucune tache.
      //   0 = message + bouton « Signer le BL seul quand meme » (defaut) ; 1 = bloquer.
      if (!in_array('NoTaskSignMode', $existing, true)) {
         $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `NoTaskSignMode` TINYINT NOT NULL DEFAULT '0'") or die($DB->error());
      }

      // AutoAssociateBl : association auto des BL aux tickets (creation + ouverture). Defaut ON.
      if (!in_array('AutoAssociateBl', $existing, true)) {
         $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `AutoAssociateBl` TINYINT NOT NULL DEFAULT '1'") or die($DB->error());
      }
   }

   // =========================================================================
   // 1.7.5 (b) — bl_number sur glpi_plugin_gestion_surveys (dedoublonnage par numero)
   // =========================================================================
   $stable = 'glpi_plugin_gestion_surveys';
   if (!$DB->tableExists($stable)) {
      return;
   }

   $scols     = $DB->doQuery("SHOW COLUMNS FROM `$stable`")->fetch_all(MYSQLI_ASSOC);
   $sexisting = array_column($scols, 'Field');

   // (b.1) Colonne normalisee
   if (!in_array('bl_number', $sexisting, true)) {
      $DB->doQuery("ALTER TABLE `$stable` ADD COLUMN `bl_number` VARCHAR(50) NULL AFTER `bl`") or die($DB->error());
   }

   // (b.2) Backfill du numero pour les lignes non renseignees
   if (function_exists('pluginGestionBlNumber')) {
      $res = $DB->doQuery("SELECT id, bl FROM `$stable` WHERE bl_number IS NULL OR bl_number = ''");
      if ($res) {
         while ($r = $DB->fetchAssoc($res)) {
            $num = pluginGestionBlNumber($r['bl'] ?? '');
            if ($num !== '') {
               $DB->update($stable, ['bl_number' => $num], ['id' => (int)$r['id']]);
            }
         }
      }
   }

   // (b.3) Nettoyage des doublons (meme numero) : garder le meilleur (signe d'abord,
   //       sinon plus recent), transferer l'association ticket si besoin, supprimer les autres.
   $dupRes = $DB->doQuery(
      "SELECT bl_number FROM `$stable`
       WHERE bl_number IS NOT NULL AND bl_number <> ''
       GROUP BY bl_number HAVING COUNT(*) > 1"
   );
   if ($dupRes) {
      $dupNumbers = [];
      while ($d = $DB->fetchAssoc($dupRes)) {
         $dupNumbers[] = $d['bl_number'];
      }
      foreach ($dupNumbers as $num) {
         $numEsc = $DB->escape($num);
         $grp = $DB->doQuery(
            "SELECT id, tickets_id, signed FROM `$stable`
             WHERE bl_number = '$numEsc' ORDER BY signed DESC, id DESC"
         );
         $keepId = 0; $keepTicket = 0; $rescueTicket = 0; $deleteIds = [];
         while ($g = $DB->fetchAssoc($grp)) {
            if ($keepId === 0) {
               $keepId     = (int)$g['id'];
               $keepTicket = (int)$g['tickets_id'];
            } else {
               if (!empty($g['tickets_id']) && $rescueTicket === 0) {
                  $rescueTicket = (int)$g['tickets_id'];
               }
               $deleteIds[] = (int)$g['id'];
            }
         }
         if ($keepId > 0 && $keepTicket === 0 && $rescueTicket > 0) {
            $DB->update($stable, ['tickets_id' => $rescueTicket], ['id' => $keepId]);
         }
         if (!empty($deleteIds)) {
            $DB->delete($stable, ['id' => $deleteIds]);
         }
      }
   }

   // (b.4) Index unique (apres nettoyage). Ne pas die() : si un doublon residuel subsiste,
   //       on journalise sans interrompre la mise a jour (le garde-fou applicatif reste actif).
   $idxRes = $DB->doQuery("SHOW INDEX FROM `$stable` WHERE Key_name = 'bl_number'");
   $hasIdx = $idxRes && $DB->numrows($idxRes) > 0;
   if (!$hasIdx) {
      if (!$DB->doQuery("ALTER TABLE `$stable` ADD UNIQUE KEY `bl_number` (`bl_number`)")) {
         error_log("[gestion] Index unique bl_number non cree (doublon residuel ?) : " . $DB->error());
      }
   }
}
