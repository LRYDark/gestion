<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

//------------------------------------------------------------------------------------------
class PluginGestionTicket extends CommonDBTM {

   public static $rightname = 'gestion';
   public  static  $EntitieAddress = 0 ;

//*--------------------------------------------------------------------------------------------- GESTION ONGLET
   static function getTypeName($nb = 0) { // voir doc glpi 
      if(Session::haveRight("plugin_gestion_gestion", READ)){
         return _n('Gestion', 'Gestion', $nb, 'gestion');
      }
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { // voir doc glpi 
         $nb = self::countForItem($item);
         switch ($item->getType()) {
            case 'Ticket' :
                  return self::createTabEntry(self::getTypeName($nb), $nb);
            default :
               return self::getTypeName($nb);
         }
         return '';
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
      if(Session::haveRight("plugin_gestion_gestion", READ)){
         return countElementsInTable(self::getTable(), ['tickets_id' => $item->getID()]);
      }
   }

   static function getAllForTicket($ID): array { // fonction qui va récupérer les informations sur le ticket 
      global $DB;

      $request = [
         'SELECT' => '*',
         'FROM'   => self::getTable(),
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
//*--------------------------------------------------------------------------------------------- 

   static function showForTicket(Ticket $ticket) { // formulaire sur le ticket
      global $DB, $CFG_GLPI;

      function isMobile() {
         return preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
      }
     
      $ID = $ticket->getField('id'); // recupération de l'id ticket
      $sum = 0;
      $count = 0;

      $params = ['job'        => $ticket->getField('id'),
      'root_doc'   => PLUGIN_GESTION_WEBDIR];

      if (!$ticket->can($ID, READ)) {
         return false;
      }

      $canedit = false;
      if (Session::haveRight(Entity::$rightname, UPDATE)) { // vérification des droits avanat d'affiché le canedit
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
      $out .= __('Gestion BL / BC', 'gestion');
      $out .= "</th></tr></table></div>";

      $number = self::countForItem($ticket);
      $rand   = mt_rand();

      if(Session::haveRight("plugin_gestion_gestion", READ) || Session::haveRight("plugin_gestion_gestion", PURGE)){
         if ($number) {
            $out .= "<div class='spaced'>";

            if(Session::haveRight("plugin_gestion_gestion", PURGE)){
               if ($canedit) { // fonction massiveactions (utiliser dans la pagestionie temps de trajet sur le ticket)
                  $out .= Html::getOpenMassiveActionsForm('mass'.__CLASS__.$rand);
                     if( Session::haveRight("plugin_gestion_gestion", PURGE)){
                        $massiveactionparams =  [
                           'num_displayed'    => $number,
                           'container'        => 'mass'.__CLASS__.$rand,
                           'rand'             => $rand,
                           'display'          => false,
                           'specific_actions' => [
                              'purge'  => _x('button', 'Delete permanently')
                           ]
                        ];
                     }
                  $out .= Html::showMassiveActions($massiveactionparams);
               }
            }

               $out .= "<table class='tab_cadre_fixehov'>";
               $header_begin  = "<tr>";
               $header_top    = '';
               $header_bottom = '';
               $header_end    = '';
               if(Session::haveRight("plugin_gestion_gestion", PURGE)){
                  if ($canedit) {
                     $header_begin  .= "<th width='10'>";
                     $header_top    .= Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand);
                     $header_bottom .= Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand);
                     $header_end    .= "</th>";
                  }
               }

            //tableau d'affichage des valeurs
            $header_end .= "<th class='center'>".__('ID', 'gestion')."</th>";
            //$header_end .= "<th class='center'>".__('Entité', 'gestion')."</th>";
            $header_end .= "<th class='center'>".__("Date de signature", 'gestion')."</th>";
            if (!isMobile()) {
               $header_end .= "<th class='center'>".__('Technicien', 'gestion')."</th>";
               $header_end .= "<th class='center'>".__('Signataire', 'gestion')."</th>";
            }
            $header_end .= "<th class='center'>".__('Numéro de BL / BC', 'gestion')."</th>";
            $header_end .= "</tr>";
            $out.= $header_begin.$header_top.$header_end;

            foreach (self::getAllForTicket($ID) as $data) {

               $out .= "<tr class='tab_bg_2'>";
               if(Session::haveRight("plugin_gestion_gestion", PURGE)){
                  if ($canedit) {
                     $out .= "<td width='10'>";
                     $out .= Html::getMassiveActionCheckBox(__CLASS__, $data["id"]);
                     $out .= "</td>";
                  }
               }

               $id_entities = $data['entities_id'];
               $gestion_entity = $DB->query("SELECT completename FROM `glpi_entities` WHERE id= $id_entities")->fetch_object();

               $out .= "<td class='center'>";
               $out .= $data['id'];
               $out .= "</td>";
               //$out .= "<td width='40%' class='center'>";
               //$out .= $gestion_entity->completename;
               //$out .= "</td>";
               $out .= "<td class='center'>";
               $out .= Html::convDate($data["date_creation"]);
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
               $blId = $data['bl']; // Récupère le numéro de BL depuis la base
               $status = $data["signed"]; // Définit le statut signé true = signé / flase non signé
               
               if ($data["signed"] == 1) {
                  $out .= Html::submit($blId . ' - Signé', [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-secondary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$blId', " . json_encode($params) . "); return false;"
                 ]);
                  } else {
                     $out .= Html::submit($blId, [
                        'name'    => 'showCriForm',
                        'class'   => 'btn btn-primary',
                        'onclick' => "gestion_loadCriForm('showCriForm', '$blId', " . json_encode($params) . "); return false;"
                    ]);
                  }       
               $out .= "</td></tr>";
               
            }
            
            $out .= $header_begin.$header_bottom.$header_end;
            $out .= "</table>";

            if(Session::haveRight("plugin_gestion_gestion", PURGE)){
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

   static function postShowItemNewTaskGESTION($params) {
      global $DB, $EntitieAddress;
  
      // Vérifier que la page actuelle est ticket.form.php
      if (strpos($_SERVER['REQUEST_URI'], 'ticket.form.php') !== false) {
          $ticketId = $_GET['id'];
          if ($EntitieAddress == 0 && $ticketId != 0 && !empty($ticketId)) {
              $EntitieAddress = 1;
  
              // Récupérer toutes les valeurs 'bl' pour le ticket spécifié
              $result = $DB->query("SELECT * FROM glpi_plugin_gestion_tickets WHERE tickets_id = $ticketId AND signed = 0");
  
              $groups = [];
              $selected_ids = [];
              while ($data = $result->fetch_assoc()) {
                  $groups[$data['bl']] = $data['bl']; // Utiliser 'bl' comme clé et valeur
                  $selected_ids[] = $data['bl'];
              }
  
              // Récupérer les fichiers PDF du dossier et les ajouter au tableau $groups sans l'extension .pdf
              $directory = GLPI_PLUGIN_DOC_DIR . "/gestion/unsigned/";
              if (is_dir($directory)) {
                  foreach (scandir($directory) as $file) {
                      if (pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
                          $file_name = pathinfo($file, PATHINFO_FILENAME);
                          if (!array_key_exists($file_name, $groups)) {
                              $groups[$file_name] = $file_name; // Utiliser le nom du fichier sans extension
                          }
                      }
                  }
              }
  
              $selected_values_json = json_encode($selected_ids);
              $csrf_token = Session::getNewCSRFToken();
              
              // Modal HTML
              echo <<<HTML
              <div class="modal fade" id="AddGestionModal" tabindex="-1" aria-labelledby="AddGestionModalLabel" aria-hidden="true">
                  <div class="modal-dialog">
                      <div class="modal-content">
                          <div class="modal-header">
                              <h5 class="modal-title" id="AddGestionModalLabel">Ajouter un BC / BL</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
              HTML;
              
                  // Fermeture temporaire de HTML pour inclure du PHP
                  echo '<form method="post" action="' . Toolbox::getItemTypeFormURL('PluginGestionTicket') . '">';
                  echo '<input type="hidden" name="_glpi_csrf_token" value="' . $csrf_token . '">';
                  echo '<input type="hidden" name="tickets_id" value="' . $ticketId . '">';
              
                  // Affichage du dropdown
                  Dropdown::showFromArray("groups_id", $groups, [
                      'multiple' => true,
                      'width' => 200,
                      'values' => json_decode($selected_values_json, true)
                  ]);
              
              echo <<<HTML
                                  <div class="modal-footer">
                                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                      <button type="submit" name="save_selection" class="btn btn-primary">Sauvegarder</button>
                                  </div>
                              </form>
                          </div>
                      </div>
                  </div>
              </div>
              HTML;
  
               // Bouton d'ouverture du modal avec style ajusté
               $entitie = "<div class='d-inline-block' style='margin-left: 0px; margin-top: 7px;'><button id='add_gestion' type='button' style='border: 1px solid; padding: 2px 10px;' class='btn-sm btn-outline-secondary' data-bs-toggle='modal' data-bs-target='#AddGestionModal'><i class='fas fa-plus'></i> Lié des documents</button></div>";

               // Script pour ajouter dynamiquement le bouton uniquement dans la section 'Catégorie', indépendamment de la langue
               $script = <<<JAVASCRIPT
                  $(document).ready(function() {
                     // Ciblage du conteneur parent du champ select2 sans utiliser d'ID spécifique
                     var categorieContainer = $("select[name='itilcategories_id']").closest("div.field-container");
                     var boutonExist = document.getElementById('add_gestion');
                     
                     if (categorieContainer.length > 0 && boutonExist === null) {
                           // Ajoute le bouton après le conteneur de la catégorie pour alignement
                           categorieContainer.append("{$entitie}");
                     }
                  });
               JAVASCRIPT;

               echo Html::scriptBlock($script);
          }
      }
  }
  

   /*static function processSelection() {
      global $DB;
      code de ticket.form.php
   }*/

   static function install(Migration $migration) { // fonction intsllation de la table en BDD
      global $DB;

      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

      $table = self::getTable();

      if (!$DB->tableExists($table)) {
         $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL auto_increment,
                     `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `users_id` int {$default_key_sign} NULL,
                     `users_ext` VARCHAR(255) NULL,
                     `bl` VARCHAR(255) NULL,
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());
      } /*else {
         // Fix #1 in 1.0.1 : change tinyint to int for tickets_id
         $migration->changeField($table, 'tickets_id', 'tickets_id', "int {$default_key_sign} NOT NULL DEFAULT 0");

         //execute the whole migration
         $migration->executeMigration();
      }*/
   }

   static function uninstall(Migration $migration) {

      $table = self::getTable();
      $migration->dropTable($table);
   }
}

