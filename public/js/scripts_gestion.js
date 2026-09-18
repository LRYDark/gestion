/**
 * Titre du modal selon ce qui est réellement affiché, pour que le technicien
 * identifie tout de suite ce qu'il s'apprête à signer.
 *
 * Le même modal sert à trois parcours : signature du BL seul, signature du
 * rapport seul, ou signature groupée Rapport + BL. On se base sur le mode
 * coché dans le sélecteur rendu par le serveur (gestion_render_combined_radio),
 * et à défaut sur les paramètres d'appel.
 */
function gestion_getCriFormTitle(response, params) {
   var html = String(response || '');
   var p    = params || {};

   if (html.indexOf('gestion-combined-mode') !== -1) {
      if (/id="gcm_rp"[^>]*checked/.test(html)) {
         return __('Signature du rapport', 'gestion');
      }
      return __('Signature Rapport + BL', 'gestion');
   }

   if (p.force_rp) {
      return __('Signature du rapport', 'gestion');
   }
   if (p.force_combined) {
      return __('Signature Rapport + BL', 'gestion');
   }

   return __('Signature du bon de livraison', 'gestion');
}

function gestion_loadCriForm(action, modal, params) {
   var formInput;

   if (params.form != undefined) {
      formInput = getGestionFormData($('form[name="' + params.form + '"]'));
   }

   $.ajax({
      url: params.root_doc + '/ajax/cri.php',
      type: "POST",
      dataType: "html",
      timeout: 12000,
      data: {
         'action': action,
         'params': params,
         'pdf_action': params.pdf_action,
         'formInput': formInput,
         'modal': modal
      },
      success: function (response, opts) {
         try {
            var json = $.parseJSON(response);
            // Cas "ticket sans tache" : le serveur demande d'ouvrir le ticket a remplir.
            if (json && json.redirect) {
               window.location.href = json.redirect;
               return;
            }
            if (!json.success) {
               $("#gestion_cri_error").html(json.message).show().delay(2000).fadeOut('slow');
            }

         } catch (err) {
            // `modal` can be a numeric survey id used server-side.
            // Using jQuery selector like `#123` may fail on some browsers/engines.
            try {
               if (modal !== undefined && modal !== null && String(modal).trim() !== '') {
                  var mount = document.getElementById(String(modal));
                  if (mount) {
                     mount.innerHTML = response;
                  }
               }
            } catch (e) {}

            switch (action) {
               case 'saveCri':
                  // Ferme le modal et recharge la page
                  window.location.reload();
                  break;
                default:
                   // Ouvre le modal et force le style no-scroll avec JavaScript
                   // Capture l'élément déclencheur pour restaurer le focus à la fermeture
                   var openerEl = document.activeElement || null;
                   window.__gestionModalFocusMap = window.__gestionModalFocusMap || {};
                   window.__gestionModalFocusMap[action] = openerEl;
                   if (typeof glpi_html_dialog !== 'function') {
                      if (params && params.fallback_url) {
                         window.location.href = params.fallback_url;
                         return;
                      }
                      alert('Impossible d\'ouvrir la fenetre de signature.');
                      return;
                   }
                   glpi_html_dialog({
                      title: gestion_getCriFormTitle(response, params),
                      body: response,
                      id: action,
                     afterOpen: function() {
                        // Forcer le style no-scroll sur le body
                        document.body.classList.add('no-scroll');
                        // Fixer les marges pour éviter le décalage horizontal
                        document.body.style.marginLeft = '0px';
                        document.body.style.marginRight = '0px';
                        
                        // A11y: gǸrer aria-hidden/inert et focus pour le modal Bootstrap
                        var $m = $('#' + action);
                        if ($m && $m.length) {
                           var modalEl = $m[0];
                           // Lever aria-hidden/inert à l'ouverture
                           modalEl.removeAttribute('aria-hidden');
                           modalEl.removeAttribute('inert');

                           // Placer un focus initial raisonnable (bouton de fermeture si prǸsent)
                           setTimeout(function(){
                              var closeBtn = modalEl.querySelector('.btn-close, [data-bs-dismiss="modal"]');
                              if (closeBtn && typeof closeBtn.focus === 'function') { closeBtn.focus(); }
                              else if (typeof modalEl.focus === 'function') { modalEl.focus(); }
                           }, 0);

                           // Avant fermeture: dǸplacer le focus hors du modal
                           $m.on('hide.bs.modal', function(){
                              try {
                                 var ae = document.activeElement;
                                 if (ae && modalEl.contains(ae)) {
                                    if (typeof ae.blur === 'function') {
                                       ae.blur();
                                    }
                                    var opener = (window.__gestionModalFocusMap || {})[action];
                                    if (opener && document.body.contains(opener) && typeof opener.focus === 'function') {
                                       opener.focus();
                                    } else if (document.body && typeof document.body.focus === 'function') {
                                       document.body.focus();
                                    }
                                 }
                              } catch(e) {}
                              modalEl.setAttribute('inert', '');
                           });

                           // Synchroniser aria-hidden/inert apr��s affichage/fermeture
                           $m.on('shown.bs.modal', function(){
                              modalEl.removeAttribute('aria-hidden');
                              modalEl.removeAttribute('inert');
                           });
                           $m.on('hidden.bs.modal', function(){
                              modalEl.setAttribute('aria-hidden', 'true');
                              modalEl.setAttribute('inert', '');
                           });
                        }
                     },
                     afterClose: function() {
                        // Rétablit le défilement de la page principale
                        document.body.classList.remove('no-scroll');
                        document.body.style.marginLeft = '';
                        document.body.style.marginRight = '';
                     }
                  });
                  break;
            }
         }
      },
      error: function(xhr, status, err) {
         try {
            console.error('[gestion] gestion_loadCriForm failed', status, err, xhr && xhr.status, xhr && xhr.responseText);
         } catch (e) {}
         if (params && params.fallback_url) {
            window.location.href = params.fallback_url;
            return;
         }
         alert('Impossible d\'ouvrir la signature pour le moment.');
      }
   });
}

// Signer le BL seul DANS le modal courant (cas "ticket sans tache" => bouton "Signer le
// BL seul quand meme"). On REMPLACE le contenu du modal au lieu d'ouvrir un 2e modal de
// meme id 'showCriForm' (qui restait invisible).
function gestion_signBlOnly(btn, blId, params) {
   try {
      var container = (btn && btn.closest)
         ? (btn.closest('.modal-body') || btn.closest('.modal-content'))
         : null;
      if (!container && btn) { container = btn.parentElement; }
      if (!container) { return; }
      $.ajax({
         url: (params && params.root_doc ? params.root_doc : '') + '/ajax/cri.php',
         type: 'POST',
         dataType: 'html',
         timeout: 12000,
         data: { action: 'showCriForm', params: params, modal: blId }
      }).done(function (html) {
         // jQuery .html() execute les scripts inline ET charge les <script src> externes
         // (necessaire pour le formulaire combine qui charge scripts_rp.js).
         $(container).html(html);
      }).fail(function () {
         alert('Impossible de charger la signature.');
      });
   } catch (e) { console.error(e); }
}

// Bascule du radio « Signature Rapport » / « Signature Rapport + BL » en haut du modal :
// recharge le formulaire correspondant DANS le modal courant (sans changer de page).
/**
 * Après soumission d'un formulaire de signature.
 *
 * Quand le réglage « Affichage du PDF après signature » est actif, le
 * formulaire porte `target="_blank"` : le PDF produit s'ouvre dans un onglet
 * voisin et la page courante NE NAVIGUE PAS. Le voile de chargement, posé au
 * moment de l'envoi, restait donc affiché indéfiniment, et la page continuait
 * d'afficher les bons comme non signés.
 *
 * On retire le voile une fois la requête partie, puis on recharge dès que
 * l'utilisateur revient sur cet onglet — c'est le moment exact où il veut voir
 * l'état à jour. Filet de sécurité à 20 s s'il ne quitte jamais la page.
 *
 * Sans `target`, la page navigue d'elle-même : cette fonction ne fait rien.
 */
function gestionAfterSubmit(form) {
   if (!form || form.target !== '_blank') {
      return;
   }

   /*
    * `__rpReloadScheduled` : garde-fou PARTAGÉ avec le plugin RP, dont
    * l'écouteur global voit lui aussi cet envoi (les deux formulaires portent
    * le nom `formReport`). Sans lui, deux rechargements concurrents seraient
    * programmés sur la même page.
    *
    * Le module de file hors-ligne (`gestion_outbox.js` / `rp_outbox.js`)
    * pose ce témoin dès son chargement et ne le rend jamais : quand il est
    * présent, c'est LUI qui retire le voile et recharge la page, que l'envoi
    * soit intercepté ou laissé au navigateur (cf. `nativeAfterSubmit`). Cette
    * suite ne sert donc que sans lui — navigateur trop ancien, ou file non
    * déclarée.
    */
   if (window.__rpReloadScheduled) {
      return;
   }
   window.__rpReloadScheduled = true;

   window.setTimeout(function () {
      var loader = document.getElementById('gestion-loader');
      if (loader) {
         loader.classList.remove('active');
      }
   }, 2000);

   var done = false;
   function reloadOnce() {
      if (done) { return; }
      done = true;
      window.location.reload();
   }

   window.addEventListener('focus', function () {
      window.setTimeout(reloadOnce, 400);
   }, { once: true });

   window.setTimeout(reloadOnce, 30000);
}

function gestion_switchCombinedMode(radio) {
   try {
      var wrap = radio.closest ? radio.closest('[data-params]') : null;
      if (!wrap) { return; }
      var blId = wrap.getAttribute('data-bl');
      var params = {};
      try { params = JSON.parse(wrap.getAttribute('data-params') || '{}'); } catch (e) { params = {}; }
      var p = $.extend({}, params);
      delete p.force_rp; delete p.force_combined; delete p.force_bl;
      // Trois modes possibles ; `bl` n'est propose que lorsque le rapport est
      // deja signe (cf. PluginGestionCri::renderCombinedModeRadio).
      if (radio.value === 'rp') {
         p.force_rp = 1;
      } else if (radio.value === 'bl') {
         p.force_bl = 1;
      } else {
         p.force_combined = 1;
      }
      var container = radio.closest('.modal-body') || radio.closest('.modal-content') || wrap.parentElement;
      if (!container) { return; }
      $.ajax({
         url: (p.root_doc ? p.root_doc : '') + '/ajax/cri.php',
         type: 'POST',
         dataType: 'html',
         timeout: 15000,
         data: { action: 'showCriForm', params: p, modal: blId }
      }).done(function (html) {
         $(container).html(html); // jQuery .html() => execute/charge les scripts du formulaire
      }).fail(function () {
         alert('Impossible de charger le formulaire.');
      });
   } catch (e) { console.error(e); }
}

