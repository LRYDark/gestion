/* global glpi_toast_success, glpi_toast_warning, glpi_toast_error, glpi_html_dialog */

/**
 * File d'attente des signatures hors-ligne.
 *
 * Le technicien arrive dans un bâtiment où le réseau ne passe pas. Le
 * formulaire de signature est déjà à l'écran, le client signe — et l'envoi part
 * dans le vide. Sans ce module, la signature est perdue et tout est à refaire.
 *
 * Ce fichier intercepte l'envoi, le tente réellement, et ne met en file QUE
 * s'il échoue. Le rejeu, plus tard, est la MÊME requête, vers la MÊME URL :
 * rien de la génération n'est réimplémenté ici.
 *
 * ── Un seul module actif, deux plugins ────────────────────────────────────
 * Les plugins RP et Gestion embarquent chacun leur copie de ce socle, pour
 * qu'aucun ne dépende de l'autre. Le premier chargé pose `window.GlpiSignOutbox`
 * et travaille ; le second se contente de déclarer sa présence. Même garde-fou
 * partagé que `window.__rpReloadScheduled`, déjà en usage entre les deux.
 *
 * La base IndexedDB, elle, est COMMUNE et porte le même nom des deux côtés :
 * si l'un des plugins est désinstallé, la copie de l'autre trouve et vide quand
 * même les signatures qu'il avait mises en file.
 *
 * ⚠ CE FICHIER EST LE JUMEAU EXACT de `rp/public/js/rp_outbox.js`,
 * aux deux constantes ci-dessous près. Les deux copies partagent la MÊME base et
 * le MÊME numéro de schéma : toute modification de l'une doit être reportée
 * telle quelle dans l'autre, sinon celle qui se charge en premier imposerait un
 * comportement que l'autre ne connaît pas.
 */
