<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

//------------------------------------------------------------------------------------------
class PluginGestionTicket extends CommonDBTM {

   public static $rightname = 'gestion';
   public  static  $gestion = 0 ;

//*--------------------------------------------------------------------------------------------- GESTION ONGLET
   static function getTypeName($nb = 0) { // voir doc glpi 
      if(Session::haveRight("plugin_gestion_sign", READ)){
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

   static function showForTicket(Ticket $ticket) { // formulaire sur le ticket
      global $DB, $CFG_GLPI;

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
      $out .= __('Gestion BL / BC', 'gestion');
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

               $out .= "<table class='tab_cadre_fixehov'>";
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
               if (!isMobile()) {
                  $BlName = $data['bl']; // Récupère le numéro de BL depuis la base
               }else{
                  $BlName = substr($data['bl'], 0, 10).'...'; // Récupère le numéro de BL depuis la base
               }
               $blId = $data['id']; // Récupère le numéro de BL depuis la base
               $status = $data["signed"]; // Définit le statut signé true = signé / flase non signé
               
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
            $out .= "</table>";

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

   static function postShowItemNewTaskGESTION($params) {
      global $DB, $gestion;
      $config = new PluginGestionConfig();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();
  
      if (Session::haveRight("plugin_gestion_add", READ)) {
          if (strpos($_SERVER['REQUEST_URI'], 'ticket.form.php') !== false) {
              $ticketId = $_GET['id'];
              if ($gestion == 0 && $ticketId != 0 && !empty($ticketId)) {
                  $gestion = 1;

                  // Récupérer toutes les valeurs 'bl' pour le ticket spécifié
                  $result = $DB->query("SELECT * FROM glpi_plugin_gestion_surveys WHERE tickets_id = $ticketId AND signed = 0");

                  $groups = [];
                  $selected_ids = [];
                  while ($data = $result->fetch_assoc()) {
                        $groups[$data['bl']] = $data['bl']; // Utiliser 'bl' comme clé et valeur
                        $url_bl = ""; // Par défaut, $folderPath est vide
                        if (!empty($data['url_bl'])){
                           $url_bl = $data['url_bl']."/";
                        }
                        $selected_ids[] = $url_bl.$data['bl'];
                  }

                  $groups = [];
                  // On parcourt le tableau $selected_ids
                  foreach ($selected_ids as $item) {
                     // On récupère la dernière partie après le dernier "/"
                     $last_part = basename($item); // Utilisation de basename pour obtenir la dernière partie

                     // On ajoute dans $groups avec la clé étant l'élément complet et la valeur étant la dernière partie
                     $groups[$item] = $last_part;
                  }
  
                  // CSRF Token
                  $selected_values_json = json_encode($selected_ids);
                  $csrf_token = Session::getNewCSRFToken();
   
                  if (Session::haveRight("plugin_gestion_add", UPDATE)) {
                      $disabled = false;
                  } else {
                      $disabled = true;
                  }   

///////////////// NEW TEST ////////////////////
    $query = "
        SELECT folder_name, params
        FROM glpi_plugin_gestion_configsfolder
        WHERE params IN (2, 3)
        ORDER BY 
            CASE params 
                WHEN 2 THEN 0 
                WHEN 3 THEN 1 
            END
        LIMIT 1
    ";

    $result = $DB->query($query);

    if ($result && $DB->numrows($result) > 0) {
        $data = $DB->fetchassoc($result);
        $folder_name = $data['folder_name'];
        $used_param = $data['params'];

        if ($used_param == 2) {
            $FolderDes = 'SharePoint';
        } 

        if ($used_param == 3) {
            $FolderDes = 'local';
            $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folder_name;

            // Vérifie si le dossier existe, sinon le crée
            if (!is_dir($destinationPath)) {
               if (!mkdir($destinationPath, 0755, true)) {
                  // En cas d’échec de création
                  echo "Erreur : impossible de créer le dossier $destinationPath";
               }
            }
        }
    } else {
        $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/DocumentsSigned";
    }

///////////////// NEW TEST ////////////////////
                   
                  // Modal HTML
                  echo <<<HTML
                  <div class="modal fade" id="AddGestionModal" tabindex="-1" aria-labelledby="AddGestionModalLabel" aria-hidden="true">
                      <div class="modal-dialog modal-lg">
                          <div class="modal-content">
                              <div class="modal-header">
                                  <h5 class="modal-title" id="AddGestionModalLabel">Ajouter un BC / BL</h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body">
                                 <!-- Rond de chargement -->
                                 <div id="loading-spinner" style="display:none; text-align:center;">
                                    <div class="spinner-border text-info" role="status" style="width: 3rem; height: 3rem; border-width: 0.4rem;">
                                          <span class="visually-hidden">Loading...</span>
                                    </div>
                                 </div>

                  HTML;
                  $connexion = false;
                  if($config->SageOn() == 1 && $config->SharePointOn() == 0){
                     if(!empty($config->SageId())){
                        $connexion = true;
                     }

                  }
                  if($config->SageOn() == 0 && $config->SharePointOn() == 1){
                     $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
                     if($result['status']){
                        $connexion = true;
                     }
                  }
                  if($config->SageOn() == 1 && $config->SharePointOn() == 1){
                     $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
                     if($result['status'] || !empty($config->SageId())){
                        $connexion = true;
                     }
                  }
                  if($config->mode() == 2){
                     $connexion = true;
                  }

                  if (!empty($connexion)) {
                     // Fermeture temporaire de HTML pour inclure du PHP
                     echo '<form method="post" action="' . Toolbox::getItemTypeFormURL('PluginGestionTicket') . '">';
                     echo '<input type="hidden" name="_glpi_csrf_token" value="' . $csrf_token . '">';
                     echo '<input type="hidden" name="tickets_id" value="' . $ticketId . '">';
                     Dropdown::showFromArray("groups_id", $groups, [
                        'multiple'     => true,
                        'width'        => '650px',
                        'values'       => json_decode($selected_values_json, true),
                        'disabled'     => $disabled,
                     ]);
                  }else{
                     echo "<div class='alert alert-danger'> Une erreur est survenue dans la configuration de la méthode de récupération des documents (Sage local, SharePoint ou dossier local).<br><br> Veuillez contacter votre administrateur afin de vérifier la configuration du plugin. </div>";
                  }
                  echo <<<HTML

                                      <div class="modal-footer" style="margin-top: 55px;">
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
  
                  // Script pour ajouter dynamiquement le bouton et gérer le clic
                  $script = <<<JAVASCRIPT
                  $(document).ready(function() {
                     var categorieContainer = $("select[name='itilcategories_id']").closest("div.field-container");
                     var boutonExist = document.getElementById('add_gestion');

                     // Si le bouton n'existe pas déjà, on l'ajoute
                     if (categorieContainer.length > 0 && boutonExist === null) {
                        categorieContainer.append("{$entitie}");
                     }

                     // Clic sur le bouton pour ouvrir le modal
                     $('#add_gestion').click(function() {
                        // Affichage du rond de chargement
                        $('#loading-spinner').show();

                        // Requête AJAX pour charger les données
                        $.ajax({
                              url: '../plugins/gestion/front/charger_dropdown.php',  // Chemin vers le fichier PHP
                              method: 'GET',
                              data: { ticketId: {$ticketId} },  // Passer le ticketId dans la requête
                              dataType: 'json',  // Assurez-vous que jQuery gère la réponse comme JSON
                              success: function(response) {
                                 console.log('Réponse brute du serveur:', response);

                                 if (response.error) {
                                    alert('Erreur : ' + response.error);
                                    return;
                                 }

                                 if (response.data) {
                                    // Ajouter les options au dropdown sans doublons
                                    $.each(response.data, function(index, value) {
                                          // Vérifier si l'option est déjà présente avant de l'ajouter
                                          if ($('[name="groups_id[]"] option[value="' + index + '"]').length === 0) {
                                             $('[name="groups_id[]"]').append('<option value="' + index + '">' + value + '</option>');
                                          }
                                    });

                                    // Réinitialiser Select2 après avoir ajouté les options
                                    $('[name="groups_id[]"]').trigger('change');

                                    // Réinitialiser Select2
                                    $('[name="groups_id[]"]').select2({
                                          width: '650',
                                          dropdownAutoWidth: true,
                                          dropdownParent: $('[name="groups_id[]"]').closest('div.modal, div.dropdown-menu, body'),
                                          quietMillis: 100,
                                          minimumResultsForSearch: 10
                                    });
                                 } else {
                                    alert('Aucune donnée à afficher.');
                                 }

                                 // Masquer le rond de chargement
                                 $('#loading-spinner').hide();
                              },
                              error: function(xhr, status, error) {
                                 console.error('Erreur AJAX:', error);
                                 $('#loading-spinner').hide();
                                 alert('Erreur lors du chargement des données.');
                              }
                        });
                     });
                  });
                  JAVASCRIPT;  

                  // Inclure le script dans la page
                  echo Html::scriptBlock($script);
              }
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
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     `doc_url` TEXT NULL,
                     `doc_date` TIMESTAMP NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());
      }
   }

   static function uninstall(Migration $migration) {

      $table = 'glpi_plugin_gestion_surveys';
      $migration->dropTable($table);
   }
}

