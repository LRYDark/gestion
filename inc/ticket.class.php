<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

//------------------------------------------------------------------------------------------
class PluginGestionTicket extends CommonDBTM {

   public static $rightname = 'plugin_gestion_sign';
   public  static  $gestion = 0 ;

   public static function getTable($classname = null) {
      if ($classname === null || $classname === static::class) {
         return 'glpi_plugin_gestion_surveys';
      }

      return parent::getTable($classname);
   }

//*--------------------------------------------------------------------------------------------- GESTION ONGLET
   static function getIcon() {
      return "fa-solid fa-file-contract";
   }

   static function getTypeName($nb = 0) { // voir doc glpi 
      return _n('Gestion', 'Gestion', $nb, 'gestion');
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { // voir doc glpi 
      if(Session::haveRight("plugin_gestion_sign", READ)){
         $nb = self::countForItem($item);
         switch ($item->getType()) {
            case 'Ticket' :
                  return self::createTabEntry(self::getTypeName($nb), $nb);
            default :
               return self::getTypeName($nb);
         }
         return '';
      }
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) { // voir doc glpi 
      switch ($item->getType()) {
         case 'Ticket' :
            self::showForTicket($item);
            break;
      }
      return true;
   }

   public static function countForItem(CommonDBTM $item) { 
      if(Session::haveRight("plugin_gestion_sign", READ)){
         return countElementsInTable('glpi_plugin_gestion_surveys', ['tickets_id' => $item->getID()]);
      }
   }

   static function getAllForTicket($ID): array { // fonction qui va récupérer les informations sur le ticket 
      global $DB;

      $request = [
         'SELECT' => '*',
         'FROM'   => 'glpi_plugin_gestion_surveys',
         'WHERE'  => [
            'tickets_id' => $ID,
         ],
         'ORDER'  => ['id DESC'],
      ];

      $vouchers = [];
      foreach ($DB->request($request) as $data) {
         $vouchers[$data['id']] = $data;
      }

      return $vouchers;
   }

   static public function AddDocForm($params) { 
      global $CFG_GLPI, $DB;

      $item = $params['item'];

      if(Session::haveRight("plugin_gestion_add", READ) || Session::haveRight("plugin_gestion_add", UPDATE)){
         if ($item instanceof Ticket) {
            echo PluginGestionTicketConfig::showForTicket($item, true);
            return;
         }
      }
   }

   static function showForTicket(Ticket $ticket) { // formulaire sur le ticket
      global $DB, $CFG_GLPI;

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css">';
      // Remote signature additions
      echo '<script>
         window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
      </script>';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?v=' . (defined('PLUGIN_GESTION_VERSION') ? PLUGIN_GESTION_VERSION : '1') . '" defer></script>';

      function isMobile() {
         return preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
      }

      $ID = $ticket->getField('id'); // recupération de l'id ticket
      $sum = 0;
      $count = 0;

      $params = ['job'           => $ticket->getField('id'),
               'root_doc'      => PLUGIN_GESTION_WEBDIR,
               'root_modal'    => 'ticket-form'];

      if (!$ticket->can($ID, READ)) {
         return false;
      }

      $canedit = false;
      if (Session::haveRight(Entity::$rightname, PURGE)) { // vérification des droits avanat d'affiché le canedit
         $canedit = true;
      } else if (
         $ticket->canEdit($ID)
         && !in_array($ticket->fields['status'], array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()))
      ) {
         $canedit = true;
      }

      $out = "";
      $out .= "<div class='spaced'>";
      $out .= "<table class='tab_cadre_fixe'>";
      $out .= "<tr class='tab_bg_1'><th colspan='2'>";
      $out .= __('Gestion BL', 'gestion');
      $out .= "</th></tr></table></div>";

      $number = self::countForItem($ticket);
      $rand   = mt_rand();

      if(Session::haveRight("plugin_gestion_sign", READ) || Session::haveRight("plugin_gestion_sign", PURGE)){
         if ($number) {
            $out .= "<div class='spaced'>";

            if(Session::haveRight("plugin_gestion_sign", PURGE)){
               if ($canedit) {
                  $out .= Html::getOpenMassiveActionsForm('mass'.__CLASS__.$rand);
                  if( Session::haveRight("plugin_gestion_sign", PURGE)){
                     $massiveactionparams =  [
                        'num_displayed'    => $number,
                        'container'        => 'mass'.__CLASS__.$rand,
                        'rand'             => $rand,
                        'display'          => false,
                        'specific_actions' => [
                           'purge'  => _x('button', 'Supprimer définitivement de GLPI')
                        ]
                     ];
                  }
                  $out .= Html::showMassiveActions($massiveactionparams);
               }
            }

            // --- Début : wrapper scrollable pour rendre le tableau responsive sans modifier sa forme
            $out .= "<div class='gestion-table-wrapper' style='overflow-x:auto; -webkit-overflow-scrolling:touch; width:100%; margin-bottom:.5rem;'>";
            $out .= "<table class='tab_cadre_fixehov' style='width:max-content; min-width:100%; table-layout:auto; white-space:nowrap;'>";
            // --- Fin : wrapper scrollable

            $header_begin  = "<tr>";
            $header_top    = '';
            $header_bottom = '';
            $header_end    = '';
            if(Session::haveRight("plugin_gestion_sign", PURGE)){
               if ($canedit) {
                  $header_begin  .= "<th width='10'>";
                  $header_top    .= Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand);
                  $header_bottom .= Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand);
                  $header_end    .= "</th>";
               }
            }

            // tableau d'affichage des valeurs
            $header_end .= "<th class='center'>".__('ID', 'gestion')."</th>";
            // $header_end .= "<th class='center'>".__('Entité', 'gestion')."</th>";
            $header_end .= "<th class='center'>".__("Date de signature", 'gestion')."</th>";
            if (!isMobile()) {
               $header_end .= "<th class='center'>".__('Technicien', 'gestion')."</th>";
               $header_end .= "<th class='center'>".__('Signataire', 'gestion')."</th>";
            }
            $header_end .= "<th class='center'>".__('Numéro de BL / BC', 'gestion')."</th>";
            $header_end .= "</tr>";
            $out .= $header_begin.$header_top.$header_end;

            foreach (self::getAllForTicket($ID) as $data) {

               $out .= "<tr class='tab_bg_2'>";
               if(Session::haveRight("plugin_gestion_sign", PURGE)){
                  if ($canedit) {
                     $out .= "<td width='10'>";
                     $out .= Html::getMassiveActionCheckBox(__CLASS__, $data["id"]);
                     $out .= "</td>";
                  }
               }

               $out .= "<td class='center'>";
               $out .= $data['id'];
               $out .= "</td>";

               $out .= "<td class='center'>";
               $signatureDate = !empty($data["doc_date"]) ? $data["doc_date"] : ($data["date_creation"] ?? null);
               $out .= Html::convDate($signatureDate);
               $out .= "</td>";

               $showuserlink = 0;
               if (Session::haveRight('user', READ)) {
                  $showuserlink = 1;
               }

               if (!isMobile()) {
                  $out .= "<td class='center'>";
                  $out .= getUserName($data["users_id"], $showuserlink);
                  $out .= "</td>";
                  $out .= "<td class='center'>";
                  $out .= $data["users_ext"];
                  $out .= "</td>";
               }

               $out .= "<td class='center'>";
               if (!isMobile()) {
                  $BlName = $data['bl']; // Récupère le numéro de BL depuis la base
               } else {
                  $BlName = substr($data['bl'], 0, 8).'...'; // Raccourci sur mobile
               }
               $blId = $data['id']; // Récupère l'ID de la ligne
               $status = $data["signed"]; // 1 = signé / 0 = non signé

               if ($data["signed"] == 1) {
                  $out .= Html::submit($BlName . ' - Signé', [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-secondary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$blId', " . json_encode($params) . "); return false;"
                  ]);
               } else {
                  $out .= Html::submit($BlName, [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-primary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$blId', " . json_encode($params) . "); return false;"
                  ]);
               }
               $out .= "</td></tr>";
            }

            $out .= $header_begin.$header_bottom.$header_end;
            $out .= "</table>";          // ferme le tableau
            $out .= "</div>";            // ferme le wrapper scrollable

            if(Session::haveRight("plugin_gestion_sign", PURGE)){
               if ($canedit) {
                  $massiveactionparams['ontop'] = false;
                  $out .= Html::showMassiveActions($massiveactionparams);
                  $out .= Html::closeForm(false);
               }
            }

         } else {
            $out .= "<p class='center b'>".__('Aucun BL / BC associé', 'gestion')."</p>";
         }
      }
      echo $out;
   }

   /**
    * Association automatique des BL d'un ticket (creation + ouverture), IDEMPOTENTE.
    * Extrait les numeros BL (BL + 6 chiffres) du titre + description + suivis + taches,
    * puis associe / insere dans glpi_plugin_gestion_surveys avec dedoublonnage par
    * bl_number => jamais de doublon. Remplace l'ancienne association du plugin warrantycheck.
    * Activable via le reglage AutoAssociateBl ; uniquement en mode Sage.
    */
   static function autoAssociateBl($ticket_id, $preload = null) {
      global $DB;

      $ticket_id = (int)$ticket_id;
      if ($ticket_id <= 0) {
         return;
      }

      $config = PluginGestionConfig::getInstance();
      if ((int)$config->AutoAssociateBl() !== 1 || (int)$config->mode() !== 1) {
         return; // desactive ou hors mode Sage
      }

      // --- Texte du ticket : titre + description + suivis + taches ---
      // A la creation (hook item_add) on recoit $item->fields (preload) : plus fiable
      // que re-interroger la BDD selon l'etat transactionnel du chemin de creation.
      if (is_array($preload) && (array_key_exists('content', $preload) || array_key_exists('name', $preload))) {
         $ticketRow = $preload;
      } else {
         $ticketRow = $DB->request([
            'SELECT' => ['name', 'content', 'entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $ticket_id],
            'LIMIT'  => 1,
         ])->current();
      }
      if (!$ticketRow) {
         return;
      }
      $text = ' ' . (string)($ticketRow['name'] ?? '') . ' ' . (string)($ticketRow['content'] ?? '');
      foreach ($DB->request([
         'SELECT' => ['content'],
         'FROM'   => 'glpi_itilfollowups',
         'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticket_id],
      ]) as $f) {
         $text .= ' ' . (string)($f['content'] ?? '');
      }
      foreach ($DB->request([
         'SELECT' => ['content'],
         'FROM'   => 'glpi_tickettasks',
         'WHERE'  => ['tickets_id' => $ticket_id],
      ]) as $tk) {
         $text .= ' ' . (string)($tk['content'] ?? '');
      }

      // --- Extraction des numeros BL (regex d'abord => si aucun, zero appel Sage) ---
      // On remplace les balises par des ESPACES (et NON strip_tags qui colle les
      // paragraphes : "<p>bl203846</p><p>texte</p>" -> "bl203846texte" cassait le match).
      $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = preg_replace('/<[^>]*>/u', ' ', $text);
      // Numero = BL|BC + 4 a 8 chiffres, NON suivi d'un chiffre. Pas de \b final
      // (sinon "bl203846texte" ne matchait pas) -> tolere du texte colle apres.
      $nbMatch = preg_match_all('/\bB[LC]\s?\d{4,8}(?!\d)/i', $text, $m);
      if (!$nbMatch || empty($m[0])) {
         return;
      }
      $numbers = [];
      foreach ($m[0] as $raw) {
         $num = function_exists('pluginGestionBlNumber')
            ? pluginGestionBlNumber($raw)
            : strtoupper(preg_replace('/\s+/', '', $raw));
         if ($num !== '') {
            $numbers[$num] = $num;
         }
      }
      if (empty($numbers)) {
         return;
      }

      $entities_id = (int)($ticketRow['entities_id'] ?? 0);
      require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

      foreach ($numbers as $serial) {
         try {
            // Dedoublonnage par bl_number : jamais 2 fois le meme BL.
            $existing = $DB->request([
               'SELECT' => ['id', 'tickets_id'],
               'FROM'   => 'glpi_plugin_gestion_surveys',
               'WHERE'  => ['bl_number' => $serial],
               'LIMIT'  => 1,
            ])->current();

            if ($existing) {
               // Existe : on associe seulement s'il n'a pas encore de ticket.
               if ((int)$existing['tickets_id'] === 0) {
                  $DB->update('glpi_plugin_gestion_surveys', ['tickets_id' => $ticket_id], ['id' => (int)$existing['id']]);
               }
               continue; // deja sur un ticket => on ne touche pas (pas de doublon)
            }

            // Inconnu : valider via Sage puis inserer (avec bl_number).
            $fields = parseDocument($serial);
            if (!is_array($fields)) {
               continue;
            }
            $client    = (string)($fields['client'] ?? '');
            $file_path = $serial . '_' . str_replace(' ', '_', $client);
            $tracker   = $fields['tracker'] ?? null;
            $doc_url   = function_exists('plugin_gestion_build_view_pdf_url')
               ? plugin_gestion_build_view_pdf_url($serial, true)
               : '';

            $DB->insert('glpi_plugin_gestion_surveys', [
               'tickets_id'    => $ticket_id,
               'entities_id'   => $entities_id,
               'url_bl'        => $serial,
               'bl'            => $file_path,
               'bl_number'     => $serial,
               'doc_url'       => $doc_url,
               'tracker'       => ($tracker !== null && $tracker !== '') ? $tracker : null,
               'save'          => 'Sage',
               'date_creation' => date('Y-m-d H:i:s'),
            ]);
         } catch (Throwable $e) {
            // BL absent de Sage (404) ou erreur reseau : ignore silencieusement.
            continue;
         }
      }
   }

   /**
    * Hook item_add (Ticket) : association des BL a la creation du ticket.
    */
   static function autoAssociateBlOnAdd($item) {
      if ($item instanceof Ticket) {
         $id = (int)$item->getID();
         if ($id > 0) {
            // On passe $item->fields (titre + description soumis) : plus fiable que la BDD.
            self::autoAssociateBl($id, is_array($item->fields ?? null) ? $item->fields : null);
         }
      }
   }

   static function install(Migration $migration) { // fonction intsllation de la table en BDD
      global $DB;

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
                     `bl_number` VARCHAR(50) NULL,
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     `doc_url` TEXT NULL,
                     `doc_date` TIMESTAMP NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`),
                     UNIQUE KEY `bl_number` (`bl_number`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());
      }
   }

   static function uninstall(Migration $migration) {

      $table = 'glpi_plugin_gestion_surveys';
      $migration->dropTable($table);
   }
}

