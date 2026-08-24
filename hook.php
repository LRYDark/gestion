<?php

function plugin_gestion_install() { // fonction installation du plugin
   global $DB;
   
   //*********************************************************************************************** */
      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
      $table = 'glpi_plugin_gestion_surveys';

      if (!$DB->tableExists($table)) {
         $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL auto_increment,
                     `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `users_id` int {$default_key_sign} NULL,
                     `users_ext` VARCHAR(255) NULL,
                     `tracker` VARCHAR(255) NULL,
                     `url_bl` VARCHAR(255) NULL,
                     `bl` VARCHAR(255) NULL,
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     `doc_url` TEXT NULL,
                     `doc_date` TIMESTAMP NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());
      }
   //*********************************************************************************************** */
   
   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/Documents";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion/DocumentsSigned";
   if (!is_dir($rep_files_gestion))
      mkdir($rep_files_gestion);

   $migration = new Migration(PLUGIN_GESTION_VERSION);

   // Parse inc directory
   foreach (glob(dirname(__FILE__).'/inc/*') as $filepath) {
      // Load *.class.php files and get the class name
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginGestion' . ucfirst($matches[1]);
         include_once($filepath);
         // If the install method exists, load it
         if (method_exists($classname, 'install')) {
            $classname::install($migration);
         }
      }
   }
   $migration->executeMigration();

   // ── Migrations spécifiques par lot (version cible) ──────────────────────
   // update_150_remote : remote_sign_requests + signaturedevices (ancienne structure)
   $update150 = dirname(__FILE__) . '/install/update_150_remote.php';
   if (file_exists($update150)) {
      require_once $update150;
      if (function_exists('update_150_remote')) {
         update_150_remote();
      }
   }

   // update_153_next : baseitems (sera droppé par update_170_alpha1)
   $update153 = dirname(__FILE__) . '/install/update_153_next.php';
   if (file_exists($update153)) {
      require_once $update153;
      // Note: update_153_next crée baseitems uniquement si absente.
      // update_170_alpha1 la supprime ensuite → ordre correct.
      if (function_exists('update_153_next')) {
         update_153_next();
      }
   }

   // update_170_alpha1 : migration 1.7.0_alpha1
   //   - Crée glpi_plugin_gestion_devices (serial+ip+last_seen+status)
   //   - Migre remote_sign_requests (device_serial remplace device_id/token)
   //   - Supprime glpi_plugin_gestion_baseitems (Base Description / Info)
   //   - Supprime glpi_plugin_gestion_signaturedevices (ancien schéma token)
   $update170 = dirname(__FILE__) . '/install/update_170_alpha1.php';
   if (file_exists($update170)) {
      require_once $update170;
      if (function_exists('update_170_alpha1')) {
         update_170_alpha1();
      }
   }

   PluginGestionProfile::initProfile();
   PluginGestionProfile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);

   // update_177_180 : mise à jour unique 1.8.0 — boutons flottants
   // (préférences utilisateur + droit de profil).
   // Appelé APRÈS initProfile() : la migration alimente les lignes de droits
   // que celui-ci vient de créer.
   $update180 = dirname(__FILE__) . '/install/update_177_180.php';
   if (file_exists($update180)) {
      require_once $update180;
      if (function_exists('update_177_180')) {
         update_177_180();
      }
   }

   CronTask::Register(PluginGestionReminder::class, PluginGestionReminder::CRON_TASK_NAME, DAY_TIMESTAMP);

   // update_180_181 : mise à jour unique 1.8.1 — purge des actions automatiques
   // fantômes de la lignée du plugin (héritage du nom « rpauto » :
   // PluginRpautoReminder::cronRpautoMail indéfinie à chaque passage du cron).
   // Appelé APRÈS CronTask::Register() : la tâche valide du plugin existe alors
   // en base, la purge ne supprime donc que ce qui n'est plus exécutable.
   $update181 = dirname(__FILE__) . '/install/update_180_181.php';
   if (file_exists($update181)) {
      require_once $update181;
      if (function_exists('update_180_181')) {
         update_180_181();
      }
   }

   return true;
}

/**
 * Hook auto GLPI (Hooks::AUTO_GIVE_ITEM) : rendu des cellules du moteur de recherche
 * pour les itemtypes du plugin. Retourner '' laisse le rendu standard s'appliquer.
 * Utilise ici pour la colonne « Signature » (search option 14 de PluginGestionSurvey).
 */
function plugin_gestion_giveItem($itemtype, $orig_id, $data, $num) {
   if ($itemtype !== 'PluginGestionSurvey') {
      return '';
   }

   // Colonne « Signé » (option 5) : rond vert / croix rouge a l'ecran,
   // rendu standard (Oui/Non) conserve dans les exports.
   if ((int)$orig_id === 5) {
      if (Search::$output_type != Search::HTML_OUTPUT) {
         return '';
      }
      $signed = (int)($data[$num][0]['name'] ?? 0);
      return $signed === 1
         ? '<i class="ti ti-check text-success" title="' . __s('Oui') . '"></i>'
         : '<i class="ti ti-x text-danger" title="' . __s('Non') . '"></i>';
   }

   // Colonne « Tickets » (option 2) : id cliquable vers le ticket + infobulle au
   // survol affichant la description, comme sur l'accueil (Ticket::showCentralList :
   // lien avec id + Html::showToolTip applyto). Le script qtip inline s'execute aussi
   // apres tri/filtre : Search/Table.js reinsere les resultats via $.html().
   if ((int)$orig_id === 2) {
      if (Search::$output_type != Search::HTML_OUTPUT) {
         return '';
      }
      $count = (int)($data[$num]['count'] ?? (isset($data[$num][0]) ? 1 : 0));
      $out   = [];
      for ($k = 0; $k < $count; $k++) {
         $tickets_id = (int)($data[$num][$k]['name'] ?? 0);
         if ($tickets_id <= 0) {
            continue;
         }
         $ticket = new Ticket();
         if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
            $out[] = (string)$tickets_id;
            continue;
         }
         $linkid = 'gestionsurveyticket' . $tickets_id . mt_rand();
         $link   = '<a id="' . $linkid . '" href="'
            . htmlspecialchars($ticket->getLinkURL(), ENT_QUOTES) . '">'
            . $tickets_id . '</a>';
         $link  .= Html::showToolTip(
            \Glpi\RichText\RichText::getEnhancedHtml($ticket->fields['content'] ?? ''),
            ['applyto' => $linkid, 'display' => false]
         );
         $out[] = $link;
      }
      if (count($out) === 0) {
         return ' ';
      }
      return implode('<br>', $out);
   }

   if ((int)$orig_id !== 14) {
      return '';
   }

   $survey_id = (int)($data['id'] ?? 0);
   $signed    = (int)($data[$num][0]['name'] ?? 0);
   if ($survey_id <= 0) {
      return ' ';
   }

   // Exports (CSV, PDF...) : texte simple, pas de HTML.
   if (Search::$output_type != Search::HTML_OUTPUT) {
      return $signed === 1 ? __('Signé', 'gestion') : __('Non signé', 'gestion');
   }

   // Meme flux que survey.form.php (gestion_loadCriForm -> ajax/cri.php, qui retrouve
   // lui-meme le ticket associe au BL) : modal de signature (non signe) ou de
   // visualisation du document signe. Repli : ouverture de la fiche.
   $base     = PLUGIN_GESTION_WEBDIR;
   $fallback = $base . '/front/survey.form.php?id=' . $survey_id;
   $params   = [
      'job'          => $survey_id,
      'root_doc'     => $base,
      'root_modal'   => 'survey-form',
      'fallback_url' => $fallback,
      // `one_bl` : on a cliqué sur CE bon dans la liste. Lui seul est précoché
      // dans le formulaire de signature (cf. ajax/cri.php), les autres bons du
      // même ticket restent visibles et cochables.
      'one_bl'       => 1,
   ];
   $onclick = "if (typeof gestion_loadCriForm === 'function') {"
      . " gestion_loadCriForm('showCriForm', '" . $survey_id . "', " . json_encode($params) . ");"
      . " } else { window.location.href = " . json_encode($fallback) . "; } return false;";

   if ($signed === 1) {
      return '<button type="button" class="btn btn-sm btn-outline-secondary" style="color:var(--tblr-body-color, var(--bs-body-color, #000));" onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '" title="' . __s('Voir le BL signé', 'gestion') . '">'
         . '<i class="ti ti-eye me-1"></i>' . __s('Voir', 'gestion') . '</button>';
   }

   return '<button type="button" class="btn btn-sm btn-primary" onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '">'
      . '<i class="ti ti-signature me-1"></i>' . __s('Signer', 'gestion') . '</button>';
}

function plugin_gestion_uninstall() { // fonction desintallation du plugin

   // Suppression du dossier de documents : NON bloquante.
   // Sur certains FS (disque reseau/mappe Windows), chmod echoue et GLPI 11 l'enveloppe
   // dans Safe\chmod() qui leve une exception -> sans ce try/catch, toute la
   // desinstallation s'interrompt (les tables ne seraient jamais supprimees).
   $rep_files_gestion = GLPI_PLUGIN_DOC_DIR . "/gestion";
   try {
      if (file_exists($rep_files_gestion)) {
         Toolbox::deleteDir($rep_files_gestion);
      }
   } catch (Throwable $e) {
      // On poursuit la desinstallation meme si le dossier n'a pas pu etre supprime.
   }

   $migration = new Migration(PLUGIN_GESTION_VERSION);

   // Parse inc directory
   foreach (glob(dirname(__FILE__).'/inc/*') as $filepath) {
      // Load *.class.php files and get the class name
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginGestion' . ucfirst($matches[1]);
         include_once($filepath);
         // If the install method exists, load it
         if (method_exists($classname, 'uninstall')) {
            $classname::uninstall($migration);
         }
      }
   }

   $migration->executeMigration();

      //Delete rights associated with the plugin
      $profileRight = new ProfileRight();
      foreach (PluginGestionProfile::getAllRights() as $right) {
         $profileRight->deleteByCriteria(['name' => $right['field']]);
      }
      PluginGestionProfile::removeRightsFromSession();
      PluginGestionMenu::removeRightsFromSession();
   
      // Désinstallation : la tâche de cron doit être RETIRÉE, pas ré-enregistrée.
      // L'ancien CronTask::Register() laissait une ligne dans glpi_crontasks après
      // suppression du plugin : GLPI tentait ensuite de l'exécuter indéfiniment
      // (« Fonction PluginXxx::cronXxx indéfinie » dans cron.log).
      // unregister() supprime toutes les taches PluginGestion* et leurs logs.
      CronTask::unregister('Gestion');

   return true;
}



