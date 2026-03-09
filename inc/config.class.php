<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionConfig extends CommonDBTM
{
   static private $_instance = null;

   function __construct()
   {
      global $DB;

      if ($DB->tableExists($this->getTable())) {
         $this->getFromDB(1);
      }
   }

   static function getTypeName($nb = 0)
   {
      return __('<span class="d-flex align-items-center"><i class="fa-solid fa-clipboard-check me-2"></i>Gestion BL</span>', "gestion");
   }

   static function getInstance()
   {
      if (!isset(self::$_instance)) {
         self::$_instance = new self();
         if (!self::$_instance->getFromDB(1)) {
            self::$_instance->getEmpty();
         }
      }
      return self::$_instance;
   }

   static function showConfigForm(){ //formulaire de configuration du plugin
      global $DB, $CFG_GLPI;
      $config = new self();
      $config->getFromDB(1);
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

      $sharepoint = new PluginGestionSharepoint();
         $errorcon = "";
         $checkcon ="";
         $mode = true;

      if($config->SharePointOn() == 1 && $config->SageOn() == 0){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 0 WHERE id = 1";
            $DB->doQuery($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SageSearch = 0 WHERE id = 1";
            $DB->doQuery($update);
      }
      if($config->SharePointOn() == 0 && $config->SageOn() == 1){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 1 WHERE id = 1";
            $DB->doQuery($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SharePointSearch = 0 WHERE id = 1";
            $DB->doQuery($update);
      }
      if($config->SharePointOn() == 0 && $config->SageOn() == 0){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 2 WHERE id = 1";
            $DB->doQuery($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SageSearch = 0 WHERE id = 1";
            $DB->doQuery($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SharePointSearch = 0 WHERE id = 1";
            $DB->doQuery($update);
            $mode = false;
      }
      if($config->SageSearch() == 0 && $config->SharePointSearch() == 0){
            $update = "UPDATE glpi_plugin_gestion_configs SET LocalSearch = 1 WHERE id = 1";
            $DB->doQuery($update);
      }

      if($config->mode() == 1){
         // Vérifie s'il existe au moins une ligne avec param = 1
         $query = "SELECT id FROM glpi_plugin_gestion_configsfolder WHERE params = 1";
         $result = $DB->doQuery($query);

         if ($result && $DB->numrows($result) > 0) {
            // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configsfolder SET params = 8 WHERE params = 1";
            $DB->doQuery($update);
         }
      }

      $config->showFormHeader(['colspan' => 4]);
      // showFormHeader() opens <table><tr><td>; close them properly before rendering card layout
      echo '</td></tr></table>'; 
      // Dedicated standalone CSRF token for this plugin form/actions (avoids shared token consumption by other requests)
      echo Html::hidden('plugin_gestion_csrf_token', ['value' => Session::getNewCSRFToken(true)]);

      $api_hl_enabled = Config::isHlApiEnabled();
      $api_legacy_enabled = !empty($CFG_GLPI['enable_api']);
      $api_glpi_enabled = $api_hl_enabled || $api_legacy_enabled;
      $api_rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');
      $api_base_url = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');
      if ($api_base_url === '') {
         $api_base_url = $api_rootdoc;
      }
      $api_prepare_endpoint = $api_rootdoc . '/plugins/gestion/api/bl_prepare.php';
      $api_sign_endpoint = $api_rootdoc . '/plugins/gestion/api/bl_sign.php';
      $api_combined_endpoint = $api_rootdoc . '/plugins/gestion/api/combined_sign.php';
      $api_ticket_bls_endpoint = $api_rootdoc . '/plugins/gestion/api/ticket_bls.php';
      $api_token_endpoint = $api_rootdoc . '/api.php/v2.2/token';
      $api_legacy_init_session_endpoint = $api_rootdoc . '/api.php/v1/initSession';

   // --- CARD : Gestion ---
      ?>
      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0"><?php echo __('Gestion', 'gestion'); ?></h3>
      </div>
      <div class="card-body">
         <div class="row g-3">

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Affichage du PDF après signature', 'gestion'); ?></label>
            <?php Dropdown::showYesNo('DisplayPdfEnd', $config->DisplayPdfEnd(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Envoie des PDF par mail', 'gestion'); ?></label>
            <?php Dropdown::showYesNo('MailTo', $config->MailTo(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __("Mode d'envoi mail au client (Rapport + BL)", 'gestion'); ?></label>
            <?php
               $mailModeValues = [
                  0 => __('Un seul mail (BL + Rapport fusionnés)', 'gestion'),
                  1 => __('Deux mails séparés (BL + Rapport)', 'gestion'),
               ];
               $rpActive = Plugin::isPluginActive('rp') && class_exists('PluginRpCri');
               $combinedVal = (int)$config->CombinedMailMode();
               if ($rpActive) {
                  Dropdown::showFromArray('CombinedMailMode', $mailModeValues, [
                     'value' => $combinedVal,
                  ]);
               } else {
                  echo Html::hidden('CombinedMailMode', ['value' => $combinedVal]);
                  echo '<select class="form-select" disabled>';
                  foreach ($mailModeValues as $k => $label) {
                     $sel = ((int)$k === $combinedVal) ? ' selected' : '';
                     echo '<option value="'.(int)$k.'"'.$sel.'>'.htmlspecialchars($label, ENT_QUOTES).'</option>';
                  }
                  echo '</select>';
                  echo '<div class="form-text text-muted">Necessite le plugin RP.</div>';
               }
            ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Gabarit : Modèle de notifications', 'gestion'); ?></label>
            <?php
               Dropdown::show('NotificationTemplate', [
                  'name'                => 'gabarit',
                  'value'               => $config->gabarit(),
                  'display_emptychoice' => 1,
                  'emptylabel'          => '-----',
                  'specific_tags'       => [],
                  'itemtype'            => 'NotificationTemplate',
                  'displaywith'         => [],
                  'used'                => [],
                  'toadd'               => [],
                  'entity_restrict'     => 0,
               ]);
            ?>
            </div>

            <div class="col-md-6">
            <label for="ZenDocMail" class="form-label mb-1"><?php echo __('Enregistrement dans ZenDoc par mail', 'gestion'); ?></label>
            <?php echo Html::input('ZenDocMail', ['value' => $config->ZenDocMail(), 'class' => 'form-control', 'id' => 'ZenDocMail']); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __("Conservation du PDF non signé après la signature", 'gestion'); ?></label>
            <?php Dropdown::showYesNo('ConfigModes', $config->ConfigModes(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Autorisé la connexion à Sage local', 'gestion'); ?></label>
            <?php Dropdown::showYesNo('SageOn', $config->SageOn(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Autorisé la connexion à Sharepoint', 'gestion'); ?></label>
            <?php Dropdown::showYesNo('SharePointOn', $config->SharePointOn(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1 d-block"><?php echo __('Recherche de documents dans le dossier local GLPI', 'gestion'); ?></label>
            <?php $LocalSearch = $config->LocalSearch(); ?>
            <input type="hidden" name="LocalSearch" value="0">
            <div class="form-check form-switch">
               <input class="form-check-input" type="checkbox" id="LocalSearch_switch" name="LocalSearch" value="1" <?php echo ($LocalSearch == 1 ? 'checked' : ''); ?>>
               <label class="form-check-label" for="LocalSearch_switch"><?php echo __('Activer', 'gestion'); ?></label>
            </div>
            </div>

            <?php if ($api_glpi_enabled): ?>
            <div class="col-md-12">
               <label class="form-label mb-1 d-block"><?php echo __('API application tierce', 'gestion'); ?></label>
               <div class="d-flex flex-wrap align-items-center gap-2">
                  <?php if ($api_hl_enabled): ?>
                  <span class="badge bg-success"><?php echo __('API v2 active', 'gestion'); ?></span>
                  <?php endif; ?>
                  <?php if ($api_legacy_enabled): ?>
                  <span class="badge bg-warning text-dark"><?php echo __('API legacy active', 'gestion'); ?></span>
                  <?php endif; ?>
                  <a class="btn btn-outline-primary btn-sm"
                     href="<?php echo Html::entities_deep($api_rootdoc . '/plugins/gestion/front/api_docs.php'); ?>"
                     target="_blank" rel="noopener">
                     <?php echo __('Voir la documentation API', 'gestion'); ?>
                  </a>
               </div>
               <div class="form-text text-muted">
                  <?php echo __('Compatible OAuth v2 (Bearer) et legacy (App-Token + user_token/session_token). En v2.2, utiliser un token utilisateur.', 'gestion'); ?>
               </div>
            </div>
            <?php endif; ?>

         </div>
      </div>
      </div>

      <?php if ($api_glpi_enabled): ?>
      <div class="modal fade" id="gestionApiDocModal" tabindex="-1" aria-labelledby="gestionApiDocModalLabel" aria-hidden="true">
         <div class="modal-dialog modal-lg">
            <div class="modal-content">
               <div class="modal-header">
                  <h5 class="modal-title" id="gestionApiDocModalLabel"><?php echo __('Documentation API du plugin Gestion', 'gestion'); ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Fermer', 'gestion'); ?>"></button>
               </div>
               <div class="modal-body">
                  <p class="mb-2"><strong><?php echo __('Pré-requis', 'gestion'); ?></strong></p>
                  <ul class="mb-3">
                     <li><?php echo __('Activer au moins une API GLPI : v2 (High-Level) ou legacy.', 'gestion'); ?></li>
                     <li><?php echo __('Ces endpoints plugin acceptent les deux méthodes d\'authentification.', 'gestion'); ?></li>
                  </ul>

                  <p class="mb-2"><strong><?php echo __('Méthode 1 : OAuth v2 (recommandée)', 'gestion'); ?></strong></p>
                  <pre class="bg-light p-2 rounded"><code>Authorization: Bearer &lt;access_token_oauth&gt;</code></pre>
                  <ul class="mb-3">
                     <li><?php echo __('Pour ces endpoints plugin, le token OAuth doit etre lie a un utilisateur.', 'gestion'); ?></li>
                     <li><?php echo __('En v2.2, utiliser grant_type=password (login GLPI + mot de passe) ou authorization_code.', 'gestion'); ?></li>
                     <li><?php echo __('grant_type=client_credentials seul renverra user_context_required.', 'gestion'); ?></li>
                  </ul>

                  <p class="mb-2"><strong><?php echo __('Endpoint OAuth token (GLPI v2.2)', 'gestion'); ?></strong></p>
                  <pre class="bg-light p-2 rounded"><code>POST <?php echo htmlspecialchars($api_token_endpoint, ENT_QUOTES, 'UTF-8'); ?></code></pre>
                  <pre class="bg-light p-2 rounded"><code>grant_type=password
client_id=&lt;client_id_oauth&gt;
client_secret=&lt;client_secret_oauth&gt;
username=&lt;login_glpi&gt;
password=&lt;mot_de_passe_glpi&gt;
scope=api user</code></pre>

                  <p class="mb-2 mt-3"><strong><?php echo __('Méthode 2 : Legacy v1 (jeton utilisateur)', 'gestion'); ?></strong></p>
                  <pre class="bg-light p-2 rounded"><code>App-Token: &lt;app_token_glpi&gt;
Authorization: user_token &lt;user_token_preferences&gt;</code></pre>
                  <p class="mb-2"><strong><?php echo __('Alternative legacy (session)', 'gestion'); ?></strong></p>
                  <pre class="bg-light p-2 rounded"><code>App-Token: &lt;app_token_glpi&gt;
Session-Token: &lt;session_token_v1&gt;   (obtenu via initSession)</code></pre>
                  <pre class="bg-light p-2 rounded"><code>POST <?php echo htmlspecialchars($api_legacy_init_session_endpoint, ENT_QUOTES, 'UTF-8'); ?></code></pre>

                  <p class="mb-2"><strong><?php echo __('Endpoints', 'gestion'); ?></strong></p>
                  <div class="mb-2">
                     <div><code>GET <?php echo htmlspecialchars($api_prepare_endpoint, ENT_QUOTES, 'UTF-8'); ?>?bl=BL123456</code></div>
                     <small class="text-muted"><?php echo __('Vérifie le BL, le prépare et retourne l\'aperçu + le statut.', 'gestion'); ?></small>
                  </div>
                  <div class="mb-3">
                     <div><code>POST <?php echo htmlspecialchars($api_sign_endpoint, ENT_QUOTES, 'UTF-8'); ?></code></div>
                     <small class="text-muted"><?php echo __('Enregistre la signature (comme le flux plugin), avec nom, email et image base64.', 'gestion'); ?></small>
                  </div>
                  <div class="mb-3">
                     <div><code>POST <?php echo htmlspecialchars($api_combined_endpoint, ENT_QUOTES, 'UTF-8'); ?></code></div>
                     <small class="text-muted"><?php echo __('Orchestre BL et/ou rapport RP dans un seul appel (mode auto, bl, report, both).', 'gestion'); ?></small>
                  </div>
                  <div class="mb-3">
                     <div><code>GET <?php echo htmlspecialchars($api_ticket_bls_endpoint, ENT_QUOTES, 'UTF-8'); ?>?ticket_id=55347</code></div>
                     <small class="text-muted"><?php echo __('Retourne la liste des BL associes a un ticket (utilise pour afficher les BL en haut de la fiche ticket dans les apps tierces).', 'gestion'); ?></small>
                  </div>

                  <p class="mb-2"><strong><?php echo __('Champs de la requete de signature', 'gestion'); ?></strong></p>
                  <ul class="mb-3">
                     <li><code>survey_id</code> ou <code>bl</code> : <?php echo __('obligatoire (au moins un des deux).', 'gestion'); ?></li>
                     <li><code>signer_name</code> (ou <code>name</code>) : <?php echo __('obligatoire.', 'gestion'); ?></li>
                     <li><code>signature</code> (base64 data URL) : <?php echo __('obligatoire.', 'gestion'); ?></li>
                     <li><code>signer_email</code> (ou <code>email</code>) : <?php echo __('optionnel.', 'gestion'); ?></li>
                     <li><code>mail_to_client</code> : <?php echo __('optionnel (0/1).', 'gestion'); ?></li>
                     <li><code>comment</code> : <?php echo __('optionnel (commentaire libre).', 'gestion'); ?></li>
                     <li><code>counter_invoice_client</code> : <?php echo __('optionnel (0/1, signe au comptoir).', 'gestion'); ?></li>
                  </ul>

                  <p class="mb-2"><strong><?php echo __('Exemple JSON pour la signature', 'gestion'); ?></strong></p>
                  <pre class="bg-light p-2 rounded mb-0"><code>{
  "survey_id": 123,
  "signer_name": "Client Nom",
  "signer_email": "client@example.com",
  "signature": "data:image/png;base64,....",
  "mail_to_client": 1,
  "comment": "Livraison effectuee au comptoir",
  "counter_invoice_client": 1
}</code></pre>
                  <div class="form-text text-muted mt-2">
                     <?php echo __('La reponse de signature renvoie aussi comment et counter_invoice_client pour completer le suivi BDD.', 'gestion'); ?>
                  </div>

                  <p class="mb-2 mt-3"><strong><?php echo __('Endpoint combiné BL/Rapport (si plugin RP actif)', 'gestion'); ?></strong></p>
                  <ul class="mb-3">
                     <li><code>mode</code> : <?php echo __('auto | bl | report | both', 'gestion'); ?></li>
                     <li><code>survey_id</code> ou <code>bl</code> : <?php echo __('recommandé pour le flux BL.', 'gestion'); ?></li>
                     <li><code>ticket_id</code> : <?php echo __('recommandé pour le flux rapport.', 'gestion'); ?></li>
                     <li><?php echo __('En mode both: ticket et BL doivent être associés.', 'gestion'); ?></li>
                     <li><?php echo __('Pour un flux orienté rapport (ticket en entrée), point d entrée principal: /plugins/rp/api/ticket_sign.php.', 'gestion'); ?></li>
                     <li><?php echo __('Le flux inverse est disponible ici via combined_sign (BL en entree), mais reste un cas secondaire.', 'gestion'); ?></li>
                  </ul>
                  <pre class="bg-light p-2 rounded mb-0"><code>{
  "mode": "both",
  "bl": "BL202852",
  "ticket_id": 55347,
  "document_type": "intervention_report",
  "signer_name": "Client Nom",
  "signer_email": "",
  "signature": "data:image/png;base64,...",
  "mail_to_client": 0,
  "comment": "Signature BL + rapport",
  "counter_invoice_client": 0
}</code></pre>

                  <p class="mb-2 mt-3"><strong><?php echo __('Endpoint BL lies a un ticket', 'gestion'); ?></strong></p>
                  <ul class="mb-3">
                     <li><code>ticket_id</code> : <?php echo __('obligatoire.', 'gestion'); ?></li>
                     <li><?php echo __('Reponse: bl_numbers (liste simple) + bls (detail survey_id, signed, doc_date, preview_url).', 'gestion'); ?></li>
                  </ul>
                  <pre class="bg-light p-2 rounded mb-0"><code>{
  "ticket_id": 55347,
  "bl_numbers": ["BL202852_EMPX"],
  "bls": [
    {
      "survey_id": 34,
      "bl": "BL202852_EMPX",
      "signed": true,
      "doc_date": "2026-02-15 10:45:00",
      "preview_url": "/glpi11/front/document.send.php?docid=28419"
    }
  ]
}</code></pre>
               </div>
               <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('Fermer', 'gestion'); ?></button>
               </div>
            </div>
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
                  <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?php echo Html::entities_deep($api_rootdoc . '/plugins/gestion/front/api_docs.php'); ?>"><?php echo __('Voir doc API', 'gestion'); ?></a>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('Fermer', 'gestion'); ?></button>
               </div>
            </div>
         </div>
      </div>

      <script>
      (function () {
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
                  "Invoke-WebRequest -Method GET -Uri \"$BaseUrl/plugins/gestion/api/bl_prepare.php?bl=$BL\" -Headers $headers -TimeoutSec 180"
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
               "Invoke-WebRequest -Method GET -Uri \"$BaseUrl/plugins/gestion/api/bl_prepare.php?bl=$BL\" -Headers $headers -TimeoutSec 180"
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

                  const prepareRes = await fetch(base + '/plugins/gestion/api/bl_prepare.php?bl=' + encodeURIComponent(bl), {
                     headers: { 'Authorization': 'Bearer ' + tokenBody.access_token, 'Accept': 'application/json' }
                  });
                  setResult("Token OK (HTTP " + tokenRes.status + ")\n\nBL prepare HTTP " + prepareRes.status + "\n" + (await readBody(prepareRes)));
                  return;
               }

               const prepareRes = await fetch(base + '/plugins/gestion/api/bl_prepare.php?bl=' + encodeURIComponent(bl), {
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
      <?php endif; ?>

      <?php
      // --- CARD : Positionnement des éléments (Paramètre : 0 pour masqué) ---
      ?>
      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0">
            <?php echo __("Positionnement des éléments (Paramétre : 0 pour masqué)", 'gestion'); ?>
         </h3>
      </div>
      <div class="card-body">
         <!-- Ligne 1 : Signature (3) + Signataire (2) -->
         <div class="row gy-4 gx-4">
            <!-- Bloc Signature : ~3/5 de la largeur (7 colonnes Bootstrap) -->
            <div class="col-12 col-xl-7">
            <div class="text-muted fw-semibold mb-2">
               <?php echo __('Position de la signature sur le PDF', 'gestion'); ?>
            </div>
            <div class="row g-3">
               <div class="col-12 col-md-4">
                  <label for="SignatureX" class="form-label mb-1"><?php echo __('Signature X', 'gestion'); ?></label>
                  <?php echo Html::input('SignatureX', ['value' => $config->SignatureX(), 'class' => 'form-control', 'id' => 'SignatureX']); ?>
               </div>
               <div class="col-12 col-md-4">
                  <label for="SignatureY" class="form-label mb-1"><?php echo __('Signature Y', 'gestion'); ?></label>
                  <?php echo Html::input('SignatureY', ['value' => $config->SignatureY(), 'class' => 'form-control', 'id' => 'SignatureY']); ?>
               </div>
               <div class="col-12 col-md-4">
                  <label for="SignatureSize" class="form-label mb-1"><?php echo __('Signature taille', 'gestion'); ?></label>
                  <?php echo Html::input('SignatureSize', ['value' => $config->SignatureSize(), 'class' => 'form-control', 'id' => 'SignatureSize']); ?>
               </div>
            </div>
            </div>

            <!-- Bloc Signataire : ~2/5 de la largeur (5 colonnes Bootstrap) -->
            <div class="col-12 col-xl-5">
            <div class="text-muted fw-semibold mb-2">
               <?php echo __('Position nom du signataire', 'gestion'); ?>
            </div>
            <div class="row g-3">
               <div class="col-6">
                  <label for="SignataireX" class="form-label mb-1"><?php echo __('Position X', 'gestion'); ?></label>
                  <?php echo Html::input('SignataireX', ['value' => $config->SignataireX(), 'class' => 'form-control', 'id' => 'SignataireX']); ?>
               </div>
               <div class="col-6">
                  <label for="SignataireY" class="form-label mb-1"><?php echo __('Position Y', 'gestion'); ?></label>
                  <?php echo Html::input('SignataireY', ['value' => $config->SignataireY(), 'class' => 'form-control', 'id' => 'SignataireY']); ?>
               </div>
            </div>
            </div>
         </div>

         <!-- Ligne 2 : Date (2) + Technicien (2) -->
         <div class="row gy-4 gx-4 mt-2">
            <!-- Bloc Date -->
            <div class="col-12 col-xl-6">
            <div class="text-muted fw-semibold mb-2">
               <?php echo __('Position date de signature', 'gestion'); ?>
            </div>
            <div class="row g-3">
               <div class="col-6">
                  <label for="DateX" class="form-label mb-1"><?php echo __('Position X', 'gestion'); ?></label>
                  <?php echo Html::input('DateX', ['value' => $config->DateX(), 'class' => 'form-control', 'id' => 'DateX']); ?>
               </div>
               <div class="col-6">
                  <label for="DateY" class="form-label mb-1"><?php echo __('Position Y', 'gestion'); ?></label>
                  <?php echo Html::input('DateY', ['value' => $config->DateY(), 'class' => 'form-control', 'id' => 'DateY']); ?>
               </div>
            </div>
            </div>

            <!-- Bloc Technicien -->
            <div class="col-12 col-xl-6">
            <div class="text-muted fw-semibold mb-2">
               <?php echo __('Position du nom du technicien', 'gestion'); ?>
            </div>
            <div class="row g-3">
               <div class="col-6">
                  <label for="TechX" class="form-label mb-1"><?php echo __('Position X', 'gestion'); ?></label>
                  <?php echo Html::input('TechX', ['value' => $config->TechX(), 'class' => 'form-control', 'id' => 'TechX']); ?>
               </div>
               <div class="col-6">
                  <label for="TechY" class="form-label mb-1"><?php echo __('Position Y', 'gestion'); ?></label>
                  <?php echo Html::input('TechY', ['value' => $config->TechY(), 'class' => 'form-control', 'id' => 'TechY']); ?>
               </div>
            </div>
            </div>
         </div>
      </div>
      </div>

      <?php
      // --- CARD : Configuration de l'affichage et Tâche cron ---
      ?>
      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0"><?php echo __("Configuration de l'affichage et Tâche cron", 'gestion'); ?></h3>
      </div>
      <div class="card-body">
         <div class="row g-3">

            <div class="col-md-6">
            <label class="form-label mb-1">
               <?php echo __("Prévisualisation du PDF avant signature", 'gestion'); ?>
               <i class='fa-solid fa-circle-info text-secondary ms-1'
                  data-bs-toggle='tooltip'
                  data-bs-placement='top'
                  title="<?php echo __("(cela peut provoquer des ralentissements). Vérifiez également la configuration de SharePoint pour l'autorisation de partage par lien.", 'gestion'); ?>"></i>
            </label>
            <?php Dropdown::showYesNo('SharePointLinkDisplay', $config->SharePointLinkDisplay(), -1); ?>
            </div>

            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __("Nombre d'éléments maximum à afficher par requête", 'gestion'); ?></label>
            <div>
               <?php
                  $dropdownValues = [];
                  for ($i = 10; $i <= 500; $i += 10) $dropdownValues[$i] = $i;
                  Dropdown::showFromArray('NumberViews', $dropdownValues, [
                  'value' => $config->NumberViews(),
                  ]);
               ?>
            </div>
            </div>

            <div class="col-md-6">
               <label class="form-label mb-1"><?php echo __('Affichage du formulaire dans un modal (Vide pour désactivé)', 'gestion'); ?></label>
               <div>
                  <?php
                  $formcreator_forms = [];
                  global $DB;
                  $result = $DB->doQuery("SELECT `id`, `name` FROM `glpi_forms_forms`");
                  if ($result) {
                     while ($data = $DB->fetchAssoc($result)) {
                        $formcreator_forms[$data['id']] = $data['name'];
                     }
                  }
                  Dropdown::showFromArray('formulaire', $formcreator_forms, [
                     'value'               => $config->formulaire(),
                     'display_emptychoice' => 1,
                     'emptylabel'          => "-----"
                  ]);
                  ?>
               </div>
            </div>

         </div>
      </div>
      </div><?php

      //---------------------------------------------------------------------------------
      $result = [];
      if(!empty($config->TenantID()) && $config->SharePointOn() == 1){
         // Utilisation
         try {
            $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
            if (isset($result['status']) && $result['status'] === true) {
               $checkcon = 'Connexion API : <i class="fa fa-check-circle fa-xl text-success"></i></i>' . "\n";
               try {              
                  // Étape 2 : Récupérer l'ID du site
                  $siteId = '';
                  $siteId = $sharepoint->getSiteId($config->Hostname(), $config->SitePath());
               } catch (Exception $e) {
                  $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Erreur : '.$e->getMessage().'"></i>';
               }
            } else {
               $checkcon = 'Connexion API : <i class="fa fa-times-circle fa-xl text-danger"></i>' . "\n";
               $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-secondary" data-bs-toggle="tooltip" data-bs-placement="top" title="'.$result['message'].'"></i>';
            }
         } catch (Exception $e) {
            $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Erreur inattendue : '.$e->getMessage().'"></i>';
         }      
      }

      if ($config->SharePointOn() == 1): ?>
      <div class="card mb-3">
         <div class="card-header">
            <h3 class="card-title mb-0">
            <?php echo __('Connexion SharePoint (API Graph) | '.$checkcon.$errorcon, 'gestion'); ?>
            </h3>
         </div>
         <div class="card-body">
            <div class="row g-3">
            <div class="col-md-6">
               <label for="TenantID" class="form-label mb-1"><?php echo __('Tenant ID', 'gestion'); ?></label>
               <?php echo Html::input('TenantID', ['value' => $config->TenantID(), 'class' => 'form-control', 'id' => 'TenantID']); ?>
            </div>
            <div class="col-md-6">
               <label for="ClientID" class="form-label mb-1"><?php echo __('Client ID', 'gestion'); ?></label>
               <?php echo Html::input('ClientID', ['value' => $config->ClientID(), 'class' => 'form-control', 'id' => 'ClientID']); ?>
            </div>

            <div class="col-md-6">
               <label for="ClientSecret" class="form-label mb-1"><?php echo __('Client Secret', 'gestion'); ?></label>
               <?php echo Html::input('ClientSecret', ['value' => $config->ClientSecret(), 'class' => 'form-control', 'id' => 'ClientSecret']); ?>
            </div>
            <div class="col-md-6">
               <label for="Hostname" class="form-label mb-1"><?php echo __('Nom d’hôte', 'gestion'); ?></label>
               <?php echo Html::input('Hostname', ['value' => $config->Hostname(), 'class' => 'form-control', 'id' => 'Hostname']); ?>
            </div>

            <div class="col-md-6">
               <label for="SitePath" class="form-label mb-1"><?php echo __('Chemin du Site (/sites/XXXX)', 'gestion'); ?></label>
               <?php echo Html::input('SitePath', ['value' => $config->SitePath(), 'class' => 'form-control', 'id' => 'SitePath']); ?>
            </div>

            <div class="col-md-6">
               <label class="form-label mb-1 d-block"><?php echo __('Recherche de documents dans SharePoint', 'gestion'); ?></label>
               <?php
                  $SharePointSearch = $config->SharePointSearch();
                  echo '<input type="hidden" name="SharePointSearch" value="0">';
               ?>
               <div class="form-check form-switch">
                  <input class="form-check-input"
                        type="checkbox"
                        id="SharePointSearch_switch"
                        name="SharePointSearch"
                        value="1" <?php echo ($SharePointSearch == 1 ? 'checked' : ''); ?>>
                  <label class="form-check-label" for="SharePointSearch_switch"><?php echo __('Activer', 'gestion'); ?></label>
               </div>
            </div>
            </div>
         </div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
         <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0"><?php echo __('Connexion SharePoint', 'gestion'); ?></h3>
            <button type="button"
                     class="btn btn-outline-primary btn-sm"
                     data-bs-toggle="modal"
                     data-bs-target="#customModal">
               <?php echo __('Statut de connexion SharePoint', 'gestion'); ?>
            </button>
         </div>

         <div class="card-body">
            <p class="text-muted mb-0">
               <?php echo __('Cliquez sur "Statut de connexion SharePoint" pour afficher le détail des vérifications.', 'gestion'); ?>
            </p>
         </div>
      </div>

      <?php

      // ---------- MODAL ----------
      $result = $sharepoint->checkSharePointAccess();

      $statusIcons = [
      1 => '<i class="fa fa-check-circle text-success"></i>', // ✅ Succès
      0 => '<i class="fa fa-times-circle text-danger"></i>'   // ❌ Échec
      ];

      $fields = [
      'accessToken'      => __('Token d\'accès', 'gestion'),
      'sharePointAccess' => __('Accès SharePoint', 'gestion'),
      'siteID'           => __('Site ID', 'gestion'),
      'graphQuery'       => __('Microsoft Graph Query', 'gestion'),
      'driveAccess'      => sprintf(__('Accès au Drive : <br> - %s', 'gestion'), Html::entities_deep($config->Global())),
      'permissions'      => sprintf(__('Permissions SharePoint : <br> - %s', 'gestion'), Html::entities_deep($config->Global()))
      ];
      ?>

      <div class="modal fade" id="customModal" tabindex="-1" aria-labelledby="AddGestionModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
         <div class="modal-content">

            <div class="modal-header">
            <h5 class="modal-title" id="AddGestionModalLabel">
               <?php echo __('Statut de connexion SharePoint', 'gestion'); ?>
               <i class="fa-solid fa-circle-info text-secondary ms-1"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  title="<?php echo __(
                     "Pensez à vérifier les droits de suppression, de lecture et d'écriture sur le site SharePoint afin d'assurer son bon fonctionnement et une récupération optimale des métadonnées.",
                     'gestion'
                  ); ?>"></i>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Fermer', 'gestion'); ?>"></button>
            </div>

            <div class="modal-body">
            <ul class="list-group">

               <!-- En-tête -->
               <li class="list-group-item">
                  <div class="row fw-bold">
                  <div class="col-4"><?php echo __('Champ', 'gestion'); ?></div>
                  <div class="col-6"><?php echo __('Statut', 'gestion'); ?></div>
                  <div class="col-2 text-center"><?php echo __('Validation', 'gestion'); ?></div>
                  </div>
               </li>

               <?php
               foreach ($fields as $key => $label) {
                  if (!isset($result[$key])) {
                  continue;
                  }
                  $status  = (int)($result[$key]['status'] ?? 0);
                  $message = (string)($result[$key]['message'] ?? '');
                  $icon    = ($key !== 'permissions') ? ($statusIcons[$status] ?? $statusIcons[0]) : '';

                  echo "<li class='list-group-item'>";
                  echo "<div class='row align-items-center gy-1'>";

                     // Colonne Champ
                     echo "<div class='col-4'><strong>$label</strong></div>";

                     // Colonne Statut (message + cas particuliers)
                     echo "<div class='col-6'>";
                        if ($key === 'permissions' && !empty($result[$key]['roles'])) {
                        echo "<ul class='list-unstyled mb-0'>";
                        foreach ($result[$key]['roles'] as $group => $roles) {
                           $roleList = implode(', ', array_map('Html::entities_deep', $roles));
                           echo "<li><strong>".Html::entities_deep($group)." :</strong> $roleList</li>";
                        }
                        echo "</ul>";
                        } else {
                        $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

                        // Ajout d'une icône d'information uniquement pour driveAccess
                        if ($key === 'driveAccess') {
                           if (strpos($message, 'modifier des fichiers') !== false) {
                              $safeMsg .= " <i class='fa-solid fa-circle-exclamation text-warning' data-bs-toggle='tooltip'
                                             data-bs-placement='top'
                                             title='".__(
                                                "Le plugin ne pourra pas supprimer ou télécharger automatiquement les documents après signature, il ne sera pas fonctionnel à 100%",
                                                'gestion'
                                             )."'></i>";
                           } elseif (strpos($message, 'uniquement lire les fichiers') !== false) {
                              $safeMsg .= " <i class='fa-solid fa-circle-info text-secondary' data-bs-toggle='tooltip'
                                             data-bs-placement='top'
                                             title='".__(
                                                "Le plugin ne pourra pas supprimer, télécharger ou modifier automatiquement les documents après signature",
                                                'gestion'
                                             )."'></i>";
                           }
                        }

                        echo $safeMsg;
                        }
                     echo "</div>";

                     // Colonne Validation (icône)
                     echo "<div class='col-2 text-center'>$icon</div>";

                  echo "</div>";
                  echo "</li>";
               }
               ?>

            </ul>
            </div>

            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('Fermer', 'gestion'); ?></button>
            </div>

         </div>
      </div>
      </div>

      <script>
         // Bootstrap 5 : init tooltips quand le DOM est prêt
         document.addEventListener('DOMContentLoaded', function () {
         document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
         });
         });

         // (Optionnel) conserve tes helpers d'accordion si utilisés ailleurs
         function toggleConfigSection(btn)  {
         const section = btn.closest('table').querySelector('.config-section');
         const arrow = btn.querySelector('.arrow');
         const isVisible = section.style.display === 'table-row-group';
         section.style.display = isVisible ? 'none' : 'table-row-group';
         arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
         }
         function toggleConfigSection1(btn) {
         const section = btn.closest('table').querySelector('.config-section1');
         const arrow = btn.querySelector('.arrow');
         const isVisible = section.style.display === 'table-row-group';
         section.style.display = isVisible ? 'none' : 'table-row-group';
         arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
         }
         function toggleConfigSection2(btn) {
         const section = btn.closest('table').querySelector('.config-section2');
         const arrow = btn.querySelector('.arrow');
         const isVisible = section.style.display === 'table-row-group';
         section.style.display = isVisible ? 'none' : 'table-row-group';
         arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
         }
         function toggleConfigSection3(btn) {
         const section = btn.closest('table').querySelector('.config-section3');
         const arrow = btn.querySelector('.arrow');
         const isVisible = section.style.display === 'table-row-group';
         section.style.display = isVisible ? 'none' : 'table-row-group';
         arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
         }
      </script>

      <style>
      /* Alignement propre dans la liste du modal */
      #customModal .list-group-item .row > [class^="col-"] { display: flex; align-items: center; }
      /* Optionnel : renforce l’alignement vertical */
      #customModal .list-group-item { padding-top: .6rem; padding-bottom: .6rem; }
      </style>

      <?php if ($config->SageOn() == 1): ?>
      <div class="card mb-3">
         <div class="card-header">
            <h3 class="card-title mb-0"><?php echo __('Connexion Sage Local', 'gestion'); ?></h3>
         </div>
         <div class="card-body">
            <div class="row g-3">
            <div class="col-md-6">
               <label for="SageUrlApi" class="form-label mb-1"><?php echo __('Url Api Sage', 'gestion'); ?></label>
               <?php echo Html::input('SageUrlApi', ['value' => $config->SageUrlApi(), 'class' => 'form-control', 'id' => 'SageUrlApi']); ?>
            </div>
            <div class="col-md-6">
               <label for="SageToken" class="form-label mb-1"><?php echo __('Sage Token', 'gestion'); ?></label>
               <?php echo Html::input('SageToken', ['value' => $config->SageToken(), 'class' => 'form-control', 'id' => 'SageToken']); ?>
            </div>

            <div class="col-md-6">
               <label class="form-label mb-1 d-block"><?php echo __('Recherche de documents dans Sage', 'gestion'); ?></label>
               <?php
                  $SageSearch = $config->SageSearch();
                  echo '<input type="hidden" name="SageSearch" value="0">';
               ?>
               <div class="form-check form-switch">
                  <input class="form-check-input"
                        type="checkbox"
                        id="SageSearch_switch"
                        name="SageSearch"
                        value="1" <?php echo ($SageSearch == 1 ? 'checked' : ''); ?>>
                  <label class="form-check-label" for="SageSearch_switch"><?php echo __('Activer', 'gestion'); ?></label>
               </div>
            </div>
            </div>
         </div>
      </div>
      <?php endif;

   // ---------------------------------------------------------------
   // BIBLIOTHÈQUES (CARTE + TABLEAU ÉDITABLE AVEC AJOUT/SUPPRESSION)
   // ---------------------------------------------------------------

      // Carte — choix du mode par défaut (inchangé)
      ?>
      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0"><?php echo __('Bibliothèques', 'gestion'); ?></h3>
      </div>
      <div class="card-body">
         <div class="row g-3 align-items-end">
            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __('Mode de recheche par defaut :', 'gestion'); ?></label>
            <div>
               <?php
               $values4 = [];
               if ($config->SharePointOn() == 1)           $values4[0] = 'Sharepoint';
               if ($config->SageOn() == 1)                 $values4[1] = 'Sage Local';
               if ($config->mode() != 0 || $mode == false) $values4[2] = 'Local';

               Dropdown::showFromArray('mode', $values4, [
                  'value' => $config->mode(),
               ]);
               ?>
            </div>
            </div>
         </div>
      </div>
      </div>

      <?php
      // Carte — choix de la bibliothèque SharePoint (inchangé) + tableau des dossiers
      if ($mode == true && (!empty($config->TenantID()) || !empty($config->SageToken()))) :

      // --- Sélection de la bibliothèque SharePoint (inchangé)
      if ($config->SharePointOn() == 1 && (isset($result['status']) && $result['status'] === true)) {
         // Récupérer les bibliothèques de documents du site
         $drives  = $sharepoint->getDrives($siteId);
         $values3 = [];
         foreach ($drives as $drive) {
            $name = ($drive['name'] == 'Documents') ? 'Documents partages' : $drive['name'];
            $values3[$name] = $name;
         }
         ?>
         <div class="card mb-3">
            <div class="card-header">
            <h3 class="card-title mb-0">
               <?php echo __("Bibliothèques SharePoint", "gestion"); ?>
               <i class='fa-solid fa-circle-exclamation text-warning ms-2'
                  data-bs-toggle='tooltip' data-bs-placement='top'
                  title="<?php echo __(
                     "Attention : toute modification de la bibliothèque après l’utilisation d’une bibliothèque précédente peut entraîner des bugs ou des conflits.",
                     'gestion'
                  ); ?>"></i>
            </h3>
            </div>
            <div class="card-body">
            <?php
               Dropdown::showFromArray('Global', $values3, [
                  'value' => $config->Global(),
               ]);
            ?>
            </div>
         </div>
         <?php
      }

      // --- TABLEAU des dossiers (remplace l’ancien “Ajouter un dossier”)
      // Lecture des lignes existantes
      $rows = [];
      $res  = $DB->doQuery("SELECT id, folder_name, params FROM glpi_plugin_gestion_configsfolder ORDER BY id ASC");
      if ($res) {
         while ($r = $DB->fetchAssoc($res)) {
            $rows[] = $r;
         }
      }

      // Construit la liste d’options $values2 selon ta logique existante (sans “Supprimer le dossier” car on a un bouton)
      if ($config->SageOn() == 1 && $config->SharePointOn() == 0) {
         $values2 = [
            2  => __('Dossier de destination (Dépot Local)', 'gestion'),
            5  => __('Envoyé un mail si visible dans le tracker', 'gestion'),
            8  => __('__Non attribué__', 'gestion'),
            10 => __('Eléments de recheche', 'gestion'),
         ];
      } elseif ($config->SageOn() == 0 && $config->SharePointOn() == 1) {
         $values2 = [
            1  => __('Dossier de récupération (Recursive SharePoint)', 'gestion'),
            2  => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
            5  => __('Envoyé un mail si visible dans le tracker', 'gestion'),
            8  => __('__Non attribué__', 'gestion'),
            10 => __('Eléments de recheche', 'gestion'),
         ];
      } elseif ($config->SageOn() == 1 && $config->SharePointOn() == 1) {
         if ($config->mode() == 1) {
            $values2 = [
            2  => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
            3  => __('Dossier de destination (Dépot Local)', 'gestion'),
            5  => __('Envoyé un mail si visible dans le tracker', 'gestion'),
            8  => __('__Non attribué__', 'gestion'),
            10 => __('Eléments de recheche', 'gestion'),
            ];
         } else {
            $values2 = [
            1  => __('Dossier de récupération (Recursive SharePoint)', 'gestion'),
            2  => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
            3  => __('Dossier de destination (Dépot Local)', 'gestion'),
            5  => __('Envoyé un mail si visible dans le tracker', 'gestion'),
            8  => __('__Non attribué__', 'gestion'),
            10 => __('Eléments de recheche', 'gestion'),
            ];
         }
      } else {
         // fallback si rien d'activé (devrait rester vide normalement)
         $values2 = [
            8 => __('__Non attribué__', 'gestion'),
         ];
      }
      ?>

      <div class="card mb-3">
         <div class="card-header">
            <h3 class="card-title mb-0">
            <?php echo __("Dossiers d'enregistrement du Site", 'gestion'); ?>
            <small class="text-muted d-block">
               <?php echo __("Voir SharePoint : le nom des dossiers contenus dans la bibliothèque principale", 'gestion'); ?>
            </small>
            </h3>
         </div>

         <div class="card-body">
            <div class="table-responsive">
            <table class="table table-sm align-middle" id="foldersTable">
               <thead>
                  <tr>
                  <th style="width:45%"><?php echo __('Nom du dossier', 'gestion'); ?></th>
                  <th style="width:45%"><?php echo __('Action', 'gestion'); ?></th>
                  <th style="width:10%"></th>
                  </tr>
               </thead>
               <tbody>
               <?php if (!empty($rows)): ?>
                  <?php foreach ($rows as $r): ?>
                  <tr data-id="<?php echo (int)$r['id']; ?>">
                     <td>
                        <input type="text"
                              name="folders[<?php echo (int)$r['id']; ?>][folder_name]"
                              class="form-control form-control-sm"
                              value="<?php echo htmlspecialchars($r['folder_name'] ?? '', ENT_QUOTES); ?>"
                              placeholder="<?php echo __('Ex : Dossiers clients', 'gestion'); ?>">
                     </td>
                     <td>
                        <?php
                        // on affiche un select simple qui poste folders[id][params]
                        Dropdown::showFromArray(
                           "folders[".(int)$r['id']."][params]",
                           $values2,
                           [
                              'value' => (int)($r['params'] ?? 8),
                              'width' => '100%',
                              'class' => 'folder-select'
                           ]
                        );
                        ?>
                     </td>
                     <td class="text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm folder-del-row" title="<?php echo __('Supprimer'); ?>">
                        <i class="fa fa-trash"></i>
                        </button>
                        <input type="hidden" name="folders[<?php echo (int)$r['id']; ?>][_delete]" value="0">
                     </td>
                  </tr>
                  <?php endforeach; ?>
               <?php endif; ?>
               </tbody>
            </table>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm" id="folderAddRow">
            <i class="fa fa-plus"></i> <?php echo __('Ajouter un dossier', 'gestion'); ?>
            </button>

            <input type="hidden" name="save_folders" value="1">
         </div>
      </div>

      <script>
      (function(){
      const tbody   = document.querySelector('#foldersTable tbody');
      const addBtn  = document.getElementById('folderAddRow');

      // options pour nouvelles lignes (générées depuis PHP)
      const options = <?php echo json_encode($values2, JSON_UNESCAPED_UNICODE); ?>;

      function buildSelect(nameAttr, selectedVal) {
         const sel = document.createElement('select');
         sel.name  = nameAttr;
         sel.className = 'form-select form-select-sm folder-select';
         for (const [val, label] of Object.entries(options)) {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = label;
            if (String(selectedVal) === String(val)) opt.selected = true;
            sel.appendChild(opt);
         }
         return sel;
      }

      // ➜ Exclusivité de groupe: si un select a 2 OU 3, alors 2 ET 3 sont grisés ailleurs
      function updateUniqueOptions() {
         // Trouver TOUS les selects (y compris ceux générés par PHP)
         const selects = tbody.querySelectorAll('select');

         // Réinitialiser tous les selects (activer toutes les options)
         selects.forEach(sel => {
            Array.from(sel.options).forEach(opt => {
            opt.disabled = false;
            opt.style.display = '';
            });
         });

         // Trouver qui détient 2 ou 3 actuellement
         let ownerSel = null;
         let ownerValue = null;

         selects.forEach(sel => {
            // Ignorer les lignes marquées pour suppression
            const tr = sel.closest('tr');
            if (!tr) return;
            
            const deleteInput = tr.querySelector('input[name*="_delete"]');
            if (deleteInput && deleteInput.value === '1') {
            return; // ignorer cette ligne
            }

            if ((sel.value === '2' || sel.value === '3') && !ownerSel) {
            ownerSel = sel;
            ownerValue = sel.value;
            }
         });

         // Si quelqu'un détient 2 ou 3, griser ces options dans tous les autres
         if (ownerSel && ownerValue) {
            selects.forEach(sel => {
            if (sel !== ownerSel) {
               // Ignorer les lignes marquées pour suppression
               const tr = sel.closest('tr');
               if (!tr) return;
               
               const deleteInput = tr.querySelector('input[name*="_delete"]');
               if (deleteInput && deleteInput.value === '1') {
                  return;
               }

               Array.from(sel.options).forEach(opt => {
                  if (opt.value === '2' || opt.value === '3') {
                  opt.disabled = true;
                  }
               });

               // Si ce select avait 2 ou 3 mais n'est plus le propriétaire, le remettre à la valeur par défaut
               if (sel.value === '2' || sel.value === '3') {
                  const defaultVal = options['8'] ? '8' : Object.keys(options)[0];
                  sel.value = defaultVal;
               }
            }
            });
         }
      }

      addBtn?.addEventListener('click', function () {
         const uid = 'new_' + Date.now();
         const tr  = document.createElement('tr');
         tr.innerHTML = `
            <td>
            <input type="text"
                     name="folders[${uid}][folder_name]"
                     class="form-control form-control-sm"
                     placeholder="<?php echo __('Ex : Dossiers clients', 'gestion'); ?>">
            </td>
            <td class="folder-select-cell"></td>
            <td class="text-end">
            <button type="button" class="btn btn-outline-danger btn-sm folder-del-row" title="<?php echo __('Supprimer'); ?>">
               <i class="fa fa-trash"></i>
            </button>
            <input type="hidden" name="folders[${uid}][_delete]" value="0">
            </td>`;
         tbody.appendChild(tr);

         // injecte le select (par défaut "__Non attribué__" = 8 si présent)
         const cell = tr.querySelector('.folder-select-cell');
         const defaultVal = options['8'] ? '8' : Object.keys(options)[0];
         const sel = buildSelect(`folders[${uid}][params]`, defaultVal);
         cell.appendChild(sel);

         // Mettre à jour l'état après ajout
         setTimeout(updateUniqueOptions, 100);
      });

      document.addEventListener('click', function(e){
         const btn = e.target.closest('.folder-del-row');
         if (!btn) return;
         
         const tr = btn.closest('tr');
         const hidden = tr.querySelector('input[type="hidden"][name*="_delete"]');
         
         if (hidden && tr.dataset.id) {
            // ligne existante : marquer pour suppression
            hidden.value = '1';
            tr.style.opacity = '0.4';
         } else {
            // ligne nouvelle : retrait direct
            tr.remove();
         }
         
         // Toujours mettre à jour après suppression
         setTimeout(updateUniqueOptions, 50);
      });

      // Event delegation sur le tbody
      tbody.addEventListener('change', function(e) {
         if (e.target.tagName === 'SELECT') {
            setTimeout(updateUniqueOptions, 50);
         }
      });

      // Event delegation global
      document.addEventListener('change', function(e) {
         if (e.target.tagName === 'SELECT' && e.target.closest('#foldersTable')) {
            setTimeout(updateUniqueOptions, 50);
         }
      });

      // MutationObserver pour détecter les changements de valeurs
      const observer = new MutationObserver(function(mutations) {
         let shouldUpdate = false;
         mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
            shouldUpdate = true;
            }
            if (mutation.type === 'childList') {
            mutation.addedNodes.forEach(function(node) {
               if (node.nodeType === 1 && (node.tagName === 'SELECT' || node.querySelector('select'))) {
                  shouldUpdate = true;
               }
            });
            }
         });
         if (shouldUpdate) {
            setTimeout(updateUniqueOptions, 50);
         }
      });

      observer.observe(tbody, { 
         childList: true, 
         subtree: true, 
         attributes: true,
         attributeFilter: ['value']
      });

      // Polling de secours
      let lastValues = [];
      setInterval(function() {
         const selects = tbody.querySelectorAll('select');
         const currentValues = Array.from(selects).map(s => s.value);
         
         if (JSON.stringify(currentValues) !== JSON.stringify(lastValues)) {
            lastValues = currentValues;
            updateUniqueOptions();
         }
      }, 500);

      // État initial
      setTimeout(function() {
         updateUniqueOptions();
         // Stocker les valeurs initiales pour le polling
         const selects = tbody.querySelectorAll('select');
         lastValues = Array.from(selects).map(s => s.value);
      }, 200);
      })();
      </script>
      <?php endif;

   // --------------------------------------------------------------------- Extraction d'un tracker
      // -- valeurs actuelles
      $ExtractYesNo        = (int)$config->ExtractYesNo();
      $MailTrackerYesNo    = (int)$config->MailTrackerYesNo();
      $extractSep          = (string)$config->extract();
      $gabaritTracker      = (int)$config->gabarit_tracker();
      $EntitiesExtract     = (int)$config->EntitiesExtract();
      $EntitiesExtractVal  = (string)$config->EntitiesExtractValue();
      $mode                = (int)$config->mode();
      ?>

      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0"><?php echo __('Entités et Tracker', 'gestion'); ?></h3>
      </div>

      <div class="card-body">
         <div class="row g-3">

            <!-- Extraction d'un tracker -->
            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __("Extraction d'un tracker", 'gestion'); ?></label>
            <?php Dropdown::showYesNo('ExtractYesNo', $ExtractYesNo, -1); ?>
            </div>

            <?php if ($ExtractYesNo === 1): ?>
            <?php if ($mode === 0): ?>
               <div class="col-md-6">
                  <label for="extract" class="form-label mb-1">
                  <?php echo __("Séparateurs pour l'extraction du tracker", 'gestion'); ?>
                  </label>
                  <?php
                  echo Html::input('extract', [
                     'value' => $extractSep,
                     'class' => 'form-control',
                     'id'    => 'extract'
                  ]);
                  ?>
               </div>
            <?php endif; ?>

            <div class="col-md-6">
               <label class="form-label mb-1">
                  <?php echo __("Envoyé un mail si le contenu d'un tracker est détécté (Tâche Cron)", 'gestion'); ?>
               </label>
               <?php Dropdown::showYesNo('MailTrackerYesNo', $MailTrackerYesNo, -1); ?>
            </div>

            <?php if ($MailTrackerYesNo === 1): ?>
               <div class="col-md-6">
                  <label for="MailTracker" class="form-label mb-1"><?php echo __('Mail', 'gestion'); ?></label>
                  <?php
                  echo Html::input('MailTracker', [
                     'value' => $config->MailTracker(),
                     'class' => 'form-control',
                     'id'    => 'MailTracker'
                  ]);
                  ?>
               </div>

               <div class="col-md-6">
                  <label class="form-label mb-1">
                  <?php echo __('Gabarit : Modèle de notifications pour la Tâche Cron (Tracker)', 'gestion'); ?>
                  </label>
                  <?php
                  Dropdown::show('NotificationTemplate', [
                     'name'                 => 'gabarit_tracker',
                     'value'                => $gabaritTracker,
                     'display_emptychoice'  => 1,
                     'emptylabel'           => '-----',
                     'specific_tags'        => [],
                     'itemtype'             => 'NotificationTemplate',
                     'displaywith'          => [],
                     'used'                 => [],
                     'toadd'                => [],
                     'entity_restrict'      => 0,
                  ]);
                  ?>
               </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="col-12"><hr class="my-2"></div>

            <!-- Extraire l'entité du dossier parent -->
            <div class="col-md-6">
            <label class="form-label mb-1"><?php echo __("Extraire l'entité du dossier parent", 'gestion'); ?></label>
            <?php Dropdown::showYesNo('EntitiesExtract', $EntitiesExtract, -1); ?>
            </div>

            <?php if ($EntitiesExtract === 1 && $mode === 0): ?>
            <div class="col-md-6">
               <label for="EntitiesExtractValue" class="form-label mb-1">
                  <?php echo __("Séparateurs pour l'extraction de l'entité depuis la Bibliothèques du site", 'gestion'); ?>
               </label>
               <div class="d-flex align-items-center gap-2">
                  <span><?php echo __('Après le chemin :', 'gestion'); ?></span>
                  <?php
                  echo Html::input('EntitiesExtractValue', [
                     'value' => $EntitiesExtractVal,
                     'class' => 'form-control',
                     'id'    => 'EntitiesExtractValue',
                     'style' => 'max-width:36rem'
                  ]);
                  ?>
               </div>
            </div>
            <?php endif; ?>
         </div>
      </div>
      </div>

      <?php
      // On conserve ta logique: si l'extraction tracker est OFF, on force MailTrackerYesNo à 0
      if ($ExtractYesNo !== 1 && $MailTrackerYesNo === 1) {
      $sql  = "UPDATE glpi_plugin_gestion_configs SET MailTrackerYesNo = ? WHERE id = 1";
      $stmt = $DB->prepare($sql);
      $stmt->execute([0]);
      }

//------------------------------------------------------------------- Dernière synchronisation Cron
      $lastrun = $DB->doQuery("SELECT lastrun FROM glpi_crontasks WHERE name = 'GestionPdf'")->fetch_object();
      $lastRunText = isset($lastrun->lastrun) ? $lastrun->lastrun : '';
      ?>

      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title mb-0">
            <?php echo sprintf(__('Dernière synchronisation Cron : %s', 'gestion'), Html::entities_deep($lastRunText)); ?>
         </h3>
      </div>

      <div class="card-body">

         <div class="mb-2 text-muted">
            <?php
            if ($config->mode() == 0) {
               echo __('Filtre de recherche, 500 documents max par ordre de modification et d\'ajout.', 'gestion') . '<br>';
            }
            echo __('Requête : de la date et heure suivante : ', 'gestion');
            ?>
         </div>

         <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
            <?php
            Html::showDateTimeField('LastCronTask', [
               'value'      => $config->LastCronTask(),
               'canedit'    => true,
               'maybeempty' => true,
               'mindate'    => '',
               'mintime'    => '',
               'maxdate'    => date('Y-m-d H:i:s'),
            ]);
            ?>
            <span class="text-muted">
            &nbsp;→ <?php echo __('Jusqu\'à la date et heure d’exécution de la tâche cron.', 'gestion'); ?>
            </span>
         </div>

         <!-- Astuce d’UI éventuelle (masquer un bouton spécifique si nécessaire) -->
         <style>button[btn-id="0"]{display:none!important}</style>

      </div>
      </div>
      <?php

      // Facture au comptoire NEW VERSION
      // valeurs actuelles
      $CounterInvoice      = (int)$config->CounterInvoice();
      $CounterInvoiceUsers = $config->CounterInvoiceUsers();   // array d'IDs
      $CounterInvoiceMail  = (string)$config->CounterInvoiceMail();
      $CounterInvoiceText  = (string)$config->CounterInvoiceText();
      $CounterInvoicePdf   = (int)$config->CounterInvoicePdf();
      ?>

      <div class="card mb-3">
      <div class="card-header">
         <h3 class="card-title"><?php echo __('Facturation comptoir', 'gestion'); ?></h3>
      </div>

      <div class="card-body">
         <div class="row g-3">

            <!-- ON/OFF principal -->
            <div class="col-md-6">
            <label for="CounterInvoice_switch" class="form-label mb-1">
               <?php echo __('Activer la facturation comptoir sur BL', 'gestion'); ?>
            </label>
            <div class="form-check form-switch">
               <!-- fallback à 0 si décoché -->
               <input type="hidden" name="CounterInvoice" value="0">
               <input class="form-check-input"
                     type="checkbox"
                     id="CounterInvoice_switch"
                     name="CounterInvoice"
                     value="1"
                     <?php echo ($CounterInvoice === 1 ? 'checked' : ''); ?>>
            </div>
            </div>

            <!-- Utilisateurs autorisés -->
            <div class="col-md-6">
            <label class="form-label mb-1">
               <?php echo __('Utilisateurs GLPI autorisés', 'gestion'); ?>
            </label>
            <?php
               Dropdown::show('User', [
                  'name'     => 'CounterInvoiceUsers[]',
                  'multiple' => true,
                  'value'    => $CounterInvoiceUsers,
                  'width'    => '100%',
               ]);
            ?>
            </div>

            <!-- Mail interne -->
            <div class="col-md-6">
            <label for="CounterInvoiceMail" class="form-label mb-1">
               <?php echo __("Mail interne de configation de payement comptoir", "gestion"); ?>
            </label>
            <?php
               echo Html::input('CounterInvoiceMail', [
                  'value'       => $CounterInvoiceMail,
                  'class'       => 'form-control',
                  'placeholder' => 'facturation@exemple.fr',
                  'id'          => 'CounterInvoiceMail',
               ]);
            ?>
            </div>

            <!-- Titre du règlement -->
            <div class="col-md-6">
            <label for="CounterInvoiceText" class="form-label mb-1">
               <?php echo __('Titre du réglement comptoir', 'gestion'); ?>
            </label>
            <?php
               echo Html::input('CounterInvoiceText', [
                  'value'       => $CounterInvoiceText,
                  'class'       => 'form-control',
                  'placeholder' => __('Ex : Payé au comptoir', 'gestion'),
                  'id'          => 'CounterInvoiceText',
               ]);
            ?>
            </div>

            <!-- Libellé “Payé Comptoir” sur le BL -->
            <div class="col-md-6">
            <label for="CounterInvoicePdf_switch" class="form-label mb-1">
               <?php echo __("Libelé 'Payé Comptoir' sur le BL", "gestion"); ?>
            </label>
            <div class="form-check form-switch">
               <input type="hidden" name="CounterInvoicePdf" value="0">
               <input class="form-check-input"
                     type="checkbox"
                     id="CounterInvoicePdf_switch"
                     name="CounterInvoicePdf"
                     value="1"
                     <?php echo ($CounterInvoicePdf === 1 ? 'checked' : ''); ?>>
            </div>
            </div>

         </div>
      </div>
      </div>
      <?php

      // --------------------- SECTION : SIGNATURE DÉPORTÉE (tablette — APPAPPLETAB) ---------------------
      // Lecture des appareils kiosque enregistrés (auto-enregistrés par l'app, sans token)
      $devicesExist = $DB->tableExists('glpi_plugin_gestion_devices');
      $kiosk_devices = $devicesExist
         ? $DB->request(['FROM' => 'glpi_plugin_gestion_devices', 'ORDER' => 'last_seen DESC'])
         : [];
      ?>

      <div class="card mb-3">
         <div class="card-header d-flex align-items-center gap-2">
            <i class="fa-solid fa-tablet-screen-button me-1"></i>
            <h3 class="card-title mb-0"><?php echo __('Signature déportée', 'gestion'); ?></h3>
         </div>
         <div class="card-body">
            <?php
            $RemoteSignatureOn = (int)$config->RemoteSignatureOn();
            $selectedUsers     = $config->RemoteSignatureUsers();
            ?>
            <div class="row g-3 mb-3">
               <div class="col-md-6">
                  <label for="RemoteSignatureOn_switch" class="form-label mb-1">
                     <?php echo __('Activer la signature déportée', 'gestion'); ?>
                  </label>
                  <div class="form-check form-switch">
                     <input type="hidden" name="RemoteSignatureOn" value="0">
                     <input class="form-check-input"
                           type="checkbox"
                           id="RemoteSignatureOn_switch"
                           name="RemoteSignatureOn"
                           value="1"
                           <?php echo ($RemoteSignatureOn === 1 ? 'checked' : ''); ?>>
                  </div>
               </div>
               <div class="col-md-6">
                  <label class="form-label mb-1">
                     <?php echo __('Utilisateurs GLPI autorisés à déclencher', 'gestion'); ?>
                  </label>
                  <?php
                     Dropdown::show('User', [
                        'name'     => 'RemoteSignatureUsers[]',
                        'multiple' => true,
                        'value'    => $selectedUsers,
                        'width'    => '100%',
                     ]);
                  ?>
               </div>
            </div>
            <hr class="my-3">

            <!-- ─── Tableau des appareils kiosque connectés ──────────────────── -->
            <h5 class="mb-2">
               <i class="fa fa-mobile-screen me-1"></i>
               <?php echo __('Appareils connectés', 'gestion'); ?>
            </h5>
            <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="devicesTable">
               <thead class="table-light">
                  <tr>
                     <th><?php echo __('N° de série', 'gestion'); ?></th>
                     <th><?php echo __('Nom', 'gestion'); ?></th>
                     <th><?php echo __('Adresse IP', 'gestion'); ?></th>
                     <th><?php echo __('Dernière vue', 'gestion'); ?></th>
                     <th class="text-center"><?php echo __('Statut', 'gestion'); ?></th>
                     <th class="text-center"><?php echo __('Actions', 'gestion'); ?></th>
                  </tr>
               </thead>
               <tbody>
               <?php
               $hasDevices = false;
               foreach ($kiosk_devices as $dev):
                  $hasDevices  = true;
                  $devId       = (int)$dev['id'];
                  $devSerial   = htmlspecialchars((string)($dev['serial']    ?? ''), ENT_QUOTES);
                  $devName     = htmlspecialchars((string)($dev['name']      ?? ''), ENT_QUOTES);
                  $devIp       = htmlspecialchars((string)($dev['ip']        ?? ''), ENT_QUOTES);
                  $devLastSeen = htmlspecialchars((string)($dev['last_seen'] ?? ''), ENT_QUOTES);
                  $devStatus   = (string)($dev['status'] ?? 'active');
                  $isBanned    = ($devStatus === 'banned');
                  $badgeClass  = $isBanned ? 'bg-danger' : 'bg-success';
                  $badgeLabel  = $isBanned ? __('Banni', 'gestion') : __('Actif', 'gestion');
               ?>
               <tr class="<?php echo $isBanned ? 'table-danger' : ''; ?>">
                  <td><code><?php echo $devSerial; ?></code></td>
                  <td><?php echo $devName !== '' ? $devName : '<span class="text-muted">—</span>'; ?></td>
                  <td><small><?php echo $devIp !== '' ? $devIp : '—'; ?></small></td>
                  <td><small><?php echo $devLastSeen !== '' ? $devLastSeen : '—'; ?></small></td>
                  <td class="text-center">
                     <span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                  </td>
                  <td class="text-center">
                     <?php if ($isBanned): ?>
                     <button type="submit"
                             name="device_action_submit"
                             value="<?php echo 'unban:' . $devId; ?>"
                             formnovalidate
                             class="btn btn-success btn-sm d-inline"
                             title="<?php echo __('Débannir', 'gestion'); ?>">
                        <i class="fa fa-check"></i> <?php echo __('Débannir', 'gestion'); ?>
                     </button>
                     <?php else: ?>
                     <button type="submit"
                             name="device_action_submit"
                             value="<?php echo 'ban:' . $devId; ?>"
                             formnovalidate
                             class="btn btn-warning btn-sm d-inline"
                             title="<?php echo __('Bannir', 'gestion'); ?>"
                             onclick="return confirm('<?php echo __('Confirmer le bannissement de cet appareil ?', 'gestion'); ?>')">
                        <i class="fa fa-ban"></i> <?php echo __('Bannir', 'gestion'); ?>
                     </button>
                     <?php endif; ?>
                     <button type="submit"
                             name="device_action_submit"
                             value="<?php echo 'delete:' . $devId; ?>"
                             formnovalidate
                             class="btn btn-outline-danger btn-sm d-inline ms-1"
                             title="<?php echo __('Supprimer', 'gestion'); ?>"
                             onclick="return confirm('<?php echo __('Supprimer définitivement cet appareil ?', 'gestion'); ?>')">
                        <i class="fa fa-trash"></i>
                     </button>
                  </td>
               </tr>
               <?php endforeach; ?>
               <?php if (!$hasDevices): ?>
               <tr>
                  <td colspan="6" class="text-center text-muted py-3">
                     <i class="fa fa-circle-info me-1"></i>
                     <?php echo __('Aucun appareil enregistré. Lancez l\'app APPAPPLETAB sur la tablette pour l\'enregistrer automatiquement.', 'gestion'); ?>
                  </td>
               </tr>
               <?php endif; ?>
               </tbody>
            </table>
            </div>
         </div>
      </div>
      <?php
      //---------------------------------------------------------------------------------------------------------------------

      // --------------------- SECTION : SIGNATURE BL À L'AJOUT DE TÂCHE ---------------------
      $taskSigUsers  = $config->TaskSignatureUsers();
      $taskSigStates = $config->TaskSignatureTriggerStates();
      $planningBLSignatureOn = (int)$config->PlanningBLSignatureOn();
      $taskStateValues = [
         Planning::INFO => Planning::getState(Planning::INFO),
         Planning::TODO => Planning::getState(Planning::TODO),
         Planning::DONE => Planning::getState(Planning::DONE),
      ];
      ?>
      <div class="card mb-3">
         <div class="card-header">
            <h3 class="card-title"><?php echo __('Signature BL à l\'ajout de tâche', 'gestion'); ?></h3>
         </div>
         <div class="card-body">
            <div class="row g-3">
               <div class="col-md-6">
                  <label class="form-label mb-1">
                     <?php echo __('Technicien(s) autorisé(s)', 'gestion'); ?>
                  </label>
                  <?php
                     Dropdown::show('User', [
                        'name'     => 'TaskSignatureUsers[]',
                        'multiple' => true,
                        'value'    => $taskSigUsers,
                        'width'    => '100%',
                     ]);
                  ?>
               </div>
               <div class="col-md-6">
                  <label class="form-label mb-1">
                     <?php echo __('Déclencheur(s) sur état de tâche', 'gestion'); ?>
                  </label>
                  <?php
                     Dropdown::showFromArray('TaskSignatureTriggerStates', $taskStateValues, [
                        'multiple' => true,
                        'values'   => $taskSigStates,
                        'width'    => '100%',
                     ]);
                  ?>
                  <div class="form-text text-muted">
                     <?php echo __('Par défaut : Fait.', 'gestion'); ?>
                  </div>
               </div>
               <div class="col-md-12">
                  <label for="PlanningBLSignatureOn_switch" class="form-label mb-1">
                     <?php echo __('Signature BL cliquable depuis le planning GLPI', 'gestion'); ?>
                  </label>
                  <div class="form-check form-switch">
                     <input type="hidden" name="PlanningBLSignatureOn" value="0">
                     <input class="form-check-input"
                           type="checkbox"
                           id="PlanningBLSignatureOn_switch"
                           name="PlanningBLSignatureOn"
                           value="1"
                           <?php echo ($planningBLSignatureOn === 1 ? 'checked' : ''); ?>>
                     <label class="form-check-label" for="PlanningBLSignatureOn_switch"><?php echo __('Activer', 'gestion'); ?></label>
                  </div>
                  <div class="form-text text-muted">
                     <?php echo __('Rend les numeros BL detectes dans les evenements du planning cliquables pour ouvrir le formulaire de signature Gestion.', 'gestion'); ?>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <?php

      // Base Description / Info : SUPPRIMÉ (v1.7.0_alpha1 — ne plus utiliser)
      // La table glpi_plugin_gestion_baseitems est droppée en migration.

      echo "<table class='tab_cadre_fixe'>";
         echo "<tr class='tab_bg_1'>";
            echo "<td class='right'>";
               echo "<input type='submit' class='submit' name='update' value=\"" . __('Save') . "\">";
            echo "</td>";
         echo "</tr>";
      echo "</table>";

      // showFormButtons() closes a table before rendering buttons; open a minimal one to keep valid HTML
      echo "<table class='tab_cadre_fixe'>";
      $config->showFormButtons(['candel' => false]);
      return false;
   }

   // --- Counter Invoice ---
   function CounterInvoice() { // NEW
      return isset($this->fields['CounterInvoice']) ? (int)$this->fields['CounterInvoice'] : 0;
   }
   function CounterInvoiceUsers() {
      $raw = $this->fields['CounterInvoiceUsers'] ?? '[]';
      $arr = json_decode($raw, true);
      return is_array($arr) ? $arr : [];
   }
   function CounterInvoicePdf() {
      return isset($this->fields['CounterInvoicePdf']) ? (int)$this->fields['CounterInvoicePdf'] : 0;
   }
   function CounterInvoiceMail(){
      return isset($this->fields['CounterInvoiceMail']) ? (string)$this->fields['CounterInvoiceMail'] : '';
   } 
   function CounterInvoiceText(){
      return isset($this->fields['CounterInvoiceText']) ? (string)$this->fields['CounterInvoiceText'] : '';
   }

   // old
   function formulaire(){
      return ($this->fields['formulaire']);
   }
   function AddFileSite(){
      if (isset($this->fields['AddFileSite'])) return ($this->fields['AddFileSite']);
   }
   function Global(){
      return ($this->fields['Global']);
   }
   function LastCronTask(){
      return ($this->fields['LastCronTask']);
   }
   function SignatureX(){
      return ($this->fields['SignatureX']);
   } 
   function SignatureY(){
      return ($this->fields['SignatureY']);
   } 
   function SignatureSize(){
      return ($this->fields['SignatureSize']);
   } 
   function ExtractYesNo(){
      return ($this->fields['ExtractYesNo']);
   } 
   function extract(){
      return ($this->fields['extract']);
   } 
   function MailTrackerYesNo(){
      return ($this->fields['MailTrackerYesNo']);
   } 
   function MailTracker(){
      return ($this->fields['MailTracker']);
   } 
   function EntitiesExtract(){
      return ($this->fields['EntitiesExtract']);
   } 
   function EntitiesExtractValue(){
      return ($this->fields['EntitiesExtractValue']);
   } 
   function SignataireX(){
      return ($this->fields['SignataireX']);
   } 
   function SignataireY(){
      return ($this->fields['SignataireY']);
   } 
   function DateX(){
      return ($this->fields['DateX']);
   } 
   function DateY(){
      return ($this->fields['DateY']);
   } 
   function TechX(){
      return ($this->fields['TechX']);
   } 
   function TechY(){
      return ($this->fields['TechY']);
   }
   function NumberViews(){
      return ($this->fields['NumberViews']);
   }
   function ZenDocMail(){
      return ($this->fields['ZenDocMail']);
   }
   function SharePointLinkDisplay(){
      return ($this->fields['SharePointLinkDisplay']);
   }
   function DisplayPdfEnd(){
      return ($this->fields['DisplayPdfEnd']);
   }
   function MailTo(){
      return ($this->fields['MailTo']);
   }
   function CombinedMailMode(){
      return isset($this->fields['CombinedMailMode']) ? (int)$this->fields['CombinedMailMode'] : 0;
   }
   function gabarit(){
      return ($this->fields['gabarit']);
   }
   function ConfigModes(){
      return ($this->fields['ConfigModes']);
   }
   function gabarit_tracker(){
      return ($this->fields['gabarit_tracker']);
   }
   function mode(){
      return ($this->fields['mode']);
   }
   function SharePointOn(){
      return ($this->fields['SharePointOn']);
   }
   function SageOn(){
      return ($this->fields['SageOn']);
   }
   function SageSearch(){
      return ($this->fields['SageSearch']);
   }
   function SharePointSearch(){
      return ($this->fields['SharePointSearch']);
   }
   function LocalSearch(){
      return ($this->fields['LocalSearch']);
   }

   // --- Remote signature getters ---
   function RemoteSignatureOn() {
      return isset($this->fields['RemoteSignatureOn']) ? (int)$this->fields['RemoteSignatureOn'] : 0;
   }
   function RemoteSignatureUsers() {
      $raw = $this->fields['RemoteSignatureUsers'] ?? '[]';
      $arr = json_decode($raw, true);
      return is_array($arr) ? $arr : [];
   }
   // --- Task signature (BL on task add) ---
   function TaskSignatureUsers() {
      $raw = $this->fields['TaskSignatureUsers'] ?? '[]';
      $arr = json_decode($raw, true);
      return is_array($arr) ? array_values(array_map('intval', $arr)) : [];
   }
   function TaskSignatureTriggerStates() {
      $raw = $this->fields['TaskSignatureTriggerStates'] ?? '';
      $arr = json_decode($raw, true);
      if (!is_array($arr) || empty($arr)) {
         // Default: Done
         return [Planning::DONE];
      }
      return array_values(array_map('intval', $arr));
   }
   function PlanningBLSignatureOn() {
      return isset($this->fields['PlanningBLSignatureOn']) ? (int)$this->fields['PlanningBLSignatureOn'] : 0;
   }
   function SageUrlApi(){
      return ($this->fields['SageUrlApi']);
   }

   function SageToken()     { return PluginGestionCrypto::decrypt($this->fields['SageToken']     ?? ''); }
   function TenantID()      { return PluginGestionCrypto::decrypt($this->fields['TenantID']      ?? ''); }
   function ClientID()      { return PluginGestionCrypto::decrypt($this->fields['ClientID']      ?? ''); }
   function ClientSecret()  { return PluginGestionCrypto::decrypt($this->fields['ClientSecret']  ?? ''); }
   function Hostname()      { return PluginGestionCrypto::decrypt($this->fields['Hostname']      ?? ''); }
   function SitePath()      { return PluginGestionCrypto::decrypt($this->fields['SitePath']      ?? ''); }
   // return fonction

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         return __('<span class="d-flex align-items-center"><i class="fa-solid fa-clipboard-check me-2"></i>Gestion BL</span>', "gestion");
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         self::showConfigForm();
      }
      return true;
   }

   function decryptData($data) {
      return PluginGestionCrypto::decrypt((string)$data);
   }

   private static function migrateEncryptedFieldsToSodium(Migration $migration): void
   {
      global $DB;

      $table = self::getTable();
      if (!$DB->tableExists($table)) {
         return;
      }

      $encrypted_fields = ['TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath', 'SageToken'];
      $available_fields = [];
      $columns_result = $DB->doQuery("SHOW COLUMNS FROM `" . $DB->escape($table) . "`");
      if ($columns_result) {
         while ($column = $DB->fetchAssoc($columns_result)) {
            if (in_array($column['Field'], $encrypted_fields, true)) {
               $available_fields[] = $column['Field'];
            }
         }
      }

      if (empty($available_fields)) {
         return;
      }

      $row = $DB->request([
         'SELECT' => array_merge(['id'], $available_fields),
         'FROM'   => $table,
         'WHERE'  => ['id' => 1],
         'LIMIT'  => 1
      ])->current();

      if (!is_array($row)) {
         return;
      }

      $updates = [];
      foreach ($available_fields as $field) {
         $raw = (string)($row[$field] ?? '');
         if ($raw === '') {
            continue;
         }
         try {
            $migrated = PluginGestionCrypto::migrateIfLegacy($raw);
         } catch (Throwable $e) {
            continue;
         }
         if (is_string($migrated) && $migrated !== $raw) {
            $updates[$field] = $migrated;
         }
      }

      if (!empty($updates)) {
         $DB->update($table, $updates, ['id' => (int)$row['id']]);
         $migration->displayMessage('Migration chiffrement config Gestion vers sodium');
      }
   }
  
   static function install(Migration $migration)
   {
      global $DB;

      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

      $table = self::getTable();
      $config = new self();

      if (!$DB->tableExists($table)) {

         $migration->displayMessage("Installing $table");

         $query = "CREATE TABLE IF NOT EXISTS $table (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `TenantID` TEXT NULL,
                  `ClientID` TEXT NULL,
                  `ClientSecret` TEXT NULL,
                  `Hostname` TEXT NULL,
                  `SitePath` TEXT NULL,
                  `Global` VARCHAR(255) NULL,
                  `ZenDocMail` VARCHAR(255) NULL,
                  `NumberViews` INT(10) NOT NULL DEFAULT '800',
                  `SharePointLinkDisplay` TINYINT NOT NULL DEFAULT '0',
                  `MailTo` TINYINT NOT NULL DEFAULT '0',
                  `CombinedMailMode` TINYINT NOT NULL DEFAULT '0',
                  `PlanningBLSignatureOn` TINYINT NOT NULL DEFAULT '0',
                  `ConfigModes` TINYINT NOT NULL DEFAULT '0',
                  `DisplayPdfEnd` TINYINT NOT NULL DEFAULT '0',
                  `gabarit` INT(10) NOT NULL DEFAULT '0',
                  `SignatureX` FLOAT NOT NULL DEFAULT '36',
                  `SignatureY` FLOAT NOT NULL DEFAULT '44',
                  `SignatureSize` FLOAT NOT NULL DEFAULT '50',
                  `SignataireX` FLOAT NOT NULL DEFAULT '20',
                  `SignataireY` FLOAT NOT NULL DEFAULT '56.5',
                  `DateX` FLOAT NOT NULL DEFAULT '20',
                  `DateY` FLOAT NOT NULL DEFAULT '51.3',
                  `TechX` FLOAT NOT NULL DEFAULT '145',
                  `TechY` FLOAT NOT NULL DEFAULT '37',
                  PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());
         $config->add(['id' => 1,]);

         $query = "CREATE TABLE IF NOT EXISTS glpi_plugin_gestion_configsfolder (
            `id` int {$default_key_sign} NOT NULL auto_increment,
            `folder_name` TEXT NULL,
            `params` TINYINT NOT NULL DEFAULT '8',
            PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());

         $result = $DB->doQuery("SELECT id FROM glpi_notificationtemplates WHERE NAME = 'Gestion Mail PDF' AND comment = 'Created by the plugin gestion'");

         while ($ID = $result->fetch_object()) {
             if (!empty($ID->id)) {
                 // Suppression de la ligne dans glpi_notificationtemplates
                 $deleteTemplateQuery = "DELETE FROM glpi_notificationtemplates WHERE id = {$ID->id}";
                 $DB->doQuery($deleteTemplateQuery);
         
                 // Suppression de la ligne correspondante dans glpi_notificationtemplatetranslations
                 $deleteTranslationQuery = "DELETE FROM glpi_notificationtemplatetranslations WHERE notificationtemplates_id = {$ID->id}";
                 $DB->doQuery($deleteTranslationQuery);
             }
         }
   
         require_once PLUGIN_GESTION_DIR.'/front/MailContent.php';
         $content_html = $ContentHtml;

         // Échapper le contenu HTML
         $content_html_escaped = Toolbox::addslashes_deep($content_html);
   
         // Construire la requête d'insertion
         $insertQuery1 = "INSERT INTO `glpi_notificationtemplates` (`name`, `itemtype`, `date_mod`, `comment`, `css`, `date_creation`) VALUES ('Gestion Mail PDF', 'Ticket', NULL, 'Created by the plugin gestion', '', NULL);";
         // Exécuter la requête
         $DB->doQuery($insertQuery1);
   
         // Construire la requête d'insertion
         $insertQuery2 = "INSERT INTO `glpi_notificationtemplatetranslations` 
            (`notificationtemplates_id`, `language`, `subject`, `content_text`, `content_html`) 
            VALUES (LAST_INSERT_ID(), 'fr_FR', '[GLPI] | Document signé', '', '{$content_html_escaped}')";
         // Exécuter la requête
         $DB->doQuery($insertQuery2);
   
         $ID = $DB->doQuery("SELECT id FROM glpi_notificationtemplates WHERE NAME = 'Gestion Mail PDF' AND comment = 'Created by the plugin gestion'")->fetch_object();
   
         $query= "UPDATE glpi_plugin_gestion_configs SET gabarit = $ID->id WHERE id=1;";
         $DB->doQuery($query) or die($DB->error());
      }
      
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.2.0'){
         include(PLUGIN_GESTION_DIR . "/install/update_120_130.php");
         update120to130(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.3.1'){
         include(PLUGIN_GESTION_DIR . "/install/update_132_next.php");
         update(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.4.3'){
         include(PLUGIN_GESTION_DIR . "/install/update_144_next.php");
         update_144_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.4.4'){
         include(PLUGIN_GESTION_DIR . "/install/update_150_remote.php");
         update_150_remote(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.5.0'){
         include(PLUGIN_GESTION_DIR . "/install/update_151_next.php");
         update_151_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.5.1'){
         include(PLUGIN_GESTION_DIR . "/install/update_152_next.php");
         update_152_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.5.2'){ // NEW 1.5.3
         include(PLUGIN_GESTION_DIR . "/install/update_153_next.php");
         update_153_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.5.9'){ // NEW 1.6.0
         include(PLUGIN_GESTION_DIR . "/install/update_160_next.php");
         update_160_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.6.2'){ // NEW 1.6.3
         include(PLUGIN_GESTION_DIR . "/install/update_163_next.php");
         update_163_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.6.3'){ // NEW 1.6.4
         include(PLUGIN_GESTION_DIR . "/install/update_164_next.php");
         update_164_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.6.4'){ // NEW 1.7.0
         include(PLUGIN_GESTION_DIR . "/install/update_170_next.php");
         update_170_next();
      }

      self::migrateEncryptedFieldsToSodium($migration);
   }

   static function uninstall(Migration $migration)
   {
      global $DB;

      $table = self::getTable();
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
      $table = 'glpi_plugin_gestion_configsfolder';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
      $table = 'glpi_plugin_gestion_remote_sign_requests';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
      // glpi_plugin_gestion_signaturedevices : supprimé en migration 1.7.0_alpha1
      // (remplacé par glpi_plugin_gestion_devices — géré dans update_170_alpha1.php)
      // Sécurité : si la table existe encore (migration non jouée), on la supprime ici aussi.
      $table = 'glpi_plugin_gestion_signaturedevices';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling (legacy) $table");
         $migration->dropTable($table);
      }

      // glpi_plugin_gestion_baseitems : supprimé en migration 1.7.0_alpha1
      $table = 'glpi_plugin_gestion_baseitems';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling (legacy) $table");
         $migration->dropTable($table);
      }

      // glpi_plugin_gestion_devices : nouvelle table (1.7.0_alpha1)
      $table = 'glpi_plugin_gestion_devices';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
