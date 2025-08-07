<?php
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionCri extends CommonDBTM {

   static $rightname = 'plugin_rp_cri_create';

   static function getTypeName($nb = 0) {
      return _n('Rapport / Prise en charge', 'Rapport / Prise en charge', $nb, 'rp');
   }

   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;
      $uniq = 'cri'.mt_rand(10000,99999);

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/css/signature.css">';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/scripts/signature.js" defer></script>';

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

      $config     = PluginGestionConfig::getInstance();
      $documents  = new Document();
      $job        = new Ticket();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $job->getfromDB($ID);
      $email = '';

      $id = $_POST["modal"];
      $DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id'")->fetch_object();
      $Doc_Name = $DOC->bl;
      $doc_id  = $DOC->doc_id;
      $DocUrlSharePoint = $DOC->doc_url;
   
      $email = $DB->query("SELECT u.email FROM glpi_useremails u JOIN glpi_users us ON u.users_id = us.id JOIN glpi_tickets t ON us.entities_id = t.entities_id WHERE t.id = $ID LIMIT 1;")->fetch_object();
      if(!empty($email->email)){
         $email = $email->email;
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
                  $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                  $filePath = $folderPath . $Doc_Name;
                  $filePath = preg_replace('#/+#', '/', $filePath);

                  if ($DOC->save == 'SharePoint'){
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath);  
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;

                  }
                  if ($DOC->save == 'Sage'){
                     $fileDownloadUrl = $DOC->doc_url;
                  }
                  if ($DOC->save == 'Sage' || $DOC->save == 'Local' || $DOC->save == 'SharePoint'){
                     echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                           class='pdf-viewer pdf-responsive' style='$responsiveIframeStyle' frameborder='0'></iframe>";
                  }
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
                  echo "    <canvas id='sig-canvas-".$uniq."' width='400' height='120' class='sig-base'></canvas>";
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
         
         // === CARTE EMAIL (si activée) ===
         if ($config->fields['MailTo'] == 1) {
            echo '<div class="form-card">';
               echo '<div class="form-label">Mail client</div>';
               echo '<div class="form-content">';
                  echo '<div class="checkbox-group">';
                     echo '<input type="checkbox" name="mailtoclient" value="1" id="send_email">';
                     echo '<label for="send_email">Envoyer le PDF par email</label>';
                  echo '</div>';
                  echo '<input type="email" id="mail" name="email" value="'.$email.'" placeholder="Email du client">';
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
                  $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                  $filePath = $folderPath . $Doc_Name;
                  $filePath = preg_replace('#/+#', '/', $filePath);

                  if ($DOC->save == 'SharePoint'){
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath);  
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;

                  }
                  if ($DOC->save == 'Sage'){
                     $fileDownloadUrl = $DOC->doc_url;
                  }
                  if ($DOC->save == 'Sage' || $DOC->save == 'Local' || $DOC->save == 'SharePoint'){
                     echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                           class='pdf-viewer pdf-responsive' style='$responsiveIframeStyle' frameborder='0'></iframe>";
                  }
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
            if (typeof initializeSignature === 'function') {
               initializeSignature('<?php echo $uniq; ?>');
            } else {
               // Si la fonction n'existe pas encore, attendre un peu
               setTimeout(function() {
                  if (typeof initializeSignature === 'function') {
                        initializeSignature('<?php echo $uniq; ?>');
                  }
               }, 500);
            }
         }, 100); // Délai de 100ms pour s'assurer que tout est chargé
      </script>
      <?php
   }
}
?>