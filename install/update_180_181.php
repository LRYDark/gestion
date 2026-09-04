<?php
/**
 * Migration 1.8.0 -> 1.8.1 (mise à jour unique)
 *
 *  - colonne `BlOnlyMergeReport` sur glpi_plugin_gestion_configs : joindre ou
 *    non le rapport déjà signé au PDF d'une signature « BL seul » ;
 *  - colonne `fab_home_tabs` sur glpi_plugin_gestion_userprefs : quels onglets
 *    le modal du bouton d'accueil propose ;
 *  - nettoyage des actions automatiques fantômes de la lignée du plugin (voir
 *    ci-dessous) ;
 *  - table `glpi_plugin_gestion_offline_queue` : garde d'idempotence de la file
 *    d'attente des signatures hors-ligne.
 *
 * ---- Actions automatiques fantômes ----
 *
 * Le plugin s'appelait « rpauto » avant d'être renommé « gestion ». Son action
 * automatique avait été enregistrée en base sous `PluginRpautoReminder` /
 * `RpautoMail`, et la ligne n'a jamais été supprimée : `plugin_gestion_uninstall()`
 * ré-enregistrait la tâche (CronTask::Register) au lieu de la retirer.
 * Résultat, à chaque passage du cron GLPI :
 *
 *   Impossible de démarrer RpautoMail
 *   Fonction PluginRpautoReminder::cronRpautoMail indéfinie (pour les actions automatiques)
 *
 * On supprime donc toute action automatique `PluginRpauto*` / `PluginGestion*`
 * dont la classe ou la méthode `cron<Nom>()` n'existe plus. Le test porte sur le
 * code réellement présent : la tâche valide du plugin
 * (`PluginGestionReminder::cronGestionPdf`) est conservée, avec son état, sa
 * fréquence et son historique.
 *
 * Idempotente : sans ligne fantôme, la fonction ne fait rien.
 */
function update_180_181() {
   global $DB;

   /*
    * --- Onglets du bouton d'accueil ---
    *
    * Quels onglets le modal « Scanner / Rechercher » propose : 1 = résolution
    * d'un identifiant, 2 = recherche par mot-clé, 3 = les deux.
    *
    * La colonne naît à 3, c'est-à-dire exactement ce que faisait le bouton
    * avant ce réglage : personne ne voit son interface changer parce qu'il a
    * mis à jour.
    *
    * Le partage des préférences avec le plugin RP ne demande rien ici : il se
    * joue à l'exécution (PluginGestionUserpref écrit dans les deux tables et lit
    * celle du voisin quand la sienne est vide), aucune donnée n'est à déplacer.
    */
   /*
    * --- Rapport joint à une signature « BL seul » ---
    *
    * Naît à 1 : ce parcours ne s'ouvre que lorsqu'un rapport signé existe déjà,
    * et joindre ce rapport au bon est ce qu'on attend. Le réglage existe pour
    * ceux qui envoient les deux documents séparément.
    */
   if ($DB->tableExists('glpi_plugin_gestion_configs')
       && !$DB->fieldExists('glpi_plugin_gestion_configs', 'BlOnlyMergeReport')) {
      try {
         $DB->doQuery(
            "ALTER TABLE `glpi_plugin_gestion_configs`
             ADD `BlOnlyMergeReport` TINYINT NOT NULL DEFAULT 1"
         );
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin-gestion',
            "1.8.1 : échec ajout colonne BlOnlyMergeReport : " . $e->getMessage() . "\n"
         );
      }
   }

   if ($DB->tableExists('glpi_plugin_gestion_userprefs')
       && !$DB->fieldExists('glpi_plugin_gestion_userprefs', 'fab_home_tabs')) {
      /*
       * doQuery() LÈVE une exception en cas d'échec sur GLPI 11, elle ne renvoie
       * jamais false : un `if (!$DB->doQuery(...))` serait du code mort. D'où le
       * try/catch, qui journalise un échec réel (droits MySQL, table verrouillée)
       * au lieu d'interrompre l'installation sans trace.
       */
      try {
         $DB->doQuery(
            "ALTER TABLE `glpi_plugin_gestion_userprefs`
             ADD `fab_home_tabs` TINYINT NOT NULL DEFAULT 3"
         );
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin-gestion',
            "1.8.1 : échec ajout colonne fab_home_tabs : " . $e->getMessage() . "\n"
         );
      }
   }

   if (!$DB->tableExists('glpi_crontasks')) {
      return;
   }

   $iterator = $DB->request([
      'SELECT' => ['id', 'itemtype', 'name'],
      'FROM'   => 'glpi_crontasks',
      'WHERE'  => [
         'OR' => [
            ['itemtype' => ['LIKE', 'PluginRpauto%']],
            ['itemtype' => ['LIKE', 'PluginGestion%']],
         ],
      ],
   ]);

   $crontask = new CronTask();
   foreach ($iterator as $data) {
      $itemtype = $data['itemtype'];
      $method   = 'cron' . $data['name'];

      // Classe absente (plugin renommé/supprimé) ou méthode de cron disparue :
      // GLPI ne pourra jamais exécuter la tâche, elle ne sert qu'à remplir les logs.
      if (class_exists($itemtype) && method_exists($itemtype, $method)) {
         continue;
      }

      // Purge (2e argument) : supprime aussi les glpi_crontasklogs liés
      // (CronTask::cleanDBonPurge()).
      if ($crontask->delete(['id' => $data['id']], true)) {
         Toolbox::logInFile(
            'plugin-gestion',
            "1.8.1 : action automatique fantome supprimee ({$itemtype}::{$method})\n"
         );
      } else {
         Toolbox::logInFile(
            'plugin-gestion',
            "1.8.1 : echec suppression de l'action automatique {$itemtype}::{$method}\n"
         );
      }
   }

   /*
    * File d'attente des signatures hors-ligne.
    *
    * La table ne retient QUE les signatures déjà traitées : la file elle-même
    * vit dans le navigateur du technicien. C'est ce qui empêche un rejeu — la
    * même requête renvoyée au retour du réseau — de signer une seconde fois le
    * même bon et d'envoyer un second mail au client.
    *
    * La création est déléguée à la classe, qui sait aussi la faire à la demande
    * si cette migration n'a pas été jouée. Une seule définition du schéma, donc
    * aucun risque qu'il diverge entre les deux chemins.
    */
   if (class_exists('PluginGestionOfflinequeue')) {
      PluginGestionOfflinequeue::createTable();
   }
}
