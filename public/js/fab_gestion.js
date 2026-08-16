/**
 * Boutons flottants du plugin Gestion — relais du plugin RP.
 *
 * Ce fichier n'est chargé que lorsque le plugin RP est INACTIF : quand les deux
 * plugins tournent, c'est RP qui fournit les boutons (avec les rapports en
 * plus). Le comportement, l'habillage et les préférences sont identiques ;
 * seules les fonctionnalités propres à RP (rapports, QR code de préparation)
 * sont absentes.
 *
 * - Accueil : bouton de scan / recherche (BL, ticket, QR code).
 * - Ticket  : bouton de signature du bon de livraison associé.
 *
 * Habillage : composants natifs GLPI/Tabler (modal, input-group, list-group,
 * badge, alert, btn). Sur téléphone, les panneaux sont ancrés en bas de l'écran
 * pour rester accessibles à une main.
 */
(function () {
   'use strict';

   function readPrefs() {
      var meta = document.querySelector('meta[name="gestion:fab"]');
      if (!meta || !meta.content) {
         return {};
      }
      try {
         return JSON.parse(meta.content) || {};
      } catch (e) {
         return {};
      }
   }

   var prefs = readPrefs();
   var root = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc ? CFG_GLPI.root_doc : '')
      .replace(/\/$/, '');
   var scanUrl = root + '/plugins/gestion/ajax/scan.php';
   var actionsUrl = root + '/plugins/gestion/ajax/ticket_actions.php';

   function isMobile() {
      return window.matchMedia('(max-width: 768px)').matches;
   }

   function shouldShow(mode) {
      var value = parseInt(mode, 10);
      if (value === 2) {
         return true;
      }
      if (value === 1) {
         return isMobile();
      }
      return false;
   }

   function esc(text) {
      return String(text === undefined || text === null ? '' : text)
         .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
         .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
   }

   /**
    * Bouton flottant déplaçable, position mémorisée par navigateur.
    */
   function createFab(options) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-primary btn-icon rounded-circle gestion-fab';
      btn.title = options.title || '';
      btn.setAttribute('aria-label', options.title || '');
      btn.innerHTML = '<i class="' + (options.icon || 'ti ti-plus') + '"></i>';
      document.body.appendChild(btn);

      var storageKey = 'gestion_fab_pos_' + (options.id || 'default');
      var dragging = false;
      var moved = false;
      var startX = 0, startY = 0, originLeft = 0, originTop = 0;

      function clamp(left, top) {
         var maxLeft = window.innerWidth - btn.offsetWidth - 4;
         var maxTop = window.innerHeight - btn.offsetHeight - 4;
         return {
            left: Math.max(4, Math.min(left, maxLeft)),
            top: Math.max(4, Math.min(top, maxTop))
         };
      }

      function applyPosition(left, top) {
         var pos = clamp(left, top);
         btn.style.left = pos.left + 'px';
         btn.style.top = pos.top + 'px';
         btn.style.right = 'auto';
         btn.style.bottom = 'auto';
      }

      try {
         var saved = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
         if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
            applyPosition(saved.left, saved.top);
         }
      } catch (e) { /* position par défaut (CSS) */ }

      btn.addEventListener('pointerdown', function (e) {
         dragging = true;
         moved = false;
         startX = e.clientX;
         startY = e.clientY;
         var rect = btn.getBoundingClientRect();
         originLeft = rect.left;
         originTop = rect.top;
         btn.setPointerCapture(e.pointerId);
      });

      btn.addEventListener('pointermove', function (e) {
         if (!dragging) {
            return;
         }
         var dx = e.clientX - startX;
         var dy = e.clientY - startY;
         if (!moved && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) {
            moved = true;
         }
         if (moved) {
            e.preventDefault();
            applyPosition(originLeft + dx, originTop + dy);
         }
      });

      function endDrag(e) {
         if (!dragging) {
            return;
         }
         dragging = false;
         try {
            btn.releasePointerCapture(e.pointerId);
         } catch (err) { /* ignore */ }
         if (moved) {
            var rect = btn.getBoundingClientRect();
            try {
               window.localStorage.setItem(storageKey, JSON.stringify({ left: rect.left, top: rect.top }));
            } catch (err) { /* stockage indisponible */ }
         }
      }

      btn.addEventListener('pointerup', endDrag);
      btn.addEventListener('pointercancel', endDrag);

      btn.addEventListener('click', function (e) {
         if (moved) {
            e.preventDefault();
            e.stopPropagation();
            moved = false;
            return;
         }
         if (typeof options.onClick === 'function') {
            options.onClick(e);
         }
      });

      window.addEventListener('resize', function () {
         var rect = btn.getBoundingClientRect();
         if (btn.style.left) {
            applyPosition(rect.left, rect.top);
         }
      });

      return btn;
   }

   // =========================================================================
   //  Bouton d'accueil : scan / recherche
   // =========================================================================
   function initHomeFab() {
      if (!shouldShow(prefs.fab_home)) {
         return;
      }

      var path = (window.location.pathname || '').replace(/\/+$/, '');
      var isHome = /\/front\/central\.php$/i.test(path)
                || /\/central$/i.test(path)
                || /\/helpdesk$/i.test(path)
                || /\/front\/helpdesk\.public\.php$/i.test(path)
                || /\/index\.php$/i.test(path)
                || path === ''
                || path === root;
      if (!isHome) {
         return;
      }

      var els = {};
      var modal = null;
      var searchTimer = null;
      var cameraStream = null;
      var scanLoopActive = false;
      var detector = null;
      var ocrBusy = false;
      var capsLoaded = false;

      var wrapper = document.createElement('div');
      wrapper.innerHTML = [
         '<div class="modal fade gestion-sheet" id="gestionScanModal" tabindex="-1" aria-hidden="true">',
         '  <div class="modal-dialog modal-lg">',
         '    <div class="modal-content">',
         '      <div class="modal-header">',
         '        <h5 class="modal-title"><i class="ti ti-scan me-2"></i>Scanner / Rechercher</h5>',
         '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>',
         '      </div>',
         '      <div class="modal-body">',
         '        <div class="input-group mb-2">',
         '          <input type="text" class="form-control" id="gestionScanInput" inputmode="search"',
         '                 autocomplete="off" autocapitalize="characters" spellcheck="false"',
         '                 placeholder="N° de BL, n° de ticket ou mot-clé">',
         '          <button type="button" class="btn btn-outline-secondary" id="gestionScanCamBtn" title="Scanner avec la caméra">',
         '            <i class="ti ti-camera"></i>',
         '          </button>',
         '        </div>',
         '        <div class="alert alert-danger d-none" id="gestionScanMsg" role="alert"></div>',
         // Appareil photo natif : utilisé sur les navigateurs sans détection QR
         // en direct (Safari/iOS). Aucune autorisation caméra n\'est demandée au
         // site, c\'est l\'appareil photo du téléphone qui s\'ouvre.
         '        <input type="file" accept="image/*" capture="environment" id="gestionScanFile" class="d-none">',
         '        <div class="d-none mb-3" id="gestionScanCamera">',
         '          <div class="gestion-scan-video-wrap">',
         '            <video id="gestionScanVideo" autoplay playsinline muted></video>',
         '            <div class="gestion-scan-frame">',
         '              <span class="tl"></span><span class="tr"></span>',
         '              <span class="bl"></span><span class="br"></span>',
         '            </div>',
         '          </div>',
         '          <div class="text-center text-muted small mt-2" id="gestionScanHint">',
         '            Visez le QR code, ou photographiez le numéro',
         '          </div>',
         '          <div class="text-center mt-2">',
         '            <button type="button" class="btn btn-primary btn-sm me-2" id="gestionScanShoot">',
         '              <i class="ti ti-camera me-1"></i>Lire le numéro',
         '            </button>',
         '            <button type="button" class="btn btn-outline-secondary btn-sm" id="gestionScanStop">Annuler</button>',
         '          </div>',
         '        </div>',
         '        <div class="list-group" id="gestionScanResults"></div>',
         '      </div>',
         '    </div>',
         '  </div>',
         '</div>'
      ].join('');
      var modalEl = wrapper.firstElementChild;
      document.body.appendChild(modalEl);

      els = {
         modalEl: modalEl,
         input:   modalEl.querySelector('#gestionScanInput'),
         cambtn:  modalEl.querySelector('#gestionScanCamBtn'),
         msg:     modalEl.querySelector('#gestionScanMsg'),
         file:    modalEl.querySelector('#gestionScanFile'),
         camera:  modalEl.querySelector('#gestionScanCamera'),
         video:   modalEl.querySelector('#gestionScanVideo'),
         hint:    modalEl.querySelector('#gestionScanHint'),
         shoot:   modalEl.querySelector('#gestionScanShoot'),
         stop:    modalEl.querySelector('#gestionScanStop'),
         results: modalEl.querySelector('#gestionScanResults')
      };

      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
         modal = new bootstrap.Modal(modalEl, {});
      }

      createFab({
         id: 'home',
         icon: 'ti ti-scan',
         title: 'Scanner / Rechercher un BL ou un ticket',
         onClick: function () { if (modal) { modal.show(); } }
      });

      modalEl.addEventListener('shown.bs.modal', function () {
         loadCaps();
         // Pas de focus automatique sur téléphone : le clavier s'ouvrirait et
         // le premier appui sur un bouton ne servirait qu'à le refermer.
         if (!isMobile()) {
            els.input.focus();
         }
      });
      modalEl.addEventListener('hidden.bs.modal', stopCamera);

      els.input.addEventListener('input', function () {
         clearTimeout(searchTimer);
         var value = els.input.value;
         searchTimer = setTimeout(function () { search(value); }, 350);
      });
      els.input.addEventListener('keydown', function (e) {
         if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            search(els.input.value);
         }
      });
      // Scan en direct quand le navigateur sait lire les QR codes (Chrome
      // Android) ; sinon appareil photo natif (Safari/iOS) : plus simple, plus
      // net, et sans autorisation caméra à redonner à chaque fois.
      els.cambtn.addEventListener('click', function () {
         if (typeof BarcodeDetector !== 'undefined') {
            startCamera();
         } else {
            els.file.click();
         }
      });
      els.file.addEventListener('change', function () {
         var picked = els.file.files && els.file.files[0];
         if (picked) {
            readImageFile(picked);
         }
         els.file.value = '';
      });
      els.stop.addEventListener('click', stopCamera);
      els.shoot.addEventListener('click', shootAndRead);

      function message(text) {
         if (!text) {
            els.msg.classList.add('d-none');
            els.msg.textContent = '';
            return;
         }
         els.msg.className = 'alert alert-danger';
         els.msg.textContent = text;
      }

      function csrf() {
         var meta = document.querySelector('meta[property="glpi:csrf_token"]');
         if (meta && meta.content) {
            return meta.content;
         }
         var input = document.querySelector('input[name="_glpi_csrf_token"]');
         return input ? input.value : '';
      }

      function loadCaps() {
         if (capsLoaded) {
            return;
         }
         capsLoaded = true;
         fetch(scanUrl + '?caps=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
               var caps = (data && data.caps) ? data.caps : null;
               if (!caps) {
                  return;
               }
               if (caps.bl && caps.ticket) {
                  els.input.placeholder = 'N° de BL, n° de ticket ou mot-clé';
               } else if (caps.bl) {
                  els.input.placeholder = 'N° de BL (ex : BL123456)';
               } else if (caps.ticket) {
                  els.input.placeholder = 'N° de ticket ou mot-clé';
               } else {
                  message("Vous n'avez accès ni aux tickets ni aux bons de livraison.");
               }
            })
            .catch(function () { /* libellés par défaut */ });
      }

      function search(value) {
         var q = (value || '').trim();
         if (q.length < 2) {
            els.results.innerHTML = '';
            message('');
            return;
         }

         els.results.innerHTML = '<div class="list-group-item text-center text-muted">'
            + '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Recherche…</div>';

         var body = new URLSearchParams();
         body.append('q', q);
         body.append('_glpi_csrf_token', csrf());

         fetch(scanUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
         })
         .then(function (r) { return r.json(); })
         .then(function (data) {
            if (data && data.redirect) {
               window.location.href = data.redirect;
               return;
            }
            if (!data || data.ok === false) {
               els.results.innerHTML = '';
               message((data && data.error) || 'Recherche impossible.');
               return;
            }
            message('');
            renderResults(data.results || []);
         })
         .catch(function () {
            els.results.innerHTML = '';
            message('Erreur de communication avec GLPI.');
         });
      }

      function renderResults(items) {
         els.results.innerHTML = '';
         if (!items.length) {
            els.results.innerHTML = '<div class="list-group-item text-muted">Aucun résultat.</div>';
            return;
         }
         items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'list-group-item';

            var badge = '';
            if (item.badge && item.badge.label) {
               badge = '<span class="badge ms-2 '
                  + (item.badge.style === 'warn' ? 'bg-warning text-dark' : 'bg-success')
                  + '">' + esc(item.badge.label) + '</span>';
            }

            var actions = '';
            (item.actions || []).forEach(function (action) {
               actions += '<a class="btn btn-sm '
                  + (action.primary ? 'btn-primary' : 'btn-outline-secondary')
                  + ' me-2 mt-2" href="' + esc(action.url) + '">'
                  + '<i class="' + esc(action.icon || 'ti ti-arrow-right') + ' me-1"></i>'
                  + esc(action.label) + '</a>';
            });

            row.innerHTML =
               '<div class="d-flex align-items-center flex-wrap">'
               + '<span class="fw-bold">' + esc(item.title || '') + '</span>' + badge
               + '</div>'
               + (item.subtitle ? '<div class="text-muted small">' + esc(item.subtitle) + '</div>' : '')
               + '<div class="d-flex flex-wrap">' + actions + '</div>';

            els.results.appendChild(row);
         });
      }

      function showBusy(text) {
         els.results.innerHTML = '<div class="list-group-item text-center text-muted">'
            + '<span class="spinner-border spinner-border-sm me-2" role="status"></span>'
            + esc(text) + '</div>';
      }

      /**
       * Photo prise avec l'appareil natif : QR code d'abord, puis lecture du
       * numéro (OCR) si aucun QR n'est trouvé.
       */
      function readImageFile(file) {
         message('');
         showBusy('Lecture de la photo…');

         var url = URL.createObjectURL(file);
         var img = new Image();

         img.onload = function () {
            var maxDim = 1600;
            var ratio = Math.min(1, maxDim / Math.max(img.width, img.height));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(img.width * ratio));
            canvas.height = Math.max(1, Math.round(img.height * ratio));
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            decodeCanvas(canvas);
         };

         img.onerror = function () {
            URL.revokeObjectURL(url);
            els.results.innerHTML = '';
            message('Photo illisible, réessayez.');
         };

         img.src = url;
      }

      function decodeCanvas(canvas) {
         decodeQrFromCanvas(canvas)
            .then(function (value) {
               if (value) {
                  els.input.value = value.length > 60 ? '' : value;
                  search(value);
                  return null;
               }
               showBusy('Lecture du numéro…');
               return loadOcr()
                  .then(function () { return window.Tesseract.recognize(canvas, 'eng'); })
                  .then(function (res) {
                     var text = (res && res.data && res.data.text) ? res.data.text : '';
                     var reference = extractReference(text);
                     if (reference !== '') {
                        els.input.value = reference;
                        search(reference);
                     } else {
                        els.results.innerHTML = '';
                        message('Numéro non reconnu sur la photo, saisissez-le.');
                     }
                     return null;
                  });
            })
            .catch(function () {
               els.results.innerHTML = '';
               message('Lecture automatique indisponible, saisissez le numéro.');
            });
      }

      function decodeQrFromCanvas(canvas) {
         if (typeof BarcodeDetector !== 'undefined') {
            try {
               var nativeDetector = new BarcodeDetector({ formats: ['qr_code'] });
               return nativeDetector.detect(canvas)
                  .then(function (codes) {
                     return (codes && codes.length && codes[0].rawValue) ? codes[0].rawValue : '';
                  })
                  .catch(function () { return ''; });
            } catch (e) { /* on tente la bibliothèque ci-dessous */ }
         }

         return loadJsQr()
            .then(function () {
               if (!window.jsQR) {
                  return '';
               }
               var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
               var found = window.jsQR(data.data, data.width, data.height, { inversionAttempts: 'attemptBoth' });
               return (found && found.data) ? found.data : '';
            })
            .catch(function () { return ''; });
      }

      function loadJsQr() {
         if (window.jsQR) {
            return Promise.resolve();
         }
         return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('jsqr')); };
            document.head.appendChild(s);
         });
      }

      function startCamera() {
         if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            message("La caméra n'est pas disponible sur cet appareil.");
            return;
         }
         message('');
         els.camera.classList.remove('d-none');
         // Retour immédiat : l'ouverture de la caméra (et l'autorisation du
         // navigateur) peut prendre un instant
         els.hint.textContent = 'Ouverture de la caméra…';

         navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false
         })
         .then(function (stream) {
            cameraStream = stream;
            els.video.srcObject = stream;
            scanLoopActive = true;
            els.hint.textContent = 'Visez le QR code, ou photographiez le numéro';
            startQrLoop();
         })
         .catch(function () {
            els.camera.classList.add('d-none');
            message("Accès à la caméra refusé.");
         });
      }

      function stopCamera() {
         scanLoopActive = false;
         if (cameraStream) {
            cameraStream.getTracks().forEach(function (t) { t.stop(); });
            cameraStream = null;
         }
         els.video.srcObject = null;
         els.camera.classList.add('d-none');
         els.hint.textContent = 'Visez le QR code, ou photographiez le numéro';
      }

      function startQrLoop() {
         if (typeof BarcodeDetector === 'undefined') {
            els.hint.textContent = 'Photographiez le numéro puis « Lire le numéro »';
            return;
         }
         try {
            detector = detector || new BarcodeDetector({ formats: ['qr_code'] });
         } catch (e) {
            els.hint.textContent = 'Photographiez le numéro puis « Lire le numéro »';
            return;
         }

         var tick = function () {
            if (!scanLoopActive || !els.video.videoWidth) {
               if (scanLoopActive) {
                  setTimeout(tick, 300);
               }
               return;
            }
            detector.detect(els.video)
               .then(function (codes) {
                  if (codes && codes.length && codes[0].rawValue) {
                     var value = codes[0].rawValue;
                     stopCamera();
                     els.input.value = value.length > 60 ? '' : value;
                     search(value);
                     return;
                  }
                  if (scanLoopActive) {
                     setTimeout(tick, 300);
                  }
               })
               .catch(function () {
                  if (scanLoopActive) {
                     setTimeout(tick, 500);
                  }
               });
         };
         setTimeout(tick, 400);
      }

      function extractReference(text) {
         var flat = (text || '').replace(/\s+/g, ' ');
         var bl = flat.match(/\bB\s?[LC]\s?0*(\d{3,8})\b/i);
         if (bl) {
            return 'BL' + bl[1];
         }
         var ticket = flat.match(/\bTICKET\s*(?:N\s*[°ºo]?)?\s*[:#-]?\s*0*(\d{2,10})\b/i);
         if (ticket) {
            return ticket[1];
         }
         var hashed = flat.match(/#\s*0*(\d{4,10})\b/);
         if (hashed) {
            return hashed[1];
         }
         return '';
      }

      function shootAndRead() {
         if (ocrBusy || !els.video.videoWidth) {
            return;
         }
         ocrBusy = true;
         els.hint.textContent = 'Lecture en cours…';

         var canvas = document.createElement('canvas');
         canvas.width = els.video.videoWidth;
         canvas.height = els.video.videoHeight;
         canvas.getContext('2d').drawImage(els.video, 0, 0, canvas.width, canvas.height);

         loadOcr()
            .then(function () { return window.Tesseract.recognize(canvas, 'eng'); })
            .then(function (res) {
               ocrBusy = false;
               var text = (res && res.data && res.data.text) ? res.data.text : '';
               var reference = extractReference(text);
               if (reference !== '') {
                  stopCamera();
                  els.input.value = reference;
                  search(reference);
               } else {
                  els.hint.textContent = 'Numéro non reconnu, réessayez ou saisissez-le';
               }
            })
            .catch(function () {
               ocrBusy = false;
               stopCamera();
               message('Lecture automatique indisponible, saisissez le numéro.');
            });
      }

      function loadOcr() {
         if (window.Tesseract) {
            return Promise.resolve();
         }
         return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('ocr')); };
            document.head.appendChild(s);
         });
      }
   }

   // =========================================================================
   //  Bouton de signature sur les tickets
   // =========================================================================
   function initTicketFab() {
      if (!shouldShow(prefs.fab_ticket)) {
         return;
      }

      var ticketId = 0;
      var els = {};
      var modal = null;

      function findTicketId() {
         var main = document.querySelector('.ITILContent[data-itemtype="Ticket"][data-items-id]');
         if (main) {
            var fromDom = parseInt(main.getAttribute('data-items-id'), 10);
            if (fromDom > 0) {
               return fromDom;
            }
         }
         var container = document.querySelector('#itil-object-container, #itil-footer, #new-itilobject-form');
         if (container) {
            var input = document.querySelector('input[name="items_id"]');
            if (input && parseInt(input.value, 10) > 0) {
               return parseInt(input.value, 10);
            }
         }
         if (/\/front\/ticket\.form\.php$/i.test(window.location.pathname || '')) {
            var match = (window.location.search || '').match(/[?&]id=(\d+)/);
            if (match) {
               return parseInt(match[1], 10);
            }
         }
         return 0;
      }

      function waitForTicket(callback) {
         var found = findTicketId();
         if (found > 0) {
            callback(found);
            return;
         }
         var attempts = 0;
         var timer = setInterval(function () {
            attempts++;
            var id = findTicketId();
            if (id > 0) {
               clearInterval(timer);
               callback(id);
            } else if (attempts >= 40) {
               clearInterval(timer);
            }
         }, 200);
      }

      waitForTicket(function (id) {
         ticketId = id;

         var wrapper = document.createElement('div');
         wrapper.innerHTML = [
            '<div class="modal fade gestion-sheet" id="gestionTicketFabModal" tabindex="-1" aria-hidden="true">',
            '  <div class="modal-dialog">',
            '    <div class="modal-content">',
            '      <div class="modal-header">',
            '        <h5 class="modal-title"><i class="ti ti-signature me-2"></i>Signatures</h5>',
            '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>',
            '      </div>',
            '      <div class="modal-body"><div id="gestionTicketFabBody"></div></div>',
            '    </div>',
            '  </div>',
            '</div>'
         ].join('');
         var modalEl = wrapper.firstElementChild;
         document.body.appendChild(modalEl);

         els.modalEl = modalEl;
         els.body = modalEl.querySelector('#gestionTicketFabBody');

         if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            modal = new bootstrap.Modal(modalEl, {});
         }

         createFab({
            id: 'ticket',
            icon: 'ti ti-signature',
            title: 'Signature du bon de livraison',
            onClick: openActions
         });
      });

      function openActions() {
         if (!modal) {
            return;
         }
         els.body.innerHTML = '<div class="text-center text-muted py-3">'
            + '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Chargement…</div>';
         modal.show();

         fetch(actionsUrl + '?ticket_id=' + ticketId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(renderActions)
            .catch(function () {
               els.body.innerHTML = '<div class="alert alert-danger mb-0">Erreur de communication avec GLPI.</div>';
            });
      }

      function renderActions(data) {
         if (!data || !data.ok) {
            els.body.innerHTML = '<div class="alert alert-danger mb-0">Actions indisponibles pour ce ticket.</div>';
            return;
         }

         var html = '';
         if (data.notice) {
            html += '<div class="alert alert-info">' + esc(data.notice) + '</div>';
         }

         if (!data.actions || !data.actions.length) {
            html += '<div class="alert alert-warning mb-0">Aucun bon de livraison à signer sur ce ticket.</div>';
            els.body.innerHTML = html;
            return;
         }

         html += '<div class="list-group">';
         data.actions.forEach(function (action, index) {
            html += '<button type="button" class="list-group-item list-group-item-action d-flex align-items-center"'
               + ' data-gestion-action="' + index + '">'
               + '<i class="' + esc(action.icon || 'ti ti-signature') + ' me-3 fs-3"></i>'
               + '<span class="flex-fill text-start" style="min-width:0">'
               + '<span class="d-block fw-bold">' + esc(action.label) + '</span>'
               + (action.hint
                  ? '<span class="d-block text-muted small text-truncate" title="' + esc(action.hint) + '">'
                     + esc(action.hint) + '</span>'
                  : '')
               + '</span>'
               + (action.primary ? '<span class="badge bg-primary ms-2 flex-shrink-0">Conseillé</span>' : '')
               + '</button>';
         });
         html += '</div>';

         els.body.innerHTML = html;

         els.body.querySelectorAll('[data-gestion-action]').forEach(function (button) {
            button.addEventListener('click', function () {
               var action = data.actions[parseInt(button.getAttribute('data-gestion-action'), 10)];
               runAction(action, data);
            });
         });
      }

      /**
       * Ouvre le modal de signature existant du plugin.
       */
      function runAction(action, data) {
         if (!action) {
            return;
         }
         if (modal) {
            modal.hide();
         }
         setTimeout(function () {
            if (typeof gestion_loadCriForm === 'function') {
               gestion_loadCriForm('showCriForm', String(action.bl_id), {
                  job: ticketId,
                  root_doc: data.gestion_webdir,
                  root_modal: 'ticket-form'
               });
            } else {
               window.location.href = data.gestion_webdir + '/front/survey.form.php?id=' + action.bl_id;
            }
         }, 250);
      }
   }

   function boot() {
      initHomeFab();
      initTicketFab();
   }

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
   } else {
      boot();
   }
})();
