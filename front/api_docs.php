<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('gestion') || !$plugin->isActivated('gestion')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', READ);

global $CFG_GLPI;
$rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? '/glpi'), '/');
$api_rootdoc = $rootdoc;
$api_base_url = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');
if ($api_base_url === '') {
   $api_base_url = $api_rootdoc;
}

Html::header(__('Documentation API Gestion', 'gestion'), $_SERVER['PHP_SELF'], 'config', 'PluginGestionConfig');
?>
<style>
.gestion-api-doc .doc-card { border:1px solid #dfe3e8; border-radius:12px; padding:16px; margin-bottom:14px; background:#fff; }
.gestion-api-doc .doc-meta { color:#6b7280; font-size:12px; margin-bottom:8px; }
.gestion-api-doc .doc-tags { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.gestion-api-doc .doc-tag { background:#eef2ff; color:#3730a3; border-radius:999px; padding:2px 8px; font-size:11px; }
.gestion-api-doc .doc-note { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; margin:8px 0; }
.gestion-api-doc .doc-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
.gestion-api-doc .doc-table th, .gestion-api-doc .doc-table td { border:1px solid #e5e7eb; padding:8px; vertical-align:top; }
.gestion-api-doc .doc-table th { background:#f8fafc; text-align:left; }
.gestion-api-doc .method { display:inline-block; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600; margin-right:6px; }
.gestion-api-doc .method-get { background:#dcfce7; color:#166534; }
.gestion-api-doc .method-post { background:#dbeafe; color:#1d4ed8; }
.gestion-api-doc .doc-steps { margin:8px 0 0 18px; }
.gestion-api-doc pre { background:#0f172a; color:#e2e8f0; padding:10px; border-radius:8px; overflow:auto; }
.gestion-api-doc code { white-space:pre-wrap; }
</style>

<div class="gestion-api-doc">
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0"><?php echo __('Documentation API Gestion', 'gestion'); ?></h3></div>
    <div class="card-body">
      <p class="mb-2"><?php echo __('Endpoints publics (URL) : /plugins/gestion/api/*.php. Fichiers physiques : /plugins/gestion/public/api/*.php', 'gestion'); ?></p>
      <p class="mb-2"><?php echo __('Auth supportée : OAuth v2 (Bearer token utilisateur) et legacy (App-Token + user_token / Session-Token).', 'gestion'); ?></p>
      <input id="gestionApiSearch" class="form-control" type="search" placeholder="<?php echo __('Rechercher endpoint, paramètre, GET/POST, curl, body JSON, erreur…', 'gestion'); ?>">
      <div class="form-text"><?php echo __('Astuce : essayez "bl prepare", "combined_sign", "ticket_bls", "GET", "POST JSON", "curl", "technician", "counter invoice".', 'gestion'); ?></div>
    </div>
  </div>

  <div class="doc-card" data-doc-item data-search="auth oauth bearer legacy app-token user_token session-token v2 v1">
    <div class="doc-meta">AUTH</div>
    <h4>Authentification</h4>
    <pre><code>OAuth v2 (recommandé)
Authorization: Bearer &lt;access_token_utilisateur&gt;

Legacy v1
App-Token: &lt;app_token_glpi&gt;
Authorization: user_token &lt;user_token_preferences&gt;

Alternative legacy session
App-Token: &lt;app_token_glpi&gt;
Session-Token: &lt;session_token_v1&gt;</code></pre>
    <pre><code>POST <?php echo htmlspecialchars($rootdoc . '/api.php/v2.2/token', ENT_QUOTES); ?>
grant_type=password
client_id=...
client_secret=...
username=...
password=...
scope=api user</code></pre>
  </div>

  <div class="doc-card" data-doc-item data-search="comment construire url get post json body querystring curl powershell content-type authorization">
    <div class="doc-meta">GUIDE RAPIDE</div>
    <h4><?php echo __('Comment construire un appel API (GET vs POST JSON)', 'gestion'); ?></h4>
    <div class="doc-note">
      <strong><?php echo __('Règle simple', 'gestion'); ?></strong> :
      <?php echo __('si la doc indique GET, vous mettez les paramètres dans l’URL (query string). Si la doc indique POST, l’URL reste l’endpoint seul et les paramètres vont dans le corps JSON.', 'gestion'); ?>
    </div>
    <table class="doc-table">
      <thead>
        <tr>
          <th><?php echo __('Type', 'gestion'); ?></th>
          <th><?php echo __('URL', 'gestion'); ?></th>
          <th><?php echo __('Où mettre les paramètres', 'gestion'); ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><span class="method method-get">GET</span></td>
          <td><code><?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_prepare.php?bl=BL202852', ENT_QUOTES); ?></code></td>
          <td><?php echo __('Dans l’URL: `?bl=...&include_remote_technicians=1`', 'gestion'); ?></td>
        </tr>
        <tr>
          <td><span class="method method-post">POST JSON</span></td>
          <td><code><?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_sign.php', ENT_QUOTES); ?></code></td>
          <td><?php echo __('Dans le body JSON + header `Content-Type: application/json`', 'gestion'); ?></td>
        </tr>
      </tbody>
    </table>
    <pre><code># Exemple curl GET (URL + query)
curl -X GET "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_prepare.php?bl=BL202852&include_remote_technicians=1', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Accept: application/json"

# Exemple curl POST JSON (URL SANS query)
curl -X POST "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_sign.php', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"survey_id":123,"signer_name":"Client","signature":"data:image/png;base64,..."}'</code></pre>
  </div>

  <div class="doc-card" data-doc-item data-search="bl_prepare bl q query search save filename folder signed technicians remote_technicians preview amount ht ttc">
    <div class="doc-meta">GET/POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_prepare.php', ENT_QUOTES); ?></div>
    <h4>`bl_prepare.php`</h4>
    <p><?php echo __('Prépare un BL Sage (par numéro), ou prépare un document depuis un résultat de recherche (Sage/SharePoint/Local), ou renvoie une recherche BL.', 'gestion'); ?></p>
    <div class="doc-note">
      <span class="method method-get">GET</span><?php echo __('recommandé pour `bl`, `q/query`, `action=technicians`', 'gestion'); ?><br>
      <span class="method method-post">POST JSON</span><?php echo __('recommandé pour la préparation depuis une sélection de recherche (`save`, `filename`, `folder`, `signed`)', 'gestion'); ?>
    </div>
    <table class="doc-table">
      <thead>
        <tr>
          <th><?php echo __('Mode', 'gestion'); ?></th>
          <th><?php echo __('Paramètres', 'gestion'); ?></th>
          <th><?php echo __('Retour principal', 'gestion'); ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?php echo __('Recherche BL', 'gestion'); ?></td>
          <td><code>q</code> ou <code>query</code>, optionnel <code>include_remote_technicians=1</code></td>
          <td><code>results[]</code></td>
        </tr>
        <tr>
          <td><?php echo __('Liste techniciens', 'gestion'); ?></td>
          <td><code>action=technicians</code></td>
          <td><code>remote_technicians[]</code></td>
        </tr>
        <tr>
          <td><?php echo __('Préparation Sage par BL', 'gestion'); ?></td>
          <td><code>bl</code>, optionnel <code>include_remote_technicians=1</code></td>
          <td><code>id</code>, <code>preview_url</code>, <code>amount_ht</code>, <code>amount_ttc</code></td>
        </tr>
        <tr>
          <td><?php echo __('Préparation depuis résultat', 'gestion'); ?></td>
          <td><code>save</code>, <code>filename</code>, <code>folder</code>, <code>signed</code></td>
          <td><code>id</code>, <code>preview_url</code>, <code>bl</code>, <code>already_signed</code></td>
        </tr>
      </tbody>
    </table>
    <pre><code>// 1) Recherche BL (modal kiosque)
GET .../bl_prepare.php?q=BL199550&amp;include_remote_technicians=1

// 2) Liste techniciens autorisés uniquement
GET .../bl_prepare.php?action=technicians&amp;include_remote_technicians=1

// 3) Préparation depuis numéro BL (Sage)
GET .../bl_prepare.php?bl=BL199550

// 4) Préparation depuis sélection de recherche (POST JSON)
{
  "save": "Local|SharePoint|Sage",
  "filename": "BL199550_CLIENT.pdf",
  "folder": "_plugins/.../" ,
  "signed": 0
}</code></pre>
    <pre><code>// Réponse (préparation)
{
  "ok": true,
  "id": 123,
  "bl": "BL199550_CLIENT",
  "preview_url": "/glpi11/plugins/gestion/view_pdf.php?...",
  "already_signed": false,
  "relatedInvoiceToBL": "FAC12345",
  "amount_ht": "100.00",
  "amount_ttc": "120.00",
  "technician_login": "jdupont",
  "technician_label": "Dupont Jean",
  "remote_technicians": [{"id":7,"login":"jdupont","label":"Dupont Jean"}]
}</code></pre>
    <pre><code># curl - recherche BL (GET)
curl -X GET "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_prepare.php?q=BL199550&include_remote_technicians=1', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" -H "Accept: application/json"

# curl - préparation depuis sélection (POST JSON, URL SANS query)
curl -X POST "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_prepare.php', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"save":"Local","filename":"BL199550_CLIENT.pdf","folder":"_plugins/gestion/...","signed":0}'</code></pre>
    <div class="doc-note">
      <strong><?php echo __('Erreurs fréquentes', 'gestion'); ?></strong> :
      <code>missing_bl</code>, <code>invalid_bl_format</code>, <code>bl_not_found</code>.
    </div>
    <div class="doc-tags"><span class="doc-tag">q / query</span><span class="doc-tag">bl</span><span class="doc-tag">save+filename+folder</span><span class="doc-tag">include_remote_technicians</span></div>
  </div>

  <div class="doc-card" data-doc-item data-search="bl_sign signature survey_id id_document signer_name signer_email comment counter_invoice_client technician_login technician">
    <div class="doc-meta">POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_sign.php', ENT_QUOTES); ?></div>
    <h4>`bl_sign.php`</h4>
    <p><?php echo __('Signe un BL via le flux plugin Gestion. Extension additif : override technicien via `technician_login`/`technician` (contrôlé par RemoteSignatureUsers).', 'gestion'); ?></p>
    <div class="doc-note">
      <span class="method method-post">POST JSON</span>
      <?php echo __('URL fixe (sans query string) : les données de signature doivent être envoyées dans le corps JSON.', 'gestion'); ?>
    </div>
    <table class="doc-table">
      <thead><tr><th><?php echo __('Champ', 'gestion'); ?></th><th><?php echo __('Type', 'gestion'); ?></th><th><?php echo __('Obligatoire', 'gestion'); ?></th><th><?php echo __('Notes', 'gestion'); ?></th></tr></thead>
      <tbody>
        <tr><td><code>survey_id</code> ou <code>id_document</code></td><td>int</td><td><?php echo __('Oui*', 'gestion'); ?></td><td><?php echo __('*ou fournir `bl`', 'gestion'); ?></td></tr>
        <tr><td><code>bl</code></td><td>string</td><td><?php echo __('Oui*', 'gestion'); ?></td><td><?php echo __('Alternative à `survey_id`', 'gestion'); ?></td></tr>
        <tr><td><code>signer_name</code></td><td>string</td><td><?php echo __('Oui', 'gestion'); ?></td><td><?php echo __('Alias accepté : `name`', 'gestion'); ?></td></tr>
        <tr><td><code>signature</code></td><td>string</td><td><?php echo __('Oui', 'gestion'); ?></td><td><?php echo __('Data URL PNG base64 (`data:image/png;base64,...`) ; alias `url`', 'gestion'); ?></td></tr>
        <tr><td><code>signer_email</code></td><td>string</td><td><?php echo __('Non', 'gestion'); ?></td><td><?php echo __('Alias `email`', 'gestion'); ?></td></tr>
        <tr><td><code>comment</code></td><td>string</td><td><?php echo __('Non', 'gestion'); ?></td><td><?php echo __('Aliases: `comments`, `commentaire`, `note`', 'gestion'); ?></td></tr>
        <tr><td><code>counter_invoice_client</code></td><td>bool/int</td><td><?php echo __('Non', 'gestion'); ?></td><td><?php echo __('Alias `CounterInvoiceClient` / `counterinvoiceclient`', 'gestion'); ?></td></tr>
        <tr><td><code>technician_login</code> / <code>technician</code></td><td>string</td><td><?php echo __('Non', 'gestion'); ?></td><td><?php echo __('Override technicien si autorisé par `RemoteSignatureUsers`', 'gestion'); ?></td></tr>
      </tbody>
    </table>
    <pre><code>{
  "survey_id": 123,
  "signer_name": "Client Nom",
  "signer_email": "client@example.com",
  "signature": "data:image/png;base64,...",
  "comment": "Livraison comptoir",
  "counter_invoice_client": 1,
  "technician_login": "jdupont"
}</code></pre>
    <pre><code># URL = endpoint seul (pas de ?ticket_id=... ici)
curl -X POST "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/bl_sign.php', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"survey_id":123,"signer_name":"Client Nom","signature":"data:image/png;base64,...","technician_login":"jdupont"}'</code></pre>
  </div>

  <div class="doc-card" data-doc-item data-search="combined_sign mode auto bl report both ticket_id bl users_id_tech technician_login followup_ids task_ids description">
    <div class="doc-meta">POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/combined_sign.php', ENT_QUOTES); ?></div>
    <h4>`combined_sign.php`</h4>
    <p><?php echo __('Orchestrateur BL et/ou rapport RP. Extension additif : transmet aussi `technician_login`/`technician` vers le flux BL.', 'gestion'); ?></p>
    <div class="doc-note">
      <span class="method method-post">POST JSON</span>
      <?php echo __('Utilisez cet endpoint quand vous voulez piloter BL + RP avec un seul appel (mode `auto`, `both`, `bl`, `report`).', 'gestion'); ?>
    </div>
    <table class="doc-table">
      <thead><tr><th><?php echo __('Mode', 'gestion'); ?></th><th><?php echo __('Comportement', 'gestion'); ?></th></tr></thead>
      <tbody>
        <tr><td><code>auto</code></td><td><?php echo __('Choisit le meilleur flux disponible (BL/RP) selon les données fournies.', 'gestion'); ?></td></tr>
        <tr><td><code>bl</code></td><td><?php echo __('Signature BL uniquement.', 'gestion'); ?></td></tr>
        <tr><td><code>report</code></td><td><?php echo __('Génération/signature RP uniquement.', 'gestion'); ?></td></tr>
        <tr><td><code>both</code></td><td><?php echo __('Tente BL + RP dans le même appel.', 'gestion'); ?></td></tr>
      </tbody>
    </table>
    <pre><code>{
  "mode": "auto|bl|report|both",
  "bl": "BL202852",
  "ticket_id": 55347,
  "document_type": "intervention_report",
  "signer_name": "Client Nom",
  "signature": "data:image/png;base64,...",
  "users_id_tech": 7,
  "technician_login": "jdupont",
  "task_ids": [101,102],
  "followup_ids": [11,12],
  "description": "Texte rapport"
}</code></pre>
    <pre><code># Exemple POST JSON (endpoint unique)
curl -X POST "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/combined_sign.php', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" -H "Content-Type: application/json" \
  -d '{"mode":"auto","ticket_id":55347,"bl":"BL202852","signer_name":"Client","signature":"data:image/png;base64,...","technician_login":"jdupont"}'</code></pre>
  </div>

  <div class="doc-card" data-doc-item data-search="ticket_bls ticket_id bls preview_url survey_id linked bl_numbers">
    <div class="doc-meta">GET <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/ticket_bls.php', ENT_QUOTES); ?></div>
    <h4>`ticket_bls.php`</h4>
    <p><?php echo __('Retourne les BL associés à un ticket (liste simple + détails survey/preview).', 'gestion'); ?></p>
    <div class="doc-note">
      <span class="method method-get">GET</span>
      <?php echo __('URL avec query string (`ticket_id` dans l’URL).', 'gestion'); ?>
    </div>
    <pre><code># Construction de l'URL
<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/ticket_bls.php?ticket_id=55347', ENT_QUOTES); ?>

# curl
curl -X GET "<?php echo htmlspecialchars($rootdoc . '/plugins/gestion/api/ticket_bls.php?ticket_id=55347', ENT_QUOTES); ?>" \
  -H "Authorization: Bearer &lt;token&gt;" -H "Accept: application/json"</code></pre>
  </div>

  <div class="doc-card" data-doc-item data-search="device_poll_v2 device_submit_v2 device_refuse_v2 device_checkin device_direct_sign kiosk borne x-device-serial">
    <div class="doc-meta">KIOSQUE (auth X-Device-Serial)</div>
    <h4>Endpoints borne</h4>
    <p><?php echo __('Utilisés par APPAPPLETAB pour l’enregistrement de la borne, le polling de signatures déportées et le fallback de signature directe simplifiée.', 'gestion'); ?></p>
    <pre><code>POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/public/api/device_checkin.php', ENT_QUOTES); ?>
GET  <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/public/api/device_poll_v2.php', ENT_QUOTES); ?>
POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/public/api/device_submit_v2.php', ENT_QUOTES); ?>
POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/public/api/device_refuse_v2.php', ENT_QUOTES); ?>
POST <?php echo htmlspecialchars($rootdoc . '/plugins/gestion/public/api/device_direct_sign.php', ENT_QUOTES); ?></code></pre>
    <div class="doc-note">
      <?php echo __('Ces endpoints borne utilisent généralement l’authentification par header `X-Device-Serial` (en plus de la configuration kiosque). Pour les signatures rapides enrichies, APPAPPLETAB utilise maintenant principalement les APIs `/plugins/gestion/api/*` et `/plugins/rp/api/*`.', 'gestion'); ?>
    </div>
  </div>

  <div class="doc-card" data-doc-item data-search="workflow quick sign signature rapide bl kiosk tablette sequence prepare sign ticket_bls combined_sign">
    <div class="doc-meta">WORKFLOW</div>
    <h4><?php echo __('Exemples de séquences d’appel (kiosque / intégration)', 'gestion'); ?></h4>
    <p><strong><?php echo __('Signature rapide BL (kiosque)', 'gestion'); ?></strong></p>
    <ol class="doc-steps">
      <li><?php echo __('Lister les techniciens autorisés: `GET bl_prepare.php?action=technicians`', 'gestion'); ?></li>
      <li><?php echo __('Rechercher un BL: `GET bl_prepare.php?q=BL...`', 'gestion'); ?></li>
      <li><?php echo __('Préparer le document sélectionné: `POST bl_prepare.php` avec `save/filename/folder/signed`', 'gestion'); ?></li>
      <li><?php echo __('Signer: `POST bl_sign.php` avec `survey_id`, signature base64, signataire, commentaire, technicien', 'gestion'); ?></li>
    </ol>
    <p class="mt-3"><strong><?php echo __('Signature ticket + BL (orchestrée)', 'gestion'); ?></strong></p>
    <ol class="doc-steps">
      <li><?php echo __('Récupérer les BL d’un ticket: `GET ticket_bls.php?ticket_id=...` (optionnel)', 'gestion'); ?></li>
      <li><?php echo __('Envoyer un seul `POST combined_sign.php` en mode `auto` ou `both`', 'gestion'); ?></li>
    </ol>
  </div>

  <div class="doc-card" data-doc-item data-search="test authentification api oauth bearer legacy app-token user_token modal config">
    <div class="doc-meta">OUTILS</div>
    <h4><?php echo __('Test API / Authentification', 'gestion'); ?></h4>
    <p class="mb-2"><?php echo __('Ouvre le testeur interactif (OAuth v2 / legacy) directement sur cette page de documentation.', 'gestion'); ?></p>
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#gestionApiTesterModal">
      <?php echo __('Test', 'gestion'); ?>
    </button>
  </div>
</div>

<div class="modal fade" id="gestionApiTesterModal" tabindex="-1" aria-labelledby="gestionApiTesterModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="gestionApiTesterModalLabel"><?php echo __('Test API / Authentification', 'gestion'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Fermer', 'gestion'); ?>"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label for="gestionApiTestMode" class="form-label"><?php echo __('Mode de test', 'gestion'); ?></label>
            <select id="gestionApiTestMode" class="form-select">
              <option value="v2_password"><?php echo __('OAuth v2.2 (grant password)', 'gestion'); ?></option>
              <option value="v1_user_token"><?php echo __('Legacy v1 (App-Token + user_token)', 'gestion'); ?></option>
            </select>
          </div>
          <div class="col-md-8">
            <label for="gestionApiTestBaseUrl" class="form-label"><?php echo __('Base URL GLPI', 'gestion'); ?></label>
            <input id="gestionApiTestBaseUrl"
                   class="form-control"
                   value="<?php echo htmlspecialchars($api_base_url, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="https://example.tld/glpi">
          </div>
          <div class="col-md-4">
            <label for="gestionApiTestBL" class="form-label"><?php echo __('BL de test', 'gestion'); ?></label>
            <input id="gestionApiTestBL" class="form-control" value="BL202852" placeholder="BL202852">
          </div>
        </div>

        <div id="gestionApiTestV2Fields" class="row g-3 mt-1">
          <div class="col-md-6">
            <label for="gestionApiClientId" class="form-label"><?php echo __('Client ID OAuth', 'gestion'); ?></label>
            <input id="gestionApiClientId" class="form-control" placeholder="client_id">
          </div>
          <div class="col-md-6">
            <label for="gestionApiClientSecret" class="form-label"><?php echo __('Client secret OAuth', 'gestion'); ?></label>
            <input id="gestionApiClientSecret" type="password" class="form-control" placeholder="client_secret">
          </div>
          <div class="col-md-6">
            <label for="gestionApiUsername" class="form-label"><?php echo __('Login GLPI', 'gestion'); ?></label>
            <input id="gestionApiUsername" class="form-control" placeholder="login">
          </div>
          <div class="col-md-6">
            <label for="gestionApiPassword" class="form-label"><?php echo __('Mot de passe GLPI', 'gestion'); ?></label>
            <input id="gestionApiPassword" type="password" class="form-control" placeholder="mot de passe">
          </div>
        </div>

        <div id="gestionApiTestV1Fields" class="row g-3 mt-1" style="display:none;">
          <div class="col-md-6">
            <label for="gestionApiAppToken" class="form-label"><?php echo __('App-Token', 'gestion'); ?></label>
            <input id="gestionApiAppToken" class="form-control" placeholder="app_token">
          </div>
          <div class="col-md-6">
            <label for="gestionApiUserToken" class="form-label"><?php echo __('User token (préférences GLPI)', 'gestion'); ?></label>
            <input id="gestionApiUserToken" class="form-control" placeholder="user_token">
          </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
          <button type="button" class="btn btn-primary" id="gestionApiRunTestBtn"><?php echo __('Tester l\'API maintenant', 'gestion'); ?></button>
          <button type="button" class="btn btn-outline-secondary" id="gestionApiBuildPsBtn"><?php echo __('Générer script PowerShell', 'gestion'); ?></button>
          <button type="button" class="btn btn-outline-secondary" id="gestionApiCopyPsBtn"><?php echo __('Copier le script', 'gestion'); ?></button>
          <button type="button" class="btn btn-outline-secondary" id="gestionApiPopupPsBtn"><?php echo __('Ouvrir dans une fenêtre', 'gestion'); ?></button>
        </div>

        <div class="mt-3">
          <label for="gestionApiPsScript" class="form-label"><?php echo __('Script PowerShell généré', 'gestion'); ?></label>
          <textarea id="gestionApiPsScript" class="form-control font-monospace" rows="14"></textarea>
        </div>

        <div class="mt-3">
          <label for="gestionApiTestResult" class="form-label"><?php echo __('Résultat du test HTTP', 'gestion'); ?></label>
          <pre id="gestionApiTestResult" class="bg-light p-2 rounded small mb-0"></pre>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('Fermer', 'gestion'); ?></button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  if (window.gestionApiTesterInit) {
    return;
  }
  window.gestionApiTesterInit = true;

  const get = (id) => document.getElementById(id);
  const modeEl = get('gestionApiTestMode');
  const resultEl = get('gestionApiTestResult');
  const scriptEl = get('gestionApiPsScript');

  const fields = {
    baseUrl: get('gestionApiTestBaseUrl'),
    bl: get('gestionApiTestBL'),
    clientId: get('gestionApiClientId'),
    clientSecret: get('gestionApiClientSecret'),
    username: get('gestionApiUsername'),
    password: get('gestionApiPassword'),
    appToken: get('gestionApiAppToken'),
    userToken: get('gestionApiUserToken'),
    v2Box: get('gestionApiTestV2Fields'),
    v1Box: get('gestionApiTestV1Fields')
  };

  const input = document.getElementById('gestionApiSearch');
  const cards = Array.from(document.querySelectorAll('[data-doc-item]'));
  const normalizeSearch = (value) => {
    let txt = String(value ?? '').toLowerCase();
    if (typeof txt.normalize === 'function') {
      txt = txt.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return txt
      .replace(/[_./:\\-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  };
  const cardSearchIndex = new Map();
  cards.forEach((card) => {
    const raw = ((card.getAttribute('data-search') || '') + ' ' + (card.innerText || ''));
    cardSearchIndex.set(card, normalizeSearch(raw));
  });
  if (input) {
    input.addEventListener('input', function(){
      const q = normalizeSearch(input.value || '');
      const tokens = q ? q.split(' ').filter(Boolean) : [];
      cards.forEach(card => {
        const text = cardSearchIndex.get(card) || '';
        const visible = tokens.length === 0 || tokens.every((t) => text.indexOf(t) !== -1);
        card.style.display = visible ? '' : 'none';
      });
    });
  }

  const escPs = (value) => String(value ?? '').replace(/'/g, "''");
  const clip = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    scriptEl.focus();
    scriptEl.select();
    document.execCommand('copy');
  };
  const normalizeBase = (txt) => String(txt ?? '').trim().replace(/\/+$/, '');
  const absolutizeBase = (txt) => {
    const base = normalizeBase(txt);
    if (base.startsWith('/')) {
      return window.location.origin + base;
    }
    return base;
  };
  const setResult = (txt) => {
    resultEl.textContent = String(txt ?? '');
  };
  const setMode = () => {
    const v2 = modeEl.value === 'v2_password';
    fields.v2Box.style.display = v2 ? '' : 'none';
    fields.v1Box.style.display = v2 ? 'none' : '';
  };

  const buildScript = () => {
    const mode = modeEl.value;
    const base = absolutizeBase(fields.baseUrl.value);
    const bl = fields.bl.value.trim();
    const prepareUrl = "$BaseUrl/plugins/gestion/api/bl_prepare.php?bl=$BL&include_remote_technicians=1";

    if (mode === 'v2_password') {
      return [
        "$BaseUrl = '" + escPs(base) + "'",
        "$BL = '" + escPs(bl) + "'",
        "$ClientId = '" + escPs(fields.clientId.value) + "'",
        "$ClientSecret = '" + escPs(fields.clientSecret.value) + "'",
        "$Username = '" + escPs(fields.username.value) + "'",
        "$Password = '" + escPs(fields.password.value) + "'",
        "",
        "$token = Invoke-RestMethod -Method POST -Uri \"$BaseUrl/api.php/v2.2/token\" -ContentType \"application/x-www-form-urlencoded\" -Body @{",
        "    grant_type    = 'password'",
        "    client_id     = $ClientId",
        "    client_secret = $ClientSecret",
        "    username      = $Username",
        "    password      = $Password",
        "    scope         = 'api user'",
        "}",
        "",
        "$headers = @{ Authorization = \"Bearer $($token.access_token)\"; Accept = 'application/json' }",
        "Invoke-WebRequest -Method GET -Uri \"" + prepareUrl + "\" -Headers $headers -TimeoutSec 180"
      ].join("\n");
    }

    return [
      "$BaseUrl = '" + escPs(base) + "'",
      "$BL = '" + escPs(bl) + "'",
      "$AppToken = '" + escPs(fields.appToken.value) + "'",
      "$UserToken = '" + escPs(fields.userToken.value) + "'",
      "",
      "$headers = @{",
      "    'App-Token'    = $AppToken",
      "    'Authorization' = \"user_token $UserToken\"",
      "    'Accept'        = 'application/json'",
      "}",
      "Invoke-WebRequest -Method GET -Uri \"" + prepareUrl + "\" -Headers $headers -TimeoutSec 180"
    ].join("\n");
  };

  const readBody = async (res) => {
    const txt = await res.text();
    try {
      return JSON.stringify(JSON.parse(txt), null, 2);
    } catch (e) {
      return txt;
    }
  };

  const runTest = async () => {
    const base = normalizeBase(fields.baseUrl.value);
    const bl = fields.bl.value.trim();
    if (!base || !bl) {
      setResult("Base URL et BL sont obligatoires.");
      return;
    }

    setResult("Test en cours...");

    try {
      if (modeEl.value === 'v2_password') {
        const tokenForm = new URLSearchParams();
        tokenForm.set('grant_type', 'password');
        tokenForm.set('client_id', fields.clientId.value.trim());
        tokenForm.set('client_secret', fields.clientSecret.value);
        tokenForm.set('username', fields.username.value.trim());
        tokenForm.set('password', fields.password.value);
        tokenForm.set('scope', 'api user');

        const tokenRes = await fetch(base + '/api.php/v2.2/token', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: tokenForm
        });
        const tokenBodyRaw = await tokenRes.text();
        let tokenBody = {};
        try {
          tokenBody = JSON.parse(tokenBodyRaw);
        } catch (e) {
          tokenBody = {};
        }
        if (!tokenRes.ok || !tokenBody.access_token) {
          setResult("OAuth v2.2 token KO (HTTP " + tokenRes.status + ")\n" + tokenBodyRaw);
          return;
        }

        const prepareRes = await fetch(base + '/plugins/gestion/api/bl_prepare.php?bl=' + encodeURIComponent(bl) + '&include_remote_technicians=1', {
          headers: { 'Authorization': 'Bearer ' + tokenBody.access_token, 'Accept': 'application/json' }
        });
        setResult("Token OK (HTTP " + tokenRes.status + ")\n\nBL prepare HTTP " + prepareRes.status + "\n" + (await readBody(prepareRes)));
        return;
      }

      const prepareRes = await fetch(base + '/plugins/gestion/api/bl_prepare.php?bl=' + encodeURIComponent(bl) + '&include_remote_technicians=1', {
        headers: {
          'App-Token': fields.appToken.value.trim(),
          'Authorization': 'user_token ' + fields.userToken.value.trim(),
          'Accept': 'application/json'
        }
      });
      setResult("BL prepare HTTP " + prepareRes.status + "\n" + (await readBody(prepareRes)));
    } catch (e) {
      setResult("Erreur JS: " + e.message);
    }
  };

  get('gestionApiRunTestBtn').addEventListener('click', runTest);
  get('gestionApiBuildPsBtn').addEventListener('click', function () {
    scriptEl.value = buildScript();
  });
  get('gestionApiCopyPsBtn').addEventListener('click', async function () {
    scriptEl.value = buildScript();
    await clip(scriptEl.value);
    setResult("Script copié dans le presse-papiers.");
  });
  get('gestionApiPopupPsBtn').addEventListener('click', function () {
    scriptEl.value = buildScript();
    const popup = window.open('', '_blank', 'width=980,height=760');
    if (!popup) {
      setResult("Popup bloquée par le navigateur.");
      return;
    }
    const escaped = scriptEl.value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
    popup.document.write('<!doctype html><html><head><meta charset=\"utf-8\"><title>Script PowerShell API Gestion</title></head><body style=\"font-family:monospace;padding:12px;\"><h3>Script PowerShell</h3><pre style=\"white-space:pre-wrap;word-break:break-word;\">' + escaped + '</pre></body></html>');
    popup.document.close();
  });

  modeEl.addEventListener('change', function () {
    setMode();
    scriptEl.value = buildScript();
  });

  setMode();
  scriptEl.value = buildScript();
})();
</script>

<?php Html::footer(); ?>