(function (global) {
  'use strict';

  // Créer un namespace unique basé sur le plugin actuel
  const currentPlugin =
    (typeof GLPI_PLUG_GESTION !== 'undefined' && typeof GLPI_PLUG_GESTION === 'string' && GLPI_PLUG_GESTION.trim()) ||
    (typeof GLPI_PLUG_RP       !== 'undefined' && typeof GLPI_PLUG_RP       === 'string' && GLPI_PLUG_RP.trim()) ||
    '/glpi/plugins/gestion';

  const pluginHash = btoa(currentPlugin).replace(/[^a-zA-Z0-9]/g, '');
  const namespace = 'RemoteSign_' + pluginHash;
  
  // Si ce namespace existe déjà, ne pas le recréer
  if (global[namespace]) {
    // Mais réattacher les event listeners si nécessaire
    setTimeout(() => attachButtonHandler(global[namespace]), 100);
    return;
  }

  function getCSRF() {
    return document.querySelector('meta[property="glpi:csrf_token"]')?.content
        || document.querySelector('input[name="_glpi_csrf_token"]')?.value
        || '';
  }

  function getTicketId() {
    const hid2 = document.getElementById('remote-ticket-id');
    if (hid2 && hid2.value) return parseInt(hid2.value, 10);
    const hid = document.querySelector('input[name="id"]');
    if (hid && hid.value) return parseInt(hid.value, 10);
    try {
      const p = new URLSearchParams(location.search);
      const id = p.get('id');
      if (id) return parseInt(id, 10);
    } catch (e) {}
    return 0;
  }

  async function postForm(url, data) {
    const csrf = getCSRF();
    const form = new URLSearchParams();
    Object.entries(data || {}).forEach(([k, v]) => form.append(k, v == null ? '' : String(v)));
    if (csrf) form.append('_glpi_csrf_token', csrf);

    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      }, csrf ? { 'X-Glpi-Csrf-Token': csrf } : {}),
      body: form.toString()
    });
    const text = await res.text();
    if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + text);
    try { return JSON.parse(text); } catch { return { ok:true, raw:text }; }
  }

  async function postJSON(url, obj) {
    const csrf = getCSRF();
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }, csrf ? { 'X-Glpi-Csrf-Token': csrf } : {}),
      body: JSON.stringify(Object.assign({}, obj, csrf ? { _glpi_csrf_token: csrf } : {}))
    });
    const text = await res.text();
    if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + text);
    try { return JSON.parse(text); } catch { return { ok:true, raw:text }; }
  }

  const base = currentPlugin;

  const RemoteSignInstance = {
    plugin: currentPlugin,
    namespace: namespace,
    
    // v1.7.0_alpha1 : identification par device_serial (sans token)
    async create({ ticket_id, device_serial, parameters }) {
      ticket_id = ticket_id || getTicketId();
      if (!ticket_id)     throw new Error('Ticket introuvable (id manquant)');
      if (!device_serial) throw new Error('Sélectionnez une tablette.');

      const payload = { ticket_id, id: ticket_id, device_serial };

      // Paramètres automatiques (infos ticket)
      let finalParameters = parameters;
      if (!finalParameters && typeof window.REMOTE_SIGN_AUTO_PARAMS === 'object' && window.REMOTE_SIGN_AUTO_PARAMS) {
        finalParameters = JSON.stringify(window.REMOTE_SIGN_AUTO_PARAMS);
      }

      if (finalParameters) {
        const json      = JSON.stringify(window.REMOTE_SIGN_AUTO_PARAMS);
        const utf8Bytes = new TextEncoder().encode(json);
        let binary = '';
        utf8Bytes.forEach(b => binary += String.fromCharCode(b));
        payload.parameters_b64 = btoa(binary);
      }

      return postForm(base + '/ajax/create_remote_signature.php', payload);
    },

    async pollTicket(ticket_id, device) {
      ticket_id = ticket_id || getTicketId();
      const payload = { ticket_id, id: ticket_id };
      // device_serial optionnel (affine la recherche côté serveur)
      if (device && device.device_serial) payload.device_serial = device.device_serial;
      return postJSON(base + '/ajax/request_poll.php', payload);
    }
    // devicePoll() et deviceSubmit() supprimés (étaient appelés par device_sign.php — supprimé en v1.7.0_alpha1)
  };

  /*
   * Résolution dans la copie VISIBLE du formulaire.
   *
   * Le formulaire de signature peut exister EN DOUBLE dans la page (conteneur
   * caché + fenêtre ajoutée en fin de body par glpi_html_dialog, mêmes
   * identifiants partout). `getElementById` renvoie la PREMIÈRE copie — la
   * cachée : le bouton « Demander la signature » réellement cliqué n'avait pas
   * d'écouteur, et la signature reçue remplissait le champ d'un formulaire
   * jamais soumis.
   */
  function visibleById(id) {
    let found = null;
    document.querySelectorAll('[id="' + CSS.escape(id) + '"]').forEach(function (el) {
      if (el.offsetParent !== null) { found = el; }
    });
    return found || document.getElementById(id);
  }

  function attachButtonHandler(remoteSignInstance) {
    const btn  = visibleById('remote-start');
    const sel  = visibleById('remote-device');
    const stat = visibleById('remote-status');
    if (!btn || !sel) return;
    
    // Vérifier si ce bouton a déjà un listener pour ce plugin
    const listenerKey = 'listener_' + pluginHash;
    if (btn.dataset[listenerKey] === '1') return;
    btn.dataset[listenerKey] = '1';

    btn.addEventListener('click', async function(){
      const inflightKey = 'inflight_' + pluginHash;
      if (btn.dataset[inflightKey] === '1') return;
      btn.dataset[inflightKey] = '1';
      btn.disabled = true;

      try {
        const opt           = sel.options[sel.selectedIndex];
        const device_serial = opt?.value || '';  // serial de la tablette (v1.7.0_alpha1)
        const tid           = getTicketId();

        if (stat) stat.textContent = "Envoi de la demande…";

        await remoteSignInstance.create({ ticket_id: tid, device_serial });

        if (stat) stat.textContent = "En attente de la tablette…";

        let tries = 0;
        const loop = async ()=>{
          try {
            const r = await remoteSignInstance.pollTicket(tid, { device_serial });
            const sig = r.signature || r.signature_base64 || null;
            const ready = (r.ready === true) || (r.status === 'done') || (!!sig && !r.none);
            
            // NOUVEAU : Gérer le statut 'cancelled' (refusé)
            if (r.status === 'cancelled') {
              if (stat) stat.textContent = "Signature refusée par le client.";
              btn.dataset[inflightKey] = '0';
              btn.disabled = false;
              return;
            }
            
            if (ready && sig) {
              // Champ et aperçu du formulaire VISIBLE (cf. visibleById) :
              // en double, ceux du document seraient la copie cachée.
              const hiddenArea = visibleById("sig-dataUrl");
              if (hiddenArea) hiddenArea.value = sig.startsWith('data:') ? sig : ('data:image/png;base64,' + sig);
              const prev = visibleById("signature-preview");
              if (prev){
                prev.src = sig.startsWith('data:') ? sig : ('data:image/png;base64,' + sig);
                prev.style.display="block";
              }
              if (stat) stat.textContent = "Signature reçue.";
              btn.dataset[inflightKey] = '0';
              btn.disabled = false;
              return;
            }
          } catch (e) {
            console.error(e);
            if (stat) stat.textContent = String(e && e.message ? e.message : e);
            btn.dataset[inflightKey] = '0';
            btn.disabled = false;
            return;
          }
          tries++;
          if (tries < 240) setTimeout(loop, 2000);
          else {
            if (stat) stat.textContent = "Aucune signature reçue (timeout).";
            btn.dataset[inflightKey] = '0';
            btn.disabled = false;
          }
        };
        loop();
      } catch (e) {
        console.error(e);
        if (stat) stat.textContent = String(e && e.message ? e.message : e);
        btn.dataset[inflightKey] = '0';
        btn.disabled = false;
      }
    });
  }

  // Créer l'instance dans le namespace unique
  global[namespace] = RemoteSignInstance;
  
  // Maintenir la compatibilité avec l'ancien nom pour le plugin actuel
  global.RemoteSign = RemoteSignInstance;
  
  // Attacher les event listeners
  attachButtonHandler(RemoteSignInstance);

})(window);

