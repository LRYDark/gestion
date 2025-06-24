<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 gestion plugin for GLPI
 Copyright (C) 2016-2022 by the gestion Development Team.

 https://github.com/pluginsglpi/gestion
 -------------------------------------------------------------------------

 LICENSE

 This file is part of gestion.

 gestion is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 gestion is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with gestion. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */


if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Class PluginGestionSurvey
 */
class PluginGestionSurvey extends CommonDBTM {

   static $rightname = "plugin_gestion_survey";

   static function getTypeName($nb = 0) {
      return _n('Gestion', 'Gestion', $nb, 'gestion');
   }

   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab(Log::class, $ong, $options);
      return $ong;
   }

   function canCreateItem() {

      if (!$this->checkEntity()) {
         return false;
      }
      return true;
   }

   /**
    * @return array
    */
   function rawSearchOptions() {
      

      $tab = [];

      $tab[] = [
         'id'                 => 'common',
         'name'               => self::getTypeName()
      ];

      $tab[] = [
         'id'                 => '1',
         'table'              => $this->getTable(),
         'field'              => 'bl',
         'name'               => __('Name'),
         'datatype'           => 'itemlink',
         'itemlink_type'      => $this->getType(),
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '2',
         'table'              => 'glpi_tickets',
         'field'              => 'id',
         'name'               => __('Tickets'),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => 'glpi_entities',
         'field'              => 'completename',
         'name'               => __('Entity'),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '4',
         'table'              => $this->getTable(),
         'field'              => 'doc_url',
         'name'               => __('Url du document'),
         'datatype'           => 'text'
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this->getTable(),
         'field'              => 'signed',
         'name'               => __('Signé'),
         'datatype'           => 'bool'
      ];

      $tab[] = [
         'id'                 => '6',
         'table'              => $this->getTable(),
         'field'              => 'doc_date',
         'name'               => __('Date de création'),
         'datatype'           => 'datetime'
      ];

      $tab[] = [
         'id'                 => '7',
         'table'              => $this->getTable(),
         'field'              => 'date_creation',
         'name'               => __('Date de signature'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '8',
         'table'              => $this->getTable(),
         'field'              => 'users_ext',
         'name'               => __('Signataire'),
         'datatype'           => 'text',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '9',
         'table'              => 'glpi_users',
         'field'              => 'name',
         'name'               => __('Users'),
         'datatype'           => 'dropdown',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '10',
         'table'              => $this->getTable(),
         'field'              => 'tracker',
         'name'               => __('Tracker'),
         'datatype'           => 'text',
         'massiveaction'      => false
      ];

      return $tab;
   }

   public function Formulaire(){
      global $DB, $CFG_GLPI;
      $config = new PluginGestionConfig();
      //----------------------------------------------------------------------------------------------------------------
      if (Plugin::isPluginActive('formcreator')) {
         if(empty($this->fields["tickets_id"]) && $config->fields['formulaire'] != 0){
            $formId = $config->fields['formulaire']; // Exemple d'ID dynamique

            $chemin1 = __DIR__ . "/../../formcreator/front/formdisplay.php";
            $chemin2 = __DIR__ . "/../../../marketplace/formcreator/front/formdisplay.php";

            if (file_exists($chemin1) && is_file($chemin1)) {
               $form  = "../../formcreator/front/formdisplay.php?id=$formId";
               $title = "(Plugin) ";
            } elseif (file_exists($chemin2) && is_file($chemin2)) {
               $title = "(Marketplace) ";
               $form  = "../../../marketplace/formcreator/front/formdisplay.php?id=$formId";
            } else {
               $title = null;
               $form  = null;
            }

            if($form != null){
               echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Création d'un formulaire")."</td>";
               echo "<td>";

                  ?>
                  <!-- Bouton pour ouvrir le modal -->
                  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#myModal">
                     <?php echo __('Générer un formulaire'); ?>
                  </button>

                  <!-- Modal Bootstrap -->
                  <div class="modal fade" id="myModal" tabindex="-1" aria-labelledby="myModalLabel" aria-hidden="true">
                     <div class="modal-dialog modal-xl" style="max-width: 50%;">
                        <div class="modal-content" style="height: 90vh;">
                              <div class="modal-header">
                                 <h5 class="modal-title" id="myModalLabel"><?php echo $title.__('Formulaire'); ?></h5>
                                 <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body p-0" style="height: calc(100% - 56px); overflow: auto;">
                                 <!-- Iframe -->
                                 <iframe id="iframe-content" 
                                          src=<?php echo $form; ?> 
                                          style="width: 100%; height: 99%; border: none;"></iframe>
                              </div>
                        </div>
                     </div>
                  </div>

                  <script>
                     function removeNavbarFromIframe(iframeId) {
                        const iframe = document.getElementById(iframeId);
                        if (iframe) {
                           iframe.onload = function () {
                              const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                              if (iframeDoc) {
                                 // Supprimer la barre de navigation (aside)
                                 const navbar = iframeDoc.querySelector('aside.navbar.navbar-vertical.navbar-expand-lg.sticky-lg-top.sidebar');
                                 if (navbar) {
                                    navbar.remove();
                                 } else {
                                 }

                                 // Supprimer l'en-tête (header)
                                 const header = iframeDoc.querySelector('header.navbar.d-print-none.sticky-lg-top.shadow-sm.navbar-light.navbar-expand-md');
                                 if (header) {
                                    header.remove();
                                 } else {
                                 }
                              }
                           };
                        }
                     }
                     // Appel de la fonction
                     removeNavbarFromIframe('iframe-content');
                  </script><?php
               echo "</td></tr>";
            }
         }
      }
      //----------------------------------------------------------------------------------------------------------------
   }

   /**
    * Print survey
    *
    * @param       $ID
    * @param array $options
    *
    * @return bool
    */
   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;
      $config = new PluginGestionConfig();

      $params = ['job'           => $ID,
                 'root_doc'      => PLUGIN_GESTION_WEBDIR,
                 'root_modal'    => 'survey-form'];

      
      if (!$this->canView()) {
         return false;
      }

      $this->initForm($ID, $options);
      $this->showFormHeader($options);

      if ($_GET['id'] != null){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Non du document : ') . "</td>";
               echo "<td>";
               echo $this->fields['bl'];
               echo "</td>";  
               echo "<td>";
               echo '<a href="' . $this->fields['doc_url'] . '" target="_blank"><strong>Voir le Document</strong></a>'; // Bouton pour voir le PDF en plein écran
            echo "</td></tr>";

         $this->Formulaire();

            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Entity') . "</td>";
               echo "<td>";
               Dropdown::show('Entity', [
                  'name' => 'entities_id',
                  'value' => $this->fields["entities_id"],
                  'display_emptychoice' => 1,
                  'specific_tags' => [],
                  'itemtype' => 'Entity',
                  'displaywith' => [],
                  'emptylabel' => "-----",
                  'used' => [],
                  'toadd' => [],
                  'entity_restrict' => 0,
               ]); 
            echo "</td><td colspan='2'></td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('<a href="../../../front/ticket.form.php?id='. $this->fields["tickets_id"] .'">Ticket ID : '. $this->fields["tickets_id"] .'</a>') . "</td>";
               echo "<td>";
               Dropdown::show('Ticket', [
                  'name' => 'tickets_id', // Le nom du champ
                  'value' => $this->fields["tickets_id"], // La valeur sélectionnée par défaut
                  'display_emptychoice' => 1, // Afficher un choix vide
                  'specific_tags' => [], // Éventuels attributs HTML supplémentaires
                  'itemtype' => 'Ticket', // Type d'objet à afficher
                  'displaywith' => ['id'], // Champs à afficher pour les tickets
                  'emptylabel' => "-----", // Étiquette pour l'option vide
                  'used' => [], // Filtrage des tickets déjà utilisés
                  'toadd' => [], // Liste personnalisée d'objets à ajouter
                  'entity_restrict' => 0, // Autoriser les tickets de toutes les entités
               ]);;
            echo "</td><td colspan='2'></td></tr>";

            echo "<tr class='tab_bg_1'><td></td></tr>";
            echo "<tr class='tab_bg_1'><td></td></tr>";
            echo "<tr class='tab_bg_1'><td></td></tr>";
            echo "<tr class='tab_bg_1'><td></td></tr>";
            echo "<tr class='tab_bg_1'><td></td></tr>";

         $signed = '';
         if ($this->fields['signed'] == 1){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Informations sur le document <strong>Signé</strong> :') ."</td>";
               echo "<td>";
                  echo Html::submit($this->fields['bl'], [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-secondary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$ID', " . json_encode($params) . "); return false;"
               ]);
            echo "</td></tr>";
         }else{
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Informations sur le document <strong>Non signé</strong> ')."</td>";
               echo "<td>";
                  echo Html::submit($this->fields['bl'], [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-primary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$ID', " . json_encode($params) . "); return false;"
                  ]);
            echo "</td></tr>";
         }
      }else{
         $script = <<<JAVASCRIPT
            $('#search_pdf').on('select2:select', function (e) {
               const data = e.params.data;

               const filename = data.filename;
               const folder   = data.folder;
               const save   = data.save; // ici : "Local"
               const signed   = data.signed; // ici : "Local"

               // Exemple : remplir des champs cachés
               $('#pdf_filename').val(filename);
               $('#pdf_folder').val(folder);
               $('#pdf_save').val(save);
               $('#pdf_signed').val(signed);
            });
         JAVASCRIPT;  

         // Inclure le script dans la page
         echo Html::scriptBlock($script);

         echo '<input type="hidden" name="pdf_filename" id="pdf_filename">';
         echo '<input type="hidden" name="pdf_folder" id="pdf_folder">';
         echo '<input type="hidden" name="pdf_save" id="pdf_save">';
         echo '<input type="hidden" name="pdf_signed" id="pdf_signed">';

         echo "<tr class='tab_bg_1'>";
         echo "<td>" . __('Recherche document :') . "</td>";
         echo "<td>";
            echo '
               <div>
                  <select id="search_pdf" name="search_pdf" style="width:650px;"></select>
                  <span id="spinner" style="display:none;">
                     <img src="' . $CFG_GLPI['root_doc'] . '/pics/spinner.gif" alt="Chargement...">
                  </span>
               </div>
            ';
         echo "</td><td colspan='2'></td></tr>";
         echo '
            <script>
            $(document).ready(function() {
               $("#search_pdf").select2({
                  placeholder: "Recherche de fichier PDF...",
                  minimumInputLength: 2,
                  ajax: {
                     delay: 300,
                     url: "ajax_search_pdf.php",
                     dataType: "json",
                     data: function(params) {
                        $("#spinner").show();
                        return { q: params.term };
                     },
                     processResults: function(data) {
                        $("#spinner").hide();
                        return { results: data };
                     },
                     cache: true
                  },
                  templateResult: function (data) {
                     return data.html ? data.html : data.text;
                  },
                  templateSelection: function (data) {
                     return data.text;
                  },
                  escapeMarkup: function (markup) {
                     return markup;
                  }
               });
            });
            </script>
         ';

         $this->Formulaire();

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Entity') . "</td>";
            echo "<td>";
            Dropdown::show('Entity', [
               'name' => 'entities_id',
               'value' => $this->fields["entities_id"],
               'display_emptychoice' => 1,
               'specific_tags' => [],
               'itemtype' => 'Entity',
               'displaywith' => [],
               'emptylabel' => "-----",
               'used' => [],
               'toadd' => [],
               'entity_restrict' => 0,
            ]); 
         echo "</td><td colspan='2'></td></tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Ticket') . "</td>";
            echo "<td>";
            Dropdown::show('Ticket', [
               'name' => 'tickets_id', // Le nom du champ
               'value' => $this->fields["tickets_id"], // La valeur sélectionnée par défaut
               'display_emptychoice' => 1, // Afficher un choix vide
               'specific_tags' => [], // Éventuels attributs HTML supplémentaires
               'itemtype' => 'Ticket', // Type d'objet à afficher
               'displaywith' => ['id'], // Champs à afficher pour les tickets
               'emptylabel' => "-----", // Étiquette pour l'option vide
               'used' => [], // Filtrage des tickets déjà utilisés
               'toadd' => [], // Liste personnalisée d'objets à ajouter
               'entity_restrict' => 0, // Autoriser les tickets de toutes les entités
            ]);;
         echo "</td><td colspan='2'></td></tr>";
      }
         
      if (Session::haveRight('plugin_gestion_survey', UPDATE)) {
         $this->showFormButtons($options);
      }
      Html::closeForm();

      return true;
   }
}
