function gestion_loadCriForm(action, modal, params) {
   var formInput;

   if (params.form != undefined) {
      formInput = getGestionFormData($('form[name="' + params.form + '"]'));
   }

   $.ajax({
      url: params.root_doc + '/ajax/cri.php',
      type: "POST",
      dataType: "html",
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
            if (!json.success) {
               $("#gestion_cri_error").html(json.message).show().delay(2000).fadeOut('slow');
            }

         } catch (err) {
            $('#' + modal).html(response);

            switch (action) {
               case 'saveCri':
                  // Ferme le modal et recharge la page
                  window.location.reload();
                  break;
               default:
                  // Ouvre le modal et force le style no-scroll avec JavaScript
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
      }
   });
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
    
    async create({ ticket_id, device_id, device_token, parameters }) {
      ticket_id = ticket_id || getTicketId();
      if (!ticket_id) throw new Error('Ticket introuvable (id manquant)');
      if (!device_id) throw new Error('Sélectionnez une tablette.');
      if (!device_token) throw new Error('Token de tablette manquant : re-sélectionnez la tablette.');
      
      const payload = { ticket_id, id: ticket_id, device_id, device_token };
      
      // Utiliser les paramètres fournis ou les paramètres automatiques
      let finalParameters = parameters;
      
      if (!finalParameters && typeof window.REMOTE_SIGN_AUTO_PARAMS === 'object' && window.REMOTE_SIGN_AUTO_PARAMS) {
        finalParameters = JSON.stringify(window.REMOTE_SIGN_AUTO_PARAMS);
      }
      
      if (finalParameters) {
        //payload.parameters = finalParameters;
        const json = JSON.stringify(window.REMOTE_SIGN_AUTO_PARAMS);
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
      if (device && device.device_id)   payload.device_id = device.device_id;
      if (device && device.device_token) payload.device_token = device.device_token;
      return postJSON(base + '/ajax/request_poll.php', payload);
    },

    async devicePoll({ device_id, device_token }) {
      return postJSON(base + '/ajax/device_poll.php', { device_id, device_token });
    },

    async deviceSubmit({ request_id, signature_base64, signer_name, device_id, device_token }) {
      return postJSON(base + '/ajax/device_submit.php', { request_id, signature: signature_base64, signer_name, device_id, device_token });
    }
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
        const opt          = sel.options[sel.selectedIndex];
        const device_id    = opt?.value || '';
        const device_token = opt?.getAttribute('data-token') || '';
        const tid          = getTicketId();
        
        if (stat) stat.textContent = "Envoi de la demande…";
        
        await remoteSignInstance.create({ ticket_id: tid, device_id, device_token });
        
        if (stat) stat.textContent = "En attente de la tablette…";

        let tries = 0;
        const loop = async ()=>{
          try {
            const r = await remoteSignInstance.pollTicket(tid, { device_id, device_token });
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
  // ---------- Capture photo (si présente) ----------
  const capturePhoto = document.getElementById("capture-photo");
  if (capturePhoto) {
    capturePhoto.addEventListener("change", function (event) {
      const file = event.target.files[0];
      if (!file) return;
      if (!file.type.startsWith("image/")) { alert("Le fichier sélectionné n'est pas une image."); return; }
      if (file.type !== "image/png" && file.type !== "image/jpeg") { alert("Le fichier doit être au format PNG ou JPEG."); return; }
      const reader = new FileReader();
      reader.onload = e => { const out = document.getElementById("photo-base64"); if (out) out.value = e.target.result; };
      reader.readAsDataURL(file);
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
      backup.getContext("2d").drawImage(originalCanvas, 0, 0);

      // resize + DPR
      fixDPR(originalCanvas, originalCtx, cssW, cssH);

      // restaure
      const m = originalCtx.getTransform();
      originalCtx.setTransform(1,0,0,1,0,0);
      originalCtx.drawImage(backup, 0,0, backup.width, backup.height,
                                    0,0, originalCanvas.width, originalCanvas.height);
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
        needModalResync = true;
        handleOrientationAndResize();
      });
    }
    function closeModal() {
      modalIsOpen = false;
      modalOverlay.classList.remove("active");
      rotateGate?.classList.remove("show");
      document.documentElement.classList.remove("no-scroll");
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