// Fonction d'initialisation globale appelée depuis le PHP
function initializeSignatureGestion(uniqId) {
  // ---------- Capture fichiers (images + PDF) ----------
  // La logique principale est gérée par le script inline dans cri.class.php
  // Ce bloc est un fallback si le script inline n'a pas été initialisé
  const captureFileInput = document.getElementById("capture-file-input");
  if (captureFileInput && !captureFileInput.dataset.gestionFileInit) {
    captureFileInput.dataset.gestionFileInit = "1";
    let photoCount = 0;
    const maxPhotos = 6;
    let hasPdf = false;
    captureFileInput.addEventListener("change", function (event) {
      const file = event.target.files[0];
      if (!file) return;
      if (file.type === "application/pdf") {
        if (hasPdf) { alert("Un seul fichier PDF autorisé."); captureFileInput.value = ""; return; }
        const reader = new FileReader();
        reader.onload = e => { const out = document.getElementById("pdf-base64"); if (out) out.value = e.target.result; hasPdf = true; captureFileInput.value = ""; };
        reader.readAsDataURL(file);
      } else if (file.type === "image/png" || file.type === "image/jpeg") {
        if (photoCount >= maxPhotos) { alert("Maximum " + maxPhotos + " images."); captureFileInput.value = ""; return; }
        const reader = new FileReader();
        reader.onload = e => { photoCount++; const out = document.getElementById("photo-base64-" + photoCount); if (out) out.value = e.target.result; captureFileInput.value = ""; };
        reader.readAsDataURL(file);
      } else {
        alert("Format non supporté. Formats acceptés : PNG, JPEG, PDF.");
        captureFileInput.value = "";
      }
    });
  }

  // ---------- Signature ----------
  // Moteur réécrit : l'historique vectoriel normalisé (0..1) est la source de
  // vérité unique ; base et modale n'en sont que des rendus. Les coordonnées
  // sont converties avec une échelle séparée par axe, mesurée au moment du
  // tracé : aucun décalage possible même si le CSS réduit le canvas.
  (function () {
    /*
     * La copie VISIBLE du formulaire, pas la première venue.
     *
     * Le formulaire existe souvent EN DOUBLE dans la page : le chargeur
     * l'injecte d'abord dans son conteneur (caché sur la page mobile), puis
     * `glpi_html_dialog` en AJOUTE une seconde copie en fin de <body> — avec
     * les mêmes identifiants. `getElementById` renvoie la PREMIÈRE copie dans
     * l'ordre du document : la cachée. Tout se câblait alors sur un canvas
     * invisible, et celui que le client avait sous le doigt restait muet.
     *
     * On retient donc la DERNIÈRE copie affichée (`offsetParent` est nul pour
     * tout élément sous un `display:none`), et à défaut la dernière tout court
     * — celle de la fenêtre, ajoutée en dernier.
     */
    const copies = document.querySelectorAll('[id="' + CSS.escape(uniqId) + '"]');
    let root = null;
    copies.forEach(function (el) { if (el.offsetParent !== null) { root = el; } });
    if (!root && copies.length) { root = copies[copies.length - 1]; }
    if (!root) return;

    /*
     * Les DEUX copies exécutent ce script (l'injection jQuery lance les
     * <script> des deux). Sans ce témoin, la copie visible était initialisée
     * deux fois : chaque trait était capté par deux jeux d'écouteurs et deux
     * historiques concurrents.
     */
    if (root.dataset.sigBound === '1') return;
    root.dataset.sigBound = '1';

    // Elements
    const originalCanvas = root.querySelector("#sig-canvas-" + uniqId);
    const modalCanvas    = root.querySelector("#modal-canvas-" + uniqId);
    const modalOverlay   = root.querySelector(".signature-modal");
    const btnZoom        = root.querySelector(".zoom-btn");
    const btnClearBase   = root.querySelector("#sig-clearBtn-" + uniqId);
    const btnValidate    = root.querySelector(".btn-validate");
    const btnClearModal  = root.querySelector(".btn-clear");
    const btnCancel      = root.querySelector(".btn-cancel");
    const rotateGate     = root.querySelector(".rotate-gate");
    const rotateCloseBtn = root.querySelector(".rotate-close-btn");
    if (!originalCanvas || !modalCanvas) return;

    const originalCtx = originalCanvas.getContext("2d");
    const modalCtx    = modalCanvas.getContext("2d");

    // Épaisseurs (px CSS)
    const BASE_LINE        = 2.00; // tracé live sur le canvas de base
    const MODAL_LINE       = 1.80; // tracé dans la modale
    const BASE_EXPORT_LINE = 2.40; // re-rendu sur la base à la validation de la modale

    let modalIsOpen = false;

    // ---------- Historique vectoriel ----------
    let paths = [];        // chaque trait = [{x,y} ...] en coordonnées normalisées 0..1
    let currentPath = null;

    /*
     * État du tracé en cours. Déclaré ICI et non près des gestionnaires de
     * dessin : `adaptCanvasSize()` s'en sert pour ne pas redimensionner le
     * canvas au milieu d'un trait, et il est appelé dès l'initialisation —
     * bien avant le bloc « Dessin ». Une déclaration `let` plus bas le
     * laisserait dans sa zone morte temporelle, et la première passe lèverait
     * une ReferenceError.
     */
    let drawing = false;
    let activeCanvas = null;
    let lastNorm = null;

    // Événement -> coordonnées normalisées, échelle séparée par axe (anti-décalage)
    function getNorm(e, canvas) {
      const rect = canvas.getBoundingClientRect();
      const p = e.touches?.[0] || e.changedTouches?.[0] || e;
      return {
        x: (p.clientX - rect.left) / (rect.width  || 1),
        y: (p.clientY - rect.top)  / (rect.height || 1)
      };
    }

    /**
     * Largeur d'AFFICHAGE du canvas, en px CSS.
     *
     * Le rect mesuré reste la référence pour un canvas posé dans la page : lui
     * seul tient compte des limites du CSS, et c'est lui que `getNorm` utilise
     * pour situer le doigt — les deux doivent parler de la même largeur.
     *
     * Mais le canvas d'EXPORT n'est jamais inséré dans le document : son rect
     * vaut 0. Sans ce repli sur la largeur déclarée, `lineWidthFor` se
     * rabattait sur `devicePixelRatio` et multipliait l'épaisseur une seconde
     * fois — trait deux à trois fois trop gras dans le PDF sur un écran HiDPI.
     */
    function cssWidthOf(canvas) {
      const rect = canvas.getBoundingClientRect();
      if (rect.width > 0) return rect.width;
      const styled = parseFloat(canvas.style.width);
      return styled > 0 ? styled : canvas.width;
    }

    // Épaisseur en px bitmap équivalente à cssLine px CSS affichés
    function lineWidthFor(canvas, cssLine) {
      const cssWidth = cssWidthOf(canvas);
      const s = cssWidth > 0 ? canvas.width / cssWidth : (window.devicePixelRatio || 1);
      return Math.max(1, cssLine * s);
    }

    function setupStroke(ctx, lw) {
      ctx.strokeStyle = "#000";
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.lineWidth = lw;
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
    }

    function drawSegmentNorm(ctx, canvas, from, to, cssLine) {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      setupStroke(ctx, lineWidthFor(canvas, cssLine));
      ctx.beginPath();
      ctx.moveTo(from.x * canvas.width, from.y * canvas.height);
      ctx.lineTo(to.x   * canvas.width, to.y   * canvas.height);
      ctx.stroke();
    }

    function renderHistoryOn(canvas, ctx, cssLine) {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      setupStroke(ctx, lineWidthFor(canvas, cssLine));
      const W = canvas.width, H = canvas.height;
      for (const path of paths) {
        if (path.length < 2) continue;
        ctx.beginPath();
        ctx.moveTo(path[0].x * W, path[0].y * H);
        for (let i = 1; i < path.length; i++) ctx.lineTo(path[i].x * W, path[i].y * H);
        ctx.stroke();
      }
    }

    function setCanvasSize(canvas, cssW, cssH) {
      const dpr = window.devicePixelRatio || 1;
      canvas.style.width  = cssW + "px";
      canvas.style.height = cssH + "px";
      canvas.width  = Math.max(1, Math.round(cssW * dpr));
      canvas.height = Math.max(1, Math.round(cssH * dpr));
    }

    // ---------- Canvas de base ----------
    /*
     * Hauteur FIXE, jamais mesurée.
     *
     * Deux approches ont précédé : figer le ratio mesuré à l'initialisation
     * (mesure prise au mauvais moment — formulaire caché, CSS pas encore
     * appliqué — donc zone carrée gelée pour toujours), puis ne le figer que
     * s'il était « vraisemblable ». Verdict : toute hauteur DÉDUITE d'une
     * mesure finit par varier selon l'instant du chargement.
     *
     * La zone est une bande de hauteur FIXE — les mêmes valeurs que la feuille
     * de style (`.signature-sub-card .sig-base`), au même point de bascule.
     *
     * DEUX hauteurs, et c'est délibéré :
     *
     *   EXPORT_BASE_H — la géométrie du PNG qui part dans le PDF. Elle ne
     *   bouge pas. Le tampon apposé sur le bon de livraison du client
     *   (`Image($png, X, Y, SignatureSize)` dans sign_bl.core.php et
     *   traitement.php) impose la LARGEUR et laisse FPDF déduire la hauteur du
     *   ratio de l'image : grandir l'image, c'est la faire descendre sur le
     *   texte du bon, qui n'a aucune place de réserve. Cette constante est
     *   donc le contrat avec les PDF, pas un réglage d'écran.
     *
     *   DISPLAY_BASE_H_WIDE — la hauteur réellement affichée sur tablette et
     *   ordinateur, où la bande de 120 px était trop plate pour signer. Le
     *   téléphone garde 120 px : il n'a pas la place, et le rendu y convient.
     *
     * L'export n'est plus le canvas affiché mais un rendu hors écran à la
     * géométrie d'export (cf. buildExportDataUrl) : la hauteur visible peut
     * donc changer librement sans qu'aucun PDF ne bouge.
     */
    const EXPORT_BASE_H = 120;
    const DISPLAY_BASE_H_WIDE = 220;

    /*
     * Le point de bascule est interrogé, jamais mesuré sur l'élément : une
     * hauteur déduite d'un `getBoundingClientRect` dépend de l'instant du
     * chargement (formulaire encore caché, CSS pas encore appliqué), ce qui a
     * déjà figé la zone dans une forme carrée par le passé. `matchMedia` donne
     * la même réponse à tout moment, avant même le premier rendu.
     */
    const wideScreenMQ = window.matchMedia("(min-width: 768px)");
    function displayBaseH() {
      return wideScreenMQ.matches ? DISPLAY_BASE_H_WIDE : EXPORT_BASE_H;
    }

    const initRect = originalCanvas.getBoundingClientRect();
    const INITIAL_BASE_W = Math.max(200, Math.round(initRect.width  || originalCanvas.clientWidth  || 320));
    const INITIAL_BASE_H = displayBaseH();

    function adaptCanvasSize() {
      // Jamais au milieu d'un trait : le trait en cours n'est pas encore dans
      // l'historique, un re-rendu l'effacerait sous le doigt.
      if (drawing) return;
      const container = originalCanvas.closest(".signature-container") || originalCanvas.parentElement;
      if (!container) return;
      const cs = getComputedStyle(container);
      const padX = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
      const cssW = Math.max(200, Math.floor(container.clientWidth - padX));
      const cssH = displayBaseH();
      if (parseInt(originalCanvas.style.width, 10) === cssW
          && parseInt(originalCanvas.style.height, 10) === cssH) {
        return;
      }

      /*
       * Le tracé VECTORIEL est rejoué, jamais étiré.
       *
       * L'ancienne reprise redessinait l'ancien bitmap aux nouvelles
       * dimensions. Tant que seule la largeur variait, la déformation passait
       * inaperçue ; maintenant que la HAUTEUR change aussi (bascule
       * téléphone/ordinateur, fenêtre redimensionnée), la même opération
       * écraserait la signature verticalement.
       *
       * Les coordonnées de ce moteur sont normalisées PAR AXE : les rejouer
       * telles quelles sur une boîte d'un autre rapport les déformerait tout
       * autant. On passe donc par le rendu ajusté, qui conserve les
       * proportions du tracé quelle que soit la forme de la zone.
       */
      if (paths.length) {
        const srcW = originalCanvas.width;
        const srcH = originalCanvas.height;
        setCanvasSize(originalCanvas, cssW, cssH);
        /*
         * L'historique est RÉÉCRIT dans le repère de la nouvelle boîte, pas
         * seulement redessiné dedans. Les coordonnées de ce moteur n'ont de
         * sens que rapportées à la zone qui les a reçues : les laisser dans
         * l'ancien repère alors que les traits suivants seraient normalisés
         * dans le nouveau mélangerait deux référentiels, et la signature se
         * disloquerait au trait d'après.
         */
        refitPathsToBox(originalCanvas, srcW, srcH);
        renderHistoryOn(originalCanvas, originalCtx, BASE_EXPORT_LINE);
        return;
      }

      // Pas d'historique : la signature vient d'une IMAGE (rejeu hors-ligne,
      // signature déportée). On la replace sans la déformer — à ses
      // proportions, centrée — plutôt que de l'étirer sur la nouvelle boîte.
      const backup = document.createElement("canvas");
      backup.width  = originalCanvas.width;
      backup.height = originalCanvas.height;
      const hasBitmap = backup.width > 0 && backup.height > 0;
      if (hasBitmap) backup.getContext("2d").drawImage(originalCanvas, 0, 0);
      setCanvasSize(originalCanvas, cssW, cssH);
      if (hasBitmap) {
        drawImageContained(originalCtx, backup, originalCanvas.width, originalCanvas.height);
      }
    }

    /**
     * Dessine une source dans une boîte SANS la déformer : facteur unique,
     * résultat centré. Le reste de la boîte demeure transparent.
     */
    function drawImageContained(ctx, source, boxW, boxH) {
      const sw = source.width, sh = source.height;
      if (!(sw > 0 && sh > 0 && boxW > 0 && boxH > 0)) return;
      const factor = Math.min(boxW / sw, boxH / sh);
      const dw = Math.max(1, Math.round(sw * factor));
      const dh = Math.max(1, Math.round(sh * factor));
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.drawImage(source, 0, 0, sw, sh,
                    Math.round((boxW - dw) / 2), Math.round((boxH - dh) / 2), dw, dh);
    }

    /**
     * Rejoue l'historique sur une zone d'un AUTRE rapport, sans déformer.
     *
     * Ce moteur normalise les coordonnées par axe (`x / largeur`,
     * `y / hauteur`) : elles ne décrivent donc une forme qu'accompagnées des
     * dimensions de la zone où elles ont été tracées. `renderHistoryOn()` les
     * rejoue sur la boîte courante et convient tant que la zone garde son
     * rapport — ce qui était le cas tant que la bande faisait toujours 120 px.
     *
     * Dès que la zone d'affichage et la zone d'export diffèrent, il faut
     * repasser par l'espace en pixels de la SOURCE, mesurer l'encombrement
     * réel du tracé, puis le ramener d'un SEUL facteur — jamais deux échelles
     * distinctes, sinon la signature s'aplatit — et le centrer.
     *
     * Le facteur est plafonné au report direct : une signature qui tient déjà
     * n'est pas agrandie (un simple point ne devient pas un pâté), on ne
     * réduit que ce qui déborderait.
     */
    function fitTransform(canvas, srcW, srcH) {
      if (!paths.length || !(srcW > 0) || !(srcH > 0)
          || !(canvas.width > 0) || !(canvas.height > 0)) {
        return null;
      }

      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      for (const path of paths) {
        for (const q of path) {
          const px = q.x * srcW, py = q.y * srcH;
          if (px < minX) minX = px;
          if (px > maxX) maxX = px;
          if (py < minY) minY = py;
          if (py > maxY) maxY = py;
        }
      }
      if (!isFinite(minX)) return null;

      const padX = canvas.width  * 0.04;
      const padY = canvas.height * 0.06;
      const availW = Math.max(1, canvas.width  - 2 * padX);
      const availH = Math.max(1, canvas.height - 2 * padY);
      const bw = Math.max(maxX - minX, 1e-6);
      const bh = Math.max(maxY - minY, 1e-6);

      const naturalFactor = canvas.width / srcW; // report direct, sans retouche
      const factor = Math.min(availW / bw, availH / bh, naturalFactor);

      return {
        factor: factor,
        offX: (canvas.width  - bw * factor) / 2 - minX * factor,
        offY: (canvas.height - bh * factor) / 2 - minY * factor
      };
    }

    /**
     * Réécrit l'historique dans le repère de `canvas`, à ses proportions.
     *
     * Destructif, et c'est le but : après l'appel, les coordonnées sont
     * normalisées sur la NOUVELLE boîte, exactement comme le seraient des
     * traits qu'on y dessinerait maintenant. Les deux se mélangent alors sans
     * risque — ce qui n'est pas le cas d'un simple redessin ajusté.
     */
    function refitPathsToBox(canvas, srcW, srcH) {
      const t = fitTransform(canvas, srcW, srcH);
      if (!t) return;
      const invW = 1 / canvas.width;
      const invH = 1 / canvas.height;
      for (const path of paths) {
        for (const q of path) {
          q.x = (q.x * srcW * t.factor + t.offX) * invW;
          q.y = (q.y * srcH * t.factor + t.offY) * invH;
        }
      }
    }

    /* ------------------------------------------------------------------
     * PNG destiné au PDF : recadré sur le TRACÉ, pas sur la zone de dessin.
     *
     * Le PDF ajuste l'image reçue dans une case en conservant son rapport.
     * Tant qu'on lui envoyait la bande entière, la signature n'en occupait
     * qu'un îlot central : le PDF réduisait aussi les MARGES BLANCHES, qui
     * mangeaient la case. En n'envoyant que le tracé, c'est lui qui la remplit.
     *
     * Le résultat ne dépend alors plus du tout de la hauteur de la zone de
     * dessin : 120 ou 220 px, le PDF est le même.
     * ------------------------------------------------------------------ */

    /*
     * Le PNG n'est jamais plus « haut » que ce rapport : au besoin on ajoute
     * des marges LATÉRALES (jamais verticales).
     *
     * C'est la protection du tampon apposé sur le bon de livraison du client
     * (sign_bl.core.php, traitement.php). Là, FPDF reçoit une largeur et
     * DÉDUIT la hauteur du rapport de l'image : une image plus haute descend
     * sur le texte du bon, qui n'a aucune réserve. Avec ce plafond la hauteur
     * du tampon ne dépasse jamais 0,313 fois la largeur configurée — soit
     * MOINS que les 0,316 déjà produits aujourd'hui par une signature prise
     * sur téléphone. Le gabarit encaisse donc déjà ce cas de figure.
     */
    const EXPORT_MIN_RATIO = 3.2;

    /*
     * Largeur du PNG produit. Le tracé étant revectorisé (et non
     * ré-échantillonné), viser large ne coûte qu'un peu de mémoire.
     */
    const EXPORT_TARGET_W = 1200;

    /*
     * Largeur minimale du cadre, en fraction de la zone de dessin. Sans elle,
     * un point posé par mégarde deviendrait sa propre image et le PDF
     * l'agrandirait jusqu'à remplir la case. Une vraie signature, qui occupe
     * 80 à 90 % de la zone, n'est pas concernée.
     */
    const EXPORT_MIN_SPAN = 0.45;

    /**
     * Encombrement du tracé, en PIXELS de la zone de dessin.
     *
     * Ce moteur normalise par axe : les coordonnées ne décrivent une forme
     * qu'accompagnées des dimensions de leur zone. On repasse donc par les
     * pixels avant toute mesure. Le demi-trait est inclus, sans quoi le
     * recadrage couperait la moitié du trait de bord.
     */
    function inkBoundsPx() {
      const srcW = originalCanvas.width, srcH = originalCanvas.height;
      if (!paths.length || !(srcW > 0) || !(srcH > 0)) return null;

      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      for (const path of paths) {
        for (const q of path) {
          const px = q.x * srcW, py = q.y * srcH;
          if (px < minX) minX = px;
          if (px > maxX) maxX = px;
          if (py < minY) minY = py;
          if (py > maxY) maxY = py;
        }
      }
      if (!isFinite(minX)) return null;

      const strokePx = lineWidthFor(originalCanvas, BASE_EXPORT_LINE);
      const pad = strokePx / 2 + 0.008 * srcW;
      minX -= pad; maxX += pad;
      minY -= pad; maxY += pad;

      const span = maxX - minX;
      const minSpan = EXPORT_MIN_SPAN * srcW;
      if (span < minSpan) {
        const grow = (minSpan - span) / 2;
        minX -= grow; maxX += grow;
      }
      return { minX: minX, minY: minY, maxX: maxX, maxY: maxY, strokePx: strokePx };
    }

    function buildExportDataUrl() {
      // Signature reçue en IMAGE (rejeu hors-ligne) : aucun tracé à recadrer,
      // on rend le canvas tel quel, comme auparavant.
      if (!paths.length) {
        return originalCanvas.toDataURL();
      }

      const b = inkBoundsPx();
      if (!b) return originalCanvas.toDataURL();

      const bw = b.maxX - b.minX;
      const bh = b.maxY - b.minY;
      if (!(bw > 0) || !(bh > 0)) return originalCanvas.toDataURL();

      const pngRatio = Math.max(bw / bh, EXPORT_MIN_RATIO);
      const outW = EXPORT_TARGET_W;
      const outH = Math.max(1, Math.round(outW / pngRatio));

      const out = document.createElement("canvas");
      out.width = outW;
      out.height = outH;
      const ctx = out.getContext("2d");

      // Le tracé remplit la HAUTEUR et se centre horizontalement : quand le
      // plafond a élargi le cadre, ce sont bien deux marges latérales égales.
      const scale = outH / bh;
      const offX = (outW - bw * scale) / 2 - b.minX * scale;
      const offY = -b.minY * scale;
      const srcW = originalCanvas.width, srcH = originalCanvas.height;

      setupStroke(ctx, Math.max(1, b.strokePx * scale));
      for (const path of paths) {
        if (path.length < 2) continue;
        ctx.beginPath();
        ctx.moveTo(path[0].x * srcW * scale + offX, path[0].y * srcH * scale + offY);
        for (let i = 1; i < path.length; i++) {
          ctx.lineTo(path[i].x * srcW * scale + offX, path[i].y * srcH * scale + offY);
        }
        ctx.stroke();
      }
      return out.toDataURL();
    }

    setCanvasSize(originalCanvas, INITIAL_BASE_W, INITIAL_BASE_H);
    adaptCanvasSize();
    const baseContainer = originalCanvas.closest(".signature-container") || originalCanvas.parentElement;
    if (baseContainer && "ResizeObserver" in window) {
      new ResizeObserver(() => adaptCanvasSize()).observe(baseContainer);
    }
    window.addEventListener("load", adaptCanvasSize);

    /*
     * Bascule téléphone <-> ordinateur : la hauteur affichée change, le canvas
     * doit suivre immédiatement. Le `ResizeObserver` ci-dessus ne voit que la
     * LARGEUR du conteneur — inchangée quand seule la requête média bascule
     * (fenêtre étirée en hauteur, écran externe, rotation d'une tablette).
     */
    if (typeof wideScreenMQ.addEventListener === "function") {
      wideScreenMQ.addEventListener("change", adaptCanvasSize);
    } else if (typeof wideScreenMQ.addListener === "function") {
      wideScreenMQ.addListener(adaptCanvasSize); // Safari < 14
    }

    // ---------- Modale : taille d'après le rect réel du wrapper ----------
    const isMobilePhone = () => Math.min(window.innerWidth, window.innerHeight) <= 768;
    const isLandscape   = () => window.innerWidth > window.innerHeight;

    function sizeModalCanvas() {
      const wrapper = root.querySelector(".cri-canvas-wrapper");
      if (!wrapper) return;
      const r  = wrapper.getBoundingClientRect();
      const cs = getComputedStyle(wrapper);
      const padX = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight)  || 0);
      const padY = (parseFloat(cs.paddingTop)  || 0) + (parseFloat(cs.paddingBottom) || 0);
      let w = Math.max(200, Math.floor(r.width  - padX));
      let h = Math.max(100, Math.floor(r.height - padY));
      /*
       * Même ratio que la BANDE de base : ce qu'on trace dans la grande fenêtre
       * a exactement la forme de ce qui sera reporté, puis imprimé. Le ratio
       * est calculé sur le canvas réellement affiché (largeur courante sur
       * hauteur fixe) — plus aucune mesure figée à l'initialisation.
       */
      const bandRatio = Math.max(
        1.5,
        (parseInt(originalCanvas.style.width, 10) || INITIAL_BASE_W) / displayBaseH()
      );
      if (w / h > bandRatio) w = Math.floor(h * bandRatio); else h = Math.floor(w / bandRatio);
      setCanvasSize(modalCanvas, w, h);
      renderHistoryOn(modalCanvas, modalCtx, MODAL_LINE);
    }

    let refreshQueued = false;
    function refreshModalLayout() {
      if (!modalIsOpen || refreshQueued) return;
      refreshQueued = true;
      // deux frames pour laisser le reflow (pivot mobile) se stabiliser
      requestAnimationFrame(() => requestAnimationFrame(() => {
        refreshQueued = false;
        if (!modalIsOpen) return;
        if (isMobilePhone() && !isLandscape()) {
          rotateGate?.classList.add("show");
        } else {
          rotateGate?.classList.remove("show");
          sizeModalCanvas();
        }
      }));
    }

    // ---------- Dessin ----------
    // `drawing`, `activeCanvas` et `lastNorm` sont déclarés en tête du moteur
    // (cf. le commentaire là-bas) : adaptCanvasSize() les lit dès la première
    // passe, bien avant ce bloc.

    function start(e, canvas) {
      e.preventDefault();
      if (canvas === modalCanvas && rotateGate && rotateGate.classList.contains("show")) return;
      drawing = true;
      activeCanvas = canvas;
      lastNorm = getNorm(e, canvas);
      currentPath = [lastNorm];
      if (e.pointerId != null) { try { canvas.setPointerCapture(e.pointerId); } catch (err) {} }
    }

    function move(e) {
      if (!drawing || !activeCanvas) return;
      const p = getNorm(e, activeCanvas);
      if (activeCanvas === modalCanvas) {
        drawSegmentNorm(modalCtx, modalCanvas, lastNorm, p, MODAL_LINE);
      } else {
        drawSegmentNorm(originalCtx, originalCanvas, lastNorm, p, BASE_LINE);
      }
      lastNorm = p;
      if (currentPath) currentPath.push(p);
    }

    function end(e) {
      if (!drawing) return;
      drawing = false;
      if (currentPath && currentPath.length > 1) paths.push(currentPath);
      currentPath = null;
      if (e && e.pointerId != null && activeCanvas) {
        try { activeCanvas.releasePointerCapture(e.pointerId); } catch (err) {}
      }
    }

    function bindCanvas(canvas) {
      canvas.addEventListener("pointerdown", (e) => start(e, canvas));
      canvas.addEventListener("pointermove", move);
      canvas.addEventListener("pointerup",   end);
      canvas.addEventListener("pointercancel", end);
      canvas.addEventListener("touchstart", e => e.preventDefault(), { passive: false });
      canvas.addEventListener("touchmove",  e => e.preventDefault(), { passive: false });
    }
    bindCanvas(originalCanvas);
    bindCanvas(modalCanvas);

    // ---------- Effacer ----------
    function wipeAll() {
      paths = [];
      currentPath = null;
      originalCtx.setTransform(1, 0, 0, 1, 0, 0);
      originalCtx.clearRect(0, 0, originalCanvas.width, originalCanvas.height);
      modalCtx.setTransform(1, 0, 0, 1, 0, 0);
      modalCtx.clearRect(0, 0, modalCanvas.width, modalCanvas.height);
    }

    if (btnClearBase) {
      btnClearBase.addEventListener("click", () => {
        wipeAll();
        // Le champ de CE formulaire : en double dans la page, celui du document
        // serait la copie cachée (cf. la résolution de `root` plus haut).
        const clearForm = root.closest("form");
        const hidden = clearForm
          ? clearForm.querySelector("#sig-dataUrl")
          : document.getElementById("sig-dataUrl");
        if (hidden) hidden.value = "";
      });
    }
    if (btnClearModal) {
      btnClearModal.addEventListener("click", () => { wipeAll(); });
    }

    // ---------- Ouverture / fermeture modale ----------
    if (btnZoom) {
      btnZoom.addEventListener("click", () => {
        modalIsOpen = true;
        document.documentElement.classList.add("no-scroll");
        modalOverlay.classList.add("active");
        modalOverlay.removeAttribute("aria-hidden");
        modalOverlay.removeAttribute("inert");
        setTimeout(() => {
          const focusTarget = rotateCloseBtn || btnCancel || btnValidate || modalOverlay;
          if (focusTarget && typeof focusTarget.focus === "function") { try { focusTarget.focus(); } catch (e) {} }
        }, 0);
        refreshModalLayout();
      });
    }

    function closeModal() {
      modalIsOpen = false;
      try {
        const ae = document.activeElement;
        if (ae && modalOverlay && modalOverlay.contains(ae)) {
          if (btnZoom && typeof btnZoom.focus === "function") { btnZoom.focus(); }
        }
      } catch (e) {}
      modalOverlay.classList.remove("active");
      rotateGate?.classList.remove("show");
      document.documentElement.classList.remove("no-scroll");
      modalOverlay?.setAttribute("aria-hidden", "true");
      modalOverlay?.setAttribute("inert", "");
    }
    if (btnCancel) btnCancel.addEventListener("click", closeModal);

    // Fermer le message pivot sans tourner : signature possible en portrait (échappatoire)
    if (rotateCloseBtn) {
      rotateCloseBtn.addEventListener("click", () => {
        rotateGate?.classList.remove("show");
        sizeModalCanvas();
      });
    }

    // ---------- Valider : re-rendu vectoriel sur la base ----------
    if (btnValidate) {
      btnValidate.addEventListener("click", () => {
        renderHistoryOn(originalCanvas, originalCtx, BASE_EXPORT_LINE);
        closeModal();
      });
    }

    // ---------- Écoutes globales ----------
    window.addEventListener("orientationchange", () => {
      adaptCanvasSize();
      refreshModalLayout();
      // certains navigateurs mobiles ne stabilisent le viewport qu'après coup
      setTimeout(() => { adaptCanvasSize(); refreshModalLayout(); }, 250);
    });
    window.addEventListener("resize", () => {
      adaptCanvasSize();
      refreshModalLayout();
    });
    if (window.visualViewport) {
      window.visualViewport.addEventListener("resize", refreshModalLayout);
    }

    // Champ hidden
    /*
     * Bouton et champ cherchés dans LE FORMULAIRE de cette copie, jamais dans
     * le document : `sig-submitBtn` et `sig-dataUrl` existent en double quand
     * le formulaire l'est. `getElementById` accrochait alors le clic au bouton
     * de la copie CACHÉE — jamais cliqué — et le formulaire visible partait
     * avec un champ signature VIDE : bon signé sans la signature que le client
     * venait pourtant de tracer.
     */
    const sigForm    = root.closest("form");
    const submitBtn  = sigForm ? sigForm.querySelector("#sig-submitBtn") : document.getElementById("sig-submitBtn");
    const hiddenArea = sigForm ? sigForm.querySelector("#sig-dataUrl")   : document.getElementById("sig-dataUrl");
    if (submitBtn && hiddenArea && !submitBtn.dataset.sigInit) {
      submitBtn.dataset.sigInit = "1";
      submitBtn.addEventListener("click", function () {
        // Jamais le canvas affiché : cf. buildExportDataUrl(). La zone visible
        // est plus haute sur tablette et ordinateur, le PNG du PDF ne l'est pas.
        hiddenArea.value = buildExportDataUrl();
      });
    }

    // Anti double-tap zoom iOS (une seule fois par page)
    if (!document.documentElement.dataset.sigNoDoubleTap) {
      document.documentElement.dataset.sigNoDoubleTap = "1";
      document.addEventListener("touchend", (function () {
        let last = 0;
        return function (e) {
          /*
           * Les CONTRÔLES ne sont jamais avalés.
           *
           * Ce garde-fou vise le double-tap de zoom d'iOS sur la zone de
           * tracé. Mais il neutralisait TOUT tap survenant moins de 300 ms
           * après le précédent — donc le tap sur « Valider » qui suit
           * immédiatement la fin du tracé, et chaque re-tap impatient qui
           * suivait. D'où des boutons qu'il fallait presser trois fois avant
           * qu'une pause suffisante ne laisse enfin passer le clic.
           */
          if (e.target && typeof e.target.closest === "function"
              && e.target.closest("button, a, input, select, textarea, label, [role=button]")) {
            last = Date.now();
            return;
          }
          const now = Date.now();
          if (now - last < 300) e.preventDefault();
          last = now;
        };
      })(), { passive: false });
    }
  })();
}

