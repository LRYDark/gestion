<?php
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionCri extends CommonDBTM {

   static $rightname = 'plugin_gestion_cri_create';

   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;
      $uniq = 'cri'.mt_rand(10000,99999);

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css">';
      // Remote signature additions
      echo '<script>
         window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
      </script>';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?v=' . time() . '" defer></script>';

      // Style CSS inline pour hauteur responsive
         $responsiveIframeStyle = "
            width: 100%; 
            border: 1px solid #dee2e6; 
            border-radius: 6px;
            height: 350px; /* Mobile par défaut */
         ";

      // JavaScript pour ajuster selon la taille d'écran
         $responsiveScript = "
         <style>
         @media (min-width: 768px) {
            .pdf-responsive { height: 500px !important; }
         }
         @media (min-width: 1024px) {
            .pdf-responsive { height: 650px !important; }
         }
         @media (min-width: 1200px) {
            .pdf-responsive { height: 700px !important; }
         }
         </style>
         ";
      // Ajouter le style au début de votre fonction
         echo $responsiveScript;

      // Fonction pour générer le token temporaire (identique à view_pdf.php)
      function generateTempTokenForPreview($doc_id, $secret_key = 'GLPI_PDF_SECRET_2024') {
          $today = date('Y-m-d');
          return hash('sha256', $doc_id . $today . $secret_key);
      }

      $config     = PluginGestionConfig::getInstance();
      $documents  = new Document();
      $job        = new Ticket();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $job->getfromDB($ID);
      $email = '';

      $id = $_POST["modal"];
      $DOC = $DB->doQuery("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id'")->fetch_object();
      $Doc_Name = $DOC->bl;
      $doc_id  = $DOC->doc_id;
      $DocUrlSharePoint = "";
   
      $email = $DB->doQuery("SELECT GROUP_CONCAT(email SEPARATOR ',') AS emails FROM ( SELECT DISTINCT u.email AS email FROM glpi_useremails u JOIN glpi_users us ON us.id = u.users_id JOIN glpi_tickets t ON t.id = $ID WHERE us.entities_id = t.entities_id AND u.email IS NOT NULL AND u.email <> '' AND us.is_deleted = 0 UNION SELECT DISTINCT e.email FROM glpi_entities e JOIN glpi_tickets t ON t.entities_id = e.id WHERE t.id = $ID AND e.email IS NOT NULL AND e.email <> '' ) AS mails;")->fetch_object();   
      if(!empty($email->emails)){
         $email = $email->emails;
      }else{
         $email = '';
      }

      $params = ['job'         => $ID,
                  'form'       => 'formReport',
                  'root_doc'   => PLUGIN_GESTION_WEBDIR];
      
      echo "<form action=\"" . PLUGIN_GESTION_WEBDIR . "/front/traitement.php\" method=\"post\" name=\"formReport\">";
      echo Html::hidden('REPORT_ID', ['value' => $ID]);
      echo Html::hidden('DOC', ['value' => $Doc_Name]);
      echo Html::hidden('id_document', ['value' => $id]);
      
      $baseUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front';
 
      if($DOC->signed == 0){ // ----------------------------------- NON SIGNÉ -----------------------------------         
         echo '<div class="form-container">';
         
         // === CARTE DOCUMENT ===
         echo '<div class="form-card">';
            echo '<div class="document-info">';
               echo '<div class="document-title">Document : ' . $Doc_Name . '</div>';
               echo '<div class="document-status status-unsigned">Non Signé</div>';
            echo '</div>';
         echo '</div>';
         
         // === CARTE PDF ===
         echo '<div class="form-card">';
            echo '<div class="form-label">Visualisation du document</div>';
            echo '<div class="form-content">';
            
            if ($config->fields['SharePointLinkDisplay'] == 1) {
               try {
                  if ($DOC->save == 'SharePoint'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($DOC->doc_url);  
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
                     $DocUrlSharePoint = $fileDownloadUrl;
                  }
                  if ($DOC->save == 'Sage'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $DOC->doc_url;
                  }
               
                  echo '<object data="' . htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8') . '#view=FitH" '
                     . 'type="application/pdf" class="pdf-viewer pdf-responsive" '
                     . 'style="' . htmlspecialchars($responsiveIframeStyle, ENT_QUOTES, 'UTF-8') . '">'
                     . 'Votre navigateur ne peut pas afficher le PDF.'
                     . '</object>';
               } catch (Exception $e) {
                  echo "<p>Erreur lors du chargement du PDF</p>";
               }
            }
            
            echo '<div style="margin-top: 15px;">';
               echo '<a href="' . $DocUrlSharePoint . '" target="_blank" class="pdf-link">Voir le PDF en plein écran</a>';
            echo '</div>';
            
            echo '</div>';
         echo '</div>';
         
         // === CARTE FICHIER/IMAGE ===
         echo '<div class="form-card">';
            echo '<div class="form-label">Ajouter un fichier / image</div>';
            echo '<div class="form-content">';
               echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">';
               echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>';
            echo '</div>';
         echo '</div>';
         
         // === CARTE SIGNATURE ===
         echo '<div class="form-card signature-card">';
            echo '<div class="form-label">SIGNATURE CLIENT</div>';
            
            // SOUS-CARTE 1 : Nom du client
            echo '<div class="signature-sub-card">';
               echo '<div class="signature-sub-title">Nom / Prénom du client</div>';
               echo '<input type="text" id="name" name="name" placeholder="Nom / Prénom du client" required>';
            echo '</div>';
            
            // SOUS-CARTE 2 : Canvas signature
            echo '<div class="signature-sub-card">';
               echo '<div class="signature-sub-title">Signature client</div>';
               echo "<div id='".$uniq."' class='cri-signature-root'>";
                  echo "  <div class='signature-container'>";
                  echo "    <button type='button' class='zoom-btn'>Agrandir <i class='fa-solid fa-up-right-and-down-left-from-center'></i></button>";
                  echo "    <canvas id='sig-canvas-".$uniq."' height='190' class='sig-base'></canvas>";
                  echo "  </div>";
                  echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton'>Supprimer la signature</button>";

                  // Modal interne pour le zoom
                  echo "  <div class='signature-modal' aria-hidden='true'>";
                  echo "    <div class='modal-wrapper'>";
                  echo "      <div class='cri-modal-content'>";
                  echo "        <div class='rotate-gate'>";
                  echo "          <button type='button' class='rotate-close-btn' aria-label='Fermer'>&times;</button>";
                  echo "          <div>";
                  echo "            <div style='font-size:18px;font-weight:700;margin-bottom:8px'>";
                  echo "              Tournez votre téléphone en mode paysage";
                  echo "            </div>";
                  echo "            <div style='opacity:0.9'>La zone de signature va s'agrandir automatiquement.</div>";
                  echo "          </div>";
                  echo "        </div>";
                  echo "        <div class='cri-canvas-wrapper'>";
                  echo "          <canvas id='modal-canvas-".$uniq."' class='modal-canvas'></canvas>";
                  echo "        </div>";
                  echo "        <div class='cri-controls-panel'>";
                  echo "          <button type='button' class='btn-validate'>Valider</button>";
                  echo "          <button type='button' class='btn-clear'>Effacer</button>";
                  echo "          <button type='button' class='btn-cancel'>Annuler</button>";
                  echo "        </div>";
                  echo "      </div>";
                  echo "    </div>";
                  echo "  </div>";
               echo "</div>";
            echo '</div>';
         echo '</div>';

         function isCurrentUserAuthorized($authorized_users_string) {
            $current_user_id = $_SESSION['glpiID'];
            $authorized_users = json_decode($authorized_users_string, true);
            
            return is_array($authorized_users) && in_array($current_user_id, $authorized_users);
         }
            
         // MODIFICATION DANS cri.class.php - VERSION ACTUELLE (DOCUMENT UNIQUEMENT)
         // Remplacer la section signature déportée existante par celle-ci :

         if ($config->fields['RemoteSignatureOn'] == 1 && isCurrentUserAuthorized($config->fields['RemoteSignatureUsers'])) {                 
            // === CARTE SIGNATURE DÉPORTÉE (tablette) ===
            
            $can_remote = false;
            $devices = [];
            // Lire la configuration directement depuis la table du plugin (si les colonnes existent)
            try {
                  $cfgrow = [];
                  $rescfg = $DB->doQuery("SELECT * FROM glpi_plugin_gestion_configs LIMIT 1");
                  if ($rescfg && $DB->numrows($rescfg) > 0) {
                     $cfgrow = $DB->fetchassoc($rescfg);
                  }
                  $enabled = isset($cfgrow['enable_remote_signature']) ? (int)$cfgrow['enable_remote_signature'] : 1;
                  $allowed_users = [];
                  if (isset($cfgrow['remote_allowed_users']) && $cfgrow['remote_allowed_users'] !== '') {
                     $raw = $cfgrow['remote_allowed_users'];
                     if (is_string($raw) && strlen($raw) > 0) {
                        if ($raw[0] === '[') {
                           $decoded = json_decode($raw, true);
                           if (is_array($decoded)) {
                              foreach ($decoded as $u) { $allowed_users[] = (int)$u; }
                           }
                        } else {
                           foreach (preg_split('/[\s,;]+/', $raw) as $u) { if ($u !== '') $allowed_users[] = (int)$u; }
                        }
                     }
                  }
                  $uid = (int)Session::getLoginUserID();
                  $can_remote = (bool)$enabled && (empty($allowed_users) || in_array($uid, $allowed_users, true));
                  if ($can_remote) {
                     $resdev = $DB->doQuery("SELECT id, device_id, serial, device_token, is_active FROM glpi_plugin_gestion_signaturedevices WHERE is_active = 1 ORDER BY device_id ASC");
                     if ($resdev) {
                        while ($r = $DB->fetchassoc($resdev)) { $devices[] = $r; }
                     }
                  }
            } catch (Throwable $e) {
                  $can_remote = false;
                  $devices = [];
            }
            
            if (!empty($devices)) {
               echo '<div class="form-card">';
               echo '  <div class="form-label">Signature déportée (tablette)</div>';
               echo '  <div class="form-content">';
               echo '    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
            
               // (re)build device list with tokens directly from DB
               global $DB;
               $rows = [];
               $res = $DB->doQuery("SELECT device_id, serial, device_token FROM glpi_plugin_gestion_signaturedevices WHERE is_active = 1");
               if ($res) {
                  while ($r = $DB->fetchassoc($res)) { $rows[] = $r; }
               }

               echo '      <select id="remote-device" style="padding:6px">';
               foreach ($rows as $d) {
                  $did = Html::entities_deep($d['device_id']);
                  $tok = Html::entities_deep($d['device_token']);
                  $ser = Html::entities_deep($d['serial']);
                  $label = $did . ($ser ? ' · ' . $ser : '');
                  echo '        <option value="'.$did.'" data-token="'.$tok.'" data-has-token="'.(!empty($tok) ? '1' : '0').'">'.$label.'</option>';
               }
               
               // expose ticket id for JS in a robust way
               $ticket_id_js = isset($ID) ? (int)$ID : 0;
               echo '<input type="hidden" id="remote-ticket-id" value="'.$ticket_id_js.'">';
               echo '      </select>';

               // Alerts after actual DB-backed tokens
               $no_rows = (count($rows) === 0);
               $no_token = true;
               foreach ($rows as $d) { if (!empty($d['device_token'])) { $no_token = false; break; } }

               if ($no_rows) {
                  echo '<div class="alert alert-important alert-danger glpi-debug-alert" style="z-index:10000">';
                  echo __('Aucune tablette active. Ajoutez-en au moins une dans la configuration.', 'gestion');
                  echo '</div>';
               } else if ($no_token) {
                  echo '<div class="alert alert-important alert-danger glpi-debug-alert" style="z-index:10000">';
                  echo __('Aucun token de tablette détecté : supprimez puis ré-ajoutez la tablette dans la configuration pour générer un token.', 'gestion');
                  echo '</div>';
               }

               echo '      <button type="button" id="remote-start" class="btn btn-primary">Demander la signature</button>';
               echo '      <span id="remote-status" class="text-muted"></span>';
               echo '    </div>';
               echo '  </div>';
               echo '</div>';
               
               // VERSION CORRIGÉE : Préparation des paramètres automatiques (DOCUMENT UNIQUEMENT)
               $autoParams = [];

               // Document name (depuis la variable existante)
               if (!empty($Doc_Name)) {
                  $autoParams['document_name'] = $Doc_Name;
               }

               // Document URL avec token temporaire (depuis la variable existante)
               if (!empty($fileDownloadUrl)) {
                  // Fonction pour générer le token temporaire
                  function generateTempToken($doc_id, $secret_key = 'GLPI_PDF_SECRET_2024') {
                     $today = date('Y-m-d');
                     return hash('sha256', $doc_id . $today . $secret_key);
                  }
                  
                  // Extraire l'ID du document depuis l'URL existante
                  $doc_id = '';
                  if (preg_match('/[?&]id=([^&]+)/', $fileDownloadUrl, $matches)) {
                     $doc_id = $matches[1];
                  } elseif (preg_match('/docid=([^&]+)/', $fileDownloadUrl, $matches)) {
                     $doc_id = $matches[1];
                  }
                  
                  if (!empty($doc_id)) {
                     // Générer le token temporaire
                     $temp_token = generateTempToken($doc_id);
                     
                     // Créer l'URL avec token
                     $separator = (strpos($fileDownloadUrl, '?') !== false) ? '&' : '?';
                     $tokenized_url = $fileDownloadUrl . $separator . 'token=' . $temp_token;
                     
                     // CORRECTION : Assigner à document_url, pas document_name
                     // ET s'assurer qu'il n'y a pas d'encodage HTML
                     $autoParams['document_url'] = html_entity_decode($tokenized_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                  } else {
                     // Fallback si on n'arrive pas à extraire l'ID
                     $autoParams['document_url'] = html_entity_decode($fileDownloadUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                  }
               }
               
               // Entity (récupérer l'entité du ticket)
               $entity_name = '';
               try {
                  $entity_sql = "SELECT e.name FROM glpi_entities e 
                              JOIN glpi_tickets t ON e.id = t.entities_id 
                              WHERE t.id = " . (int)$ID . " LIMIT 1";
                  $entity_result = $DB->doQuery($entity_sql);
                  if ($entity_result && $DB->numrows($entity_result) > 0) {
                     $entity_row = $DB->fetchAssoc($entity_result);
                     $entity_name = $entity_row['name'];
                  }
               } catch (Exception $e) {
                  $entity_name = '';
               }
               
               if (!empty($entity_name)) {
                  $autoParams['entity_name'] = $entity_name;
               }

               // NOUVEAU : Ajouter l'email s'il est disponible
               if (!empty($email)) {
                  $autoParams['client_email'] = $email;
               }
               
               // Convertir en JSON pour JavaScript
               $autoParamsJson = !empty($autoParams) ? json_encode($autoParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null';
               
               echo '<script>
               // Variable globale contenant les paramètres automatiques
               window.REMOTE_SIGN_AUTO_PARAMS = ' . $autoParamsJson . ';
               
               // Script de récupération automatique des signatures déportées
               (function() {
               function initRemoteSignatureCapture() {
                  const stat = document.getElementById("remote-status");
                  if (!stat) {
                     setTimeout(initRemoteSignatureCapture, 2000);
                     return;
                  }
                  
                  // Observer les changements de statut
                  let lastStatus = stat.textContent;
                  const checkStatus = function() {
                     const currentStatus = stat.textContent;
                     if (currentStatus !== lastStatus) {
                     lastStatus = currentStatus;
                     
                     // Si on voit "Signature reçue", récupérer les données
                     if (currentStatus.includes("Signature reçue")) {
                        setTimeout(retrieveSignatureData, 100);
                     }
                     }
                  };
                  
                  setInterval(checkStatus, 500);
                  
                  async function retrieveSignatureData() {
                     try {
                     const sel = document.getElementById("remote-device");
                     if (!sel) return;
                     
                     const opt = sel.options[sel.selectedIndex];
                     const device_id = opt.value;
                     const device_token = opt.getAttribute("data-token");
                     const ticket_id = '.((int)$ID).';
                     
                     const r = await RemoteSign.pollTicket(ticket_id, { device_id, device_token });
                     
                     if (r.ok && r.ready && r.signature_base64) {
                        // Remplir le champ caché
                        const hiddenArea = document.getElementById("sig-dataUrl");
                        if (hiddenArea) {
                           const sigData = r.signature_base64.startsWith("data:") ? r.signature_base64 : "data:image/png;base64," + r.signature_base64;
                           hiddenArea.value = sigData;
                        }
                        
                        // Dessiner sur le canvas
                        const allCanvas = document.querySelectorAll("canvas");
                        if (allCanvas.length > 0) {
                           const canvas = allCanvas[0];
                           const ctx = canvas.getContext("2d");
                           
                           const img = new Image();
                           img.onload = function() {
                           ctx.clearRect(0, 0, canvas.width, canvas.height);
                           
                           const tempCanvas = document.createElement("canvas");
                           tempCanvas.width = img.width;
                           tempCanvas.height = img.height;
                           const tempCtx = tempCanvas.getContext("2d");
                           
                           tempCtx.drawImage(img, 0, 0);
                           
                           const imageData = tempCtx.getImageData(0, 0, tempCanvas.width, tempCanvas.height);
                           const data = imageData.data;
                           
                           for (let i = 0; i < data.length; i += 4) {
                              const alpha = data[i + 3];
                              if (alpha > 0) {
                                 data[i] = 0;
                                 data[i + 1] = 0;
                                 data[i + 2] = 0;
                              }
                           }
                           
                           tempCtx.putImageData(imageData, 0, 0);
                           ctx.drawImage(tempCanvas, 0, 0, canvas.width, canvas.height);
                           };
                           img.onerror = function() {
                           console.error("Erreur chargement signature image");
                           };
                           const sigData = r.signature_base64.startsWith("data:") ? r.signature_base64 : "data:image/png;base64," + r.signature_base64;
                           img.src = sigData;
                        }
                        
                        // Remplir le champ nom
                        const nameField = document.getElementById("name");
                        if (nameField && r.signer_name) {
                           nameField.value = r.signer_name;
                        }
                        
                        // Remplir le champ email
                        const emailField = document.getElementById("mail");
                        if (emailField && r.signer_email) {
                           emailField.value = r.signer_email;
                           const emailCheckbox = document.getElementById("send_email");
                           if (emailCheckbox && r.signer_email.trim() !== "") {
                              emailCheckbox.checked = true;
                           }
                        }
                     }
                     
                     } catch (e) {
                     console.error("Erreur récupération signature:", e);
                     }
                  }
               }

               // Initialiser
               if (document.readyState === "loading") {
                  document.addEventListener("DOMContentLoaded", initRemoteSignatureCapture);
               } else {
                  setTimeout(initRemoteSignatureCapture, 100);
               }
               })();
               </script>';
            }
         }

         ?>
         <style>
         .modal-dialog { 
            max-width: 1050px; 
            margin: 1.75rem auto; 
         }

         .email-combo-container {
            position: relative;
            width: 100%;
            max-width: 400px; /* on garde ta limite */
         }

         .email-input {
            width: 100%;
            padding-right: 32px; /* espace pour le bouton */
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
         }

         /* Bouton flèche collé à droite de l’input */
         .email-dropdown-btn {
            position: absolute;
            right: 8px;                /* toujours au bord droit du conteneur */
            top: 50%;                  /* centré verticalement */
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
         }

         .email-dropdown-btn.open {
            transform: translateY(-50%) rotate(180deg);
         }

         /* Menu exactement de la largeur du conteneur (input + bouton) */
         .email-dropdown {
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            width: 100%;
            max-width: 400px;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-sizing: border-box;
         }

         .email-option {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
         }

         .email-option:hover {
            background: #f5f5f5;
         }

         .email-option:last-child {
            border-bottom: none;
         }
         </style>

         <script>
         function showEmailDropdown() {
            var dropdown = document.getElementById("email_dropdown_list");
            if (!dropdown) return; // pas de dropdown si pas d'emails

            dropdown.style.display = "block";

            // Ajoute l'état "ouvert" sur la flèche
            var btn = document.querySelector(".email-dropdown-btn");
            if (btn) btn.classList.add("open");
         }

         function toggleEmailDropdown() {
            var dropdown = document.getElementById("email_dropdown_list");
            if (!dropdown) return;

            var btn = document.querySelector(".email-dropdown-btn");
            var isOpen = dropdown.style.display === "block";

            dropdown.style.display = isOpen ? "none" : "block";
            if (btn) btn.classList.toggle("open", !isOpen);
         }

         function selectEmail(email) {
            document.getElementById("mail").value = email;

            var dropdown = document.getElementById("email_dropdown_list");
            if (dropdown) dropdown.style.display = "none";

            // Ferme visuellement la flèche
            var btn = document.querySelector(".email-dropdown-btn");
            if (btn) btn.classList.remove("open");
         }

         // Fermer le dropdown si on clique ailleurs
         document.addEventListener("click", function(event) {
            var container = document.querySelector(".email-combo-container");
            var dropdown  = document.getElementById("email_dropdown_list");
            if (!dropdown) return;

            if (!container.contains(event.target)) {
               dropdown.style.display = "none";

               // Ferme visuellement la flèche
               var btn = document.querySelector(".email-dropdown-btn");
               if (btn) btn.classList.remove("open");
            }
         });
         </script>
         <?php

         // === Facturation comptoir (si activée) ===
         if ($config->fields['CounterInvoice'] == 1 && isCurrentUserAuthorized($config->fields['CounterInvoiceUsers'])) { // NEW
            echo '<div class="form-card">';
               echo '<div class="form-label">Règlement effectué au comptoir</div>';
               echo '<div class="form-content">';
                     echo '<div class="checkbox-group">';
                        echo '<input type="checkbox" name="CounterInvoiceClient" value="1" id="CounterInvoiceClient">';
                        echo '<label for="CounterInvoiceClient">Règlement effectué</label>';
                     echo '</div>';                     
               echo '</div>';
            echo '</div>';
         }

         // === CARTE EMAIL (si activée) ===
         if ($config->fields['MailTo'] == 1) {
            // Traitement de la variable $email pour créer un tableau
            $emailArray = array();
            if (!empty($email)) {
               $emailArray = array_filter(array_map('trim', explode(',', $email)));
               // Supprimer les doublons et réindexer
               $emailArray = array_values(array_unique($emailArray));
            }
            
            // Premier email par défaut
            $defaultEmail = !empty($emailArray) ? $emailArray[0] : '';
            
            echo '<div class="form-card">';
               echo '<div class="form-label">Mail client</div>';
               echo '<div class="form-content">';
                     echo '<div class="checkbox-group">';
                        echo '<input type="checkbox" name="mailtoclient" value="1" id="send_email">';
                        echo '<label for="send_email">Envoyer le PDF par email</label>';
                     echo '</div>';
                     
                     echo '<div class="email-combo-container">';
                        // Input principal (celui qui sera envoyé)
                        echo '<input type="email" id="mail" name="email" class="email-input" value="' . htmlspecialchars($defaultEmail) . '" placeholder="Email du client" onclick="showEmailDropdown()" onfocus="showEmailDropdown()">';
                        
                        // Bouton dropdown si on a des emails
                        if (!empty($emailArray)) {
                           echo '<button type="button" class="email-dropdown-btn" onclick="toggleEmailDropdown()"><i class="fa-solid fa-chevron-down"></i></button>';
                           
                           // Dropdown personnalisé
                           echo '<div id="email_dropdown_list" class="email-dropdown">';
                                 foreach ($emailArray as $emailOption) {
                                    echo '<div class="email-option" onclick="selectEmail(\'' . htmlspecialchars($emailOption, ENT_QUOTES) . '\')">';
                                    echo htmlspecialchars($emailOption);
                                    echo '</div>';
                                 }
                           echo '</div>';
                        }
                        
                     echo '</div>';
                     
               echo '</div>';
            echo '</div>';
         }
        
         // === CARTE ACTIONS ===
         if(Session::haveRight("plugin_gestion_sign", CREATE)){
            echo '<div class="form-card actions-card" id="actions-bottom">';
               echo '<div class="form-content">';
                  echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signer le PDF" class="submit-btn">';
               echo '</div>';
            echo '</div>';
            
            echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';
         }
         
         echo '</div>'; // Fin form-container

      } else { // ----------------------------------- SIGNÉ -----------------------------------         
         echo '<div class="form-container">';
         
         // === CARTE DOCUMENT SIGNÉ ===
         echo '<div class="form-card">';
            echo '<div class="document-info">';
               echo '<div class="document-title">Document : ' . $Doc_Name . '</div>';
               echo '<div class="document-status status-signed">Signé</div>';
            echo '</div>';
            
            echo '<div class="signed-details">';
               echo '<p><strong>Signé le :</strong> ' . $DOC->date_creation . '</p>';
               echo '<p><strong>Par :</strong> ' . $DOC->users_ext . '</p>';
               echo '<p><strong>Livré par :</strong> ' . getUserName($DOC->users_id) . '</p>';
            echo '</div>';
         echo '</div>';
         
         // === CARTE PDF SIGNÉ ===
         echo '<div class="form-card">';
            echo '<div class="form-label">Document signé</div>';
            echo '<div class="form-content">';
            
            if ($config->fields['SharePointLinkDisplay'] == 1) {
               try {
                  if ($DOC->save == 'SharePoint'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($DOC->doc_url);  
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
                     $DocUrlSharePoint = $fileDownloadUrl;
                  }
                  if ($DOC->save == 'Sage'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $DOC->doc_url;
                  }
                  
                  echo '<object data="' . htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8') . '#view=FitH" '
                     . 'type="application/pdf" class="pdf-viewer pdf-responsive" '
                     . 'style="' . htmlspecialchars($responsiveIframeStyle, ENT_QUOTES, 'UTF-8') . '">'
                     . 'Votre navigateur ne peut pas afficher le PDF.'
                     . '</object>';
               } catch (Exception $e) {
                  echo "<p>Erreur lors du chargement du PDF</p>";
               }
            }
            
            echo '<div style="margin-top: 15px;">';
               echo '<a href="' . $DocUrlSharePoint . '" target="_blank" class="pdf-link">Voir le PDF en plein écran</a>';
            echo '</div>';
            
            echo '</div>';
         echo '</div>';
         
         echo '</div>'; // Fin form-container
         
      } // ----------------------------------- FIN SIGNÉ -----------------------------------

      // Bouton flottant "Aller en bas"
      echo '<button type="button" class="fab-go-bottom" title="Aller en bas" aria-label="Aller en bas">↓</button>';
      
      Html::closeForm();
      ?>
      <script>
         setTimeout(function() {   
            // 4. Bouton "Aller en bas de la page"
            const goBottomBtn = document.querySelector('.fab-go-bottom');
               if (goBottomBtn) {
               goBottomBtn.addEventListener('click', () => {
                  // Fermer une modale de signature si elle est ouverte
                  const openedModal = document.querySelector('.signature-modal[aria-hidden="false"], .signature-modal:not([aria-hidden])');
                  if (openedModal) {
                     openedModal.setAttribute('aria-hidden', 'true');
                  }
                  document.documentElement.classList.remove('no-scroll');
                  document.body.classList.remove('no-scroll');

                  // Cibler la carte Actions si présente, sinon bas de page
                  const target = document.getElementById('actions-bottom');
                  if (target && typeof target.scrollIntoView === 'function') {
                     target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                  } else {
                     window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
                  }
               });
            }
 
            // Vérifier que la fonction existe avant de l'appeler
            if (typeof initializeSignatureGestion === 'function') {
               initializeSignatureGestion('<?php echo $uniq; ?>');
            } else {
               // Si la fonction n'existe pas encore, attendre un peu
               setTimeout(function() {
                  if (typeof initializeSignatureGestion === 'function') {
                        initializeSignatureGestion('<?php echo $uniq; ?>');
                  }
               }, 500);
            }
         }, 100); // Délai de 100ms pour s'assurer que tout est chargé
      </script>
      <?php
   }
}
?>