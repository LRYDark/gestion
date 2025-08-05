// Fonction d'initialisation globale appelée depuis le PHP
function initializeSignature(uniqId) {
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
    const TARGET_BASE_LINE   = 1.2;  // ta nouvelle épaisseur “en live” sur le canvas de base
    const VISUAL_MODAL_LINE  = 1.6;  // affichage modale (comme avant)
    const REF_BASE_EXPORT_LINE = 1.8; // ⬅️ épaisseur “cible” quand on revient de la modale vers la base
    let   exportLineCSS = TARGET_BASE_LINE;

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
      ctx.lineWidth = Math.max(1, cssLineWidth * s); // épaisseur bitmap
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
      ctx.lineWidth = Math.max(1, cssLineWidth * s);

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
      const ratio = (modalExportCanvas.width || 1) / (originalCanvas.width || 1);
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
