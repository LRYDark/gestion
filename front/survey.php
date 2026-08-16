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
   if (defined('GESTION_AUTO_SCAN') && GESTION_AUTO_SCAN) {
      echo '<script>window.GESTION_AUTO_SCAN = true;</script>';
   }
   echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '" defer></script>';
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

   // ---- Barre de stats BL : chaque carte est cliquable et filtre la liste dessous ----
   $survey_table = 'glpi_plugin_gestion_surveys';
   $entity_crit  = getEntitiesRestrictCriteria($survey_table);
   $late_limit   = date('Y-m-d H:i:s', time() - (14 * DAY_TIMESTAMP));
   $nb_signed    = countElementsInTable($survey_table, ['signed' => 1] + $entity_crit);
   $nb_unsigned  = countElementsInTable($survey_table, ['signed' => 0] + $entity_crit);
   $nb_late      = countElementsInTable($survey_table, ['signed' => 0, 'date_creation' => ['<', $late_limit]] + $entity_crit);

   // Options de recherche : 5 = signe (bool), 6 = date_creation (datatype date => valeur Y-m-d).
   $self_url     = PLUGIN_GESTION_WEBDIR . '/front/survey.php';
   $url_signed   = $self_url . '?reset=reset&criteria[0][link]=AND&criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=1';
   $url_unsigned = $self_url . '?reset=reset&criteria[0][link]=AND&criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]=0';
   $url_late     = $url_unsigned . '&criteria[1][link]=AND&criteria[1][field]=6&criteria[1][searchtype]=lessthan&criteria[1][value]=' . urlencode(date('Y-m-d', time() - (14 * DAY_TIMESTAMP)));

   $url_all = $self_url . '?reset=reset';

   $stat_cards = [
      ['url' => $url_all,      'label' => 'Tous les BL',                    'count' => $nb_signed + $nb_unsigned, 'color' => 'primary', 'icon' => 'ti ti-list'],
      ['url' => $url_signed,   'label' => 'BL signés',                      'count' => $nb_signed,   'color' => 'success', 'icon' => 'ti ti-circle-check'],
      ['url' => $url_unsigned, 'label' => 'BL non signés',                  'count' => $nb_unsigned, 'color' => 'warning', 'icon' => 'ti ti-signature'],
      ['url' => $url_late,     'label' => 'Non signés depuis + de 14 jours', 'count' => $nb_late,     'color' => 'danger',  'icon' => 'ti ti-alert-triangle'],
   ];
   echo '<div class="card mb-2" id="gestionBlStatsBar">';
   echo '<div class="card-body py-2 px-3 d-flex flex-wrap align-items-center">';
   $first = true;
   foreach ($stat_cards as $c) {
      if (!$first) {
         echo '<div class="vr mx-3 my-1"></div>';
      }
      $first = false;
      echo '<a href="' . htmlspecialchars($c['url'], ENT_QUOTES) . '" class="d-flex align-items-center gap-2 text-decoration-none text-reset py-1" title="' . htmlspecialchars(__('Cliquer pour filtrer la liste', 'gestion'), ENT_QUOTES) . '">';
      echo '<span class="avatar avatar-sm bg-' . $c['color'] . '-lt"><i class="' . $c['icon'] . '"></i></span>';
      echo '<span class="d-flex flex-column lh-sm">';
      echo '<span class="h2 fw-bold mb-0">' . (int)$c['count'] . '</span>';
      echo '<span class="text-muted small">' . htmlspecialchars($c['label']) . '</span>';
      echo '</span></a>';
   }
   echo '</div></div>';

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
                        <div class="input-group">
                           <input type="text" id="gestionQuickBlListInput" class="form-control" value="BL" placeholder="Ex: BL123456">
                           <button type="button" id="gestionBlScanBtn" class="btn btn-outline-secondary" title="Scanner / Joindre un document">
                              <i class="ti ti-scan"></i>
                           </button>
                        </div>
                        <input type="file" id="gestionBlFileInput" accept="image/*,.pdf,application/pdf" style="display:none;">
                        <div id="gestionQuickBlListErr" class="alert alert-danger" style="display:none; margin-top:8px;"></div>
                     </div>
                     <div id="gestionBlCameraArea" style="display:none;">
                        <div style="position:relative; border-radius:12px; overflow:hidden; background:#000;">
                           <video id="gestionBlScanVideo" autoplay playsinline muted style="width:100%; max-height:300px; display:block; object-fit:cover;"></video>
                           <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:80%; height:40%; border:2px dashed rgba(255,255,255,0.5); border-radius:12px; pointer-events:none;">
                              <div style="position:absolute;top:-2px;left:-2px;width:24px;height:24px;border-top:3px solid var(--tblr-primary,#206bc4);border-left:3px solid var(--tblr-primary,#206bc4);border-radius:8px 0 0 0;"></div>
                              <div style="position:absolute;top:-2px;right:-2px;width:24px;height:24px;border-top:3px solid var(--tblr-primary,#206bc4);border-right:3px solid var(--tblr-primary,#206bc4);border-radius:0 8px 0 0;"></div>
                              <div style="position:absolute;bottom:-2px;left:-2px;width:24px;height:24px;border-bottom:3px solid var(--tblr-primary,#206bc4);border-left:3px solid var(--tblr-primary,#206bc4);border-radius:0 0 0 8px;"></div>
                              <div style="position:absolute;bottom:-2px;right:-2px;width:24px;height:24px;border-bottom:3px solid var(--tblr-primary,#206bc4);border-right:3px solid var(--tblr-primary,#206bc4);border-radius:0 0 8px 0;"></div>
                           </div>
                           <canvas id="gestionBlScanCanvas" style="display:none;"></canvas>
                        </div>
                        <div class="text-center mt-2 mb-2">
                           <small class="text-muted" id="gestionBlScanHint">Placez le numero BL dans le cadre</small>
                           <div class="mt-2">
                              <button type="button" id="gestionBlCaptureBtn" class="btn btn-primary btn-sm me-2"><i class="ti ti-camera me-1"></i>Capturer</button>
                              <button type="button" id="gestionBlCancelScan" class="btn btn-outline-secondary btn-sm">Annuler</button>
                           </div>
                        </div>
                     </div>
                     <div id="gestionBlOcrProgress" style="display:none;" class="text-center my-3">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2 text-muted" id="gestionBlOcrStatus">Analyse du document...</span>
                        <div class="progress mt-2" style="height:4px;">
                           <div id="gestionBlOcrBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%;"></div>
                        </div>
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
            let cameraStream = null;
            let autoScanActive = false;
            let ocrWorker = null;
            let pendingAutoClick = false;
            let barcodeDetector = null;
            let barcodeDetectorReady = false;
            let jsQrLoadPromise = null;

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
                  v = 'BL' + v.replace(/[^0-9]/g, '').slice(0, 6);
               } else {
                  v = 'BL' + v.slice(2).replace(/[^0-9]/g, '').slice(0, 6);
               }
               return v;
            }

            function isStrictBl(val) {
               return /^BL\d{6}$/.test((val || '').toString().toUpperCase());
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
                  inputEl.value = normalizeBl(txt);
                  ensureCaret();
               });
            }

            function isSageOnlyItem(item) {
               if (parseInt(item.signed || 0, 10) === 1) return false;
               var html = (item.html || item.text || '').toUpperCase();
               return html.indexOf('SAGE') !== -1
                  && html.indexOf('SIGN') === -1
                  && html.indexOf('LOCAL') === -1;
            }

            function renderResults(items) {
               const resultsEl = document.getElementById(resultsId);
               if (!resultsEl) return;
               resultsEl.innerHTML = '';
               if (!items || items.length === 0) {
                  resultsEl.innerHTML = '<div class="list-group-item text-muted">Aucun résultat : BL déjà en facture ou introuvable dans SAGE</div>';
                  pendingAutoClick = false;
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
               // Auto-clic apres scan : 1 seul resultat SAGE uniquement
               if (pendingAutoClick) {
                  pendingAutoClick = false;
                  if (items.length === 1 && isSageOnlyItem(items[0])) {
                     setTimeout(function() { selectItem(items[0]); }, 400);
                  }
               }
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
                     root_modal: 'scan-form'
                  });
               } catch (e) {
                  showError('Creation impossible: ' + e.message);
               }
            }

            function openModal() {
               const modalEl = document.getElementById(modalId);
               if (!modalEl) return;
               stopCamera();
               clearError();
               showOcrProgress(false);
               const inputEl = document.getElementById(inputId);
               const resultsEl = document.getElementById(resultsId);
               if (resultsEl) { resultsEl.innerHTML = ''; resultsEl.style.display = ''; }
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
               stopCamera();
               showOcrProgress(false);
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
                  const fromUrl = params.get('signature_bl') === '1';
                  const fromShortcut = !!window.GESTION_AUTO_SCAN;

                  if (fromUrl || fromShortcut) {
                     const wantScan = params.get('scan') === '1' || fromShortcut;
                     openModal();

                     // Nettoyer l URL (seulement si parametres presents)
                     if (fromUrl) {
                        params.delete('signature_bl');
                        params.delete('scan');
                        const qs = params.toString();
                        const clean = window.location.pathname + (qs ? ('?' + qs) : '') + window.location.hash;
                        if (window.history && window.history.replaceState) {
                           window.history.replaceState({}, document.title, clean);
                        }
                     }
                     if (fromShortcut) delete window.GESTION_AUTO_SCAN;

                     // Lancer le scanner auto (raccourci tablette)
                     if (wantScan) {
                        setTimeout(async function() {
                           var ok = await startCamera();
                           if (ok) {
                              var captureBtn = document.getElementById('gestionBlCaptureBtn');
                              if (captureBtn) captureBtn.style.display = 'none';
                              autoScanLoop();
                           }
                        }, 500);
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

            // ─── Scanner / OCR BL ───────────────────────────────
            function isMobileDevice() {
               return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
                  || ('ontouchstart' in window)
                  || (navigator.maxTouchPoints > 0 && navigator.maxTouchPoints !== 256);
            }

            function loadTesseractJs() {
               return new Promise(function(resolve, reject) {
                  if (window.Tesseract) { resolve(); return; }
                  var s = document.createElement('script');
                  s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
                  s.onload = function() { resolve(); };
                  s.onerror = function() { reject(new Error('Impossible de charger le module OCR.')); };
                  document.head.appendChild(s);
               });
            }

            function loadJsQrJs() {
               if (window.jsQR) return Promise.resolve();
               if (jsQrLoadPromise) return jsQrLoadPromise;
               jsQrLoadPromise = new Promise(function(resolve, reject) {
                  var s = document.createElement('script');
                  s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
                  s.onload = function() {
                     if (window.jsQR) resolve();
                     else reject(new Error('Impossible de charger le module QR code.'));
                  };
                  s.onerror = function() { reject(new Error('Impossible de charger le module QR code.')); };
                  document.head.appendChild(s);
               });
               return jsQrLoadPromise;
            }

            function loadPdfJs() {
               return new Promise(function(resolve, reject) {
                  if (window.pdfjsLib) { resolve(); return; }
                  var s = document.createElement('script');
                  s.src = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3/build/pdf.min.js';
                  s.onload = function() {
                     if (window.pdfjsLib) {
                        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3/build/pdf.worker.min.js';
                     }
                     resolve();
                  };
                  s.onerror = function() { reject(new Error('Impossible de charger le module PDF.')); };
                  document.head.appendChild(s);
               });
            }

            async function renderPdfToCanvas(file) {
               await loadPdfJs();
               var arrayBuffer = await file.arrayBuffer();
               var pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
               var page = await pdf.getPage(1);
               var viewport = page.getViewport({ scale: 2 });
               var canvas = document.createElement('canvas');
               canvas.width = viewport.width;
               canvas.height = viewport.height;
               var ctx = canvas.getContext('2d');
               await page.render({ canvasContext: ctx, viewport: viewport }).promise;
               return canvas;
            }

            function showOcrProgress(show) {
               var el = document.getElementById('gestionBlOcrProgress');
               if (el) el.style.display = show ? 'block' : 'none';
               if (!show) setOcrBar(0);
            }

            function setOcrBar(pct) {
               var bar = document.getElementById('gestionBlOcrBar');
               if (bar) bar.style.width = Math.round(pct) + '%';
            }

            function setOcrStatus(msg) {
               var el = document.getElementById('gestionBlOcrStatus');
               if (el) el.textContent = msg || '';
            }

            function stopCamera() {
               autoScanActive = false;
               terminateOcrWorker();
               if (cameraStream) {
                  cameraStream.getTracks().forEach(function(t) { t.stop(); });
                  cameraStream = null;
               }
               var video = document.getElementById('gestionBlScanVideo');
               if (video) video.srcObject = null;
               var area = document.getElementById('gestionBlCameraArea');
               if (area) area.style.display = 'none';
               var results = document.getElementById(resultsId);
               if (results) results.style.display = '';
               var hint = document.getElementById('gestionBlScanHint');
               if (hint) hint.textContent = 'Placez le numero BL dans le cadre';
            }

            async function startCamera() {
               stopCamera();
               if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                  return false;
               }
               var area = document.getElementById('gestionBlCameraArea');
               var video = document.getElementById('gestionBlScanVideo');
               if (!area || !video) return false;
               try {
                  cameraStream = await navigator.mediaDevices.getUserMedia({
                     video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
                  });
                  video.srcObject = cameraStream;
                  area.style.display = 'block';
                  var results = document.getElementById(resultsId);
                  if (results) results.style.display = 'none';
                  return true;
               } catch (e) {
                  stopCamera();
                  return false;
               }
            }

            function captureFrameFromVideo() {
               var video = document.getElementById('gestionBlScanVideo');
               var canvas = document.getElementById('gestionBlScanCanvas');
               if (!video || !canvas) return null;
               canvas.width = video.videoWidth || 640;
               canvas.height = video.videoHeight || 480;
               var ctx = canvas.getContext('2d');
               ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
               return canvas;
            }

            function extractBlFromText(text) {
               if (!text) return null;
               var t = text.toUpperCase().replace(/[^A-Z0-9\s\/\-:.]/g, ' ');
               // Anti faux-positifs: BL explicite + exactement 6 chiffres.
               var m = t.match(/(?:^|[^A-Z0-9])B\s*L\s*[:\-\/.\s]*((?:\d[\s\-\/.]*){6})(?![\s\-\/.]*\d)/);
               if (m) {
                  var num = m[1].replace(/[^0-9]/g, '');
                  if (num.length === 6) return 'BL' + num;
               }
               return null;
            }

            function fillInputWithBl(blNum) {
               if (!blNum) return;
               var normalized = normalizeBl(blNum);
               if (!isStrictBl(normalized)) return;
               pendingAutoClick = true;
               var inp = document.getElementById(inputId);
               if (inp) {
                  inp.value = normalized;
                  inp.dispatchEvent(new Event('input', { bubbles: true }));
               }
            }

            function toCanvasSource(source) {
               if (!source) return null;
               if (typeof HTMLCanvasElement !== 'undefined' && source instanceof HTMLCanvasElement) {
                  return source;
               }
               var width = 0;
               var height = 0;
               if (typeof HTMLVideoElement !== 'undefined' && source instanceof HTMLVideoElement) {
                  width = source.videoWidth || source.clientWidth || 0;
                  height = source.videoHeight || source.clientHeight || 0;
               } else {
                  width = source.naturalWidth || source.width || 0;
                  height = source.naturalHeight || source.height || 0;
               }
               if (!width || !height) return null;
               var canvas = document.createElement('canvas');
               canvas.width = width;
               canvas.height = height;
               var ctx = canvas.getContext('2d');
               if (!ctx) return null;
               ctx.drawImage(source, 0, 0, width, height);
               return canvas;
            }

            async function detectBlWithBarcodeDetector(source) {
               if (typeof BarcodeDetector === 'undefined') return null;
               var canvas = toCanvasSource(source);
               if (!canvas) return null;
               try {
                  if (!barcodeDetectorReady) {
                     var formats = ['qr_code'];
                     var canFilterFormats = false;
                     if (typeof BarcodeDetector.getSupportedFormats === 'function') {
                        canFilterFormats = true;
                        try {
                           var supported = await BarcodeDetector.getSupportedFormats();
                           if (Array.isArray(supported) && supported.length) {
                              formats = formats.filter(function(f) { return supported.indexOf(f) !== -1; });
                           }
                        } catch (e) {}
                     }
                     barcodeDetector = (canFilterFormats && formats.length)
                        ? new BarcodeDetector({ formats: formats })
                        : new BarcodeDetector();
                     barcodeDetectorReady = true;
                  }
                  var detected = await barcodeDetector.detect(canvas);
                  if (!detected || !detected.length) return null;
                  for (var i = 0; i < detected.length; i++) {
                     var raw = (detected[i] && detected[i].rawValue) ? String(detected[i].rawValue) : '';
                     var bl = extractBlFromText(raw);
                     if (bl) return bl;
                  }
               } catch (e) {}
               return null;
            }

            async function detectBlWithJsQr(source) {
               var canvas = toCanvasSource(source);
               if (!canvas) return null;
               try {
                  await loadJsQrJs();
               } catch (e) {
                  return null;
               }
               if (!window.jsQR) return null;
               try {
                  var ctx = canvas.getContext('2d', { willReadFrequently: true });
                  if (!ctx) return null;
                  var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                  var qrResult = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'attemptBoth' });
                  if (!qrResult || !qrResult.data) return null;
                  return extractBlFromText(String(qrResult.data));
               } catch (e) {
                  return null;
               }
            }

            async function detectBlFromCodes(source) {
               var bl = await detectBlWithBarcodeDetector(source);
               if (bl) return bl;
               return await detectBlWithJsQr(source);
            }

            async function getOcrWorker() {
               if (ocrWorker) return ocrWorker;
               await loadTesseractJs();
               ocrWorker = await Tesseract.createWorker('fra+eng', 1);
               return ocrWorker;
            }

            function terminateOcrWorker() {
               if (ocrWorker) {
                  try { ocrWorker.terminate(); } catch(e) {}
                  ocrWorker = null;
               }
            }

            function delay(ms) { return new Promise(function(r) { setTimeout(r, ms); }); }

            async function autoScanLoop() {
               autoScanActive = true;
               var hint = document.getElementById('gestionBlScanHint');
               var attempts = 0;
               var maxAttempts = 20;
               var codeCandidate = '';
               var codeCandidateHits = 0;
               var ocrCandidate = '';
               var ocrCandidateHits = 0;

               // Pre-charger le worker OCR pendant que l utilisateur positionne le BL
               if (hint) hint.textContent = 'Chargement OCR...';
               try {
                  var worker = await getOcrWorker();
               } catch (e) {
                  showError('Erreur OCR: ' + e.message);
                  return;
               }
               if (!autoScanActive) return;
               if (hint) hint.textContent = 'Recherche automatique du numero BL...';

               // Attendre 1s que la camera se stabilise
               await delay(1000);

               while (autoScanActive && cameraStream && attempts < maxAttempts) {
                  attempts++;
                  if (hint) hint.textContent = 'Scan en cours... (' + attempts + '/' + maxAttempts + ')';

                  var canvas = captureFrameFromVideo();
                  if (!canvas) { await delay(1000); continue; }

                  try {
                     var codeBl = await detectBlFromCodes(canvas);
                     if (codeBl) {
                        if (codeBl === codeCandidate) {
                           codeCandidateHits++;
                        } else {
                           codeCandidate = codeBl;
                           codeCandidateHits = 1;
                        }
                        if (codeCandidateHits < 2) {
                           if (hint) hint.textContent = 'Code detecte, confirmation...';
                           if (autoScanActive) await delay(250);
                           continue;
                        }
                        autoScanActive = false;
                        if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
                        stopCamera();
                        fillInputWithBl(codeBl);
                        return;
                     } else {
                        codeCandidate = '';
                        codeCandidateHits = 0;
                     }
                  } catch (e) {}

                  try {
                     var result = await worker.recognize(canvas);
                     var text = (result && result.data && result.data.text) || '';
                     var blNum = extractBlFromText(text);

                     if (blNum) {
                        if (blNum === ocrCandidate) {
                           ocrCandidateHits++;
                        } else {
                           ocrCandidate = blNum;
                           ocrCandidateHits = 1;
                        }
                        if (ocrCandidateHits < 2) {
                           if (hint) hint.textContent = 'OCR detecte, confirmation...';
                           if (autoScanActive) await delay(250);
                           continue;
                        }
                        autoScanActive = false;
                        if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
                        stopCamera();
                        fillInputWithBl(blNum);
                        return;
                     } else {
                        ocrCandidate = '';
                        ocrCandidateHits = 0;
                     }
                  } catch (e) {
                     // Continuer
                  }

                  if (autoScanActive) await delay(800);
               }

               if (autoScanActive && attempts >= maxAttempts) {
                  autoScanActive = false;
                  if (hint) hint.textContent = 'BL non detecte.';
                  showError('Numero BL non detecte apres ' + maxAttempts + ' tentatives. Essayez de rapprocher le document.');
               }
            }

            async function runOcr(imageSource) {
               clearError();
               showOcrProgress(true);
               setOcrBar(8);
               setOcrStatus('Lecture QR code...');
               try {
                  var codeBl = await detectBlFromCodes(imageSource);
                  if (codeBl) {
                     setOcrBar(100);
                     showOcrProgress(false);
                     fillInputWithBl(codeBl);
                     if (navigator.vibrate) navigator.vibrate(100);
                     return codeBl;
                  }
               } catch (e) {}
               setOcrBar(10);
               setOcrStatus('Chargement du moteur OCR...');
               try {
                  await loadTesseractJs();
                  setOcrBar(25);
                  setOcrStatus('Initialisation...');
                  var worker = await Tesseract.createWorker('fra+eng', 1, {
                     logger: function(info) {
                        if (info.status === 'recognizing text' && info.progress) {
                           setOcrBar(30 + Math.round(info.progress * 65));
                           setOcrStatus('Lecture du texte...');
                        }
                     }
                  });
                  setOcrBar(30);
                  var result = await worker.recognize(imageSource);
                  var text = (result && result.data && result.data.text) || '';
                  var blNum = extractBlFromText(text);
                  if (blNum) {
                     setOcrBar(92);
                     setOcrStatus('Verification OCR...');
                     var confirm = await worker.recognize(imageSource);
                     var confirmText = (confirm && confirm.data && confirm.data.text) || '';
                     var confirmBl = extractBlFromText(confirmText);
                     if (confirmBl !== blNum) {
                        blNum = null;
                     }
                  }
                  setOcrBar(98);
                  await worker.terminate();
                  setOcrBar(100);
                  showOcrProgress(false);
                  if (blNum) {
                     fillInputWithBl(blNum);
                     if (navigator.vibrate) navigator.vibrate(100);
                     return blNum;
                  } else {
                     var preview = text.substring(0, 150).trim();
                     showError('Aucun numero BL detecte.' + (preview ? ' Texte lu: "' + preview + '"' : ''));
                     return null;
                  }
               } catch (e) {
                  showOcrProgress(false);
                  showError('Erreur OCR : ' + e.message);
                  return null;
               }
            }

            function attachScanBtn() {
               var scanBtn = document.getElementById('gestionBlScanBtn');
               var fileInput = document.getElementById('gestionBlFileInput');
               var captureBtn = document.getElementById('gestionBlCaptureBtn');
               var cancelBtn = document.getElementById('gestionBlCancelScan');
               if (!scanBtn || scanBtn.dataset.bound === '1') return;
               scanBtn.dataset.bound = '1';
               if (isMobileDevice() && fileInput) {
                  fileInput.setAttribute('capture', 'environment');
               }

               // Sur mobile : cacher le bouton Capturer (scan auto)
               if (isMobileDevice() && captureBtn) {
                  captureBtn.style.display = 'none';
               }

               scanBtn.addEventListener('click', async function() {
                  clearError();
                  showOcrProgress(false);
                  if (isMobileDevice()) {
                     var ok = await startCamera();
                     if (ok) {
                        autoScanLoop();
                     } else {
                        if (fileInput) fileInput.click();
                     }
                  } else {
                     if (fileInput) fileInput.click();
                  }
               });

               if (fileInput) {
                  fileInput.addEventListener('change', async function() {
                     if (!fileInput.files || !fileInput.files[0]) return;
                     var file = fileInput.files[0];
                     stopCamera();
                     var isPdf = file.type === 'application/pdf'
                        || (file.name && file.name.toLowerCase().endsWith('.pdf'));
                     if (isPdf) {
                        try {
                           showOcrProgress(true);
                           setOcrBar(5);
                           setOcrStatus('Lecture du PDF...');
                           var canvas = await renderPdfToCanvas(file);
                           showOcrProgress(false);
                           await runOcr(canvas);
                        } catch (e) {
                           showOcrProgress(false);
                           showError('Erreur lecture PDF : ' + e.message);
                        }
                        fileInput.value = '';
                     } else {
                        var img = new Image();
                        img.onload = async function() {
                           await runOcr(img);
                           URL.revokeObjectURL(img.src);
                           fileInput.value = '';
                        };
                        img.onerror = function() {
                           showError('Impossible de lire le fichier image.');
                           fileInput.value = '';
                        };
                        img.src = URL.createObjectURL(file);
                     }
                  });
               }

               if (captureBtn) {
                  captureBtn.addEventListener('click', async function() {
                     var canvas = captureFrameFromVideo();
                     if (!canvas) { showError('Capture impossible.'); return; }
                     stopCamera();
                     await runOcr(canvas);
                  });
               }

               if (cancelBtn) {
                  cancelBtn.addEventListener('click', function() {
                     stopCamera();
                  });
               }

               var mainModal = document.getElementById(modalId);
               if (mainModal) {
                  mainModal.addEventListener('hidden.bs.modal', function() {
                     stopCamera();
                     showOcrProgress(false);
                  });
               }
            }

            if (document.readyState === 'loading') {
               document.addEventListener('DOMContentLoaded', function(){
                  attachSignatureLink();
                  attachInput();
                  attachScanBtn();
                  openFromUrl();
               });
            } else {
               attachSignatureLink();
               attachInput();
               attachScanBtn();
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