(function () {
   'use strict';

   var PLUGIN_KEY     = 'gestion';
   var META_NAME      = 'gestion:outbox';
   var MODULE_VERSION = 1;

   /**
    * Déclaration du plugin, posée par PHP dans une balise `<meta>`.
    * Absente = plugin actif mais file non configurée : on ne fait rien.
    */
   function readDeclaration() {
      var el = document.querySelector('meta[name="' + META_NAME + '"]');
      if (!el) {
         return null;
      }
      try {
         var decl = JSON.parse(el.getAttribute('content') || '{}');
         if (!decl || typeof decl.webdir !== 'string' || decl.webdir === '') {
            return null;
         }
         decl.key = PLUGIN_KEY;
         return decl;
      } catch (e) {
         return null;
      }
   }

   var declaration = readDeclaration();
   if (!declaration) {
      return;
   }

   /*
    * Navigateur trop ancien : on ne s'installe PAS.
    *
    * Sans `fetch`, l'envoi ne peut pas être intercepté ; sans `indexedDB`, rien
    * ne peut être mis en attente. Installer l'intercepteur quand même
    * remplacerait une soumission qui marche par une erreur — le pire des
    * échanges. Mieux vaut le comportement d'avant ce module, intact.
    */
   if (!window.fetch || !window.Promise || !window.indexedDB || !window.FormData) {
      return;
   }

   // Un socle est déjà en place (l'autre plugin s'est chargé avant) : on lui
   // signale seulement que ce plugin-ci est présent, et on s'arrête là.
   if (window.GlpiSignOutbox && window.GlpiSignOutbox.version >= MODULE_VERSION) {
      window.GlpiSignOutbox.declarePlugin(declaration);
      return;
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Stockage
    * ══════════════════════════════════════════════════════════════════════ */

   var DB_NAME     = 'glpi_signature_outbox';
   var DB_VERSION  = 1;
   var STORE_META  = 'meta';   // descripteurs légers : la liste se lit ici
   var STORE_ITEMS = 'items';  // charge utile complète : lue une par une
   var SCHEMA      = 1;

   /*
    * DEUX magasins, et non un seul.
    *
    * Une signature peut peser plusieurs mégaoctets : six photos et un PDF y
    * voyagent en base64. Tout mettre dans un seul magasin obligerait à charger
    * la totalité de la file en mémoire rien que pour l'afficher — de quoi faire
    * tomber l'onglet d'un téléphone. Les descripteurs sont donc à part, et la
    * charge utile n'est lue qu'au moment de l'envoyer.
    */

   var MAX_ITEMS       = 20;
   var MAX_TOTAL_BYTES = 60 * 1024 * 1024;
   var RETRY_QUIET_MS  = 30 * 1000;   // pas deux tentatives coup sur coup
   var SEND_TIMEOUT_MS = 45 * 1000;

   function openDb() {
      return new Promise(function (resolve, reject) {
         if (!window.indexedDB) {
            reject(new Error('IndexedDB indisponible'));
            return;
         }
         var req;
         try {
            req = window.indexedDB.open(DB_NAME, DB_VERSION);
         } catch (e) {
            reject(e);
            return;
         }
         req.onupgradeneeded = function () {
            var db = req.result;
            if (!db.objectStoreNames.contains(STORE_META)) {
               db.createObjectStore(STORE_META, { keyPath: 'uid' });
            }
            if (!db.objectStoreNames.contains(STORE_ITEMS)) {
               db.createObjectStore(STORE_ITEMS, { keyPath: 'uid' });
            }
         };
         req.onsuccess = function () { resolve(req.result); };
         req.onerror = function () { reject(req.error || new Error('IndexedDB refusée')); };
         req.onblocked = function () { reject(new Error('IndexedDB bloquée')); };
      });
   }

   function tx(stores, mode, work) {
      return openDb().then(function (db) {
         return new Promise(function (resolve, reject) {
            var t = db.transaction(stores, mode);
            var out;
            t.oncomplete = function () { db.close(); resolve(out); };
            t.onerror = function () { db.close(); reject(t.error); };
            t.onabort = function () { db.close(); reject(t.error || new Error('Transaction annulée')); };
            try {
               out = work(t);
            } catch (e) {
               try { t.abort(); } catch (ignore) { /* déjà avortée */ }
               reject(e);
            }
         });
      });
   }

   function storePut(meta, item) {
      return tx([STORE_META, STORE_ITEMS], 'readwrite', function (t) {
         t.objectStore(STORE_META).put(meta);
         t.objectStore(STORE_ITEMS).put(item);
      });
   }

   /*
    * Les suites de requêtes se chaînent par `onsuccess`, PAS par des promesses.
    *
    * Une transaction IndexedDB se referme dès que la boucle d'événements
    * reprend la main sans requête en cours. Enchaîner un `put` dans un `.then()`
    * revient à parier sur le moment exact où le navigateur valide : le pari est
    * perdu sur certains, et l'écriture disparaît sans erreur. Dans le
    * gestionnaire `onsuccess`, la transaction est encore vivante par définition.
    */
   function storeListMeta() {
      var rows = [];
      return tx([STORE_META], 'readonly', function (t) {
         var req = t.objectStore(STORE_META).getAll();
         req.onsuccess = function () { rows = req.result || []; };
      }).then(function () {
         rows.sort(function (a, b) { return (a.created_ms || 0) - (b.created_ms || 0); });
         return rows;
      });
   }

   function storeGetItem(uid) {
      var found = null;
      return tx([STORE_ITEMS], 'readonly', function (t) {
         var req = t.objectStore(STORE_ITEMS).get(uid);
         req.onsuccess = function () { found = req.result || null; };
      }).then(function () { return found; });
   }

   function storePatchMeta(uid, patch) {
      return tx([STORE_META], 'readwrite', function (t) {
         var store = t.objectStore(STORE_META);
         var req = store.get(uid);
         req.onsuccess = function () {
            var row = req.result;
            if (!row) {
               return;
            }
            Object.keys(patch).forEach(function (k) { row[k] = patch[k]; });
            store.put(row);
         };
      });
   }

   function storeRemove(uid) {
      return tx([STORE_META, STORE_ITEMS], 'readwrite', function (t) {
         t.objectStore(STORE_META).delete(uid);
         t.objectStore(STORE_ITEMS).delete(uid);
      });
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Outils
    * ══════════════════════════════════════════════════════════════════════ */

   var plugins = {};

   function uuid() {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
         return window.crypto.randomUUID();
      }
      // Repli : `randomUUID` n'existe qu'en contexte sécurisé, et bien des GLPI
      // de réseau local sont servis en http.
      var bytes = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) {
         window.crypto.getRandomValues(bytes);
      } else {
         for (var i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
         }
      }
      bytes[6] = (bytes[6] & 0x0f) | 0x40;
      bytes[8] = (bytes[8] & 0x3f) | 0x80;
      var hex = [];
      for (var j = 0; j < 16; j++) {
         hex.push(('0' + bytes[j].toString(16)).slice(-2));
      }
      return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-'
         + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-'
         + hex.slice(10, 16).join('');
   }

   function escapeHtml(value) {
      return String(value === null || value === undefined ? '' : value)
         .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
         .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
   }

   function encodeFields(fields) {
      var parts = [];
      fields.forEach(function (pair) {
         parts.push(encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]));
      });
      return parts.join('&');
   }

   function bytesOf(fields) {
      var total = 0;
      fields.forEach(function (pair) {
         total += pair[0].length + pair[1].length + 2;
      });
      // Marge d'encodage : l'URL-encodage gonfle les données base64.
      return Math.round(total * 1.15);
   }

   function humanSize(bytes) {
      if (bytes < 1024) { return bytes + ' o'; }
      if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(0) + ' Ko'; }
      return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
   }

   function fieldValue(fields, name) {
      for (var i = 0; i < fields.length; i++) {
         if (fields[i][0] === name) {
            return fields[i][1];
         }
      }
      return '';
   }

   function formatStamp(iso) {
      var d = new Date(iso);
      if (isNaN(d.getTime())) {
         return '';
      }
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
         + ' à ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
   }

   /**
    * Message natif GLPI.
    *
    * Les fonctions de toast sont appelées par leur NOM, jamais via
    * `window['glpi_toast_' + kind]`.
    *
    * `js/glpi_dialog.js` les déclare avec `const` : elles vivent dans la portée
    * lexicale globale, mais ne sont PAS des propriétés de `window`. Les chercher
    * dans `window[...]` renvoyait donc toujours `undefined`, et AUCUN message
    * n'apparaissait jamais — ni la confirmation d'envoi, ni les avertissements.
    * Seul `glpi_html_dialog`, déclaré avec `var`, y figure.
    */
   function toast(kind, message) {
      var options = { delay: kind === 'success' ? 12000 : 20000 };
      try {
         if (kind === 'success' && typeof glpi_toast_success === 'function') {
            glpi_toast_success(message, 'Signatures', options); return;
         }
         if (kind === 'info' && typeof glpi_toast_info === 'function') {
            glpi_toast_info(message, 'Signatures', options); return;
         }
         if (kind === 'warning' && typeof glpi_toast_warning === 'function') {
            glpi_toast_warning(message, 'Signatures', options); return;
         }
         if (kind === 'error' && typeof glpi_toast_error === 'function') {
            glpi_toast_error(message, 'Signatures', options); return;
         }
      } catch (e) {
         // On ne laisse pas un défaut d'affichage interrompre le vidage.
      }
      // `glpi_dialog.js` est chargé sur toutes les pages (Html::includeHeader) :
      // ce repli ne sert qu'en cas de page très inhabituelle.
      if (window.console) {
         window.console.info('[signatures] ' + message.replace(/<[^>]*>/g, ''));
      }
   }

   /**
    * À quel plugin appartient une URL de traitement ?
    * C'est ce qui décide de la table d'idempotence qui la garde, et donc du
    * plugin dont dépend son rejeu.
    */
   function pluginOfAction(action) {
      var keys = Object.keys(plugins);
      for (var i = 0; i < keys.length; i++) {
         var decl = plugins[keys[i]];
         if (decl && decl.webdir && action.indexOf(decl.webdir + '/') === 0) {
            return keys[i];
         }
      }
      // Repli sur le chemin : un élément mis en file quand les DEUX plugins
      // étaient là doit rester reconnaissable même si l'un a disparu.
      var m = action.match(/\/plugins\/([a-z0-9_-]+)\//i);
      return m ? m[1].toLowerCase() : '';
   }

   function absoluteAction(form) {
      var action = form.getAttribute('action') || window.location.href;
      var a = document.createElement('a');
      a.href = action;
      return a.pathname + (a.search || '');
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Jeton CSRF
    * ══════════════════════════════════════════════════════════════════════ */

   var tokenCache = {};

   /*
    * GLPI 11 contrôle le jeton dans un écouteur du noyau, AVANT le script
    * (CheckCsrfListener). Signalée AJAX, la requête voit son jeton lu dans
    * l'en-tête `X-Glpi-Csrf-Token` et CONSERVÉ : un seul jeton suffit donc à
    * vider toute la file. Le jeton du `<meta>` de la page convient, sauf si la
    * page traîne ouverte depuis des heures et qu'il a été évincé — d'où le
    * point d'entrée `ajax/csrf.php` du plugin.
    */
   function metaToken() {
      var el = document.querySelector('meta[property="glpi:csrf_token"]');
      return el ? (el.getAttribute('content') || '') : '';
   }

   function freshToken(pluginKey, force) {
      if (!force && tokenCache[pluginKey]) {
         return Promise.resolve(tokenCache[pluginKey]);
      }
      var decl = plugins[pluginKey];
      if (!decl) {
         return Promise.resolve(metaToken());
      }
      return fetch(decl.webdir + '/ajax/csrf.php', {
         method: 'GET',
         credentials: 'same-origin',
         headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      }).then(function (res) {
         if (!res.ok) { throw new Error('csrf ' + res.status); }
         return res.json();
      }).then(function (json) {
         tokenCache[pluginKey] = (json && json.token) || metaToken();
         return tokenCache[pluginKey];
      }).catch(function () {
         return metaToken();
      });
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Envoi
    * ══════════════════════════════════════════════════════════════════════ */

   function postWithTimeout(url, body, headers, timeoutMs) {
      var controller = window.AbortController ? new AbortController() : null;
      var timer = null;

      var options = {
         method: 'POST',
         credentials: 'same-origin',
         headers: headers,
         body: body,
         redirect: 'follow'
      };
      if (controller) {
         options.signal = controller.signal;
      }

      var run = fetch(url, options);

      if (controller) {
         timer = window.setTimeout(function () { controller.abort(); }, timeoutMs);
      }

      return run.then(function (res) {
         if (timer) { window.clearTimeout(timer); }
         return res;
      }, function (err) {
         if (timer) { window.clearTimeout(timer); }
         throw err;
      });
   }

   /**
    * Interroge l'état des signatures auprès du plugin qui les garde.
    * @return {Promise<Object|null>} null si l'appel n'a pas abouti.
    */
   function fetchStates(pluginKey, uids) {
      var decl = plugins[pluginKey];
      if (!decl || !uids.length) {
         return Promise.resolve(null);
      }
      var url = decl.webdir + '/ajax/offline_status.php?uids=' + encodeURIComponent(uids.join(','));
      return fetch(url, {
         method: 'GET',
         credentials: 'same-origin',
         headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      }).then(function (res) {
         if (res.status === 401) { return { expired: true }; }
         if (!res.ok) { return null; }
         return res.json();
      }).then(function (json) {
         if (!json) { return null; }
         if (json.expired) { return { expired: true }; }
         return json.states || {};
      }).catch(function () {
         return null;
      });
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Mise en file
    * ══════════════════════════════════════════════════════════════════════ */

   function describe(form, fields, action) {
      var pluginKey = pluginOfAction(action);
      var ticket = fieldValue(fields, 'REPORT_ID') || fieldValue(fields, 'ticket_id') || '';
      var signature = fieldValue(fields, 'url');

      return {
         uid: '',
         schema: SCHEMA,
         plugin: pluginKey,
         ticket_id: ticket,
         signer: fieldValue(fields, 'name'),
         email: fieldValue(fields, 'email'),
         captured_at: new Date().toISOString(),
         created_ms: Date.now(),
         state: 'pending',
         attempts: 0,
         last_error: '',
         last_http: 0,
         last_try_ms: 0,
         size: bytesOf(fields),
         // Vignette : c'est ce que le technicien montre au client pour lui
         // prouver que sa signature est bien enregistrée.
         preview: (signature && signature.indexOf('data:image') === 0) ? signature : '',
         page_url: window.location.pathname + window.location.search,
         target_blank: form.target === '_blank'
      };
   }

   function enqueue(meta, action, fields) {
      return storeListMeta().then(function (existing) {
         if (existing.length >= MAX_ITEMS) {
            throw new Error('La file est pleine (' + MAX_ITEMS + ' signatures en attente).');
         }
         var total = existing.reduce(function (sum, m) { return sum + (m.size || 0); }, 0);
         if (total + meta.size > MAX_TOTAL_BYTES) {
            throw new Error('La file dépasse la place autorisée sur cet appareil.');
         }
         return storePut(meta, { uid: meta.uid, action: action, fields: fields });
      });
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Interception de la signature
    * ══════════════════════════════════════════════════════════════════════ */

   /*
    * Le rechargement différé des deux plugins est NEUTRALISÉ d'entrée.
    *
    * `scripts_rp.js` et `gestionAfterSubmit` programment tous deux un
    * rechargement de la page après un envoi vers un nouvel onglet, en se
    * partageant le témoin `window.__rpReloadScheduled`. Leur minuterie de
    * secours à 30 s effacerait l'écran de confirmation montré au client, et
    * leur rechargement au retour d'onglet ferait de même.
    *
    * Le témoin est posé DÈS LE CHARGEMENT, et non au moment de l'envoi : leurs
    * écouteurs sont enregistrés avant le nôtre (leur fichier est servi en
    * premier) et ont donc déjà programmé le rechargement quand nous prenons la
    * main. Trop tard pour les en empêcher après coup.
    *
    * Il n'est JAMAIS rendu. Une première version le remettait à `false` quand
    * ce module renonçait à intercepter (rapport d'atelier, plugin inconnu,
    * fichier joint), en comptant sur les deux plugins pour reprendre la main.
    * Mais l'écouteur de `scripts_rp.js` est enregistré AVANT le nôtre dès que
    * RP est chargé avant Gestion : il avait déjà lu le témoin à `true` et
    * renoncé quand nous le rendions. Résultat : le PDF s'ouvrait bien à côté,
    * mais le voile « Génération en cours » restait affiché sans fin et le
    * ticket ne se rafraîchissait jamais.
    *
    * Quand ce module renonce, c'est donc LUI qui retire le voile et recharge
    * la page (cf. nativeAfterSubmit) : le résultat ne dépend plus de l'ordre
    * de chargement des plugins.
    */
   window.__rpReloadScheduled = true;

   /**
    * Envoi laissé au navigateur : retirer le voile, puis recharger la page.
    *
    * Reprend à l'identique ce que `scripts_rp.js` et `gestionAfterSubmit`
    * font quand ce module n'est pas chargé. Le formulaire vise un nouvel
    * onglet : la page courante ne navigue pas, rien ne retirerait le voile ni
    * ne rafraîchirait le ticket. Le rechargement attend le retour sur cet
    * onglet — le document est alors produit par construction, c'est le moment
    * exact où l'état à jour intéresse le technicien. Filet à 30 s s'il ne
    * quitte jamais la page.
    *
    * Sans `target`, la page navigue d'elle-même : il n'y a rien à faire.
    */
   function nativeAfterSubmit(form) {
      if (!form || form.target !== '_blank') {
         return;
      }

      // Le voile a fait son office : le laisser tourner indéfiniment donnerait
      // l'impression d'une page bloquée.
      window.setTimeout(hideLoaders, 2000);

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

   function collectFields(form, submitter) {
      var data;
      try {
         data = new FormData(form, submitter || undefined);
      } catch (e) {
         data = new FormData(form);
         if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value || '');
         }
      }

      var fields = [];
      data.forEach(function (value, key) {
         /*
          * Le jeton CSRF n'est PAS mis en file : GLPI le consomme dès qu'il a
          * servi et ne le conserve que le temps de la session. Rejoué des
          * heures plus tard, il serait refusé. Le rejeu en demande un neuf.
          */
         if (key === '_glpi_csrf_token') {
            return;
         }
         // Les fichiers ne sont jamais nommés dans ces formulaires : photos et
         // PDF y voyagent déjà en base64 dans des `<textarea>`. Si l'un
         // apparaissait un jour, on refuserait la mise en file plutôt que de
         // stocker une signature amputée.
         if (typeof value !== 'string') {
            throw new Error('Ce formulaire contient un fichier joint : la mise en attente est impossible.');
         }
         fields.push([key, value]);
      });
      return fields;
   }

   function reenableSubmit(form) {
      var btn = form.querySelector('input[type=submit], button[type=submit]');
      if (!btn) {
         return;
      }
      btn.disabled = false;
      if (btn.tagName === 'INPUT' && btn.dataset.outboxLabel) {
         btn.value = btn.dataset.outboxLabel;
      }
   }

   function hideLoaders() {
      ['rp-loader', 'gestion-loader'].forEach(function (id) {
         var el = document.getElementById(id);
         if (el) { el.classList.remove('active'); }
      });
   }

   function onSubmit(event) {
      var form = event.target;
      if (!form || form.nodeName !== 'FORM' || form.getAttribute('name') !== 'formReport') {
         /*
          * Le rapport d'atelier de RP (`formPreparation`) n'est pas mis en
          * file : il part nativement, vers un nouvel onglet. `scripts_rp.js`
          * a posé son voile « Génération en cours » mais, témoin oblige, ne le
          * retirera pas : c'est à nous de le faire et de recharger le ticket.
          */
         if (form && form.nodeName === 'FORM' && form.getAttribute('name') === 'formPreparation') {
            nativeAfterSubmit(form);
         }
         return;
      }
      if (form.dataset.outboxHandled === '1') {
         event.preventDefault();
         return;
      }

      var action = absoluteAction(form);
      var pluginKey = pluginOfAction(action);
      if (!plugins[pluginKey]) {
         // Traitement d'un plugin qu'on ne connaît pas : on ne s'en mêle pas,
         // l'envoi natif suit son cours.
         nativeAfterSubmit(form);
         return;
      }

      var fields;
      try {
         fields = collectFields(form, event.submitter);
      } catch (e) {
         // Un fichier joint nommé, que la file ne saurait pas stocker. On laisse
         // partir nativement : mieux vaut le comportement d'avant ce module
         // qu'un envoi bloqué par lui.
         nativeAfterSubmit(form);
         return;
      }

      var meta = describe(form, fields, action);
      meta.uid = uuid();
      meta.plugin = pluginKey;

      var maxPost = plugins[pluginKey].post_max || 0;
      if (maxPost > 0 && meta.size > maxPost) {
         /*
          * Trop gros pour le serveur : le mettre en file ne ferait que repousser
          * un échec certain. On le dit tout de suite, tant que le technicien
          * peut encore retirer une photo.
          *
          * `stopPropagation` : le formulaire du plugin Gestion porte son PROPRE
          * contrôle de taille, qui afficherait la même alerte juste après. Sans
          * cela, le technicien la lirait deux fois de suite.
          */
         event.preventDefault();
         event.stopPropagation();
         if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
         }
         hideLoaders();
         reenableSubmit(form);
         window.alert(
            'Impossible de signer : les pièces jointes dépassent la limite du serveur ('
            + humanSize(meta.size) + ' envoyés pour ' + humanSize(maxPost) + ' maximum).'
            + '\n\nRetirez ou allégez des photos / PDF puis réessayez.'
         );
         return;
      }

      fields.push(['sign_uid', meta.uid]);
      fields.push(['sign_captured_at', meta.captured_at]);

      event.preventDefault();
      form.dataset.outboxHandled = '1';

      var btn = form.querySelector('input[type=submit]');
      if (btn && btn.tagName === 'INPUT' && !btn.dataset.outboxLabel) {
         btn.dataset.outboxLabel = btn.value;
      }

      /*
       * L'onglet du PDF est ouvert MAINTENANT, pas à la réponse.
       *
       * `window.open` n'est autorisé que pendant le geste de l'utilisateur.
       * Ouvert après le `fetch`, il serait bloqué par le navigateur et le
       * technicien ne verrait jamais son document. On ouvre donc un onglet vide
       * tout de suite, qu'on remplit ou qu'on referme selon l'issue.
       */
      var pdfTab = null;
      if (meta.target_blank) {
         try {
            pdfTab = window.open('', '_blank');
         } catch (e) {
            pdfTab = null;
         }
      }

      var token = metaToken();
      var headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };

      /*
       * PREMIER envoi : volontairement NON signalé AJAX.
       *
       * Le noyau lit alors le jeton dans le corps et le consomme, exactement
       * comme pour la soumission native qu'on remplace. Le serveur voit une
       * requête en tout point identique à celle d'avant ce module — c'est ce
       * qui rend le chemin nominal sans surprise. Seul le REJEU, qui n'a plus
       * de jeton valide en réserve, passe par la branche AJAX.
       */
      var body = encodeFields(fields) + '&_glpi_csrf_token=' + encodeURIComponent(token);

      postWithTimeout(action, body, headers, SEND_TIMEOUT_MS)
         .then(function (res) {
            if (res.status === 401 || res.status === 403) {
               throw Object.assign(new Error('refus ' + res.status), { httpStatus: res.status });
            }
            if (!res.ok) {
               throw Object.assign(new Error('erreur ' + res.status), { httpStatus: res.status });
            }
            return res.blob().then(function (blob) { return { res: res, blob: blob }; });
         })
         .then(function (out) {
            // Envoi abouti : on retrouve le comportement d'avant — le document
            // s'ouvre à côté, et la page se rafraîchit.
            var type = out.blob.type || '';
            if (pdfTab && type.indexOf('pdf') !== -1) {
               try {
                  pdfTab.location.href = URL.createObjectURL(out.blob);
               } catch (e) {
                  pdfTab.close();
               }
            } else if (pdfTab) {
               pdfTab.close();
            }
            hideLoaders();
            window.setTimeout(function () { window.location.reload(); }, 600);
         })
         .catch(function (err) {
            if (pdfTab) {
               try { pdfTab.close(); } catch (e) { /* déjà fermé */ }
            }
            hideLoaders();

            var status = err && err.httpStatus;
            if (status === 401 || status === 403) {
               /*
                * Refus du serveur, pas panne de réseau : la signature repartirait
                * à l'identique et serait refusée de la même façon. On ne met
                * donc RIEN en file, et on le dit — c'est une question de droits
                * ou de session, que seul le technicien peut régler.
                */
               form.dataset.outboxHandled = '';
               reenableSubmit(form);
               window.alert(
                  status === 401
                     ? 'Votre session GLPI a expiré. Reconnectez-vous dans un autre onglet, puis signez à nouveau.'
                     : "Le serveur a refusé la signature (droits insuffisants ou jeton expiré). Rechargez la page et réessayez."
               );
               return;
            }

            // Panne réseau ou délai dépassé : c'est le cas pour lequel tout ceci
            // existe.
            meta.state = 'pending';
            meta.attempts = 1;
            meta.last_error = (err && err.message) || 'Réseau indisponible';
            meta.last_try_ms = Date.now();

            enqueue(meta, action, fields).then(function () {
               showCapturedScreen(meta, form);
            }).catch(function (storeErr) {
               form.dataset.outboxHandled = '';
               reenableSubmit(form);
               window.alert(
                  "La signature n'a pas pu être envoyée, et cet appareil n'a pas pu la mettre en attente.\n\n"
                  + ((storeErr && storeErr.message) || '')
                  + "\n\nNe quittez pas cette page : réessayez dès que le réseau revient."
               );
            });
         });
   }

   document.addEventListener('submit', onSubmit, true);

   /* ══════════════════════════════════════════════════════════════════════
    *  Écran de confirmation montré au client
    * ══════════════════════════════════════════════════════════════════════ */

   /**
    * Le formulaire ne peut plus servir : sa signature est en file.
    *
    * Re-signer produirait une SECONDE signature, sous un autre identifiant —
    * donc, le jour où les deux partiraient, deux rapports et deux mails. Le
    * bouton reste donc désactivé, et il DIT pourquoi plutôt que de rester
    * grisé sans explication.
    */
   function markFormQueued(form) {
      if (!form) {
         return;
      }
      var btn = form.querySelector('input[type=submit], button[type=submit]');
      if (!btn) {
         return;
      }
      btn.disabled = true;
      if (btn.tagName === 'INPUT') {
         btn.value = 'Signature en attente d’envoi';
      } else {
         btn.textContent = 'Signature en attente d’envoi';
      }
   }

   /**
    * Le reçu montré au client, et rien d'autre.
    *
    * Il vient de signer et n'a RIEN : ni PDF, ni mail — ils ne partiront qu'au
    * retour du réseau. Cet écran est le seul justificatif qu'on puisse lui
    * donner sur place, d'où le ticket, l'heure et sa propre signature.
    *
    * Tout le reste en a été retiré. Un avertissement sur les données du
    * navigateur et un lien vers une « file d'attente » ne veulent rien dire pour
    * la personne qui signe, et transforment un reçu en écran technique au
    * moment précis où il faut inspirer confiance. Ces deux informations
    * s'adressent au TECHNICIEN, et le rejoignent au bon moment : dans le message
    * que GLPI lui affiche à la page suivante.
    */
   function showCapturedScreen(meta, form) {
      var old = document.getElementById('sign-outbox-captured');
      if (old) {
         old.parentNode.removeChild(old);
      }

      var wrap = document.createElement('div');
      wrap.id = 'sign-outbox-captured';
      wrap.className = 'sign-outbox-captured';
      wrap.setAttribute('role', 'dialog');
      wrap.setAttribute('aria-modal', 'true');

      wrap.innerHTML =
         '<div class="sign-outbox-captured-card">'
         + '  <div class="sign-outbox-captured-icon"><i class="ti ti-cloud-off"></i></div>'
         + '  <h2 class="sign-outbox-captured-title">Signature enregistrée</h2>'
         + '  <p class="sign-outbox-captured-lead">Le réseau est indisponible. La signature est conservée sur cet appareil'
         + '     et sera transmise automatiquement dès le retour de la connexion.</p>'
         + '  <dl class="sign-outbox-captured-facts">'
         + (meta.ticket_id ? '<dt>Ticket</dt><dd>#' + escapeHtml(meta.ticket_id) + '</dd>' : '')
         + (meta.signer ? '<dt>Signataire</dt><dd>' + escapeHtml(meta.signer) + '</dd>' : '')
         + '    <dt>Signée le</dt><dd>' + escapeHtml(formatStamp(meta.captured_at)) + '</dd>'
         + '  </dl>'
         + (meta.preview
            ? '<div class="sign-outbox-captured-sig"><img src="' + escapeHtml(meta.preview) + '" alt="Signature recueillie"></div>'
            : '')
         + '  <div class="sign-outbox-captured-actions">'
         + '    <button type="button" class="btn btn-primary btn-lg w-100" data-outbox-close="1">Fermer</button>'
         + '  </div>'
         + '</div>';

      wrap._outboxForm = form;
      document.body.appendChild(wrap);

      wrap.addEventListener('click', function (event) {
         if (!event.target || typeof event.target.closest !== 'function') {
            return;
         }
         if (event.target.closest('[data-outbox-close]')) {
            wrap.parentNode.removeChild(wrap);
            /*
             * On NE RECHARGE PAS la page.
             *
             * Le réseau vient précisément de tomber : un rechargement s'y
             * fracassait sur la page d'erreur du navigateur, et le technicien
             * perdait l'écran de confirmation qu'il montrait à son client. Rien
             * n'a d'ailleurs changé côté serveur — il n'y a rien à recharger.
             *
             * Le bouton d'envoi reste désactivé et le dit : re-signer créerait
             * une SECONDE signature, avec un autre identifiant, donc un second
             * rapport le jour où les deux partiraient.
             */
            markFormQueued(wrap._outboxForm);
         }
      });
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Rejeu
    * ══════════════════════════════════════════════════════════════════════ */

   var draining = false;
   var sessionExpired = false;

   function replayOne(meta) {
      return storeGetItem(meta.uid).then(function (item) {
         if (!item) {
            // Descripteur orphelin : la charge utile a disparu, il n'y a plus
            // rien à envoyer.
            return storeRemove(meta.uid).then(function () {
               return { uid: meta.uid, outcome: 'dropped' };
            });
         }

         function attempt(tok, isRetry) {
               /*
                * REJEU : signalé AJAX.
                *
                * Le noyau lit alors le jeton dans l'en-tête et le CONSERVE
                * (preserve_token), si bien qu'un seul jeton suffit à vider
                * toute la file. Aucune des cibles ne revérifie le jeton
                * elle-même : le contrôle du noyau est le seul, et il est
                * satisfait.
                */
               var headers = {
                  'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-Glpi-Csrf-Token': tok
               };
               /*
                * `sign_replayed` : le rejeu se PRÉSENTE comme tel.
                *
                * C'est ce qui permet au PDF d'imprimer la date de capture pour
                * TOUTE signature passée par la file — y compris rejouée une
                * minute après. Sans ce témoin, le serveur ne peut pas
                * distinguer un rejeu rapide d'une signature envoyée en direct :
                * la ligne de garde est créée au même moment dans les deux cas.
                */
               return postWithTimeout(item.action, encodeFields(item.fields) + '&sign_replayed=1', headers, SEND_TIMEOUT_MS)
                  .then(function (res) {
                     if (res.status === 401) {
                        sessionExpired = true;
                        return { uid: meta.uid, outcome: 'expired' };
                     }
                     if (res.status === 403 && !isRetry) {
                        // Jeton évincé plutôt que droits manquants : on en
                        // demande un neuf et on retente UNE fois.
                        return freshToken(meta.plugin, true).then(function (t2) {
                           return attempt(t2, true);
                        });
                     }
                     if (res.status === 409) {
                        /*
                         * Une tentative précédente travaille ENCORE côté
                         * serveur. Ce n'est ni un succès ni un échec : on ne
                         * touche à rien et on repassera. Surtout, on ne
                         * supprime pas — l'issue de cette tentative est encore
                         * inconnue.
                         */
                        return storePatchMeta(meta.uid, {
                           state: 'pending',
                           last_error: 'Envoi précédent encore en cours sur le serveur',
                           last_try_ms: Date.now()
                        }).then(function () {
                           return { uid: meta.uid, outcome: 'offline' };
                        });
                     }
                     if (!res.ok) {
                        return storePatchMeta(meta.uid, {
                           state: 'failed',
                           attempts: (meta.attempts || 0) + 1,
                           last_error: 'Refus du serveur (' + res.status + ')',
                           last_http: res.status,
                           last_try_ms: Date.now()
                        }).then(function () {
                           return { uid: meta.uid, outcome: 'failed', status: res.status };
                        });
                     }

                     /*
                      * Réponse 200 : on ne s'en contente pas.
                      *
                      * Le générateur peut répondre par une redirection suivie
                      * d'une page ordinaire ; seule la garde d'idempotence dit
                      * avec certitude que le document est produit ET enregistré.
                      * On le lui demande avant de supprimer quoi que ce soit.
                      */
                     return fetchStates(meta.plugin, [meta.uid]).then(function (states) {
                        if (states && !states.expired && states[meta.uid]
                            && states[meta.uid].state === 'done') {
                           return storeRemove(meta.uid).then(function () {
                              return { uid: meta.uid, outcome: 'sent', ticket: meta.ticket_id };
                           });
                        }
                        // Le serveur a répondu mais rien n'est enregistré : on
                        // GARDE. Perdre une signature est le seul échec
                        // inacceptable.
                        return storePatchMeta(meta.uid, {
                           state: 'failed',
                           attempts: (meta.attempts || 0) + 1,
                           last_error: "Réponse reçue mais signature non enregistrée",
                           last_try_ms: Date.now()
                        }).then(function () {
                           return { uid: meta.uid, outcome: 'failed' };
                        });
                     });
                  })
                  .catch(function (err) {
                     return storePatchMeta(meta.uid, {
                        state: 'pending',
                        attempts: (meta.attempts || 0) + 1,
                        last_error: (err && err.message) || 'Réseau indisponible',
                        last_try_ms: Date.now()
                     }).then(function () {
                        return { uid: meta.uid, outcome: 'offline' };
                     });
                  });
         }

         return freshToken(meta.plugin, false).then(function (token) {
            return attempt(token, false);
         });
      });
   }

   /**
    * Vide la file : d'abord en demandant l'état, puis en rejouant ce qui reste.
    * @param {{silent?: boolean, force?: boolean}} opts
    */
   function drain(opts) {
      opts = opts || {};

      if (draining) {
         return Promise.resolve();
      }
      if (sessionExpired && !opts.force) {
         return Promise.resolve();
      }
      draining = true;
      tokenCache = {};

      var summary = { sent: 0, recovered: 0, failed: 0, offline: 0, orphan: 0, tickets: [] };

      return storeListMeta().then(function (metas) {
         if (!metas.length) {
            return null;
         }

         // 1. Ce dont le plugin a disparu ne sera pas rejoué à l'aveugle.
         var live = [];
         var orphanPatches = [];
         metas.forEach(function (m) {
            if (!plugins[m.plugin]) {
               summary.orphan++;
               if (m.state !== 'orphan') {
                  orphanPatches.push(storePatchMeta(m.uid, {
                     state: 'orphan',
                     last_error: 'Le plugin « ' + m.plugin + " » n'est plus installé"
                  }));
               }
               return;
            }
            live.push(m);
         });

         return Promise.all(orphanPatches).then(function () {
            if (!live.length) {
               return null;
            }

            // 2. État côté serveur AVANT de rejouer : c'est ce qui récupère les
            //    signatures parties dont la réponse s'était perdue.
            var byPlugin = {};
            live.forEach(function (m) {
               (byPlugin[m.plugin] = byPlugin[m.plugin] || []).push(m.uid);
            });

            var lookups = Object.keys(byPlugin).map(function (key) {
               return fetchStates(key, byPlugin[key]).then(function (states) {
                  return { key: key, states: states };
               });
            });

            return Promise.all(lookups).then(function (results) {
               var known = {};
               results.forEach(function (r) {
                  if (r.states && r.states.expired) {
                     sessionExpired = true;
                     return;
                  }
                  if (r.states) {
                     Object.keys(r.states).forEach(function (uid) { known[uid] = r.states[uid]; });
                  }
               });

               if (sessionExpired && !opts.force) {
                  return null;
               }

               var queue = [];
               var settled = [];

               live.forEach(function (m) {
                  var info = known[m.uid];
                  if (info && info.state === 'done') {
                     summary.recovered++;
                     settled.push(storeRemove(m.uid));
                     return;
                  }
                  if (info && info.state === 'running') {
                     // Une tentative précédente travaille encore côté serveur.
                     summary.offline++;
                     return;
                  }
                  if (!opts.force && m.last_try_ms
                      && (Date.now() - m.last_try_ms) < RETRY_QUIET_MS) {
                     return;
                  }
                  queue.push(m);
               });

               return Promise.all(settled).then(function () {
                  // 3. Rejeu, un par un : deux gros envois en parallèle sur un
                  //    réseau fragile se gênent plus qu'ils ne s'aident.
                  return queue.reduce(function (chain, m) {
                     return chain.then(function () {
                        if (sessionExpired && !opts.force) {
                           return null;
                        }
                        return replayOne(m).then(function (r) {
                           if (r.outcome === 'sent') {
                              summary.sent++;
                              if (r.ticket) { summary.tickets.push(r.ticket); }
                           } else if (r.outcome === 'failed') { summary.failed++; }
                           else if (r.outcome === 'offline') { summary.offline++; }
                        });
                     });
                  }, Promise.resolve());
               });
            });
         });
      }).then(function () {
         announce(summary, opts);
      }).catch(function (err) {
         if (window.console) {
            window.console.warn('[outbox] vidage interrompu', err);
         }
      }).then(function () {
         draining = false;
      });
   }

   /**
    * Avertissement à ne montrer QU'UNE FOIS par session de navigation.
    *
    * Une signature refusée le reste jusqu'à ce qu'on s'en occupe : sans ce
    * garde-fou, le message reviendrait à CHAQUE page GLPI ouverte, et le
    * technicien apprendrait à ne plus le lire — précisément le message qu'il
    * doit voir.
    */
   function noticeOnce(key, kind, message) {
      try {
         if (window.sessionStorage.getItem('sign-outbox-notice:' + key) === '1') {
            return;
         }
         window.sessionStorage.setItem('sign-outbox-notice:' + key, '1');
      } catch (e) {
         // Navigation privée, stockage refusé : on préfère répéter le message
         // plutôt que de le taire.
      }
      toast(kind, message);
   }

   function announce(summary, opts) {
      var touched = summary.sent + summary.recovered + summary.failed + summary.orphan;

      /*
       * Le ticket est NOMMÉ dans la confirmation.
       *
       * « Une signature a été transmise » laisse le technicien se demander
       * laquelle, et donc aller vérifier. Le numéro répond à la seule question
       * qu'il se pose vraiment : est-ce que CELLE de tout à l'heure est partie ?
       */
      var tickets = summary.tickets.map(function (t) { return '#' + t; }).join(', ');

      /*
       * « après la coupure réseau » : le message dit D'OÙ vient cette
       * signature. Sans cela, un « signature transmise » surgissant sur une
       * page quelconque semblait sorti de nulle part — le technicien ne
       * faisait pas le lien avec la panne de tout à l'heure.
       */
      if (summary.sent > 0) {
         toast('success', summary.sent > 1
            ? summary.sent + ' signatures retenues par une coupure réseau ont été transmises'
              + (tickets ? ' (tickets ' + escapeHtml(tickets) + ')' : '') + '.'
            : 'Signature transmise après la coupure réseau'
              + (tickets ? ' — ticket ' + escapeHtml(tickets) : '')
              + '. Le rapport est généré et le mail parti.');
      }
      if (summary.recovered > 0) {
         toast('info', summary.recovered > 1
            ? summary.recovered + ' signatures retenues par une coupure réseau étaient en fait déjà arrivées : la file a été nettoyée.'
            : 'La signature retenue par la coupure réseau était en fait déjà arrivée : elle a été retirée de la file.');
      }
      /*
       * Le lien est DANS le message : c'est la seule porte d'entrée vers la
       * file. Sans lui, un avertissement « ouvrez la file » désignerait un
       * écran que rien ne permet d'atteindre. `glpi_toast` insère le corps du
       * message tel quel, le clic est ramassé par l'écouteur délégué.
       */
      var link = ' <a href="#" data-outbox-queue="1" class="text-white text-decoration-underline">'
         + 'Ouvrir la file</a>';

      if (sessionExpired) {
         noticeOnce('expired', 'warning',
            'Des signatures attendent d\'être envoyées, mais votre session a expiré. Reconnectez-vous.' + link);
      }
      if (summary.failed > 0) {
         noticeOnce('failed:' + summary.failed, 'error', (summary.failed > 1
            ? summary.failed + ' signatures ont été refusées par le serveur.'
            : 'Une signature a été refusée par le serveur.') + link);
      }
      if (summary.orphan > 0) {
         noticeOnce('orphan:' + summary.orphan, 'warning',
            'Des signatures attendent un plugin qui n\'est plus installé.' + link);
      }
      /*
       * Une tentative qui échoue est TOUJOURS annoncée, même sur un vidage
       * discret.
       *
       * C'est ici que le technicien apprend ce que l'écran de confirmation ne
       * lui dit plus : la signature vit sur CET appareil, et vider les données
       * du navigateur la perdrait. L'information arrive au bon moment — devant
       * son écran, pas devant le client — et le cas est rare : la page n'a pu
       * se charger que parce que le réseau était revenu.
       */
      if (touched === 0 && summary.offline > 0) {
         toast('warning', (summary.offline > 1
            ? summary.offline + ' signatures n\'ont pas encore pu être transmises.'
            : 'Une signature n\'a pas encore pu être transmise.')
            + ' Elle reste sur cet appareil : ne videz pas les données de ce navigateur.'
            + link);
      }
   }

   /* ══════════════════════════════════════════════════════════════════════
    *  Fenêtre « File d'attente »
    * ══════════════════════════════════════════════════════════════════════ */

   function stateBadge(meta) {
      if (meta.state === 'orphan') {
         return '<span class="badge bg-secondary text-white">Plugin absent</span>';
      }
      if (meta.state === 'failed') {
         return '<span class="badge bg-danger text-white">Refusée</span>';
      }
      return '<span class="badge bg-warning text-dark">En attente</span>';
   }

   function renderQueue(metas) {
      if (!metas.length) {
         return '<div class="text-secondary p-3">Aucune signature en attente.</div>';
      }

      var rows = metas.map(function (m) {
         return '<div class="list-group-item" data-outbox-uid="' + escapeHtml(m.uid) + '">'
            + '  <div class="d-flex justify-content-between align-items-start gap-3">'
            + '    <div>'
            + '      <div class="fw-bold">'
            + (m.ticket_id ? 'Ticket #' + escapeHtml(m.ticket_id) : 'Signature')
            + (m.signer ? ' — ' + escapeHtml(m.signer) : '')
            + '</div>'
            + '      <div class="text-secondary small">Signée le ' + escapeHtml(formatStamp(m.captured_at))
            + ' · ' + escapeHtml(humanSize(m.size || 0))
            + ' · ' + escapeHtml(String(m.attempts || 0)) + ' tentative(s)</div>'
            + (m.last_error ? '<div class="text-danger small mt-1">' + escapeHtml(m.last_error) + '</div>' : '')
            + '    </div>'
            + '    <div class="text-end">' + stateBadge(m) + '</div>'
            + '  </div>'
            + '  <div class="mt-2 d-flex gap-2 flex-wrap">'
            + '    <button type="button" class="btn btn-sm btn-primary" data-outbox-retry="' + escapeHtml(m.uid) + '">'
            + '      <i class="ti ti-send me-1"></i>Envoyer</button>'
            + '    <button type="button" class="btn btn-sm btn-outline-secondary" data-outbox-export="' + escapeHtml(m.uid) + '">'
            + '      <i class="ti ti-download me-1"></i>Exporter</button>'
            + '    <button type="button" class="btn btn-sm btn-outline-danger" data-outbox-drop="' + escapeHtml(m.uid) + '">'
            + '      <i class="ti ti-trash me-1"></i>Supprimer</button>'
            + '  </div>'
            + '</div>';
      }).join('');

      return '<div class="list-group list-group-flush sign-outbox-list">' + rows + '</div>'
         + '<div class="text-secondary small mt-3">'
         + 'Ces signatures ne vivent que dans ce navigateur, sur cet appareil. '
         + 'Elles ne sont visibles ni depuis un autre poste, ni depuis un autre profil.'
         + '</div>';
   }

   function showQueueDialog() {
      storeListMeta().then(function (metas) {
         var body = renderQueue(metas);
         if (typeof glpi_html_dialog === 'function') {
            glpi_html_dialog({
               title: 'Signatures en attente d\'envoi',
               body: body,
               id: 'sign-outbox-dialog'
            });
         } else {
            var host = document.createElement('div');
            host.className = 'sign-outbox-fallback card';
            host.innerHTML = '<div class="card-body">' + body + '</div>';
            document.body.appendChild(host);
         }
      });
   }

   /**
    * Export d'une signature que plus rien ne peut envoyer.
    *
    * Le seul cas où la file capitule : le plugin qui devait la traiter n'est
    * plus installé. On ne la supprime pas en douce — le technicien récupère
    * l'image de la signature et le détail de la saisie, pour la ressaisir ou
    * la joindre au ticket à la main.
    */
   function exportOne(uid) {
      return storeGetItem(uid).then(function (item) {
         if (!item) {
            return;
         }
         var dump = { uid: uid, action: item.action, fields: {} };
         item.fields.forEach(function (pair) {
            dump.fields[pair[0]] = pair[1];
         });
         var blob = new Blob([JSON.stringify(dump, null, 2)], { type: 'application/json' });
         var a = document.createElement('a');
         a.href = URL.createObjectURL(blob);
         a.download = 'signature-' + uid + '.json';
         document.body.appendChild(a);
         a.click();
         document.body.removeChild(a);
      });
   }

   document.addEventListener('click', function (event) {
      /*
       * Écouteur posé sur TOUTES les pages GLPI : il voit chaque clic de
       * l'application. `closest` n'existe pas sur toutes les cibles possibles
       * (document, nœuds hors HTML) — sans ce garde-fou, on remplirait la
       * console d'erreurs sur des clics qui ne nous concernent pas.
       */
      if (!event.target || typeof event.target.closest !== 'function') {
         return;
      }

      var retry = event.target.closest('[data-outbox-retry]');
      if (retry) {
         event.preventDefault();
         retry.disabled = true;
         drain({ force: true }).then(function () { showQueueDialog(); });
         return;
      }
      var exp = event.target.closest('[data-outbox-export]');
      if (exp) {
         event.preventDefault();
         exportOne(exp.getAttribute('data-outbox-export'));
         return;
      }
      var drop = event.target.closest('[data-outbox-drop]');
      if (drop) {
         event.preventDefault();
         if (!window.confirm('Supprimer définitivement cette signature ? Elle ne pourra plus être envoyée.')) {
            return;
         }
         storeRemove(drop.getAttribute('data-outbox-drop')).then(function () { showQueueDialog(); });
         return;
      }
      var open = event.target.closest('[data-outbox-queue]');
      if (open) {
         event.preventDefault();
         showQueueDialog();
      }
   });

   /* ══════════════════════════════════════════════════════════════════════
    *  Déclencheurs
    * ══════════════════════════════════════════════════════════════════════ */

   var outbox = {
      version: MODULE_VERSION,
      owner: PLUGIN_KEY,
      declarePlugin: function (decl) {
         if (decl && decl.key) {
            plugins[decl.key] = decl;
         }
      },
      list: storeListMeta,
      drain: drain,
      open: showQueueDialog
   };

   window.GlpiSignOutbox = outbox;
   outbox.declarePlugin(declaration);

   /*
    * Le vidage n'attend PAS l'ouverture d'un ticket : ce fichier est servi par
    * le hook `add_javascript`, donc sur TOUTE page GLPI d'un utilisateur
    * connecté — interface centrale comme interface simplifiée, puisque
    * `Html::helpFooter()` n'est qu'un alias de `Html::footer()`. La première
    * page ouverte au retour au bureau suffit à faire partir la signature.
    */
   function kick(opts) {
      // Un court délai laisse la page finir de s'installer : le vidage n'est
      // jamais urgent à la milliseconde près, et il ne doit pas concurrencer
      // l'affichage.
      window.setTimeout(function () { drain(opts); }, 1500);
   }

   if (document.readyState === 'complete' || document.readyState === 'interactive') {
      kick({ silent: true });
   } else {
      document.addEventListener('DOMContentLoaded', function () { kick({ silent: true }); });
   }

   window.addEventListener('online', function () {
      // `online` est un bon signal POSITIF de changement d'état ; il ne sert
      // jamais à décider qu'on est hors-ligne (il vaut « true » sur un wifi de
      // bâtiment qui ne route rien).
      sessionExpired = false;
      kick({});
   });

   document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
         drain({ silent: true });
      }
   });

   // Relance espacée tant que la page vit : le technicien peut rester des
   // heures sur le même écran en sortant peu à peu de la zone sans réseau.
   window.setInterval(function () { drain({ silent: true }); }, 3 * 60 * 1000);
})();
