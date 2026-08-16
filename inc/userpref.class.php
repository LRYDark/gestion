<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Préférences personnelles du plugin Gestion (onglet des Préférences GLPI).
 *
 * Mêmes règles que le plugin RP, pour que le comportement soit identique quel
 * que soit le plugin qui fournit les boutons flottants :
 *   MODE_MOBILE (défaut) : sur téléphone uniquement
 *   MODE_ALWAYS          : partout
 *   MODE_NEVER           : nulle part
 *
 * L'absence de ligne vaut MODE_MOBILE. Le droit de profil
 * `plugin_gestion_boutons` reste prioritaire sur la préférence.
 */
class PluginGestionUserpref extends CommonDBTM {

   const MODE_NEVER  = 0;
   const MODE_MOBILE = 1;
   const MODE_ALWAYS = 2;

   static $rightname = "plugin_gestion_boutons";

   /** @var array<int,array> cache par utilisateur */
   private static $cache = [];

   static function getTypeName($nb = 0) {
      return __('Gestion', 'gestion');
   }

   static function getIcon() {
      return "ti ti-file-invoice";
   }

   static function getModes(): array {
      return [
         self::MODE_MOBILE => __('Sur mobile uniquement (recommandé)', 'gestion'),
         self::MODE_ALWAYS => __('Toujours (mobile et ordinateur)', 'gestion'),
         self::MODE_NEVER  => __('Jamais', 'gestion'),
      ];
   }

   /**
    * @return array{fab_home:int,fab_ticket:int}
    */
   static function getForUser(?int $users_id = null): array {
      global $DB;

      $users_id = $users_id ?? (int)Session::getLoginUserID();
      if (isset(self::$cache[$users_id])) {
         return self::$cache[$users_id];
      }

      $prefs = ['fab_home' => self::MODE_MOBILE, 'fab_ticket' => self::MODE_MOBILE];

      if ($users_id > 0 && $DB->tableExists('glpi_plugin_gestion_userprefs')) {
         $row = $DB->request([
            'FROM'  => 'glpi_plugin_gestion_userprefs',
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
         ])->current();
         if ($row) {
            foreach (['fab_home', 'fab_ticket'] as $field) {
               $value = (int)($row[$field] ?? self::MODE_MOBILE);
               if (in_array($value, [self::MODE_NEVER, self::MODE_MOBILE, self::MODE_ALWAYS], true)) {
                  $prefs[$field] = $value;
               }
            }
         }
      }

      return self::$cache[$users_id] = $prefs;
   }

   static function saveForUser(array $input): bool {
      global $DB;

      $users_id = (int)Session::getLoginUserID();
      if ($users_id <= 0 || !$DB->tableExists('glpi_plugin_gestion_userprefs')) {
         return false;
      }

      $data = [];
      foreach (['fab_home', 'fab_ticket'] as $field) {
         $value = (int)($input[$field] ?? self::MODE_MOBILE);
         if (!in_array($value, [self::MODE_NEVER, self::MODE_MOBILE, self::MODE_ALWAYS], true)) {
            $value = self::MODE_MOBILE;
         }
         $data[$field] = $value;
      }

      $existing = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_gestion_userprefs',
         'WHERE'  => ['users_id' => $users_id],
         'LIMIT'  => 1,
      ])->current();

      unset(self::$cache[$users_id]);

      if ($existing) {
         return (bool)$DB->update('glpi_plugin_gestion_userprefs', $data, ['id' => (int)$existing['id']]);
      }
      $data['users_id'] = $users_id;
      return (bool)$DB->insert('glpi_plugin_gestion_userprefs', $data);
   }

   /**
    * Mode effectif : droit de profil croisé avec la préférence.
    */
   static function getEffectiveMode(string $button): int {
      $right = ($button === 'fab_home') ? READ : UPDATE;
      if (!Session::haveRight('plugin_gestion_boutons', $right)) {
         return self::MODE_NEVER;
      }
      $prefs = self::getForUser();
      return (int)($prefs[$button] ?? self::MODE_MOBILE);
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() === 'Preference'
          && Session::haveRightsOr('plugin_gestion_boutons', [READ, UPDATE])) {
         return self::createTabEntry(__('Gestion', 'gestion'), 0, null, self::getIcon());
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() === 'Preference') {
         self::showPreferencesForm();
      }
      return true;
   }

   static function showPreferencesForm(): void {
      $prefs      = self::getForUser();
      $modes      = self::getModes();
      $can_home   = Session::haveRight('plugin_gestion_boutons', READ);
      $can_ticket = Session::haveRight('plugin_gestion_boutons', UPDATE);

      echo "<form method='post' action='" . PLUGIN_GESTION_WEBDIR . "/front/userpref.form.php'>";
      // Jeton autonome dédié : le formulaire est rendu dans un onglet AJAX
      echo Html::hidden('plugin_gestion_userpref_csrf_token', ['value' => Session::getNewCSRFToken(true)]);

      echo "<div class='card mb-3'>";
      echo "<div class='card-header'><h3 class='card-title'>" . __('Boutons flottants', 'gestion') . "</h3></div>";
      echo "<div class='card-body'>";
      echo "<p class='text-muted'>"
         . __("Ces boutons donnent un accès rapide au scan et à la signature des bons de livraison. Ils sont pensés pour le téléphone : par défaut ils n'apparaissent pas sur ordinateur.", 'gestion')
         . "</p>";

      echo "<div class='row'>";

      if ($can_home) {
         echo "<div class='col-md-6 mb-3'>";
         echo "<label class='form-label'><i class='ti ti-scan me-1'></i>"
            . __("Bouton de scan sur la page d'accueil", 'gestion') . "</label>";
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

      echo "</div>";

      if (!$can_home && !$can_ticket) {
         echo "<div class='alert alert-info mb-0'>"
            . __("Aucun bouton flottant n'est autorisé par votre profil.", 'gestion')
            . "</div>";
      }

      echo "</div>";

      if ($can_home || $can_ticket) {
         echo "<div class='card-footer text-end'>";
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update_gestion_prefs', 'class' => 'btn btn-primary']);
         echo "</div>";
      }

      echo "</div>";
      Html::closeForm();
   }

   static function uninstall(Migration $migration) {
      global $DB;

      $table = 'glpi_plugin_gestion_userprefs';
      if ($DB->tableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
