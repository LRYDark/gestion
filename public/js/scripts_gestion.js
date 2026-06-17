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
                      title: __('Gestion BL', 'gestion'),
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
function gestion_switchCombinedMode(radio) {
   try {
      var wrap = radio.closest ? radio.closest('[data-params]') : null;
      if (!wrap) { return; }
      var blId = wrap.getAttribute('data-bl');
      var params = {};
      try { params = JSON.parse(wrap.getAttribute('data-params') || '{}'); } catch (e) { params = {}; }
      var p = $.extend({}, params);
      delete p.force_rp; delete p.force_combined; delete p.force_bl;
      if (radio.value === 'rp') { p.force_rp = 1; } else { p.force_combined = 1; }
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

  function attachButtonHandler(remoteSignInstance) {
    const btn  = document.getElementById('remote-start');
    const sel  = document.getElementById('remote-device');
    const stat = document.getElementById('remote-status');
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
              const hiddenArea = document.getElementById("sig-dataUrl");
              if (hiddenArea) hiddenArea.value = sig.startsWith('data:') ? sig : ('data:image/png;base64,' + sig);
              const prev = document.getElementById("signature-preview");
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
  (function () {
    const root = document.getElementById(uniqId);
    if (!root) return;

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

    // Contexts
    const originalCtx = originalCanvas.getContext("2d");
    const modalCtx    = modalCanvas.getContext("2d");

    // Canvas d'export (offscreen)
    const modalExportCanvas = document.createElement("canvas");
    const modalExportCtx    = modalExportCanvas.getContext("2d");

    // États
    let modalIsOpen = false;
    let needModalResync = false; // resynchro forcée après pivot

    // Épaisseurs (px CSS)
    const TARGET_BASE_LINE   = 2.00;  // ta nouvelle épaisseur “en live” sur le canvas de base
    const VISUAL_MODAL_LINE  = 1.80;  // affichage modale (comme avant)
    const REF_BASE_EXPORT_LINE = 2.40; // ⬅️ épaisseur “cible” quand on revient de la modale vers la base
    let   exportLineCSS = TARGET_BASE_LINE;
    const MOBILE_TWEAK = 0.90;

    // ---- utilitaires ----
    function setup(ctx, lw) {
      ctx.strokeStyle = "#000";
      ctx.lineCap     = "round";
      ctx.lineJoin    = "round";
      ctx.lineWidth   = lw; // (sera recalculée en px bitmap quand on trace)
    }

    // Fixe taille CSS + bitmap + transform (DPR) — on ne s’appuie plus dessus pour l’épaisseur.
    function fixDPR(canvas, ctx, cssW, cssH) {
      const dpr = window.devicePixelRatio || 1;
      canvas.style.width  = cssW + "px";
      canvas.style.height = cssH + "px";
      canvas.width  = Math.round(cssW * dpr);
      canvas.height = Math.round(cssH * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.beginPath();
    }

    function clearCanvas(ctx, canvas) {
      const m = ctx.getTransform();
      ctx.setTransform(1,0,0,1,0,0);
      ctx.clearRect(0,0,canvas.width,canvas.height);
      ctx.setTransform(m);
      ctx.beginPath();
    }

    // ====== NOUVEAU : pipeline de dessin indépendante du DPR ======
    // facteur CSS→bitmap (pas besoin du DPR)
    function scaleCSS2BM(canvas) {
      const rectW = canvas.getBoundingClientRect().width || parseFloat(canvas.style.width) || 1;
      return canvas.width / rectW; // ex. dpr=3 ⇒ width bitmap = 3× rectW
    }

    // trace un segment (fromCSS -> toCSS) en pixels *bitmap* (épaisseur stable)
    function drawSegment(ctx, canvas, fromCSS, toCSS, cssLineWidth) {
      const s = scaleCSS2BM(canvas);
      const m = ctx.getTransform();
      ctx.setTransform(1,0,0,1,0,0);               // unité = pixel bitmap
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      const isModal = (canvas === modalCanvas || canvas === modalExportCanvas);
      ctx.lineWidth = Math.max(1, cssLineWidth * s * (isModal ? MOBILE_TWEAK : 1));
      ctx.beginPath();
      ctx.moveTo(fromCSS.x * s, fromCSS.y * s);
      ctx.lineTo(toCSS.x   * s, toCSS.y   * s);
      ctx.stroke();
      ctx.setTransform(m);
    }
    // =================================================================

    // ---------- Historique vectoriel ----------
    let paths = [];      // chaque path = [{x,y} …] coordonnées normalisées 0..1
    let currentPath = null;

    // coordonnées robustes : offsetX/offsetY si dispo (PointerEvent), sinon rect
    function getPos(e, canvas) {
      const p = e.touches?.[0] || e.changedTouches?.[0] || e;
      if (e.offsetX != null && e.offsetY != null && e.target === canvas) {
        return { x: e.offsetX, y: e.offsetY };
      }
      const rect = canvas.getBoundingClientRect();
      return { x: p.clientX - rect.left, y: p.clientY - rect.top };
    }
    function getPosNorm(e, canvas) {
      const rect = canvas.getBoundingClientRect();
      const p = e.touches?.[0] || e.changedTouches?.[0] || e;
      return { x: (p.clientX - rect.left) / (rect.width || 1),
               y: (p.clientY - rect.top)  / (rect.height || 1) };
    }

    // rendu vectoriel : même logique que drawSegment (épaisseur stable)
    function renderHistoryOn(canvas, ctx, cssLineWidth) {
      const m = ctx.getTransform();
      const s = scaleCSS2BM(canvas);
      ctx.setTransform(1,0,0,1,0,0);
      ctx.clearRect(0,0,canvas.width,canvas.height);
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.strokeStyle = "#000";
      ctx.lineCap="round"; ctx.lineJoin="round";
      const isModal = (canvas === modalCanvas || canvas === modalExportCanvas);
      ctx.lineWidth = Math.max(1, cssLineWidth * s * (isModal ? MOBILE_TWEAK : 1));

      const W = canvas.width, H = canvas.height;
      for (const path of paths) {
        if (path.length < 2) continue;
        ctx.beginPath();
        ctx.moveTo(path[0].x*W, path[0].y*H);
        for (let i=1;i<path.length;i++) ctx.lineTo(path[i].x*W, path[i].y*H);
        ctx.stroke();
      }
      ctx.setTransform(m);
    }

    function applyModalStyle() {
      setup(modalCtx, VISUAL_MODAL_LINE);
      const ratioRaw = (modalExportCanvas.width || 1) / (originalCanvas.width || 1);

      // ⬇️ garde-fou : évite d’amplifier/réduire trop l’épaisseur quand la modale est bien plus grande/petite
      const ratio = Math.min(1.15, Math.max(0.85, ratioRaw));

      exportLineCSS = Math.max(1, REF_BASE_EXPORT_LINE * ratio);
      setup(modalExportCtx, exportLineCSS);
    }

    // ---------- Base : init + ratio fixe ----------
    const initRect = originalCanvas.getBoundingClientRect();
    const INITIAL_BASE_CSS_W = Math.max(200, Math.round(initRect.width  || originalCanvas.clientWidth  || 320));
    const INITIAL_BASE_CSS_H = Math.max( 60, Math.round(initRect.height || originalCanvas.clientHeight ||  80));
    const BASE_ASPECT = INITIAL_BASE_CSS_W / INITIAL_BASE_CSS_H || 4;

    (function initBase() {
      fixDPR(originalCanvas, originalCtx, INITIAL_BASE_CSS_W, INITIAL_BASE_CSS_H);
      setup(originalCtx, TARGET_BASE_LINE);
    })();

    function adaptCanvasSize() {
      const container = originalCanvas.closest(".signature-container") || originalCanvas.parentElement;
      if (!container) return;
      const cs   = getComputedStyle(container);
      const padX = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
      const availW = Math.max(200, Math.floor(container.clientWidth - padX));
      const cssW = availW;
      const cssH = Math.max(60, Math.round(cssW / BASE_ASPECT));

      // sauvegarde bitmap
      const backup = document.createElement("canvas");
      backup.width  = originalCanvas.width;
      backup.height = originalCanvas.height;
      const hasSourceBitmap = originalCanvas.width > 0 && originalCanvas.height > 0;
      if (hasSourceBitmap) {
        backup.getContext("2d").drawImage(originalCanvas, 0, 0);
      }

      // resize + DPR
      fixDPR(originalCanvas, originalCtx, cssW, cssH);

      // restaure
      const m = originalCtx.getTransform();
      originalCtx.setTransform(1,0,0,1,0,0);
      if (backup.width > 0 && backup.height > 0) {
        originalCtx.drawImage(backup, 0,0, backup.width, backup.height,
                                      0,0, originalCanvas.width, originalCanvas.height);
      }
      originalCtx.setTransform(m);
      setup(originalCtx, TARGET_BASE_LINE);
    }

    // Appels init (aucun resize au clic)
    adaptCanvasSize();
    const baseContainer = originalCanvas.closest('.signature-container') || originalCanvas.parentElement;
    if (baseContainer && 'ResizeObserver' in window) {
      const ro = new ResizeObserver(() => adaptCanvasSize());
      ro.observe(baseContainer);
    }
    window.addEventListener('load', adaptCanvasSize);

    // ---------- Dessin (pipeline stable) ----------
    let currentCanvas = originalCanvas;
    let drawing = false;
    let lastPosCSS = {x:0,y:0};

    async function forceModalSyncSize() {
      // laisse iOS finir le reflow/DPR (2 frames)
      await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));

      const aspect  = BASE_ASPECT;
      const wrapper = root.querySelector(".cri-canvas-wrapper");
      const r = wrapper.getBoundingClientRect();
      const pad = 20;
      let w = Math.max(320, Math.floor(r.width  - pad*2));
      let h = Math.max(120, Math.floor(r.height - pad*2));
      if (w / h > aspect) w = Math.floor(h * aspect); else h = Math.floor(w / aspect);

      fixDPR(modalCanvas,       modalCtx,       w, h);
      fixDPR(modalExportCanvas, modalExportCtx, w, h);
      applyModalStyle();

      renderHistoryOn(modalCanvas,       modalCtx,       VISUAL_MODAL_LINE);
      renderHistoryOn(modalExportCanvas, modalExportCtx, exportLineCSS);

      needModalResync = false;
    }

    function start(e, canvas){
      e.preventDefault();

      const go = () => {
        currentCanvas = canvas;
        drawing = true;
        lastPosCSS = getPos(e, canvas);
        // historisation vectorielle
        currentPath = [ getPosNorm(e, canvas) ];
        if (e.pointerId != null) canvas.setPointerCapture(e.pointerId);
      };

      if (canvas === modalCanvas) {
        const p = needModalResync ? forceModalSyncSize() : Promise.resolve();
        p.then(()=>requestAnimationFrame(go));
        return;
      }

      // base : déjà redimensionné au chargement/observer
      requestAnimationFrame(go);
    }

    function move(e){
      if (!drawing) return;
      const pCSS = getPos(e, currentCanvas);

      if (currentCanvas === modalCanvas) {
        // VISUEL modale
        drawSegment(modalCtx, modalCanvas, lastPosCSS, pCSS, VISUAL_MODAL_LINE);
        // EXPORT modale (pour renvoyer vers base)
        drawSegment(modalExportCtx, modalExportCanvas, lastPosCSS, pCSS, exportLineCSS);
      } else {
        // BASE
        drawSegment(originalCtx, originalCanvas, lastPosCSS, pCSS, TARGET_BASE_LINE);
      }

      lastPosCSS = pCSS;
      // historisation vectorielle
      if (currentPath) currentPath.push(getPosNorm(e, currentCanvas));
    }

    function end(e){
      if (!drawing) return;
      drawing = false;
      if (currentPath && currentPath.length > 1) paths.push(currentPath);
      currentPath = null;
      if (e && e.pointerId != null) { try { currentCanvas.releasePointerCapture(e.pointerId); } catch {} }
    }

    function bindCanvas(canvas){
      canvas.addEventListener("pointerdown", (e)=>start(e, canvas));
      canvas.addEventListener("pointermove", move);
      canvas.addEventListener("pointerup",   end);
      canvas.addEventListener("pointercancel", end);
      canvas.addEventListener("touchstart", e=>e.preventDefault(), {passive:false});
      canvas.addEventListener("touchmove",  e=>e.preventDefault(), {passive:false});
    }
    bindCanvas(originalCanvas);
    bindCanvas(modalCanvas);

    // ---------- Effacer ----------
    function wipeAll() {
      paths = [];
      clearCanvas(originalCtx, originalCanvas);
      clearCanvas(modalCtx, modalCanvas);
      clearCanvas(modalExportCtx, modalExportCanvas);
      applyModalStyle();
    }

    if (btnClearBase) {
      btnClearBase.addEventListener("click", ()=>{
        wipeAll();
        const hidden = document.getElementById("sig-dataUrl"); if (hidden) hidden.value = "";
      });
    }
    if (btnClearModal) {
      btnClearModal.addEventListener("click", ()=>{ wipeAll(); });
    }

    // ---------- Orientation / tailles modale ----------
    const isMobilePhone = () => window.innerWidth <= 768;
    const isLandscape   = () => window.innerWidth > window.innerHeight;

    function sizeModalCanvasDesktop(){
      const wrapper = root.querySelector(".cri-canvas-wrapper");
      const r = wrapper.getBoundingClientRect();
      const pad = 20, aspect = BASE_ASPECT;
      let w = Math.max(360, Math.floor(r.width  - pad*2));
      let h = Math.max(120, Math.floor(r.height - pad*2));
      if (w/h > aspect) w = Math.floor(h*aspect); else h = Math.floor(w/aspect);
      fixDPR(modalCanvas,       modalCtx,       w, h);
      fixDPR(modalExportCanvas, modalExportCtx, w, h);
      applyModalStyle();
    }
    function sizeModalCanvasMobile(){
      const panelW = Math.max(100, root.querySelector(".cri-controls-panel")?.getBoundingClientRect().width || 120);
      const pad = 20, aspect = BASE_ASPECT;
      let availW = Math.max(320, window.innerWidth  - panelW - pad*2);
      let availH = Math.max(160, window.innerHeight - pad*2);
      let w = availW, h = availH;
      if (w/h > aspect) w = Math.floor(h*aspect); else h = Math.floor(w/aspect);
      fixDPR(modalCanvas,       modalCtx,       w, h);
      fixDPR(modalExportCanvas, modalExportCtx, w, h);
      applyModalStyle();
    }

    function copyToModal(){
      renderHistoryOn(modalCanvas,       modalCtx,       VISUAL_MODAL_LINE);
      renderHistoryOn(modalExportCanvas, modalExportCtx, exportLineCSS);
    }

    function handleOrientationAndResize(){
      if (!modalIsOpen) return;
      if (isMobilePhone() && isLandscape()) {
        rotateGate?.classList.remove("show"); sizeModalCanvasMobile(); copyToModal();
      } else if (isMobilePhone()) {
        rotateGate?.classList.add("show");
      } else {
        rotateGate?.classList.remove("show"); sizeModalCanvasDesktop(); copyToModal();
      }
      needModalResync = true; // on exigera une resynchro avant le prochain trait
    }

    if (rotateCloseBtn) {
      rotateCloseBtn.addEventListener("click", ()=>{
        rotateGate?.classList.remove("show");
        if (isMobilePhone()) { sizeModalCanvasMobile(); copyToModal(); }
        needModalResync = true;
      });
    }

    // ---------- Ouverture / fermeture modale ----------
    if (btnZoom) {
      btnZoom.addEventListener("click", ()=>{
        modalIsOpen = true;
        document.documentElement.classList.add("no-scroll");
        modalOverlay.classList.add("active");
        // A11y: rendre la modale interactive et visible pour les AT
        modalOverlay.removeAttribute('aria-hidden');
        modalOverlay.removeAttribute('inert');
        // Focus initial raisonnable à l'ouverture
        setTimeout(()=>{
          const focusTarget = rotateCloseBtn || btnCancel || btnValidate || modalOverlay;
          if (focusTarget && typeof focusTarget.focus === 'function') { try { focusTarget.focus(); } catch(e){} }
        }, 0);
        needModalResync = true;
        handleOrientationAndResize();
      });
    }
    function closeModal() {
      modalIsOpen = false;
      // A11y: si le focus est à l'intérieur, le déplacer hors de la modale
      try {
        const ae = document.activeElement;
        if (ae && modalOverlay && modalOverlay.contains(ae)) {
          if (btnZoom && typeof btnZoom.focus === 'function') { btnZoom.focus(); }
          else if (document.body && typeof document.body.focus === 'function') { document.body.focus(); }
        }
      } catch(e){}
      modalOverlay.classList.remove("active");
      rotateGate?.classList.remove("show");
      document.documentElement.classList.remove("no-scroll");
      // A11y: marquer comme caché/inactif
      modalOverlay?.setAttribute('aria-hidden', 'true');
      modalOverlay?.setAttribute('inert', '');
    }
    if (btnCancel) btnCancel.addEventListener("click", closeModal);

    // ---------- Valider : modale → base ----------
    if (btnValidate) {
      btnValidate.addEventListener("click", ()=>{
        const m = originalCtx.getTransform();
        originalCtx.setTransform(1,0,0,1,0,0);
        originalCtx.clearRect(0,0, originalCanvas.width, originalCanvas.height);
        originalCtx.imageSmoothingEnabled = true;
        originalCtx.imageSmoothingQuality = "high";
        originalCtx.drawImage(
          modalExportCanvas,
          0,0, modalExportCanvas.width, modalExportCanvas.height,
          0,0, originalCanvas.width, originalCanvas.height
        );
        originalCtx.setTransform(m);
        originalCtx.beginPath();
        closeModal();
      });
    }

    // ---------- Écoutes globales ----------
    window.addEventListener("orientationchange", ()=>{
      adaptCanvasSize();
      handleOrientationAndResize();
      needModalResync = true;
      setTimeout(()=>{ adaptCanvasSize(); handleOrientationAndResize(); needModalResync = true; }, 150);
    });
    window.addEventListener("resize", ()=>{
      adaptCanvasSize();
      handleOrientationAndResize();
      needModalResync = true;
    });

    // Champ hidden
    const submitBtn  = document.getElementById("sig-submitBtn");
    const hiddenArea = document.getElementById("sig-dataUrl");
    if (submitBtn && hiddenArea && !submitBtn.dataset.sigInit) {
      submitBtn.dataset.sigInit = "1";
      submitBtn.addEventListener("click", function () {
        hiddenArea.value = originalCanvas.toDataURL();
      });
    }

    // Anti double-tap zoom iOS
    document.addEventListener("touchend", (function(){ let last=0; return function(e){ const now=Date.now(); if (now-last<300) e.preventDefault(); last=now; }; })(), {passive:false});
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
      title: 'Gestion BL',
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
        title: 'Gestion BL',
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
        fallback_url: fallbackUrl
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
        alert('Aucun document trouve pour ' + bl + '.');
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