// --------- Signature BL/Rapport lors de l'ajout d'une tâche ---------
(function(){
  const root = (typeof GLPI_PLUG_GESTION !== 'undefined' && typeof GLPI_PLUG_GESTION === 'string' && GLPI_PLUG_GESTION.trim())
    ? GLPI_PLUG_GESTION
    : ((window.CFG_GLPI && window.CFG_GLPI.root_doc) ? (window.CFG_GLPI.root_doc + '/plugins/gestion') : '/glpi/plugins/gestion');

  let inflight = false;

  function isTaskForm(form) {
    if (!form) return false;
    if (!form.closest('.itiltask')) return false;
    if (!form.querySelector('[name=\"state\"]')) return false;
    if (!form.querySelector('[name=\"users_id_tech\"]')) return false;
    return true;
  }

  function isAddTaskForm(form) {
    return isTaskForm(form) && !!form.querySelector('button[name=\"add\"]');
  }

  function getTicketId(form) {
    const input = form.querySelector('input[name=\"tickets_id\"]') || form.querySelector('input[name=\"items_id\"]') || form.querySelector('input[name=\"id\"]');
    if (!input) return 0;
    const v = parseInt(input.value, 10);
    return isNaN(v) ? 0 : v;
  }

  function getTaskState(form) {
    const sel = form.querySelector('[name=\"state\"]');
    if (!sel) return null;
    const v = parseInt(sel.value, 10);
    return isNaN(v) ? null : v;
  }

  function fetchContext(ticketId, state) {
    return new Promise((resolve, reject) => {
      $.ajax({
        url: root + '/ajax/task_signature_context.php',
        method: 'POST',
        dataType: 'json',
        data: { ticket_id: ticketId, state: state },
        timeout: 8000
      }).done(resolve).fail(reject);
    });
  }

  function setPending(ticketId, state) {
    try {
      const payload = { ticket_id: ticketId, state: state, ts: Date.now() };
      sessionStorage.setItem('gestion_task_sig_pending', JSON.stringify(payload));
    } catch (e) {}
  }

  function readPending() {
    try {
      const raw = sessionStorage.getItem('gestion_task_sig_pending');
      if (!raw) return null;
      const data = JSON.parse(raw);
      return data && typeof data === 'object' ? data : null;
    } catch (e) {
      return null;
    }
  }

  function clearPending() {
    try { sessionStorage.removeItem('gestion_task_sig_pending'); } catch (e) {}
  }

  function isTicketPage() {
    const path = (location && location.pathname) ? location.pathname : '';
    return /ticket\.form\.php$/i.test(path) || /\/front\/ticket\.form\.php$/i.test(path) || /ticket\.form\.php/i.test(path);
  }

  function injectHtmlWithScripts($container, html) {
    $container.html(html);
    $container.find('script').each(function(){
      const src = this.getAttribute('src');
      if (src) {
        // Déjà chargé globalement, on évite les doublons.
        return;
      }
      const code = this.text || this.textContent || this.innerText || '';
      if (code.trim() !== '') {
        try { (0, eval)(code); } catch (e) { console.error(e); }
      }
    });
  }

  function openSignatureModal(ctx) {
    const modalId = 'gestion-task-signature-dialog';
    const hasMultipleBL = Array.isArray(ctx.bls) && ctx.bls.length > 1;

    let blOptions = '';
    if (Array.isArray(ctx.bls)) {
      ctx.bls.forEach(b => {
        const sel = (b.id === ctx.default_bl_id) ? ' selected' : '';
        blOptions += `<option value=\"${b.id}\"${sel}>${b.label}</option>`;
      });
    }

    const defaultMode = ctx.default_mode || (ctx.rp_active ? 'both' : 'bl');
    const radioHtml = ctx.rp_active ? `
      <div class="mb-3">
        <label class="form-label mb-1">Mode de signature</label>
        <div class="d-flex flex-wrap gap-3">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="gestion_sig_mode" id="gestion_sig_mode_rp" value="rp" ${defaultMode === 'rp' ? 'checked' : ''}>
            <label class="form-check-label" for="gestion_sig_mode_rp">Signature Rapport</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="gestion_sig_mode" id="gestion_sig_mode_both" value="both" ${defaultMode === 'both' ? 'checked' : ''}>
            <label class="form-check-label" for="gestion_sig_mode_both">Signature Rapport + BL</label>
          </div>
        </div>
      </div>
    ` : '';

    const blSelectHtml = hasMultipleBL ? `
      <div class=\"mb-3\">
        <label class=\"form-label mb-1\">Sélection du BL</label>
        <select id=\"gestion_sig_bl_select\" class=\"form-select\">${blOptions}</select>
      </div>
    ` : '';

    const loadingHtml = `
      <div class="d-flex align-items-center gap-2 text-muted">
        <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
        <span>Chargement...</span>
      </div>
    `;

    const body = `
      <div id=\"gestion-task-signature-modal\" data-ticket-id=\"${ctx.ticket_id}\">
        ${radioHtml}
        ${blSelectHtml}
        <div id=\"gestion-task-signature-container\">
          ${loadingHtml}
        </div>
      </div>
    `;

    glpi_html_dialog({
      title: defaultMode === 'both'
         ? 'Signature Rapport + BL'
         : (defaultMode === 'rp' ? 'Signature du rapport' : 'Signature du bon de livraison'),
      body: body,
      id: modalId,
      show: function(){
        const $modal = $('#gestion-task-signature-modal');
        const $container = $('#gestion-task-signature-container');
        const $blSelect = $('#gestion_sig_bl_select');

        function currentMode() {
          if (!ctx.rp_active) return 'bl';
          const v = $modal.find('input[name=\"gestion_sig_mode\"]:checked').val();
          return v || defaultMode;
        }

        function currentBlId() {
          const v = $blSelect.length ? parseInt($blSelect.val(), 10) : (ctx.default_bl_id || 0);
          return isNaN(v) ? 0 : v;
        }

        function loadForm() {
          const mode = currentMode();
          const blId = currentBlId();

          // BL requis sauf mode RP
          if (mode !== 'rp' && !blId) {
            $container.html('<div class=\"alert alert-warning\">BL manquant.</div>');
            return;
          }

          // Afficher/masquer le dropdown BL selon le mode
          if ($blSelect.length) {
            $blSelect.closest('.mb-3').toggle(mode !== 'rp');
          }

          $container.html(loadingHtml);
          $.ajax({
            url: root + '/ajax/task_signature_form.php',
            method: 'POST',
            data: { ticket_id: ctx.ticket_id, mode: mode, bl_id: blId },
            dataType: 'html'
          }).done(function(html){
            if (!html || !String(html).trim()) {
              $container.html('<div class=\"alert alert-warning\">Réponse vide du serveur.</div>');
              return;
            }
            injectHtmlWithScripts($container, html);
          }).fail(function(xhr, status, err){
            const details = (xhr && xhr.responseText) ? String(xhr.responseText).slice(0, 600) : '';
            const msg = '<div class=\"alert alert-danger\">Erreur de chargement du formulaire'
              + (status ? ' (' + status + ')' : '') + '.</div>'
              + (details ? '<pre style=\"white-space:pre-wrap; font-size:12px; margin-top:6px;\">' + details + '</pre>' : '');
            $container.html(msg);
          });
        }

        $modal.on('change', 'input[name=\"gestion_sig_mode\"]', function(){ loadForm(); });
        $blSelect.on('change', function(){ loadForm(); });

        // Chargement initial
        loadForm();
      }
    });
  }

  $(document).on('submit', 'form', function(){
    const form = this;
    if (!isAddTaskForm(form)) return;

    const ticketId = getTicketId(form);
    const state = getTaskState(form);
    if (!ticketId || state === null) return;
    setPending(ticketId, state);
  });

  // Ouverture différée après l'enregistrement (après reload)
  $(function(){
    const pending = readPending();
    if (!pending || !pending.ticket_id) return;

    if (!isTicketPage()) {
      return; // attendre d'être sur la page ticket
    }

    const now = Date.now();
    const age = (pending.ts && typeof pending.ts === 'number') ? (now - pending.ts) : 0;
    if (age > 120000) { // 2 minutes
      clearPending();
      return;
    }

    // Consommer avant d'ouvrir pour éviter les boucles
    clearPending();

    if (inflight) return;
    inflight = true;

    fetchContext(pending.ticket_id, pending.state)
      .then(function(ctx){
        inflight = false;
        if (ctx && ctx.ok && ctx.show && typeof gestion_loadCriForm === 'function') {
          // MEME modal que l'onglet « Gestion BL » et survey.form.php : on passe par
          // gestion_loadCriForm -> cri.php (radio Rapport / Rapport+BL + formulaire
          // combine si 1 BL, ou groupe avec cases a cocher si >=2). Logique unique, identique partout.
          var blId = ctx.default_bl_id || (Array.isArray(ctx.bls) && ctx.bls[0] ? ctx.bls[0].id : 0);
          gestion_loadCriForm('showCriForm', String(blId), { job: ctx.ticket_id, root_doc: root, root_modal: 'ticket-form' });
        }
      })
      .catch(function(){
        inflight = false;
      });
  });
})();

