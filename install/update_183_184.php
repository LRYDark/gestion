<?php
/**
 * Migration 1.8.3 -> 1.8.4 (mise à jour unique) : abandon de SharePoint.
 *
 * La récupération des bons dans SharePoint (API Graph), leur dépôt une fois
 * signés et la tâche cron qui y cherchait les nouveaux PDF sont retirés du
 * plugin : trop lourds à maintenir pour une méthode qui ne sert plus. Restent
 * Sage et le dossier local.
 *
 *  - `mode` 0 (SharePoint) devient 1 (Sage) si Sage est actif, 2 (Local)
 *    sinon ; la colonne naît désormais à 2 ;
 *  - `SharePointLinkDisplay`, qui réglait en fait la prévisualisation du PDF
 *    pour toutes les sources, est renommée `PdfPreview` (valeur conservée) ;
 *  - les réglages propres à SharePoint et à sa tâche cron sont supprimés, dont
 *    les identifiants Azure (TenantID, ClientID, ClientSecret) : un secret qui
 *    ne sert plus n'a rien à faire en base ;
 *  - les dossiers configurés pour SharePoint ou pour la tâche cron (params 1,
 *    5 et 10) repassent « non attribué » (8), et le dossier d'archivage local
 *    est noté 2 quel que soit son ancien numéro ;
 *  - l'action automatique « GestionPdf » est supprimée, avec son historique ;
 *  - le gabarit « Gestion Mail PDF (Tracker) », qui ne servait qu'à cette
 *    tâche, est supprimé s'il n'est rattaché à aucune notification.
 *
 * Les bons déjà enregistrés avec `save = 'SharePoint'` ne sont pas touchés :
 * leur lien d'origine reste affiché, et une signature éventuelle passe par la
 * page de remplacement, comme pour toute source devenue introuvable.
 *
 * Idempotente : rejouée, elle ne trouve plus rien à faire.
 */
function update_183_184(): void
{
   global $DB;

   $table = 'glpi_plugin_gestion_configs';
   if (!$DB->tableExists($table)) {
      return;
   }

   $log = static function (string $message): void {
      Toolbox::logInFile('plugin-gestion', '1.8.4 : ' . $message . "\n");
   };

   // --- Mode de recherche par défaut ---
   if ($DB->fieldExists($table, 'mode', false)) {
      try {
         if ($DB->fieldExists($table, 'SageOn', false)) {
            $DB->doQuery("UPDATE `$table` SET `mode` = IF(`SageOn` = 1, 1, 2) WHERE `mode` = 0");
         } else {
            $DB->doQuery("UPDATE `$table` SET `mode` = 2 WHERE `mode` = 0");
         }
         $DB->doQuery("ALTER TABLE `$table` MODIFY `mode` TINYINT NOT NULL DEFAULT '2'");
      } catch (\Throwable $e) {
         $log('échec conversion du mode SharePoint : ' . $e->getMessage());
      }
   }

   // --- Prévisualisation du PDF ---
   try {
      if ($DB->fieldExists($table, 'SharePointLinkDisplay', false)) {
         if ($DB->fieldExists($table, 'PdfPreview', false)) {
            $DB->doQuery("ALTER TABLE `$table` DROP COLUMN `SharePointLinkDisplay`");
         } else {
            $DB->doQuery(
               "ALTER TABLE `$table`
                CHANGE `SharePointLinkDisplay` `PdfPreview` TINYINT NOT NULL DEFAULT '0'"
            );
         }
      } elseif (!$DB->fieldExists($table, 'PdfPreview', false)) {
         $DB->doQuery("ALTER TABLE `$table` ADD `PdfPreview` TINYINT NOT NULL DEFAULT '0'");
      }
   } catch (\Throwable $e) {
      $log('échec renommage SharePointLinkDisplay -> PdfPreview : ' . $e->getMessage());
   }

   // --- Réglages SharePoint et de sa tâche cron ---
   $obsolete = [
      'TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath', 'Global',
      'SharePointOn', 'SharePointSearch',
      'extract', 'MailTracker', 'MailTrackerYesNo', 'gabarit_tracker',
      'EntitiesExtract', 'EntitiesExtractValue', 'LastCronTask',
   ];
   foreach ($obsolete as $column) {
      if (!$DB->fieldExists($table, $column, false)) {
         continue;
      }
      try {
         $DB->doQuery("ALTER TABLE `$table` DROP COLUMN `$column`");
      } catch (\Throwable $e) {
         $log("échec suppression colonne $column : " . $e->getMessage());
      }
   }

   // --- Dossiers configurés ---
   $folders = 'glpi_plugin_gestion_configsfolder';
   if ($DB->tableExists($folders)) {
      try {
         $DB->update($folders, ['params' => 8], ['params' => [1, 5, 10]]);
         $DB->update($folders, ['params' => 2], ['params' => 3]);
      } catch (\Throwable $e) {
         $log('échec mise à jour des dossiers : ' . $e->getMessage());
      }
   }

   // --- Action automatique de la recherche SharePoint ---
   $crontask = new CronTask();
   foreach ($DB->request([
      'SELECT' => ['id'],
      'FROM'   => 'glpi_crontasks',
      'WHERE'  => ['itemtype' => 'PluginGestionReminder', 'name' => 'GestionPdf'],
   ]) as $row) {
      // Purge : supprime aussi son historique (CronTask::cleanDBonPurge()).
      if (!$crontask->delete(['id' => (int)$row['id']], true)) {
         $log('échec suppression de l\'action automatique GestionPdf');
      }
   }

   // --- Gabarit du mail « tracker » de cette tâche ---
   $template = new NotificationTemplate();
   foreach ($DB->request([
      'SELECT' => ['id'],
      'FROM'   => 'glpi_notificationtemplates',
      'WHERE'  => [
         'name'    => 'Gestion Mail PDF (Tracker)',
         'comment' => 'Created by the plugin gestion (Tracker)',
      ],
   ]) as $row) {
      $used = countElementsInTable(
         'glpi_notifications_notificationtemplates',
         ['notificationtemplates_id' => (int)$row['id']]
      );
      if ($used > 0) {
         continue;
      }
      if (!$template->delete(['id' => (int)$row['id']], true)) {
         $log('échec suppression du gabarit « Gestion Mail PDF (Tracker) »');
      }
   }
}
