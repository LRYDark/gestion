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


include('../../../inc/includes.php');

// Force menu refresh once if custom action link is missing (menu is cached in session)
if (isset($_SESSION['glpimenu'])) {
   $has_link = isset($_SESSION['glpimenu']['management']['content']['plugingestionmenu']['links']['Signature BL']);
   if (!$has_link) {
      unset($_SESSION['glpimenu']);
   }
}

Html::header(PluginGestionSurvey::getTypeName(2), '', "management", "plugingestionmenu");
      
$gestion = new PluginGestionSurvey();
$gestion->checkGlobal(READ);

if ($gestion->canView()) {
   echo '<script>
      window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
   </script>';
   echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?v=' . time() . '" defer></script>';
   echo '<style>
      a.btn[href*="signature_bl=1"] {
         background-color: transparent !important;
         background-image: none !important;
         border-color: var(--tblr-primary) !important;
         color: var(--tblr-body-color, var(--bs-body-color, #000)) !important;
         box-shadow: none !important;
      }
      a.btn[href*="signature_bl=1"]:hover,
      a.btn[href*="signature_bl=1"]:focus {
         background-color: transparent !important;
         background-image: none !important;
         border-color: var(--tblr-primary-darken) !important;
         color: var(--tblr-body-color, var(--bs-body-color, #000)) !important;
         box-shadow: none !important;
         filter: brightness(0.9);
      }
   </style>';

   Search::show('PluginGestionSurvey');

   if (Session::haveRight('plugin_gestion_survey', CREATE)) {
      echo '
         <div class="modal fade" id="gestionQuickBlListModal" tabindex="-1" aria-labelledby="gestionQuickBlListLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
               <div class="modal-content">
                  <div class="modal-header">
                     <h5 class="modal-title" id="gestionQuickBlListLabel">Signature BL</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                     <div class="mb-3">
                        <label class="form-label">Recherche BL (ex: BL123456)</label>
                        <input type="text" id="gestionQuickBlListInput" class="form-control" value="BL" placeholder="Ex: BL123456">
                        <div id="gestionQuickBlListErr" class="alert alert-danger" style="display:none; margin-top:8px;"></div>
                     </div>
                     <div id="gestionQuickBlListResults" class="list-group" style="max-height: 280px; overflow:auto;"></div>
                  </div>
               </div>
            </div>
         </div>
      ';

      $base = PLUGIN_GESTION_WEBDIR;
      $script = <<<JAVASCRIPT
         (function(){
            const base = (window.GLPI_PLUG_GESTION || "{$base}").replace(/\\/$/, '');
            const modalId = 'gestionQuickBlListModal';
            const inputId = 'gestionQuickBlListInput';
            const resultsId = 'gestionQuickBlListResults';
            const errId = 'gestionQuickBlListErr';
            let searchTimer = null;

            function getCsrf() {
               const meta = document.querySelector('meta[property="glpi:csrf_token"]');
               if (meta && meta.content) return meta.content;
               const input = document.querySelector('input[name="_glpi_csrf_token"]');
               return input ? input.value : '';
            }

            function showError(msg) {
               const errEl = document.getElementById(errId);
               if (!errEl) return;
               errEl.textContent = msg || 'Erreur inconnue';
               errEl.style.display = 'block';
            }

            function clearError() {
               const errEl = document.getElementById(errId);
               if (!errEl) return;
               errEl.textContent = '';
               errEl.style.display = 'none';
            }

            function normalizeBl(val) {
               let v = (val || '').toString().toUpperCase();
               if (!v.startsWith('BL')) {
                  v = 'BL' + v.replace(/[^0-9]/g, '');
               } else {
                  v = 'BL' + v.slice(2).replace(/[^0-9]/g, '');
               }
               return v;
            }

            function attachBlGuards(inputEl) {
               if (!inputEl || inputEl.dataset.blGuard === '1') return;
               inputEl.dataset.blGuard = '1';

               const ensureCaret = () => {
                  try {
                     const pos = Math.max(2, inputEl.selectionStart || 0);
                     if ((inputEl.selectionStart || 0) < 2) inputEl.setSelectionRange(pos, pos);
                  } catch (e) {}
               };

               inputEl.addEventListener('focus', () => {
                  inputEl.value = normalizeBl(inputEl.value);
                  setTimeout(ensureCaret, 0);
               });
               inputEl.addEventListener('click', ensureCaret);
               inputEl.addEventListener('keyup', ensureCaret);
               inputEl.addEventListener('beforeinput', (e) => {
                  const s = inputEl.selectionStart || 0;
                  const epos = inputEl.selectionEnd || 0;
                  const type = e.inputType || '';
                  if ((type === 'deleteContentBackward' && s <= 2 && epos <= 2) ||
                      (type === 'deleteContentForward' && s < 2) ||
                      (type === 'deleteByCut' && s < 2)) {
                     e.preventDefault();
                     ensureCaret();
                  }
               });
               inputEl.addEventListener('keydown', (e) => {
                  const s = inputEl.selectionStart || 0;
                  const epos = inputEl.selectionEnd || 0;
                  if ((e.key === 'Backspace' && s <= 2 && epos <= 2) ||
                      (e.key === 'Delete' && s < 2)) {
                     e.preventDefault();
                     ensureCaret();
                  }
                  if (e.key === 'ArrowLeft' && s <= 2) {
                     e.preventDefault();
                     try { inputEl.setSelectionRange(2, 2); } catch (err) {}
                  }
               });
               inputEl.addEventListener('paste', (e) => {
                  e.preventDefault();
                  const txt = (e.clipboardData || window.clipboardData)?.getData('text') || '';
                  inputEl.value = 'BL' + String(txt).replace(/[^0-9]/g, '');
                  ensureCaret();
               });
            }

            function renderResults(items) {
               const resultsEl = document.getElementById(resultsId);
               if (!resultsEl) return;
               resultsEl.innerHTML = '';
               if (!items || items.length === 0) {
                  resultsEl.innerHTML = '<div class="list-group-item text-muted">Aucun resultat</div>';
                  return;
               }
               items.forEach((item) => {
                  const row = document.createElement('div');
                  row.className = 'list-group-item list-group-item-action';
                  row.style.cursor = 'pointer';
                  row.innerHTML = item.html || item.text || item.filename || '';
                  row.addEventListener('click', () => selectItem(item));
                  resultsEl.appendChild(row);
               });
            }

            async function searchBl(q) {
               clearError();
               const resultsEl = document.getElementById(resultsId);
               if (!q || q.length < 2) {
                  if (resultsEl) resultsEl.innerHTML = '';
                  showError('Veuillez saisir au moins 2 caracteres.');
                  return;
               }
               try {
                  const url = base + '/ajax/ajax_search_pdf.php?q=' + encodeURIComponent(q);
                  const res = await fetch(url, { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                  const data = await res.json();
                  renderResults(Array.isArray(data) ? data : []);
               } catch (e) {
                  showError('Erreur de recherche: ' + e.message);
               }
            }

            async function selectItem(item) {
               clearError();
               const signed = parseInt(item.signed || 0, 10);
               if (signed === 1) {
                  showError('Ce BL est déjà signé.');
                  return;
               }

               const payload = new URLSearchParams();
               payload.append('save', (item.save || '').trim());
               payload.append('filename', (item.filename || item.text || '').trim());
               payload.append('folder', (item.folder || '').trim());
               payload.append('signed', String(signed));
               payload.append('search_pdf', (item.text || item.id || '').trim());
               payload.append('tickets_id', '0');
               payload.append('entities_id', '0');
               const csrf = getCsrf();
               if (csrf) payload.append('_glpi_csrf_token', csrf);

               try {
                  const headers = {
                     'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                     'X-Requested-With': 'XMLHttpRequest'
                  };
                  if (csrf) {
                     headers['X-Glpi-Csrf-Token'] = csrf;
                  }
                  const res = await fetch(base + '/ajax/quick_add_survey_form.php', {
                     method: 'POST',
                     headers,
                     body: payload.toString(),
                     credentials: 'same-origin'
                  });
                  const text = await res.text();
                  let data;
                  try { data = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 200)); }
                  if (!res.ok || !data.ok) {
                     throw new Error(data.error || ('HTTP ' + res.status));
                  }

                  const surveyId = data.id;
                  if (!surveyId) {
                     throw new Error('Document introuvable.');
                  }

                  closeModal();

                  if (typeof gestion_loadCriForm !== 'function') {
                     throw new Error('Fonction de signature indisponible.');
                  }
                  gestion_loadCriForm('showCriForm', surveyId, {
                     job: parseInt(data.tickets_id || '0', 10) || 0,
                     root_doc: base,
                     root_modal: 'survey-form'
                  });
               } catch (e) {
                  showError('Creation impossible: ' + e.message);
               }
            }

            function openModal() {
               const modalEl = document.getElementById(modalId);
               if (!modalEl) return;
               clearError();
               const inputEl = document.getElementById(inputId);
               const resultsEl = document.getElementById(resultsId);
               if (resultsEl) resultsEl.innerHTML = '';
               if (inputEl) {
                  inputEl.value = normalizeBl(inputEl.value);
                  setTimeout(() => {
                     inputEl.focus();
                     try {
                        const len = inputEl.value.length;
                        inputEl.setSelectionRange(len, len);
                     } catch (e) {}
                  }, 50);
               }
               if (window.bootstrap && bootstrap.Modal) {
                  bootstrap.Modal.getOrCreateInstance(modalEl).show();
               } else {
                  modalEl.style.display = 'block';
                  modalEl.classList.add('show');
                  modalEl.removeAttribute('aria-hidden');
                  modalEl.setAttribute('role', 'dialog');
               }
            }

            function closeModal() {
               const modalEl = document.getElementById(modalId);
               if (!modalEl) return;
               if (window.bootstrap && bootstrap.Modal) {
                  const inst = bootstrap.Modal.getOrCreateInstance(modalEl);
                  inst.hide();
               } else {
                  modalEl.style.display = 'none';
                  modalEl.classList.remove('show');
                  modalEl.setAttribute('aria-hidden', 'true');
               }
            }

            function findSignatureLink() {
               const links = Array.from(document.querySelectorAll('a[href*="signature_bl=1"]'));
               if (links.length > 0) return links[0];
               const byText = Array.from(document.querySelectorAll('a'))
                  .find(a => (a.textContent || '').trim().toLowerCase() === 'signature bl');
               return byText || null;
            }

            function attachSignatureLink() {
               const link = findSignatureLink();
               if (!link || link.dataset.bound === '1') return;
               link.dataset.bound = '1';
               link.setAttribute('title', 'Signature BL');
               link.setAttribute('data-bs-toggle', 'tooltip');
               link.setAttribute('data-bs-placement', 'bottom');
               if (window.bootstrap && bootstrap.Tooltip) {
                  bootstrap.Tooltip.getOrCreateInstance(link);
               }
               link.addEventListener('click', function(ev){
                  ev.preventDefault();
                  openModal();
               });
            }

            function openFromUrl() {
               try {
                  const params = new URLSearchParams(window.location.search || '');
                  if (params.get('signature_bl') === '1') {
                     openModal();
                     params.delete('signature_bl');
                     const qs = params.toString();
                     const clean = window.location.pathname + (qs ? ('?' + qs) : '') + window.location.hash;
                     if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, document.title, clean);
                     }
                  }
               } catch (e) {}
            }

            function attachInput() {
               const inputEl = document.getElementById(inputId);
               if (!inputEl || inputEl.dataset.bound === '1') return;
               inputEl.dataset.bound = '1';
               attachBlGuards(inputEl);
               inputEl.addEventListener('input', () => {
                  clearTimeout(searchTimer);
                  searchTimer = setTimeout(() => {
                     const q = normalizeBl(inputEl.value);
                     if (inputEl.value !== q) inputEl.value = q;
                     searchBl(q.trim());
                  }, 300);
               });
            }

            if (document.readyState === 'loading') {
               document.addEventListener('DOMContentLoaded', function(){
                  attachSignatureLink();
                  attachInput();
                  openFromUrl();
               });
            } else {
               attachSignatureLink();
               attachInput();
               openFromUrl();
            }
         })();
      JAVASCRIPT;

      echo Html::scriptBlock($script);
   }

} else {
   Html::displayRightError();
}

Html::footer();
