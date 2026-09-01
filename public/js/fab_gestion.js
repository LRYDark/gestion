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
   var claimUrl = root + '/plugins/gestion/ajax/claim_bl.php';

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

   function csrf() {
      var meta = document.querySelector('meta[property="glpi:csrf_token"]');
      if (meta && meta.content) {
         return meta.content;
      }
      var input = document.querySelector('input[name="_glpi_csrf_token"]');
      return input ? input.value : '';
   }

   /**
    * Le script capable d'ouvrir ce formulaire est-il chargé ?
    * Sans ce test, on afficherait un bouton inerte quand le plugin concerné
    * est absent ou désactivé.
    */
   function signHandlerAvailable(handler) {
      if (handler === 'rp') {
         return typeof rp_loadCriForm === 'function';
      }
      return typeof gestion_loadCriForm === 'function';
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

      /*
       * Onglets retenus par l'utilisateur (Préférences > Boutons flottants).
       * 1 = « BL / Ticket » seul, 2 = « Par mot-clé » seul, 3 = les deux.
       */
      var TABS_RESOLVE = 1;
      var TABS_SEARCH  = 2;
      var homeTabs     = parseInt(prefs.fab_home_tabs, 10);
      if (homeTabs !== TABS_RESOLVE && homeTabs !== TABS_SEARCH) {
         homeTabs = 3;
      }

      /*
       * Nom du premier onglet.
       *
       * Sans accès aux bons, il ne résout plus que des tickets : l'appeler
       * « BL / Ticket » promettrait une recherche qui ne renverra jamais rien.
       * Le serveur tranche (balise meta), et l'écran des préférences nomme
       * l'onglet de la même façon.
       */
      var hasBl        = !!prefs.fab_home_bl;
      var resolveLabel = hasBl ? 'BL / Ticket' : 'Ticket';
      var fabTitle     = hasBl
         ? 'Scanner / Rechercher un BL ou un ticket'
         : 'Scanner / Rechercher un ticket';

      /**
       * Retire l'onglet que l'utilisateur n'a pas retenu.
       *
       * Un seul onglet restant, la barre d'onglets n'a plus rien à choisir : on
       * la retire plutôt que d'afficher un onglet unique, et le modal comme le
       * bouton prennent le nom de ce qu'ils font désormais — appeler
       * « Scanner » un bouton qui ne fait plus que chercher serait un mensonge
       * d'interface.
       *
       * Réglage d'affichage, jamais de droit : ce que chaque onglet peut
       * atteindre reste vérifié par ajax/scan.php. Forcer la valeur n'ouvre
       * donc rien.
       */
      function applyTabsPreference(modalEl, fab) {
         if (homeTabs !== TABS_RESOLVE && homeTabs !== TABS_SEARCH) {
            return;
         }

         var keepScan = (homeTabs === TABS_RESOLVE);
         var nav      = modalEl.querySelector('.nav-tabs');
         var paneScan = modalEl.querySelector('#gestionScanPaneScan');
         var paneDeep = modalEl.querySelector('#gestionScanPaneDeep');
         var title    = modalEl.querySelector('.modal-title');

         if (nav) {
            nav.classList.add('d-none');
         }

         var shown  = keepScan ? paneScan : paneDeep;
         var hidden = keepScan ? paneDeep : paneScan;
         if (hidden) {
            hidden.classList.remove('show', 'active');
         }
         if (shown) {
            shown.classList.add('show', 'active');
         }

         if (title) {
            title.innerHTML = keepScan
               ? '<i class="ti ti-scan me-2"></i>' + resolveLabel
               : '<i class="ti ti-list-search me-2"></i>Rechercher par mot-clé';
         }

         if (fab) {
            var label = keepScan
               ? (hasBl ? 'Scanner un BL ou un ticket' : 'Scanner un ticket')
               : 'Rechercher par mot-clé';
            fab.title = label;
            fab.setAttribute('aria-label', label);
            var icon = fab.querySelector('i');
            if (icon) {
               icon.className = keepScan ? 'ti ti-scan' : 'ti ti-list-search';
            }
         }
      }

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
         // Deux questions distinctes, deux onglets : « quel est ce numéro ? »
         // (identification immédiate, la frappe cherche au fil des lettres) et
         // « où ai-je vu ce mot ? » (fouille du contenu, lancée à la demande).
         // Les mêler dans un seul champ obligerait à deviner l\'intention.
         '        <ul class="nav nav-tabs mb-3" role="tablist">',
         '          <li class="nav-item" role="presentation">',
         '            <button class="nav-link active" id="gestionScanTabBtn" data-bs-toggle="tab"',
         '                    data-bs-target="#gestionScanPaneScan" type="button" role="tab"',
         '                    aria-controls="gestionScanPaneScan" aria-selected="true">',
         '              <i class="ti ti-scan me-1"></i>' + resolveLabel,
         '            </button>',
         '          </li>',
         '          <li class="nav-item" role="presentation">',
         '            <button class="nav-link" id="gestionDeepTabBtn" data-bs-toggle="tab"',
         '                    data-bs-target="#gestionScanPaneDeep" type="button" role="tab"',
         '                    aria-controls="gestionScanPaneDeep" aria-selected="false">',
         '              <i class="ti ti-list-search me-1"></i>Par mot-clé',
         '            </button>',
         '          </li>',
         '        </ul>',
         '        <div class="tab-content">',
         '        <div class="tab-pane fade show active" id="gestionScanPaneScan" role="tabpanel" aria-labelledby="gestionScanTabBtn">',
         '        <div class="input-group mb-2">',
         '          <input type="text" class="form-control" id="gestionScanInput" inputmode="search"',
         '                 autocomplete="off" autocapitalize="characters" spellcheck="false"',
         // Valeur de départ tirée de la balise meta, affinée ensuite par
         // loadCaps() : sans elle, l'utilisateur sans accès aux bons verrait un
         // instant qu'on lui propose de chercher un BL.
         '                 placeholder="' + (hasBl ? 'N° de BL, n° de ticket ou mot-clé' : 'N° de ticket ou mot-clé') + '">',
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
         '        </div>',
         // ---- Onglet « Dans les tickets » : recherche par mot-clé ----
         '        <div class="tab-pane fade" id="gestionScanPaneDeep" role="tabpanel" aria-labelledby="gestionDeepTabBtn">',
         // Interrupteur de portée. Les deux recherches n'ont rien de comparable
         // en coût : fouiller le texte des tickets lit des centaines de milliers
         // de lignes, retrouver un client en lit quelques centaines. Les cumuler
         // ferait payer la première à qui ne demandait que la seconde.
         // Discret et de la taille de ce qu'il fait : ce choix accompagne la
         // recherche, il ne la précède pas en importance. Pleine largeur et en
         // couleur d'accent, il occupait le regard avant le champ lui-même.
         '          <div class="d-flex align-items-center flex-wrap gap-2 mb-2">',
         '            <span class="text-muted small">Chercher dans</span>',
         '            <div class="btn-group btn-group-sm" role="group" aria-label="Où chercher">',
         '              <input type="radio" class="btn-check" name="gestionDeepScope" id="gestionDeepScopeTicket" value="ticket" autocomplete="off" checked>',
         '              <label class="btn btn-outline-secondary" for="gestionDeepScopeTicket"><i class="ti ti-ticket me-1"></i>Tickets</label>',
         '              <input type="radio" class="btn-check" name="gestionDeepScope" id="gestionDeepScopeEntity" value="entity" autocomplete="off">',
         '              <label class="btn btn-outline-secondary" for="gestionDeepScopeEntity"><i class="ti ti-building me-1"></i>Entités</label>',
         '            </div>',
         '          </div>',
         '          <div class="input-group mb-2">',
         '            <input type="text" class="form-control" id="gestionDeepInput" inputmode="search"',
         '                   autocomplete="off" autocapitalize="off" spellcheck="false"',
         '                   placeholder="N° de série, mot-clé">',
         '            <button type="button" class="btn btn-primary" id="gestionDeepBtn">',
         '              <i class="ti ti-search me-1"></i>Rechercher',
         '            </button>',
         '          </div>',
         '          <div class="form-text mb-2" id="gestionDeepHint"></div>',
         '          <div class="alert alert-danger d-none" id="gestionDeepMsg" role="alert"></div>',
         '          <div class="list-group" id="gestionDeepResults"></div>',
         '        </div>',
         '        </div>',
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
         results: modalEl.querySelector('#gestionScanResults'),
         deepTab:     modalEl.querySelector('#gestionDeepTabBtn'),
         deepInput:   modalEl.querySelector('#gestionDeepInput'),
         deepBtn:     modalEl.querySelector('#gestionDeepBtn'),
         deepMsg:     modalEl.querySelector('#gestionDeepMsg'),
         deepHint:    modalEl.querySelector('#gestionDeepHint'),
         deepResults: modalEl.querySelector('#gestionDeepResults'),
         deepScopes:  modalEl.querySelectorAll('input[name="gestionDeepScope"]')
      };

      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
         modal = new bootstrap.Modal(modalEl, {});
      }

      /*
       * Clic sur « Signer » : on FERME d'abord la feuille de scan, puis on ouvre
       * le formulaire de signature. Imbriquer deux modals Bootstrap laisse un
       * voile résiduel qui masque toute la page une fois le second refermé.
       */
      els.results.addEventListener('click', function (event) {
         var button = event.target.closest('.gestion-scan-sign');
         if (!button) {
            return;
         }
         event.preventDefault();

         var action;
         try {
            action = JSON.parse(button.dataset.open || '{}');
         } catch (e) {
            return;
         }
         if (!action.modal || !signHandlerAvailable(action.handler)) {
            return;
         }

         var open = function () {
            if (action.handler === 'rp') {
               rp_loadCriForm('showCriForm', String(action.modal), action.params || {});
            } else {
               gestion_loadCriForm('showCriForm', String(action.modal), action.params || {});
            }
         };

         if (modal && els.modalEl) {
            els.modalEl.addEventListener('hidden.bs.modal', open, { once: true });
            modal.hide();
         } else {
            open();
         }
      });

      var homeFab = createFab({
         id: 'home',
         icon: 'ti ti-scan',
         title: fabTitle,
         onClick: function () { if (modal) { modal.show(); } }
      });

      // Préférence « Onglets proposés par ce bouton », appliquée avant toute
      // ouverture : le modal ne doit jamais s'afficher puis se réorganiser.
      applyTabsPreference(modalEl, homeFab);

      modalEl.addEventListener('shown.bs.modal', function () {
         loadCaps();
         // Pas de focus automatique sur téléphone : le clavier s'ouvrirait et
         // le premier appui sur un bouton ne servirait qu'à le refermer.
         if (!isMobile()) {
            var field = (homeTabs === TABS_SEARCH) ? els.deepInput : els.input;
            if (field) {
               field.focus();
            }
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

      /*
       * Onglet « Dans les tickets » : la recherche ne part QU'au bouton (ou à
       * Entrée). Elle fouille la description, les tâches et les suivis de tous
       * les tickets visibles : la déclencher à chaque lettre ferait courir au
       * serveur cinq requêtes lourdes pour un numéro de série de dix
       * caractères, dont une seule intéresse.
       */
      els.deepBtn.addEventListener('click', function () {
         deepSearch(els.deepInput.value);
      });
      els.deepInput.addEventListener('keydown', function (e) {
         if (e.key === 'Enter') {
            e.preventDefault();
            deepSearch(els.deepInput.value);
         }
      });
      /*
       * Changer de portée efface les résultats affichés : ils répondaient à
       * l'autre question, les laisser sous un interrupteur qui dit maintenant
       * le contraire tromperait sur ce qu'ils sont. La recherche ne repart pas
       * toute seule pour autant — c'est au bouton de la déclencher.
       */
      Array.prototype.forEach.call(els.deepScopes, function (radio) {
         radio.addEventListener('change', function () {
            els.deepResults.innerHTML = '';
            deepMessage('');
            applyScope();
         });
      });

      // La caméra appartient au premier onglet : en partir doit l'éteindre.
      els.deepTab.addEventListener('shown.bs.tab', function () {
         stopCamera();
         if (!isMobile()) {
            els.deepInput.focus();
         }
      });

      applyScope();

      function message(text) {
         if (!text) {
            els.msg.classList.add('d-none');
            els.msg.textContent = '';
            return;
         }
         els.msg.className = 'alert alert-danger';
         els.msg.textContent = text;
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
         // Conservé pour compatibilité, mais c'est l'en-tête ci-dessous que
         // GLPI 11 utilise réellement.
         body.append('_glpi_csrf_token', csrf());

         fetch(scanUrl, {
            method: 'POST',
            credentials: 'same-origin',
            /*
             * GLPI 11 contrôle le jeton CSRF dans un écouteur du noyau, AVANT
             * d'atteindre le script. Signalée AJAX, la requête voit son jeton
             * lu dans l'en-tête `X-Glpi-Csrf-Token` et CONSERVÉ, donc
             * réutilisable ; sinon il est lu dans le corps et CONSOMMÉ, et
             * toute recherche suivante échoue.
             */
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
               'X-Requested-With': 'XMLHttpRequest',
               'X-Glpi-Csrf-Token': csrf()
            },
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
            renderResults(els.results, data.results || []);
         })
         .catch(function () {
            els.results.innerHTML = '';
            message('Erreur de communication avec GLPI.');
         });
      }

      /**
       * Rend une liste de résultats dans le conteneur donné.
       *
       * Les deux onglets partagent ce rendu : le serveur leur renvoie la même
       * forme, et une ligne de résultat doit se présenter pareil quel que soit
       * le chemin qui l'a trouvée.
       */
      function renderResults(container, items) {
         container.innerHTML = '';
         if (!items.length) {
            container.innerHTML = '<div class="list-group-item text-muted">Aucun résultat.</div>';
            return;
         }
         items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'list-group-item';

            var badge = '';
            if (item.badge && item.badge.label) {
               // `muted` : un état constaté (statut du ticket, entité), qui
               // informe sans rien réclamer — le vert et l'orange sont réservés
               // à ce qui attend une action.
               var tone = 'bg-success';
               if (item.badge.style === 'warn') {
                  tone = 'bg-warning text-dark';
               } else if (item.badge.style === 'muted') {
                  tone = 'bg-secondary';
               }
               badge = '<span class="badge ms-2 ' + tone + '">' + esc(item.badge.label) + '</span>';
            }

            var actions = '';
            (item.actions || []).forEach(function (action) {
               var classes = 'btn btn-sm '
                  + (action.primary ? 'btn-primary' : 'btn-outline-secondary')
                  + ' me-2 mt-2';
               var inner = '<i class="' + esc(action.icon || 'ti ti-arrow-right') + ' me-1"></i>'
                  + esc(action.label);

               // Action ouvrant un formulaire sur place, sans passer par la page
               // du BL. Le lien reste en repli si le script concerné n'est pas
               // chargé (cf. le clic plus bas).
               if (action.open && signHandlerAvailable(action.open.handler)) {
                  actions += '<button type="button" class="' + classes + ' gestion-scan-sign" '
                     + 'data-open="' + esc(JSON.stringify(action.open)) + '">'
                     + inner + '</button>';
                  return;
               }
               actions += '<a class="' + classes + '" href="' + esc(action.url) + '">'
                  + inner + '</a>';
            });

            row.innerHTML =
               '<div class="d-flex align-items-center flex-wrap">'
               + '<span class="fw-bold">' + esc(item.title || '') + '</span>' + badge
               + '</div>'
               + (item.subtitle ? '<div class="text-muted small">' + esc(item.subtitle) + '</div>' : '')
               + '<div class="d-flex flex-wrap gestion-scan-actions">' + actions + '</div>';

            container.appendChild(row);
         });
      }

      // ---- Onglet « Par mot-clé » -------------------------------------------
      function deepScope() {
         for (var i = 0; i < els.deepScopes.length; i++) {
            if (els.deepScopes[i].checked) {
               return els.deepScopes[i].value;
            }
         }
         return 'ticket';
      }

      /** Le champ et l'explication suivent la portée choisie. */
      function applyScope() {
         if (deepScope() === 'entity') {
            els.deepInput.placeholder = 'Client, désignation, adresse';
            els.deepHint.textContent = "Cherche le client par son nom, sa désignation ou son adresse, "
               + 'et remonte ses tickets les plus récents.';
         } else {
            els.deepInput.placeholder = 'N° de série, mot-clé';
            els.deepHint.textContent = 'Cherche dans le titre, la description, les tâches, les suivis, '
               + "le n° de série du matériel et les BL. 20 tickets au maximum.";
         }
      }

      function deepMessage(text) {
         if (!text) {
            els.deepMsg.classList.add('d-none');
            els.deepMsg.textContent = '';
            return;
         }
         els.deepMsg.className = 'alert alert-danger';
         els.deepMsg.textContent = text;
      }

      function deepSearch(value) {
         var q = (value || '').trim();
         if (q.length < 3) {
            els.deepResults.innerHTML = '';
            deepMessage('Saisissez au moins 3 caractères.');
            return;
         }
         deepMessage('');
         els.deepResults.innerHTML = '<div class="list-group-item text-center text-muted">'
            + '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Recherche…</div>';

         var body = new URLSearchParams();
         body.append('mode', 'deep');
         body.append('scope', deepScope());
         body.append('q', q);
         body.append('_glpi_csrf_token', csrf());

         fetch(scanUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
               'X-Requested-With': 'XMLHttpRequest',
               'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
         })
         .then(function (r) { return r.json(); })
         .then(function (data) {
            if (!data || data.ok === false) {
               els.deepResults.innerHTML = '';
               deepMessage((data && data.error) || 'Recherche impossible.');
               return;
            }
            renderResults(els.deepResults, data.results || []);
            if (data.loose) {
               // Le terme n'a pas été trouvé tel quel : ces résultats viennent
               // du second passage, qui tolère les séparateurs. Le dire explique
               // à la fois l'attente et pourquoi le texte trouvé ne s'écrit pas
               // comme ce qui a été tapé.
               var loose = document.createElement('div');
               loose.className = 'list-group-item text-muted small';
               loose.textContent = 'Terme introuvable tel quel : résultats trouvés en ignorant les séparateurs.';
               els.deepResults.insertBefore(loose, els.deepResults.firstChild);
            }
            if (data.truncated) {
               // Une liste tronquée en silence se lit comme une liste complète :
               // le technicien croirait avoir vu tous les tickets du client.
               var more = document.createElement('div');
               more.className = 'list-group-item text-muted small';
               more.textContent = 'Seuls les 20 tickets les plus récents sont affichés — précisez la recherche.';
               els.deepResults.appendChild(more);
            }
         })
         .catch(function () {
            els.deepResults.innerHTML = '';
            deepMessage('Erreur de communication avec GLPI.');
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
      /*
       * Message à afficher au prochain rendu du panneau. La reprise d'un bon
       * recharge la page — le message est donc déposé dans sessionStorage et
       * relu ici, sinon il disparaîtrait avec le rechargement.
       */
      var pendingFlash = '';
      try {
         pendingFlash = sessionStorage.getItem('gestionClaimFlash') || '';
         if (pendingFlash) {
            sessionStorage.removeItem('gestionClaimFlash');
         }
      } catch (e) {
         pendingFlash = '';
      }

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

         /*
          * Réouverture après la bascule.
          *
          * L'association recharge la page — l'onglet « Gestion » et le compteur de
          * bons sont rendus côté serveur. Le panneau se rouvre donc de lui-même sur
          * le message de réussite, sinon l'écran revenait au ticket nu et il fallait
          * rappeler le bouton flottant pour voir ce qui venait de se passer.
          *
          * `pendingFlash` ne vaut quelque chose qu'au retour d'une bascule : c'est
          * lui, et rien d'autre, qui distingue ce rechargement d'un affichage normal.
          */
         if (pendingFlash) {
            openActions();
         }
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

      /*
       * Bons cités par ce ticket mais rattachés à un AUTRE ticket.
       *
       * Placé en bas et en petit : c'est une réparation, pas le geste courant.
       * Le panneau nomme le ticket qui détient chaque bon — sans ce numéro, on
       * ne sait pas si le doublon est celui qu'on regarde ou l'autre.
       */

      /*
       * Ce que l'utilisateur vient de demander, tant qu'il ne l'a pas confirmé :
       * { ids: [...], text: '...' }, sinon null.
       *
       * Déplacer un bon ne se rattrape pas d'un clic, et sur mobile un panneau
       * qu'on fait défiler se touche par accident : rien ne part sans une
       * seconde intention explicite.
       */
      var claimPending = null;
      var claimError = '';

      function claimSection(data) {
         var rows = (data && data.claimable) || [];
         if (!rows.length) {
            return '';
         }

         var many = rows.length > 1;
         var movable = data.can_claim_bl;
         var html = '<div id="gestionClaimBox" class="mt-3 pt-3 border-top">';

         if (claimError) {
            html += '<div class="alert alert-danger py-2 small">' + esc(claimError) + '</div>';
            claimError = '';
         }

         // En attente de confirmation : la liste laisse la place à la question.
         if (claimPending) {
            return html + '<div class="text-secondary small mb-2">'
               + '<i class="ti ti-alert-triangle me-1"></i>Confirmer l\'association'
               + '</div>'
               + '<div class="mb-2">' + esc(claimPending.text) + '</div>'
               + '<div class="d-flex flex-wrap align-items-center gap-2">'
               /*
                * Vert pour ce qui agit, gris pour ce qui renonce : sur une question
                * fermée, la couleur porte la réponse avant que le mot ne soit lu.
                * Les deux gardent la hauteur allégée du bouton d'origine.
                */
               + '<button type="button" class="btn btn-secondary flex-shrink-0 px-3"'
               + ' style="padding-top:.25rem;padding-bottom:.25rem" data-gestion-claim-cancel="1">'
               + '<i class="ti ti-x me-1"></i>Annuler'
               + '</button>'
               + '<button type="button" class="btn btn-success flex-shrink-0 px-3 ms-auto"'
               + ' style="padding-top:.25rem;padding-bottom:.25rem" data-gestion-claim-confirm="1">'
               + '<i class="ti ti-check me-1"></i>Confirmer'
               + '</button>'
               + '</div></div>';
         }

         html += '<div class="text-secondary small mb-2">'
            + '<i class="ti ti-alert-triangle me-1"></i>'
            + (many ? 'Bons de livraison rattachés à un autre ticket' : 'Bon de livraison rattaché à un autre ticket')
            + '</div>'
            + '<ul class="list-unstyled small text-muted mb-2">';

         rows.forEach(function (row, index) {
            var target = row.ticket_exists
               ? ('ticket #' + row.tickets_id + (row.ticket_name ? ' — ' + esc(row.ticket_name) : ''))
               : ('ticket #' + row.tickets_id + ' (supprimé)');
            var name = esc(row.bl_number || row.bl);
            /*
             * Le NUMERO est l'élément cliquable, pas un bouton ajouté à côté.
             *
             * Un bouton par ligne remplissait le panneau de boutons oranges pour
             * une action qui n'arrive presque jamais ; le numéro, lui, est déjà
             * ce que l'œil cherche, et c'est exactement ce qu'on veut désigner.
             */
            html += '<li class="mb-1">'
               + (movable
                  ? '<a href="#" class="fw-bold" data-gestion-claim-one="' + index + '">' + name + '</a>'
                  : '<span class="fw-bold">' + name + '</span>')
               + ' · ' + target
               + '</li>';
         });
         html += '</ul>';

         if (!movable) {
            html += '<div class="text-muted small fst-italic mb-0">'
               + 'Vous n\'avez pas le droit de déplacer un bon de livraison.</div>';
         } else {
            /*
             * Le bouton solde TOUT ; le geste par bon passe par son numéro. La
             * phrase de gauche est donc là pour annoncer ce second geste, que
             * rien ne signalerait autrement.
             */
            html += '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">'
               + '<span class="text-muted small">'
               + (many ? 'Touchez un numéro pour n\'associer que celui-là.'
                  : 'Il sera retiré de son ticket actuel.')
               + '</span>'
               + '<button type="button" class="btn btn-primary flex-shrink-0 px-3 ms-auto"'
               + ' style="padding-top:.25rem;padding-bottom:.25rem" data-gestion-claim-all="1">'
               + '<i class="ti ti-link me-1"></i>'
               + (many ? 'Associer les ' + rows.length + ' à ce ticket' : 'Associer à ce ticket')
               + '</button>'
               + '</div>';
         }

         return html + '</div>';
      }

      function claimRowText(row) {
         return (row.bl_number || row.bl) + ' sera retiré du ticket #' + row.tickets_id
            + ' et associé à ce ticket.';
      }

      function bindClaim(data) {
         var box = els.body.querySelector('#gestionClaimBox');
         if (!box) {
            return;
         }
         var rows = (data && data.claimable) || [];

         box.querySelectorAll('[data-gestion-claim-one]').forEach(function (link) {
            link.addEventListener('click', function (event) {
               event.preventDefault();
               var row = rows[parseInt(link.getAttribute('data-gestion-claim-one'), 10)];
               if (row) {
                  claimPending = { ids: [row.id], text: claimRowText(row) };
                  redrawClaim(data);
               }
            });
         });

         var all = box.querySelector('[data-gestion-claim-all]');
         if (all) {
            all.addEventListener('click', function () {
               claimPending = {
                  ids: rows.map(function (row) { return row.id; }),
                  text: rows.length > 1
                     ? ('Les ' + rows.length + ' bons seront retirés de leur ticket actuel et associés à ce ticket.')
                     : claimRowText(rows[0])
               };
               redrawClaim(data);
            });
         }

         var cancel = box.querySelector('[data-gestion-claim-cancel]');
         if (cancel) {
            cancel.addEventListener('click', function () {
               claimPending = null;
               redrawClaim(data);
            });
         }

         var confirm = box.querySelector('[data-gestion-claim-confirm]');
         if (confirm) {
            confirm.addEventListener('click', function () { claimBl(confirm, data); });
         }
      }

      /**
       * Redessine la SEULE section des bons.
       *
       * Le reste du panneau n'a pas bougé ; le reconstruire ferait sauter la
       * liste des signatures sous le doigt, et sur mobile on perdrait sa place
       * dans le panneau à chaque aller-retour vers la confirmation.
       */
      function redrawClaim(data) {
         var box = els.body.querySelector('#gestionClaimBox');
         if (box) {
            box.outerHTML = claimSection(data);
            bindClaim(data);
         }
      }

      /**
       * La CAUSE, pas « échec ».
       *
       * Un bouton qui se contente d'annoncer son échec n'apprend rien : selon le
       * code renvoyé, il faut recharger la page, demander un droit, ou aller lire
       * le journal. Le code brut est conservé pour les cas non prévus — il vaut
       * mieux le montrer que de le taire.
       */
      function claimErrorText(res) {
         var code = (res && res.error) || '';
         if (code === 'invalid_csrf') {
            return 'Session expirée : rechargez la page, puis réessayez.';
         }
         if (code === 'forbidden') {
            return 'Droits insuffisants pour déplacer un bon sur ce ticket.';
         }
         if (code === 'claim_failed') {
            return 'Le déplacement a échoué (détail dans le journal plugin-gestion).';
         }
         return 'Association impossible' + (code ? ' (' + code + ')' : '') + '.';
      }

      /**
       * Bascule des bons confirmés, puis rechargement de la page.
       *
       * L'onglet « Gestion » et le compteur de bons sont rendus côté serveur :
       * sans rechargement ils continueraient à afficher le ticket tel qu'il
       * était avant le déplacement. Le message de réussite transite donc par
       * sessionStorage, sinon il partirait avec la page.
       */
      function claimBl(button, data) {
         var pending = claimPending;
         if (!pending || !pending.ids.length) {
            return;
         }
         button.disabled = true;
         button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Association…';

         var body = new URLSearchParams();
         body.append('ticket_id', String(ticketId));
         body.append('_glpi_csrf_token', csrf());
         pending.ids.forEach(function (id) { body.append('ids[]', String(id)); });

         fetch(claimUrl, {
            method: 'POST',
            credentials: 'same-origin',
            /*
             * GLPI 11 contrôle le jeton CSRF dans un écouteur du noyau, AVANT
             * d'atteindre le script (CheckCsrfListener). Signalée AJAX, la
             * requête voit son jeton lu dans `X-Glpi-Csrf-Token` et CONSERVÉ ;
             * sinon il est lu dans le corps et CONSOMMÉ — le contrôle du script
             * échouait alors sur un jeton que GLPI venait lui-même de retirer.
             */
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
               'X-Requested-With': 'XMLHttpRequest',
               'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
         })
         .then(function (r) { return r.json(); })
         .then(function (res) {
            claimPending = null;
            if (!res || !res.ok) {
               claimError = claimErrorText(res);
               redrawClaim(data);
               return;
            }
            var moved = (res.moved || []).length;
            if (!moved) {
               claimError = 'Aucun bon n\'a pu être associé.';
               redrawClaim(data);
               return;
            }
            try {
               window.sessionStorage.setItem(
                  'gestionClaimFlash',
                  moved > 1 ? (moved + ' bons de livraison ont été associés à ce ticket.')
                     : 'Le bon de livraison a été associé à ce ticket.'
               );
            } catch (e) { /* non bloquant */ }
            window.location.reload();
         })
         .catch(function () {
            claimPending = null;
            claimError = 'Erreur de communication avec GLPI.';
            redrawClaim(data);
         });
      }

      function renderActions(data) {
         if (!data || !data.ok) {
            els.body.innerHTML = '<div class="alert alert-danger mb-0">Actions indisponibles pour ce ticket.</div>';
            return;
         }

         var html = '';
         if (pendingFlash) {
            html += '<div class="alert alert-success">' + esc(pendingFlash) + '</div>';
            pendingFlash = '';
         }
         if (data.notice) {
            html += '<div class="alert alert-info">' + esc(data.notice) + '</div>';
         }

         var claim = claimSection(data);

         if (!data.actions || !data.actions.length) {
            html += '<div class="alert alert-warning' + (claim ? '' : ' mb-0') + '">'
               + 'Aucun bon de livraison à signer sur ce ticket.</div>';
            els.body.innerHTML = html + claim;
            bindClaim(data);
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

         els.body.innerHTML = html + claim;

         els.body.querySelectorAll('[data-gestion-action]').forEach(function (button) {
            button.addEventListener('click', function () {
               var action = data.actions[parseInt(button.getAttribute('data-gestion-action'), 10)];
               runAction(action, data);
            });
         });
         bindClaim(data);
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