// --------- Lien BL cliquable dans le planning GLPI ---------
(function(){
  const PLANNING_INIT_GUARD = '__gestion_planning_bl_init__';
  if (window[PLANNING_INIT_GUARD]) {
    return;
  }
  window[PLANNING_INIT_GUARD] = true;

  const root = (typeof GLPI_PLUG_GESTION !== 'undefined' && typeof GLPI_PLUG_GESTION === 'string' && GLPI_PLUG_GESTION.trim())
    ? GLPI_PLUG_GESTION
    : ((window.CFG_GLPI && window.CFG_GLPI.root_doc) ? (window.CFG_GLPI.root_doc + '/plugins/gestion') : '/glpi/plugins/gestion');

  const path = ((window.location && window.location.pathname) ? window.location.pathname : '').toLowerCase();
  const isPlanningPage = /\/front\/planning\.php$/.test(path) || path.indexOf('/front/planning.php') !== -1;
  const isExternalEventFormPage = /\/front\/planningexternalevent\.form\.php$/.test(path)
    || path.indexOf('/front/planningexternalevent.form.php') !== -1;
  if (!isPlanningPage && !isExternalEventFormPage) {
    return;
  }

  const BL_REGEX = /\bBL[\s-]?\d{6}\b/gi;
  const LINK_CLASS = 'gestion-planning-bl-link';
  const ACTION_LINK_CLASS = 'gestion-planning-bl-open';
  let featureEnabled = false;
  let scanQueued = false;
  let formRefreshTimer = null;

  function getCsrf() {
    const meta = document.querySelector('meta[property="glpi:csrf_token"]');
    if (meta && meta.content) return meta.content;
    const input = document.querySelector('input[name="_glpi_csrf_token"]');
    return input ? input.value : '';
  }

  function normalizeBL(raw) {
    if (!raw) return '';
    const compact = String(raw).toUpperCase().replace(/[\s-]/g, '');
    if (/^\d{6}$/.test(compact)) return 'BL' + compact;
    if (/^BL\d{6}$/.test(compact)) return compact;
    return '';
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function fetchFeatureFlag() {
    // Link activation remains client-side to avoid extra network roundtrip.
    return true;
  }

  function firstBLFromText(text) {
    const list = extractBLs(text || '');
    return list.length ? list[0] : '';
  }

  function normalizeCandidateFilename(name) {
    return String(name || '')
      .trim()
      .replace(/\.pdf$/i, '');
  }

  function pickBestDocumentCandidate(bl, items) {
    if (!Array.isArray(items) || !items.length) {
      return null;
    }

    const unsigned = items.filter(function(item) {
      return parseInt(item && item.signed, 10) !== 1;
    });
    const pool = unsigned.length ? unsigned : items;

    // 1) Exact BL match from text/id/filename
    const exact = pool.find(function(item) {
      const textBL = firstBLFromText(item && item.text);
      const idBL = firstBLFromText(item && item.id);
      const fileBL = firstBLFromText(normalizeCandidateFilename(item && item.filename));
      return textBL === bl || idBL === bl || fileBL === bl;
    });
    if (exact) {
      return exact;
    }

    // 2) Fallback to first result
    return pool[0] || null;
  }

  async function searchDocumentsForBL(bl) {
    const response = await fetch(root + '/ajax/ajax_search_pdf.php?q=' + encodeURIComponent(bl), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    if (!response.ok) {
      throw new Error('search_http_' + response.status);
    }
    let data = [];
    try {
      data = await response.json();
    } catch (e) {
      data = [];
    }
    return Array.isArray(data) ? data : [];
  }

  async function createSurveyFromCandidate(candidate, bl) {
    const save = String((candidate && candidate.save) || '').trim();
    const filenameRaw = String((candidate && candidate.filename) || '').trim();
    const folder = String((candidate && candidate.folder) || '').trim();
    const signed = parseInt((candidate && candidate.signed) || 0, 10) === 1 ? 1 : 0;
    const searchPdf = String((candidate && (candidate.text || candidate.id)) || bl).trim();
    const filename = filenameRaw || (bl + '.pdf');

    if (!save || !folder) {
      throw new Error('candidate_incomplete');
    }

    const csrf = getCsrf();
    const payload = new URLSearchParams();
    payload.append('save', save);
    payload.append('filename', filename);
    payload.append('folder', folder);
    payload.append('signed', String(signed));
    payload.append('search_pdf', searchPdf);
    payload.append('tickets_id', '0');
    payload.append('entities_id', '0');
    if (csrf) payload.append('_glpi_csrf_token', csrf);

    const response = await fetch(root + '/ajax/quick_add_survey_form.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign(
        {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        csrf ? { 'X-Glpi-Csrf-Token': csrf } : {}
      ),
      body: payload.toString()
    });

    let data = {};
    try {
      data = await response.json();
    } catch (e) {
      data = {};
    }

    if (!response.ok || !data || data.ok !== true) {
      const err = (data && (data.error || data.message)) ? String(data.error || data.message) : ('create_http_' + response.status);
      throw new Error(err);
    }

    return {
      survey_id: parseInt(data.id, 10) || 0,
      ticket_id: parseInt(data.tickets_id, 10) || 0,
      bl: data.bl || bl
    };
  }

  async function ensureSurveyForBL(bl, onStatus) {
    if (typeof onStatus === 'function') {
      onStatus('Recherche du BL en cours...');
    }

    // Search candidate docs then auto-create survey
    const items = await searchDocumentsForBL(bl);
    let candidate = pickBestDocumentCandidate(bl, items);

    // Fallback: force Sage creation from BL even if search list is empty
    if (!candidate) {
      candidate = {
        save: 'Sage',
        filename: bl + '.pdf',
        folder: bl,
        text: bl,
        id: bl,
        signed: 0
      };
    }

    if (typeof onStatus === 'function') {
      onStatus('Creation de la fiche signature...');
    }
    return createSurveyFromCandidate(candidate, bl);
  }

  function buildLinkedHtml(text) {
    if (!text || !BL_REGEX.test(text)) {
      BL_REGEX.lastIndex = 0;
      return null;
    }

    BL_REGEX.lastIndex = 0;
    let cursor = 0;
    let html = '';
    let matched = false;
    let match;
    while ((match = BL_REGEX.exec(text)) !== null) {
      matched = true;
      const raw = match[0];
      const normalized = normalizeBL(raw);
      const start = match.index;
      const end = start + raw.length;
      html += escapeHtml(text.slice(cursor, start));
      if (normalized) {
        html += '<a href="#" class="' + LINK_CLASS + '" data-bl="' + escapeHtml(normalized) + '">' + escapeHtml(raw) + '</a>';
      } else {
        html += escapeHtml(raw);
      }
      cursor = end;
    }
    html += escapeHtml(text.slice(cursor));
    return matched ? html : null;
  }

  function stripHtml(raw) {
    const tmp = document.createElement('div');
    tmp.innerHTML = String(raw || '');
    return (tmp.textContent || tmp.innerText || '').trim();
  }

  function extractBLs(rawText) {
    const text = String(rawText || '');
    if (!text) {
      return [];
    }
    BL_REGEX.lastIndex = 0;
    const seen = new Set();
    const result = [];
    let match;
    while ((match = BL_REGEX.exec(text)) !== null) {
      const bl = normalizeBL(match[0]);
      if (bl && !seen.has(bl)) {
        seen.add(bl);
        result.push(bl);
      }
    }
    BL_REGEX.lastIndex = 0;
    return result;
  }

  function getRichFieldText(textarea) {
    if (!textarea) {
      return '';
    }

    const editorId = (textarea.getAttribute('id') || '').trim();
    if (editorId && typeof window.getRichTextEditorContent === 'function') {
      try {
        const html = window.getRichTextEditorContent(editorId);
        if (typeof html === 'string' && html.trim() !== '') {
          return stripHtml(html);
        }
      } catch (e) {
        // fallback on textarea value
      }
    }

    const value = String(textarea.value || '').trim();
    const host = textarea.closest('.form-field, .mb-3, .form-group, .col-10, .col-sm-8') || textarea.parentElement || document;

    const editable = host.querySelector('[contenteditable="true"]');
    if (editable) {
      const visibleText = (editable.innerText || editable.textContent || '').trim();
      if (visibleText) {
        return visibleText;
      }
    }

    const iframe = host.querySelector('iframe.tox-edit-area__iframe, .tox-edit-area iframe');
    if (iframe && iframe.contentDocument && iframe.contentDocument.body) {
      const iframeText = (iframe.contentDocument.body.innerText || iframe.contentDocument.body.textContent || '').trim();
      if (iframeText) {
        return iframeText;
      }
    }

    if (/<[a-z][\s\S]*>/i.test(value)) {
      return stripHtml(value);
    }
    return value;
  }

  function ensureActionContainer(textarea) {
    const host = textarea.closest('.form-field, .mb-3, .form-group, .col-10, .col-sm-8') || textarea.parentElement;
    if (!host) {
      return null;
    }

    let box = host.querySelector('.gestion-planning-bl-actions');
    if (!box) {
      box = document.createElement('div');
      box.className = 'gestion-planning-bl-actions mt-2 d-flex flex-wrap gap-2';
      box.style.display = 'none';
      host.appendChild(box);
    }
    return box;
  }

  function decoratePlanningDescriptionField(textarea) {
    if (!textarea || textarea.name !== 'text') {
      return;
    }

    const content = getRichFieldText(textarea);
    const bls = extractBLs(content);
    const box = ensureActionContainer(textarea);
    if (!box) {
      return;
    }

    const signature = bls.join('|');
    const previousSignature = box.dataset.gestionPlanningBLSignature || '';

    if (!bls.length) {
      if (previousSignature === '' && box.style.display === 'none') {
        return;
      }
      box.innerHTML = '';
      box.style.display = 'none';
      box.dataset.gestionPlanningBLSignature = '';
      return;
    }

    if (previousSignature === signature) {
      if (box.style.display === 'none') {
        box.style.display = '';
      }
      return;
    }

    box.innerHTML = bls.map(function(bl) {
      return '<a href="#" class="btn btn-primary ' + ACTION_LINK_CLASS + '" style="cursor:pointer;pointer-events:auto;" data-bl="' + escapeHtml(bl) + '">' +
        '<i class="ti ti-signature me-1"></i>Signer ' + escapeHtml(bl) +
      '</a>';
    }).join('');
    box.dataset.gestionPlanningBLSignature = signature;
    bindDirectActionLinks(box);
    box.style.display = '';
  }

  function scanPlanningFormsForBL(rootEl) {
    if (!featureEnabled) {
      return;
    }
    const scope = rootEl || document;
    const textareas = scope.querySelectorAll('textarea[name="text"]');
    textareas.forEach(function(textarea) {
      if (!textarea.dataset.gestionPlanningBLBind) {
        textarea.dataset.gestionPlanningBLBind = '1';
        ['input', 'change', 'keyup', 'blur'].forEach(function(evt) {
          textarea.addEventListener(evt, function() {
            decoratePlanningDescriptionField(textarea);
          });
        });
      }
      decoratePlanningDescriptionField(textarea);
    });
    bindDirectActionLinks(scope);
  }

  function decoratePlanningCell(el) {
    if (!el || el.tagName === 'A' || el.children.length > 0) {
      return;
    }

    const currentText = (el.textContent || '').trim();
    if (!currentText) {
      return;
    }
    if (el.dataset.gestionPlanningLinkedText === currentText) {
      return;
    }

    const linkedHtml = buildLinkedHtml(currentText);
    if (!linkedHtml) {
      el.dataset.gestionPlanningLinkedText = currentText;
      return;
    }

    el.innerHTML = linkedHtml;
    el.dataset.gestionPlanningLinkedText = currentText;
  }

  function scanPlanningForBL() {
    if (!featureEnabled) {
      return;
    }
    const candidates = document.querySelectorAll('.fc-event-title, .fc-list-event-title');
    candidates.forEach(decoratePlanningCell);
  }

  function queueScan() {
    if (scanQueued) return;
    scanQueued = true;
    window.requestAnimationFrame(function() {
      scanQueued = false;
      scanPlanningForBL();
      scanPlanningFormsForBL(document);
      scanIframesForBL();
    });
  }

  const PROGRESS_MODAL_ID = 'gestion-planning-bl-progress';
  let inflightRequest = null;
  let inflightBL = '';

  function renderProgressBody(bl, message) {
    const safeBL = escapeHtml(bl || '');
    const safeMessage = escapeHtml(message || 'Chargement...');
    return ''
      + '<div class="d-flex align-items-center gap-2 py-2">'
      + '  <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>'
      + '  <div>'
      + '    <div class="fw-bold">Signature ' + safeBL + '</div>'
      + '    <div class="text-muted">' + safeMessage + '</div>'
      + '  </div>'
      + '</div>';
  }

  function showProgressModal(bl, message) {
    const body = renderProgressBody(bl, message);
    const existing = document.getElementById(PROGRESS_MODAL_ID);
    if (existing) {
      const target = existing.querySelector('.modal-body') || existing;
      target.innerHTML = body;
      return;
    }
    if (typeof glpi_html_dialog === 'function') {
      glpi_html_dialog({
        title: 'Traitement du bon de livraison',
        body: body,
        id: PROGRESS_MODAL_ID
      });
      return;
    }

    let overlay = document.getElementById(PROGRESS_MODAL_ID + '-fallback');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = PROGRESS_MODAL_ID + '-fallback';
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.background = 'rgba(0,0,0,0.35)';
      overlay.style.zIndex = '99999';
      overlay.style.display = 'flex';
      overlay.style.alignItems = 'center';
      overlay.style.justifyContent = 'center';
      overlay.innerHTML = '<div class="card shadow" style="min-width:320px;max-width:90vw;"><div class="card-body"></div></div>';
      document.body.appendChild(overlay);
    }
    const cardBody = overlay.querySelector('.card-body') || overlay;
    cardBody.innerHTML = body;
  }

  function closeProgressModal() {
    const modal = document.getElementById(PROGRESS_MODAL_ID);
    if (modal) {
      try {
        const $modal = $(modal);
        if ($modal && typeof $modal.modal === 'function') {
          $modal.modal('hide');
        }
      } catch (e) {
        // ignore and fallback on hard remove below
      }
    }
    window.setTimeout(function() {
      const stale = document.getElementById(PROGRESS_MODAL_ID);
      if (stale && stale.parentNode) {
        stale.parentNode.removeChild(stale);
      }
      const fallback = document.getElementById(PROGRESS_MODAL_ID + '-fallback');
      if (fallback && fallback.parentNode) {
        fallback.parentNode.removeChild(fallback);
      }
    }, 250);
  }

  function openSignatureModal(row) {
    const surveyId = parseInt(row.survey_id, 10);
    const ticketId = parseInt(row.ticket_id, 10) || 0;
    if (!surveyId) {
      alert('BL introuvable dans Gestion.');
      return;
    }

    const fallbackUrl = root + '/front/survey.form.php?id=' + encodeURIComponent(String(surveyId));

    if (typeof gestion_loadCriForm === 'function') {
      const params = {
        job: ticketId,
        root_doc: root,
        root_modal: 'planning-form',
        fallback_url: fallbackUrl,
        // `one_bl` : on a cliqué sur CE bon dans le planning. Lui seul est
        // précoché dans le formulaire de signature (cf. ajax/cri.php) ; les
        // autres bons du même ticket restent visibles et cochables.
        one_bl: 1
      };
      const fallbackTimer = window.setTimeout(function() {
        const modalShown = !!document.querySelector('#showCriForm.show, #showCriForm.modal.show');
        if (!modalShown) {
          window.location.href = fallbackUrl;
        }
      }, 3500);

      const clearTimerOnShow = window.setInterval(function() {
        const modalShown = !!document.querySelector('#showCriForm.show, #showCriForm.modal.show');
        if (modalShown) {
          window.clearTimeout(fallbackTimer);
          window.clearInterval(clearTimerOnShow);
        }
      }, 120);

      window.setTimeout(function() {
        window.clearInterval(clearTimerOnShow);
      }, 4000);

      gestion_loadCriForm('showCriForm', String(surveyId), params);
      return;
    }

    window.location.href = fallbackUrl;
  }

  async function onBLClick(bl) {
    if (inflightRequest) {
      showProgressModal(inflightBL || bl, 'Ouverture en cours...');
      return inflightRequest;
    }

    showProgressModal(bl, 'Demarrage...');
    inflightBL = bl;

    const task = (async function() {
    try {
      const row = await ensureSurveyForBL(bl, function(status) {
        showProgressModal(bl, status);
      });
      if (!row || !parseInt(row.survey_id, 10)) {
        closeProgressModal();
        alert('Impossible d\'ouvrir la signature pour ' + bl + '.');
        return;
      }
      showProgressModal(bl, 'Ouverture du formulaire...');
      closeProgressModal();
      openSignatureModal(row);
    } catch (error) {
      closeProgressModal();
      const code = String((error && error.message) || '');
      if (code === 'feature_disabled') {
        alert('La signature BL depuis le planning est desactivee dans la configuration du plugin.');
        return;
      }
      if (code === 'not_found') {
        alert('Aucun document trouve pour ' + bl + ' : BL deja en facture ou introuvable dans SAGE.');
        return;
      }
      if (code.indexOf('already') !== -1 || code.indexOf('deja') !== -1) {
        alert('Ce BL est deja signe.');
        return;
      }
      console.error(error);
      alert('Impossible d\'ouvrir la signature pour ' + bl + '.');
    } finally {
      inflightRequest = null;
      inflightBL = '';
    }
    })();

    inflightRequest = task;
    return task;
  }

  function handleBLActionClick(event, element) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const source = element || (event && event.currentTarget) || (event && event.target) || null;
    if (!source) {
      return;
    }
    const bl = normalizeBL(source.getAttribute('data-bl') || source.textContent || '');
    if (!bl) {
      return;
    }

    onBLClick(bl).catch(function(err) {
      console.error(err);
      alert('Erreur lors de l\'ouverture de la signature BL.');
    });
  }

  function bindDirectActionLinks(scope) {
    const rootEl = scope || document;
    const items = rootEl.querySelectorAll('a.' + ACTION_LINK_CLASS + ', button.' + ACTION_LINK_CLASS);
    items.forEach(function(item) {
      if (item.dataset.gestionPlanningBLDirectBind === '1') {
        return;
      }
      item.dataset.gestionPlanningBLDirectBind = '1';
      item.addEventListener('click', function(evt) {
        handleBLActionClick(evt, item);
      });
      item.addEventListener('keydown', function(evt) {
        if (evt.key === 'Enter' || evt.key === ' ') {
          handleBLActionClick(evt, item);
        }
      });
    });
  }

  document.addEventListener('click', function(event) {
    let link = event.target && event.target.closest ? event.target.closest('a.' + LINK_CLASS + ', button.' + LINK_CLASS) : null;
    if (!link && event.target && event.target.closest) {
      link = event.target.closest('a.' + ACTION_LINK_CLASS + ', button.' + ACTION_LINK_CLASS);
    }
    if (!link) return;
    handleBLActionClick(event, link);
  }, true);

  function startObserver() {
    const observer = new MutationObserver(function() {
      queueScan();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function scanIframesForBL() {
    const frames = document.querySelectorAll('iframe');
    frames.forEach(function(frame) {
      if (!frame.dataset.gestionPlanningBLFrameBind) {
        frame.dataset.gestionPlanningBLFrameBind = '1';
        frame.addEventListener('load', function() {
          try {
            const doc = frame.contentDocument;
            if (!doc) {
              return;
            }
            scanPlanningFormsForBL(doc);
            bindDirectActionLinks(doc);
          } catch (e) {
            // cross-origin or not ready
          }
        });
      }
      try {
        const doc = frame.contentDocument;
        if (!doc) {
          return;
        }
        scanPlanningFormsForBL(doc);
        bindDirectActionLinks(doc);
      } catch (e) {
        // cross-origin or not ready
      }
    });
  }

  function startFormRefresh() {
    if (formRefreshTimer) {
      return;
    }
    formRefreshTimer = window.setInterval(function() {
      if (!featureEnabled) {
        return;
      }
      if (document.hidden) {
        return;
      }
      scanPlanningFormsForBL(document);
      scanIframesForBL();
    }, 5000);
  }

  async function bootstrapPlanningBLLinks() {
    try {
      featureEnabled = await fetchFeatureFlag();
      if (!featureEnabled) return;
      queueScan();
      bindDirectActionLinks(document);
      scanIframesForBL();
      startObserver();
      startFormRefresh();
    } catch (e) {
      console.error('[gestion] planning BL links init failed', e);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapPlanningBLLinks);
  } else {
    bootstrapPlanningBLLinks();
  }
})();

// --------- Association automatique des BL en arriere-plan (a l'ouverture du ticket) ---------
// Declenche un appel asynchrone APRES le rendu de la fiche ticket : zero ralentissement.
// L'association reelle (extraction + Sage + dedoublon bl_number) est faite cote serveur.
(function(){
  const path = ((window.location && window.location.pathname) ? window.location.pathname : '').toLowerCase();
  if (path.indexOf('ticket.form.php') === -1) return;

  const params = new URLSearchParams(window.location.search || '');
  const tid = parseInt(params.get('id') || '0', 10);
  if (!tid || isNaN(tid)) return; // creation (id absent/0) => geree par le hook item_add

  if (window.__gestionAutoAssocDone) return; // une seule fois par chargement
  window.__gestionAutoAssocDone = true;

  const root = (typeof GLPI_PLUG_GESTION !== 'undefined' && typeof GLPI_PLUG_GESTION === 'string' && GLPI_PLUG_GESTION.trim())
    ? GLPI_PLUG_GESTION
    : ((window.CFG_GLPI && window.CFG_GLPI.root_doc) ? (window.CFG_GLPI.root_doc + '/plugins/gestion') : '/glpi/plugins/gestion');

  function run(){
    try {
      // $.ajax (jQuery) pour beneficier du token CSRF injecte globalement par GLPI
      // (ajaxSetup), comme les autres endpoints du plugin. fetch() natif ne recoit
      // PAS ce token => le CheckCsrfListener de GLPI 11 rejette la requete.
      $.ajax({
        url: root + '/ajax/auto_associate_bl.php',
        method: 'POST',
        data: { ticket_id: tid }
      });
    } catch (e) {}
  }

  // Apres le rendu, sans bloquer l'affichage.
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 3000 });
  } else {
    setTimeout(run, 1200);
  }
})();
