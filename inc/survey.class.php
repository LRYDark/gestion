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
class PluginGestionSurvey extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface {

   static $rightname = "plugin_gestion_survey";

   static function getTypeName($nb = 0) {
      return _n('Gestion', 'Gestion', $nb, 'gestion');
   }

   /**
    * Tri par defaut de la liste (front/survey.php) : derniers BL crees en premier.
    * Option 6 = date_creation (cf. rawSearchOptions).
    */
   public static function getDefaultSearchRequest(): array {
      return [
         'sort'  => 6,
         'order' => 'DESC',
      ];
   }

   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab(Log::class, $ong, $options);
      return $ong;
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
         'datatype'           => 'dropdown',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => 'glpi_entities',
         'field'              => 'completename',
         'name'               => __('Entity'),
         'datatype'           => 'dropdown',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '4',
         'table'              => $this->getTable(),
         'field'              => 'doc_url',
         'name'               => __('Url du document'),
         'datatype'           => 'text',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this->getTable(),
         'field'              => 'signed',
         'name'               => __('Signé'),
         'datatype'           => 'bool',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '6',
         'table'              => $this->getTable(),
         'field'              => 'date_creation',
         'name'               => __('Date de création'),
         'datatype'           => 'date',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '7',
         'table'              => $this->getTable(),
         'field'              => 'doc_date',
         'name'               => __('Date de signature'),
         'datatype'           => 'date',
         'massiveaction'      => true
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
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '10',
         'table'              => $this->getTable(),
         'field'              => 'tracker',
         'name'               => __('Tracker'),
         'datatype'           => 'text',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '11',
         'table'              => $this->getTable(),
         'field'              => 'relatedInvoiceToBL',
         'name'               => __('Document lié'),
         'datatype'           => 'text',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '12',
         'table'              => $this->getTable(),
         'field'              => 'paid',
         'name'               => __('Payé au comptoir'),
         'datatype'           => 'bool',
         'massiveaction'      => true
      ];

      $tab[] = [
         'id'                 => '13',
         'table'              => $this->getTable(),
         'field'              => 'comment',
         'name'               => __('Commentaire'),
         'datatype'           => 'text',
         'massiveaction'      => true
      ];

      // Colonne « Signature » : bouton « Signer » (BL non signe) / coche (signe).
      // Rendu HTML par plugin_gestion_giveItem() dans hook.php (hook auto giveItem).
      $tab[] = [
         'id'                 => '14',
         'table'              => $this->getTable(),
         'field'              => 'signed',
         'name'               => __('Signature', 'gestion'),
         'datatype'           => 'specific',
         'nosearch'           => true,
         'nosort'             => true,
         'massiveaction'      => false
      ];

      return $tab;
   }

   public function Formulaire(){
      global $DB, $CFG_GLPI;
      $config = new PluginGestionConfig();

      //----------------------------------------------------------------------------------------------------------------
         if(empty($this->fields["tickets_id"]) && $config->fields['formulaire'] != 0){

            $formId = $config->fields['formulaire']; // Exemple d'ID dynamique
            $form  = $CFG_GLPI['root_doc']."/Form/Render/$formId";
            $FormName = $DB->doQuery("SELECT name FROM glpi_forms_forms WHERE id = $formId")->fetch_object();

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
                              <h5 class="modal-title" id="myModalLabel"><?php echo 'Formulaire - ' . (!empty($FormName->name) ? $FormName->name : ""); ?></h5>
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

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '">';
      // Remote signature additions
      echo '<script>
         window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
      </script>';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '" defer></script>';

      $params = ['job'           => $ID,
                 'root_doc'      => PLUGIN_GESTION_WEBDIR,
                 'root_modal'    => 'survey-form'];

      if (!$this->canView()) {
         return false;
      }

      $this->initForm($ID, $options);
      $this->showFormHeader($options);

      // Distinguish creation (ID 0 / empty) from an existing record.
      $isExistingSurvey = ((int)$ID > 0);

      if ($isExistingSurvey){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Non du document : ') . "</td>";
               echo "<td>";
               echo $this->fields['bl'];
               echo "</td>";  
               echo "<td>";
               $docUrl = (string)($this->fields['doc_url'] ?? '');
               if (function_exists('plugin_gestion_normalize_view_pdf_url')) {
                  $docUrl = plugin_gestion_normalize_view_pdf_url($docUrl);
               }
               if (function_exists('plugin_gestion_ensure_pdf_token')) {
                  $docUrl = plugin_gestion_ensure_pdf_token($docUrl);
               }
               echo '<a href="' . Html::entities_deep($docUrl) . '" target="_blank"><strong>Voir le Document</strong></a>'; // Bouton pour voir le PDF en plein écran
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

         $availableEmails = [];
         $defaultEmail    = '';
         if (!empty($this->fields['tickets_id'])) {
            $ticket_id_mails = (int)$this->fields['tickets_id'];
            $sql = " SELECT GROUP_CONCAT(DISTINCT mails.email SEPARATOR ',') AS emails
                     FROM (
                        SELECT e.email
                        FROM glpi_tickets t
                        JOIN glpi_entities e ON e.id = t.entities_id
                        WHERE t.id = $ticket_id_mails
                           AND e.email IS NOT NULL
                           AND e.email <> ''

                        UNION ALL

                        SELECT ue.email
                        FROM glpi_tickets t
                        JOIN glpi_profiles_users pu ON pu.entities_id = t.entities_id
                        JOIN glpi_users u           ON u.id = pu.users_id
                        JOIN glpi_useremails ue     ON ue.users_id = u.id
                        WHERE t.id = $ticket_id_mails
                           AND u.is_deleted = 0
                           AND ue.email IS NOT NULL
                           AND ue.email <> ''
                     ) AS mails;";
            $resEmail = $DB->doQuery($sql);
            if ($resEmail) {
               $emailsObj = $resEmail->fetch_object();
               if (!empty($emailsObj->emails)) {
                  $availableEmails = array_values(array_unique(array_filter(array_map('trim', explode(',', $emailsObj->emails)))));
                  $defaultEmail    = $availableEmails[0] ?? '';
               }
            }
         }

         $signed = '';
         if ($this->fields['signed'] == 1){
            $modalId = 'gestionResendMailModal'.$ID;
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Informations sur le document <strong>Signé</strong> :') ."</td>";
               echo "<td>";
                  echo Html::submit($this->fields['bl'], [
                     'name'    => 'showCriForm',
                     'class'   => 'btn btn-secondary',
                     'onclick' => "gestion_loadCriForm('showCriForm', '$ID', " . json_encode($params) . "); return false;"
               ]);
               echo "&nbsp;";
               echo '<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#'.$modalId.'">Renvoyer par mail</button>';
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

         if(!empty($this->fields['relatedInvoiceToBL'])){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Document lié : <strong>'.$this->fields['relatedInvoiceToBL'].'</strong> ')."</td>";
            echo "</tr>";
         }
         if(!empty($this->fields['comment'])){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __('Commentaire : <strong>'.$this->fields['comment'].'</strong> ')."</td>";
            echo "</tr>";
         }
         if ($this->fields['signed'] == 1 && isset($modalId)) {
            $csrfToken = Session::getNewCSRFToken(true);
            $ajaxUrl   = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/plugins/gestion/ajax/cri.php';
            ?>
            <div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1" aria-labelledby="<?php echo $modalId; ?>Label" aria-hidden="true">
               <div class="modal-dialog">
                  <div class="modal-content">
                     <div class="modal-header">
                        <h5 class="modal-title" id="<?php echo $modalId; ?>Label">Renvoyer le document signé</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body">
                        <div id="gestion-resend-mail-form-<?php echo $ID; ?>">
                           <input type="hidden" name="_glpi_csrf_token" value="<?php echo $csrfToken; ?>">
                           <input type="hidden" name="survey_id" value="<?php echo $ID; ?>">
                           <div class="mb-3">
                              <label class="form-label">Déstinataire principal</label>
                              <input type="text" name="to" class="form-control" value="<?php echo Html::entities_deep($defaultEmail); ?>" placeholder="email1@example.com, email2@example.com">
                              <?php if (!empty($availableEmails)) { ?>
                                 <div class="mt-2">
                                    <small class="text-muted">Suggestions :</small>
                                    <div class="mt-1" style="display:flex;flex-wrap:wrap;gap:6px;">
                                       <?php foreach ($availableEmails as $email) { ?>
                                          <button type="button" class="btn btn-light btn-sm gestion-mail-suggestion" data-target="<?php echo $modalId; ?>" data-value="<?php echo Html::entities_deep($email); ?>"><?php echo Html::entities_deep($email); ?></button>
                                       <?php } ?>
                                    </div>
                                 </div>
                              <?php } ?>
                           </div>
                           <div class="mb-3">
                              <label class="form-label">Copie (CC)</label>
                              <input type="text" name="cc" class="form-control" placeholder="Emails en copie, separes par des virgules">
                           </div>
                        </div>
                        <div class="text-danger gestion-send-mail-feedback" style="display:none;"></div>
                     </div>
                     <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                        <button type="button" id="gestion-send-mail-btn-<?php echo $ID; ?>" class="btn btn-primary gestion-send-mail-submit" data-target="<?php echo $modalId; ?>">Envoyer le mail</button>
                     </div>
                  </div>
               </div>
            </div>
            <?php

            $script = <<<JAVASCRIPT
               function gestionSendMail(modalId, ajaxUrl, surveyId, csrfToken) {
                  var modalEl = document.getElementById(modalId);
                  var feedback = modalEl ? modalEl.querySelector('.gestion-send-mail-feedback') : null;
                  var toInput = modalEl ? modalEl.querySelector('input[name="to"]') : null;
                  var ccInput = modalEl ? modalEl.querySelector('input[name="cc"]') : null;
                  if (!modalEl || !toInput || !ccInput) {
                     return false;
                  }

                  var hasRecipient = (toInput.value || '').trim() !== '' || (ccInput.value || '').trim() !== '';
                  if (!hasRecipient) {
                     if (feedback) {
                        feedback.className = 'text-danger gestion-send-mail-feedback';
                        feedback.style.display = 'block';
                        feedback.textContent = 'Merci de saisir au moins un destinataire.';
                     }
                     return false;
                  }

                  var btns = modalEl.querySelectorAll('.gestion-send-mail-submit');
                  btns.forEach(function(b){ b.setAttribute('disabled','disabled'); });
                  if (feedback) {
                     feedback.style.display = 'block';
                     feedback.className = 'text-muted gestion-send-mail-feedback';
                     feedback.textContent = 'Envoi en cours...';
                  }

                  var payload = new URLSearchParams();
                  payload.append('action', 'sendMail');
                  payload.append('survey_id', surveyId);
                  payload.append('to', toInput.value);
                  payload.append('cc', ccInput.value);
                  payload.append('_glpi_csrf_token', csrfToken);

                  fetch(ajaxUrl, {
                     method: 'POST',
                     credentials: 'same-origin',
                     headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-GLPI-CSRF-Token': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                     },
                     body: payload.toString()
                  }).then(function(res){
                     return res.text().then(function(text){
                        return { status: res.status, text: text };
                     });
                  }).then(function(resObj){
                     var resp = null;
                     try { resp = JSON.parse(resObj.text); } catch(e) { resp = null; }
                     if (feedback) {
                        if (resp && resp.ok) {
                           var modalInstance = null;
                           if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                              modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                           }
                           if (modalInstance) {
                              modalInstance.hide();
                           }
                           window.location.reload();
                        } else {
                           feedback.className = 'text-danger gestion-send-mail-feedback';
                           feedback.textContent = (resp && resp.message) ? resp.message : "Erreur lors de l'envoi.";
                        }
                     }
                  }).catch(function(err){
                     if (feedback) {
                        feedback.className = 'text-danger gestion-send-mail-feedback';
                        feedback.textContent = "Erreur lors de l'envoi.";
                     }
                  }).finally(function(){
                     btns.forEach(function(b){ b.removeAttribute('disabled'); });
                  });

                  return false;
               }

               (function(){
                  var ajaxUrl   = "{$ajaxUrl}";
                  var modalId   = "{$modalId}";
                  var surveyId  = "{$ID}";
                  var csrfToken = "{$csrfToken}";
                  var btnId     = "gestion-send-mail-btn-{$ID}";
                  var btn       = document.getElementById(btnId);

                  if (btn) {
                     btn.addEventListener('click', function(ev){
                        ev.preventDefault();
                        gestionSendMail(modalId, ajaxUrl, surveyId, csrfToken);
                     });
                  } else {
                  }
               })();
            JAVASCRIPT;

            echo Html::scriptBlock($script);
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

         // Dans votre survey.class.php
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

         // JavaScript amélioré pour gérer la recherche Sage
         echo '
            <script>
            $(document).ready(function() {
               $("#search_pdf").select2({
                  placeholder: "Recherche de fichier PDF...",
                  minimumInputLength: 2,
                  language: {
                     noResults: function () {
                        return "Aucun résultat : BL déjà en facture ou introuvable dans SAGE";
                     }
                  },
                  ajax: {
                     delay: 300,
                     url: "../ajax/ajax_search_pdf.php",
                     dataType: "json",
                     data: function(params) {
                        $("#spinner").show();
                        return { q: params.term };
                     },
                     processResults: function(data) {
                        $("#spinner").hide();
                        
                        // Si aucun résultat, ne rien retourner
                        if (!data || data.length === 0) {
                           return { results: [] };
                        }
                        
                        // Traitement spécial pour les résultats Sage
                        var processedResults = [];
                        
                        $.each(data, function(index, item) {
                           if (item.source === "sage") {
                              // Pour les résultats Sage, ajouter une indication visuelle
                              item.html = item.text + \' <span style="color:white;background-color:#007bff;padding:2px 6px;border-radius:4px;font-size:11px;">📄 SAGE</span>\';
                           }
                           processedResults.push(item);
                        });
                        
                        return { results: processedResults };
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
                  },
                  // DÉSACTIVER COMPLÈTEMENT les tags - seuls les résultats de recherche sont autorisés
                  tags: false,
                  // Empêcher la création de nouvelles options
                  createTag: function (params) {
                     return null; // Ne jamais permettre la création de tags
                  }
               });
               
               // Pas de gestion spéciale de sélection - seuls les résultats réels sont autorisés
               $("#search_pdf").on("select2:select", function (e) {
                  var data = e.params.data;
                  
                  // Tous les résultats proviennent maintenant de la recherche réelle
                  // Pas de vérification supplémentaire nécessaire
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
         
      echo Html::hidden('plugin_gestion_survey_csrf_token', ['value' => Session::getNewCSRFToken(true)]);

      if (Session::haveRightsOr('plugin_gestion_survey', [CREATE, UPDATE])) {
         $this->showFormButtons($options);
      } else {
         Html::closeForm();
      }

      return true;
   }
}
