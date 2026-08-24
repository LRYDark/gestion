<?php
/**
 * Migration 1.8.0 -> 1.8.1 (mise à jour unique)
 *
 * Nettoyage des actions automatiques fantômes de la lignée du plugin.
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
}
