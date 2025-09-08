<?php
/**
 * plugins/gestion/inc/ticketconfig.class.php
 */


use Glpi\Application\View\TemplateRenderer;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionTicketConfig extends CommonDBTM
{
    public static $rightname = 'plugin_gestion_ticketconfig';

    public static function getTypeName($nb = 0)
    {
        return _sn('Gestion des documents liés', 'Gestion des documents liés', $nb, 'gestion');
    }

    /**
     * Affiche le panneau + bouton + modal + JS.
     * (Remplace l’ancien postShowItemNewTaskGESTION)
     */
    public static function showForTicket($ticket, bool $embed_in_ticket_form = true, bool $uncollapsed = true)
    {
      global $DB, $CFG_GLPI;
        if (!($ticket instanceof Ticket)) {
            return;
        }

        $gestion_button_enabled = false;
        $gestion_modal_html     = '';
        $gestion_js_block       = '';
        $linked_docs            = [];

        if (Session::haveRight('plugin_gestion_add', READ) || Session::haveRight('plugin_gestion_add', UPDATE)){
            $ticketId = (int)$ticket->getID();
            if ($ticketId > 0) {

              if (Session::haveRight('plugin_gestion_add', UPDATE)) {
                $gestion_button_enabled = true;
              }else{
                $gestion_button_enabled = false;
              }
              
              // ---------------- Connexions (Sage / SharePoint / Local) ----------------
                $config = new PluginGestionConfig();
                require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
                $sharepoint = new PluginGestionSharepoint();

                $connexion = false;
                if ($config->SageOn() == 1 && $config->SharePointOn() == 0) {
                    if (!empty($config->SageToken())) {
                        $connexion = true;
                    }
                }
                if ($config->SageOn() == 0 && $config->SharePointOn() == 1) {
                    $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
                    if (isset($result['status']) && $result['status'] === true) {
                        $connexion = true;
                    }
                }
                if ($config->SageOn() == 1 && $config->SharePointOn() == 1) {
                    $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
                    if ((isset($result['status']) && $result['status'] === true) || !empty($config->SageToken())) {
                        $connexion = true;
                    }
                }
                if ($config->mode() == 2) {
                    $connexion = true;
                }
                // -------------------------------------------------------------------------

                // --------- Documents déjà liés (non signés) -> liste + présélection modal
                $selected_ids = [];
                $res = $DB->doQuery("
                    SELECT bl, url_bl
                    FROM glpi_plugin_gestion_surveys
                    WHERE tickets_id = $ticketId AND signed = 0
                ");
                if ($res) {
                    while ($row = $DB->fetchAssoc($res)) {
                        $prefix = !empty($row['url_bl']) ? rtrim($row['url_bl'], '/').'/' : '';
                        $full   = $prefix.$row['bl'];
                        $selected_ids[] = $full;                               // valeurs à sélectionner
                        $linked_docs[]  = ['value' => $full, 'label' => basename($full)]; // pour la liste sous le panneau
                    }
                }

                // ⚠️ IMPORTANT : construire $groups pour afficher les options existantes avant l'AJAX
                $groups = [];
                foreach ($selected_ids as $val) {
                    $groups[$val] = basename($val);
                }

                // JSON pour le JS
                $selected_json = json_encode(array_values($selected_ids));

                // (Optionnel) destination locale si config params=3 — non bloquant
                $destinationPath = GLPI_PLUGIN_DOC_DIR . '/gestion/DocumentsSigned';
                if (class_exists('PluginGestionConfig')) {
                    $q = "
                        SELECT folder_name, params
                        FROM glpi_plugin_gestion_configsfolder
                        WHERE params IN (2,3)
                        ORDER BY CASE params WHEN 2 THEN 0 WHEN 3 THEN 1 END
                        LIMIT 1
                    ";
                    if ($r = $DB->doQuery($q)) {
                        if ($DB->numrows($r) > 0) {
                            $d = $DB->fetchAssoc($r);
                            if ((int)$d['params'] === 3) {
                                $destinationPath = GLPI_PLUGIN_DOC_DIR . '/gestion/' . $d['folder_name'];
                                if (!is_dir($destinationPath)) {
                                    @mkdir($destinationPath, 0755, true);
                                }
                            }
                        }
                    }
                }

                // Droits édition
                $disabled = !Session::haveRight('plugin_gestion_add', UPDATE);

                // ------------------------- MODAL HTML -------------------------
                ob_start(); ?>
                <style>
                  #AddGestionModal .select2-container { width:100%!important; max-width:100%; }
                </style>
                <div class="modal fade" id="AddGestionModal"
                    data-root-doc="<?= Html::cleanInputText($CFG_GLPI['root_doc']) ?>"
                    tabindex="-1" aria-labelledby="AddGestionModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="AddGestionModalLabel">Ajouter des documents</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>

                      <div class="modal-body">
                        <!-- Zone messages -->
                        <div id="gestion-messages" style="display:none; margin-bottom: 15px;">
                          <div class="alert alert-dismissible fade show" role="alert" id="gestion-alert">
                            <span id="gestion-message-text"></span>
                            <button type="button" class="btn-close" aria-label="Close" onclick="$('#gestion-messages').hide();"></button>
                          </div>
                        </div>

                        <!-- Spinner -->
                        <div id="loading-spinner" style="display:none; text-align:center;">
                          <div class="spinner-border text-info" role="status" style="width: 3rem; height: 3rem; border-width: .4rem;">
                            <span class="visually-hidden">Loading...</span>
                          </div>
                        </div>
                        <?php if ($connexion) { ?>
                          <!-- tokens/infos à lire côté JS -->
                          <input type="hidden" id="gestion_csrf" value="<?= Html::cleanInputText(Session::getNewCSRFToken()) ?>">
                          <input type="hidden" id="gestion_ticket_id" value="<?= (int)$ticketId ?>">

                          <!-- aide au-dessus du select -->
                          <div id="gestion-hint" class="text-muted small mb-2"></div>

                          <?php
                          // IMPORTANT : on pré-remplit avec $groups + $selected_ids
                          Dropdown::showFromArray('groups_id', $groups, [
                              'multiple' => true,
                              'width'    => '100%',
                              'values'   => $selected_ids,
                              'disabled' => $disabled,
                          ]);
                          ?>

                          <div class="modal-footer" style="margin-top:55px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                            <button type="button" id="gestion-submit" class="btn btn-primary" <?= $disabled ? 'disabled' : '' ?>>Sauvegarder</button>
                          </div>
                        <?php } else { ?>
                          <div class="alert alert-danger">
                            Une erreur est survenue dans la configuration de la méthode de récupération des documents
                            (Sage local, SharePoint ou dossier local).<br><br>
                            Veuillez contacter votre administrateur afin de vérifier la configuration du plugin.
                          </div>
                        <?php } ?>
                      </div>
                    </div>
                  </div>
                </div>
                <?php
                $gestion_modal_html = ob_get_clean();

                // --------------------------- JS ---------------------------
                $ticketIdJs = (int)$ticketId;
                $canConnect = $connexion ? 'true' : 'false';

                $gestion_js_block = <<<'JS'
                  $(function() {
                    var GESTION_CONNEXION = (typeof CONNEXION_PLACEHOLDER !== 'undefined') ? CONNEXION_PLACEHOLDER : true;

                    function showModalMessage(msg, type) {
                      type = type || 'danger';
                      $('#gestion-message-text').text(msg);
                      $('#gestion-alert').removeClass('alert-danger alert-warning alert-success alert-info')
                                        .addClass('alert-' + type);
                      $('#gestion-messages').show();
                      if (type === 'success') {
                        setTimeout(function(){ $('#gestion-messages').hide(); }, 5000);
                      }
                    }
                    function hideModalMessage(){ $('#gestion-messages').hide(); }

                    // Ouvrir le modal + charger options
                    $(document).on('click', '[data-action="open-gestion-modal"]', function(e){
                      e.preventDefault();

                      if (!GESTION_CONNEXION) {
                        $('#AddGestionModal').modal('show');
                        return;
                      }

                      hideModalMessage();
                      $('#loading-spinner').show();

                      $.ajax({
                        url: '../plugins/gestion/front/charger_dropdown.php',
                        method: 'GET',
                        data: { ticketId: TICKET_ID_PLACEHOLDER },
                        dataType: 'json',
                        timeout: 10000
                      })
                      .done(function(resp){
                        var $sel = $('[name="groups_id[]"]');
                        if (!$sel.length) {
                          $('#loading-spinner').hide();
                          $('#AddGestionModal').modal('show');
                          return;
                        }

                        var preselected = SELECTED_JSON_PLACEHOLDER; // injecté depuis PHP

                        // Remplir
                        $sel.empty();
                        if (resp && resp.data) {
                          Object.keys(resp.data).forEach(function(k){
                            $sel.append('<option value="'+k+'">'+resp.data[k]+'</option>');
                          });
                        }

                        // Aide
                        var currentMode = resp && resp.mode ? resp.mode : 0;
                        var hintText = currentMode == 1
                          ? 'Tapez le numéro de document (ex : BL154869), puis Entrée'
                          : 'Sélectionnez un ou plusieurs documents';
                        $('#gestion-hint').text(hintText);

                        // Select2
                        if ($sel.hasClass('select2-hidden-accessible')) { $sel.select2('destroy'); }
                        $sel.select2({
                          width: '100%',
                          dropdownAutoWidth: false,
                          dropdownParent: $('#AddGestionModal'),
                          minimumResultsForSearch: 0,
                          allowClear: false,
                          tags: currentMode == 1,
                          tokenSeparators: [',',' '],
                          createTag: function (params) {
                            var t = $.trim(params.term);
                            return t === '' ? null : { id:t, text:t, newTag:true };
                          }
                        });

                        // Forcer présélection
                        if (Array.isArray(preselected) && preselected.length) {
                          preselected.forEach(function(v){
                            if ($sel.find('option[value="'+v+'"]').length === 0) {
                              var label = (v.indexOf('/') > -1) ? v.split('/').pop() : v;
                              $sel.append('<option value="'+v+'">'+label+'</option>');
                            }
                          });
                          $sel.val(preselected).trigger('change');
                        }

                        // Vérif SAGE
                        if (currentMode == 1) {
                          $sel.off('select2:select.verify').on('select2:select.verify', function (e) {
                            var data = e.params.data;
                            if (!data.newTag) return;
                            hideModalMessage();
                            $('#loading-spinner').show();

                            $.ajax({
                              url: '../plugins/gestion/front/charger_dropdown.php',
                              method: 'GET',
                              data: { ticketId: TICKET_ID_PLACEHOLDER, verifyDoc: data.id },
                              dataType: 'json',
                              timeout: 5000
                            })
                            .done(function(vr){
                              if (!vr || !vr.success) {
                                var vals = $sel.val() || [];
                                var idx = vals.indexOf(data.id);
                                if (idx > -1) { vals.splice(idx,1); $sel.val(vals).trigger('change'); }
                                $sel.find('option[value="'+data.id+'"]').remove();
                                showModalMessage('Document "'+data.id+'" non trouvé dans l\'API Sage. Il a été retiré de la sélection.', 'warning');
                              } else {
                                $sel.find('option[value="'+data.id+'"]').removeAttr('data-select2-tag');
                                showModalMessage('Document "'+data.id+'" vérifié et ajouté.', 'success');
                              }
                            })
                            .fail(function(){
                              var vals = $sel.val() || [];
                              var idx = vals.indexOf(data.id);
                              if (idx > -1) { vals.splice(idx,1); $sel.val(vals).trigger('change'); }
                              $sel.find('option[value="'+data.id+'"]').remove();
                              showModalMessage('Erreur lors de la vérification du document "'+data.id+'". Il a été retiré de la sélection.', 'danger');
                            })
                            .always(function(){ $('#loading-spinner').hide(); });
                          });
                        }

                        $('#loading-spinner').hide();
                        $('#AddGestionModal').modal('show');
                      })
                      .fail(function(xhr, status, err){
                        console.error('Erreur AJAX dropdown:', status, err, xhr && xhr.responseText);
                        showModalMessage('Erreur de communication avec le serveur. Vérifiez la console pour plus de détails.', 'danger');
                        $('#loading-spinner').hide();
                        $('#AddGestionModal').modal('show');
                      });
                    });

                  // ⬇️ SAUVEGARDE : construire un form hors du DOM GLPI et le soumettre
                  $(document).on('click', '#gestion-submit', function(){
                    var $sel = $('[name="groups_id[]"]');
                    if (!$sel.length) return;

                    // --- Neutraliser le guard "unsaved changes" de GLPI ---
                    try { $(window).off('beforeunload'); } catch(e){}
                    try { window.onbeforeunload = null; } catch(e){}
                    // Plusieurs versions GLPI : on tente aussi de remettre le flag à false
                    try { if (window.GLPI && GLPI.Forms && typeof GLPI.Forms.setChanged === 'function') { GLPI.Forms.setChanged(false); } } catch(e){}
                    if (typeof window.glpi_form_changed !== 'undefined') { window.glpi_form_changed = false; }

                    // --- Construire et soumettre le formulaire dédié plugin ---
                    var root  = $('#AddGestionModal').data('root-doc') || '';
                    var token = $('#gestion_csrf').val() || '';
                    var tid   = $('#gestion_ticket_id').val() || '';
                    var values = $sel.val() || [];

                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = root + '/plugins/gestion/front/ticket.form.php';

                    function add(name, val) {
                      var input = document.createElement('input');
                      input.type = 'hidden';
                      input.name = name;
                      input.value = val;
                      form.appendChild(input);
                    }

                    add('_glpi_csrf_token', token);
                    add('tickets_id', tid);
                    add('save_selection', '1');
                    values.forEach(function(v){ add('groups_id[]', v); });

                    document.body.appendChild(form);
                    form.submit();
                  });
                  });
                JS;

                // Injecte placeholders dans le JS
                $gestion_js_block = str_replace(
                    ['TICKET_ID_PLACEHOLDER', 'SELECTED_JSON_PLACEHOLDER', 'CONNEXION_PLACEHOLDER'],
                    [(string)$ticketId, ($selected_json ?: '[]'), $canConnect],
                    $gestion_js_block
                );
            }
        }

        // ------------------------- Rendu Twig -------------------------
        TemplateRenderer::getInstance()->display('@gestion/tickets/config.html.twig', [
            'embed_in_ticket_form'     => $embed_in_ticket_form,
            'uncollapsed'              => $uncollapsed ?? false,
            'type_name'                => self::getTypeName(),
            'ticket'                   => $ticket,
            'gestion_button_enabled'   => $gestion_button_enabled,
            'gestion_modal_html'       => $gestion_modal_html,
            'gestion_js_block'         => $gestion_js_block,
            'linked_docs'              => $linked_docs,
        ]);
    }

}
