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
        payload.parameters = finalParameters;
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