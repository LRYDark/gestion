<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Préférences personnelles des boutons flottants (onglet des Préférences GLPI).
 *
 * ---- Un seul onglet, des réglages partagés ----
 *
 * RP et Gestion fournissent le même onglet ; un seul est enregistré à la fois
 * (Gestion s'efface quand RP est actif), l'utilisateur n'en voit donc jamais
 * deux. Mais chaque plugin a sa table, et l'utilisateur ne doit pas perdre ses
 * réglages parce qu'il a désinstallé l'un des deux : l'enregistrement écrit
 * donc dans LES DEUX tables présentes, et la lecture se rabat sur celle du
 * voisin quand la sienne est vide. Régler depuis Gestion puis installer RP, ou
 * l'inverse, donne le même résultat, et désinstaller l'un laisse l'autre avec
 * les réglages intacts.
 *
 * ---- Les trois réglages ----
 *
 *  1. `fab_home`      où afficher le bouton « Scanner / Rechercher » ;
 *  2. `fab_ticket`    où afficher le bouton de signature des tickets ;
 *  3. `fab_home_tabs` quels onglets le modal d'accueil propose.
 *
 * Les deux premiers partagent la même échelle :
 *   MODE_MOBILE (défaut) : sur téléphone uniquement
 *   MODE_ALWAYS          : partout (mobile et ordinateur)
 *   MODE_NEVER           : nulle part
 *
 * L'absence de ligne vaut MODE_MOBILE. Le droit de profil
 * `plugin_gestion_boutons` reste prioritaire sur la préférence.
 *
 * Jumeau volontaire de `plugins/rp/inc/userpref.class.php` : chaque plugin doit
 * fonctionner seul. Seuls diffèrent les noms, l'icône et `canUseHomeButton()` —
 * Gestion seul ne dispose que des bons de livraison. Toute correction ici est à
 * reporter là-bas.
 */
class PluginGestionUserpref extends CommonDBTM {

   const MODE_NEVER  = 0;
   const MODE_MOBILE = 1;
   const MODE_ALWAYS = 2;

   /*
    * Onglets du modal d'accueil, en champ de bits : les deux onglets répondent
    * à deux questions distinctes (« quel est ce numéro ? » / « où ai-je vu ce
    * mot ? ») et rien n'oblige à les vouloir tous les deux. Un champ de bits
    * plutôt que trois valeurs numérotées : l'ajout d'un futur onglet n'oblige
    * alors pas à renuméroter l'existant.
    */
   const TABS_RESOLVE = 1;   // « BL / Ticket » : résolution d'un identifiant
   const TABS_SEARCH  = 2;   // « Par mot-clé » : fouille du contenu des tickets
   const TABS_BOTH    = 3;   // les deux (défaut)

   /** Table du plugin, et celle du voisin avec qui les réglages sont partagés */
   const TABLE         = 'glpi_plugin_gestion_userprefs';
   const SIBLING_TABLE = 'glpi_plugin_rp_userprefs';
   const SIBLING_CLASS = 'PluginRpUserpref';

   /** Préfixe des positions mémorisées par le navigateur (localStorage) */
   const POS_PREFIX = 'gestion_fab_pos_';

   static $rightname = "plugin_gestion_boutons";

   /** @var array<int,array> cache par utilisateur */
   private static $cache = [];

   static function getTypeName($nb = 0) {
      return __('Boutons flottants', 'gestion');
   }

   static function getIcon() {
      return "ti ti-hand-click";
   }

   static function getModes(): array {
      return [
         self::MODE_MOBILE => __('Sur mobile uniquement (recommandé)', 'gestion'),
         self::MODE_ALWAYS => __('Toujours (mobile et ordinateur)', 'gestion'),
         self::MODE_NEVER  => __('Jamais', 'gestion'),
      ];
   }

   /**
    * Les bons de livraison sont-ils atteignables ?
    *
    * Ils sont le périmètre propre de ce plugin : la table et le droit suffisent.
    * Le test existe surtout pour rester le miroir exact de
    * PluginRpUserpref::hasBl(), où la question se pose vraiment.
    */
   static function hasBl(): bool {
      global $DB;

      return $DB->tableExists('glpi_plugin_gestion_surveys')
         && Session::haveRight('plugin_gestion_survey', READ);
   }

   /**
    * Nom du premier onglet du modal d'accueil.
    *
    * Sans accès aux bons, cet onglet ne résout plus que des tickets : l'appeler
    * « BL / Ticket » annoncerait une recherche qui ne renverra jamais rien. Le
    * libellé suit donc ce que l'onglet peut réellement trouver, ici comme dans
    * le modal (fab_gestion.js lit la même information).
    */
   static function getResolveTabLabel(): string {
      return self::hasBl() ? __('BL / Ticket', 'gestion') : __('Ticket', 'gestion');
   }

   /**
    * Libellés des onglets du modal d'accueil. Ils reprennent mot pour mot ceux
    * affichés dans le modal, sans quoi le réglage désignerait autre chose que
    * ce que l'utilisateur voit.
    */
   static function getHomeTabsChoices(): array {
      return [
         self::TABS_BOTH    => __('Les deux (recommandé)', 'gestion'),
         self::TABS_RESOLVE => sprintf(__('« %s » uniquement', 'gestion'), self::getResolveTabLabel()),
         self::TABS_SEARCH  => __('« Par mot-clé » uniquement', 'gestion'),
      ];
   }

   /**
    * L'utilisateur a-t-il quelque chose à faire du bouton d'accueil ?
    *
    * Ce bouton n'est fourni par Gestion que lorsque RP est inactif : son
    * périmètre se limite alors aux bons de livraison. Sans ce droit, il
    * n'ouvrirait qu'un écran vide — on ne l'affiche pas, et on ne propose pas
    * non plus de le régler.
    */
   static function canUseHomeButton(): bool {
      return Session::haveRight('plugin_gestion_survey', READ);
   }

   /**
    * Valeurs par défaut : celles qui s'appliquent tant que l'utilisateur n'a
    * rien réglé.
    *
    * @return array{fab_home:int,fab_ticket:int,fab_home_tabs:int}
    */
   static function getDefaults(): array {
      return [
         'fab_home'      => self::MODE_MOBILE,
         'fab_ticket'    => self::MODE_MOBILE,
         'fab_home_tabs' => self::TABS_BOTH,
      ];
   }

   /**
    * Lit et valide la ligne d'un utilisateur dans l'une des deux tables.
    *
    * Chaque valeur est vérifiée séparément : une colonne absente (migration pas
    * encore jouée) ou une valeur aberrante retombe sur le défaut sans emporter
    * les autres réglages.
    *
    * @return array|null null si la table ou la ligne n'existe pas
    */
   private static function readRow(string $table, int $users_id): ?array {
      global $DB;

      if ($users_id <= 0 || !$DB->tableExists($table)) {
         return null;
      }

      $row = $DB->request([
         'FROM'  => $table,
         'WHERE' => ['users_id' => $users_id],
         'LIMIT' => 1,
      ])->current();

      if (!$row) {
         return null;
      }

      $prefs = self::getDefaults();
      $modes = [self::MODE_NEVER, self::MODE_MOBILE, self::MODE_ALWAYS];

      foreach (['fab_home', 'fab_ticket'] as $field) {
         if (array_key_exists($field, $row) && in_array((int)$row[$field], $modes, true)) {
            $prefs[$field] = (int)$row[$field];
         }
      }

      $tabs = [self::TABS_RESOLVE, self::TABS_SEARCH, self::TABS_BOTH];
      if (array_key_exists('fab_home_tabs', $row) && in_array((int)$row['fab_home_tabs'], $tabs, true)) {
         $prefs['fab_home_tabs'] = (int)$row['fab_home_tabs'];
      }

      return $prefs;
   }

   /**
    * Préférences d'un utilisateur, valeurs par défaut comprises.
    *
    * Sa propre table d'abord ; à défaut celle du plugin voisin, que l'on recopie
    * alors chez soi. Cette recopie est ce qui rend la désinstallation du voisin
    * sans conséquence : sans elle, les réglages resteraient dans une table qui
    * partirait avec lui.
    *
    * @return array{fab_home:int,fab_ticket:int,fab_home_tabs:int}
    */
   static function getForUser(?int $users_id = null): array {
      $users_id = $users_id ?? (int)Session::getLoginUserID();
      if (isset(self::$cache[$users_id])) {
         return self::$cache[$users_id];
      }

      $prefs = self::readRow(self::TABLE, $users_id);

      if ($prefs === null) {
         $inherited = self::readRow(self::SIBLING_TABLE, $users_id);
         if ($inherited !== null) {
            $prefs = $inherited;
            self::writeRow(self::TABLE, $users_id, $inherited);
         }
      }

      return self::$cache[$users_id] = ($prefs ?? self::getDefaults());
   }

   /**
    * Onglets retenus pour le modal d'accueil.
    */
   static function getHomeTabs(): int {
      $prefs = self::getForUser();
      return (int)($prefs['fab_home_tabs'] ?? self::TABS_BOTH);
   }

   /**
    * Écrit les réglages dans une table donnée (insertion ou mise à jour).
    *
    * `fab_home_tabs` n'est écrit que si la colonne est là : le plugin tourne
    * avec son code neuf dès la copie des fichiers, alors que la migration, elle,
    * attend le clic « Mettre à jour ». Sans cette garde, enregistrer ses
    * préférences échouerait dans cet intervalle et emporterait les deux autres
    * réglages avec elle.
    *
    * @return bool false si la table est absente ou l'écriture refusée
    */
   private static function writeRow(string $table, int $users_id, array $values): bool {
      global $DB;

      if ($users_id <= 0 || !$DB->tableExists($table)) {
         return false;
      }

      $data = [
         'fab_home'   => (int)$values['fab_home'],
         'fab_ticket' => (int)$values['fab_ticket'],
      ];
      if ($DB->fieldExists($table, 'fab_home_tabs')) {
         $data['fab_home_tabs'] = (int)$values['fab_home_tabs'];
      }

      $existing = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => $table,
         'WHERE'  => ['users_id' => $users_id],
         'LIMIT'  => 1,
      ])->current();

      if ($existing) {
         return (bool)$DB->update($table, $data, ['id' => (int)$existing['id']]);
      }
      $data['users_id'] = $users_id;
      return (bool)$DB->insert($table, $data);
   }

   /**
    * Enregistre les préférences de l'utilisateur connecté, dans sa table ET
    * dans celle du plugin voisin si elle existe.
    *
    * Le succès se juge sur SA table : la table du voisin peut très bien être
    * absente, ce n'est pas un échec.
    */
   static function saveForUser(array $input): bool {
      $users_id = (int)Session::getLoginUserID();
      if ($users_id <= 0) {
         return false;
      }

      /*
       * On part de l'existant, et non des valeurs par défaut : le formulaire ne
       * montre que les réglages auxquels l'utilisateur a droit. Repartir des
       * défauts remettrait à zéro, en silence, ceux qu'il n'a pas vus — et,
       * depuis que les deux plugins se partagent les préférences, cette remise
       * à zéro serait recopiée chez le voisin.
       */
      $values = self::getForUser($users_id);

      $modes = [self::MODE_NEVER, self::MODE_MOBILE, self::MODE_ALWAYS];
      foreach (['fab_home', 'fab_ticket'] as $field) {
         if (!array_key_exists($field, $input)) {
            continue;
         }
         $value = (int)$input[$field];
         $values[$field] = in_array($value, $modes, true) ? $value : self::MODE_MOBILE;
      }

      if (array_key_exists('fab_home_tabs', $input)) {
         $tabs  = [self::TABS_RESOLVE, self::TABS_SEARCH, self::TABS_BOTH];
         $value = (int)$input['fab_home_tabs'];
         $values['fab_home_tabs'] = in_array($value, $tabs, true) ? $value : self::TABS_BOTH;
      }

      $ok = self::writeRow(self::TABLE, $users_id, $values);
      self::writeRow(self::SIBLING_TABLE, $users_id, $values);

      self::invalidateCache($users_id);
      if (class_exists(self::SIBLING_CLASS)) {
         // Le voisin garde son propre cache statique : sans cet appel il
         // servirait les anciennes valeurs jusqu'à la fin de la requête.
         call_user_func([self::SIBLING_CLASS, 'invalidateCache'], $users_id);
      }

      return $ok;
   }

   /**
    * Oublie les préférences mises en cache pour un utilisateur.
    */
   static function invalidateCache(int $users_id): void {
      unset(self::$cache[$users_id]);
   }

   /**
    * Mode effectif d'un bouton : croise le droit de profil, la préférence et —
    * pour le bouton d'accueil — ce que l'utilisateur peut réellement en faire.
    * Renvoie MODE_NEVER si l'une des trois conditions manque.
    */
   static function getEffectiveMode(string $button): int {
      $right = ($button === 'fab_home') ? READ : UPDATE;
      if (!Session::haveRight('plugin_gestion_boutons', $right)) {
         return self::MODE_NEVER;
      }
      if ($button === 'fab_home' && !self::canUseHomeButton()) {
         return self::MODE_NEVER;
      }
      $prefs = self::getForUser();
      return (int)($prefs[$button] ?? self::MODE_MOBILE);
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() === 'Preference'
          && Session::haveRightsOr('plugin_gestion_boutons', [READ, UPDATE])) {
         return self::createTabEntry(self::getTypeName(), 0, null, self::getIcon());
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() === 'Preference') {
         self::showPreferencesForm();
      }
      return true;
   }

   /**
    * Formulaire des préférences (composants natifs GLPI).
    */
   static function showPreferencesForm(): void {
      $prefs      = self::getForUser();
      $modes      = self::getModes();
      // Même règle que l'affichage : le droit de profil ET de quoi s'en servir.
      $can_home   = Session::haveRight('plugin_gestion_boutons', READ) && self::canUseHomeButton();
      $can_ticket = Session::haveRight('plugin_gestion_boutons', UPDATE);

      echo "<form method='post' action='" . PLUGIN_GESTION_WEBDIR . "/front/userpref.form.php'>";
      // Jeton autonome dédié : le formulaire est rendu dans un onglet AJAX
      echo Html::hidden('plugin_gestion_userpref_csrf_token', ['value' => Session::getNewCSRFToken(true)]);

      echo "<div class='card mb-3'>";
      echo "<div class='card-header'><h3 class='card-title'>" . self::getTypeName() . "</h3></div>";
      echo "<div class='card-body'>";
      echo "<p class='text-muted'>"
         . __("Ces boutons donnent un accès rapide à la recherche et à la signature des bons de livraison. Ils sont pensés pour le téléphone : par défaut ils n'apparaissent pas sur ordinateur.", 'gestion')
         . "</p>";

      echo "<div class='row'>";

      if ($can_home) {
         echo "<div class='col-md-6 mb-3'>";
         echo "<label class='form-label'><i class='ti ti-scan me-1'></i>"
            . __("Bouton « Scanner / Rechercher » sur la page d'accueil", 'gestion') . "</label>";
         Dropdown::showFromArray('fab_home', $modes, [
            'value' => $prefs['fab_home'],
            'width' => '100%',
         ]);
         echo "</div>";
      }

      if ($can_ticket) {
         echo "<div class='col-md-6 mb-3'>";
         echo "<label class='form-label'><i class='ti ti-signature me-1'></i>"
            . __('Bouton de signature sur les tickets', 'gestion') . "</label>";
         Dropdown::showFromArray('fab_ticket', $modes, [
            'value' => $prefs['fab_ticket'],
            'width' => '100%',
         ]);
         echo "</div>";
      }

      echo "</div>"; // row

      /*
       * Onglets du modal d'accueil.
       *
       * Réglage subordonné : il n'a de sens que si le bouton s'affiche quelque
       * part. Il est donc masqué quand le bouton est réglé sur « Jamais » —
       * immédiatement, sans attendre l'enregistrement, sinon le formulaire
       * afficherait un réglage que le choix voisin vient de rendre caduc.
       */
      if ($can_home) {
         $hidden = ($prefs['fab_home'] === self::MODE_NEVER) ? " style='display:none'" : "";
         echo "<div class='row' id='gestion_fab_home_tabs_row'{$hidden}>";
         echo "<div class='col-md-6 mb-3'>";
         echo "<label class='form-label'><i class='ti ti-layout-navbar me-1'></i>"
            . __('Onglets proposés par ce bouton', 'gestion') . "</label>";
         Dropdown::showFromArray('fab_home_tabs', self::getHomeTabsChoices(), [
            'value' => $prefs['fab_home_tabs'],
            'width' => '100%',
         ]);
         echo "<div class='form-hint'>"
            . __("« BL / Ticket » identifie un numéro scanné ou saisi ; « Par mot-clé » fouille le contenu des tickets.", 'gestion')
            . "</div>";
         echo "</div>";
         echo "</div>"; // row
      }

      if (!$can_home && !$can_ticket) {
         echo "<div class='alert alert-info mb-0'>"
            . __("Aucun bouton flottant n'est autorisé par votre profil.", 'gestion')
            . "</div>";
      }

      echo "</div>"; // card-body

      if ($can_home || $can_ticket) {
         echo "<div class='card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center'>";
         /*
          * Remise en place des boutons.
          *
          * La position d'un bouton déplacé est mémorisée par le NAVIGATEUR
          * (localStorage), pas en base : elle n'a donc rien à faire dans le
          * formulaire, et ce bouton ne doit surtout pas l'envoyer au serveur —
          * d'où type='button'. Un bouton traîné hors de vue, ou laissé sous un
          * élément de l'interface, se récupère ici.
          */
         echo "<button type='button' class='btn btn-outline-secondary' id='gestion_fab_reset_pos'>"
            . "<i class='ti ti-arrow-back-up me-1'></i>"
            . __('Réinitialiser la position des boutons', 'gestion')
            . "</button>";
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update_gestion_prefs', 'class' => 'btn btn-primary']);
         echo "</div>";
      }

      echo "</div>"; // card
      Html::closeForm();

      self::showPreferencesScript($can_home);
   }

   /**
    * Comportements du formulaire : masquage du réglage des onglets et remise à
    * zéro des positions mémorisées.
    *
    * jQuery et non `addEventListener` : les listes de GLPI sont des select2,
    * qui signalent leurs changements par un événement jQuery — un écouteur
    * natif ne l'entendrait jamais.
    */
   private static function showPreferencesScript(bool $can_home): void {
      $never    = self::MODE_NEVER;
      $prefix   = self::POS_PREFIX;
      $msg_done = json_encode(__('Position des boutons réinitialisée.', 'gestion'));
      $msg_none = json_encode(__('Aucune position personnalisée à réinitialiser.', 'gestion'));

      $tabs_block = $can_home
         ? "\$(document).on('change', 'select[name=\"fab_home\"]', function () {
               \$('#gestion_fab_home_tabs_row').toggle(parseInt(\$(this).val(), 10) !== {$never});
            });"
         : '';

      echo Html::scriptBlock(<<<JS
      $(function () {
         {$tabs_block}

         $(document).on('click', '#gestion_fab_reset_pos', function (e) {
            e.preventDefault();
            var removed = 0;
            try {
               // Parcours à l'envers : supprimer une clé décale les suivantes.
               for (var i = window.localStorage.length - 1; i >= 0; i--) {
                  var key = window.localStorage.key(i);
                  if (key && key.indexOf('{$prefix}') === 0) {
                     window.localStorage.removeItem(key);
                     removed++;
                  }
               }
            } catch (err) { /* stockage indisponible : rien à nettoyer */ }

            // Remise en place immédiate des boutons présents sur cette page :
            // vider le style rend la main au CSS, qui porte la position d'origine.
            $('.gestion-fab').each(function () {
               this.style.left = '';
               this.style.top = '';
               this.style.right = '';
               this.style.bottom = '';
            });

            glpi_toast_info(removed > 0 ? {$msg_done} : {$msg_none});
         });
      });
JS
      );
   }

   static function uninstall(Migration $migration) {
      global $DB;

      $table = self::TABLE;
      if ($DB->tableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
