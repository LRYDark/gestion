<?php
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to tdis file");
}

class PluginGestionCri extends CommonDBTM {

   static $rightname = 'plugin_rp_cri_create';

   static function getTypeName($nb = 0) {
      return _n('Rapport / Prise en charge', 'Rapport / Prise en charge', $nb, 'rp');
   }

   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;
      $uniq = 'cri'.mt_rand(10000,99999);

      $id = $_POST["modal"];
      $DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id'")->fetch_object(); // Récupérer les informations du document  

      if ($options["root_modal"] == 'ticket-form' && $DOC->signed == 0){
         $querytask = "SELECT id FROM glpi_tickettasks WHERE tickets_id = $ID";
         $resulttask = $DB->query($querytask);
         $numbertask = $DB->numrows($resulttask);

         if($numbertask > 0){
            
         }else{
            echo "<div class='alert alert-important alert-warning'>";
            echo "<b><center>" . __("Vous ne pouvez pas signer le document avant d'avoir rempli votre fiche d'intervention.") . "</center></b></div>";
            exit; 
         } 
      }

      if ($options["root_modal"] == 'survey-form' && $DOC->signed == 0){
         if($DOC->tickets_id != 0){
            $querytask = "SELECT id FROM glpi_tickettasks WHERE tickets_id = $DOC->tickets_id";
            $resulttask = $DB->query($querytask);
            $numbertask = $DB->numrows($resulttask);

            if($numbertask > 0){

            }else{
               echo "<div class='alert alert-important alert-warning'>";
               echo "<b><center>" . __("Vous ne pouvez pas signer le document avant d'avoir rempli votre fiche d'intervention.") . "</center></b></div>";
               echo '<center><a href="../../../front/ticket.form.php?id='.$DOC->tickets_id.'" class="btn btn-secondary">Allez au ticket</a></center>';
               exit; 
            }  
         }else{
            echo "<div class='alert alert-important alert-primary'>";
            echo "<b><center>" . __("Aucun ticket associé") . "</center></b></div>";

         }
      }

      $config     = PluginGestionConfig::getInstance(); // Récupérer la configuration
      $documents  = new Document(); // Initialiser la classe Document
      $job        = new Ticket(); // Initialiser la classe Ticket
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint(); // Initialiser la classe SharePointGraph

      $job->getfromDB($ID);
      $email = '';

      $DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id'")->fetch_object(); // Récupérer les informations du document   
      $Doc_Name = $DOC->bl;
      $doc_id  = $DOC->doc_id;
   
      $email = $DB->query("SELECT u.email FROM glpi_useremails u JOIN glpi_users us ON u.users_id = us.id JOIN glpi_tickets t ON us.entities_id = t.entities_id WHERE t.id = $ID LIMIT 1;")->fetch_object(); // Récupérer les informations du document
      if(!empty($email->email)){
         $email = $email->email;
      }else{
         $email = '';
      }

      $params = ['job'         => $ID,
                  'form'       => 'formReport',
                  'root_doc'   => PLUGIN_GESTION_WEBDIR];

      ?><style> /*Style du modale et du tableau */
         /* Classe pour désactiver le défilement sur le body */
         .no-scroll {
            overflow: hidden !important;
            position: fixed !important;
            width: 100vw !important;
            height: 100vh !important;
         }

         /* MODAL */
         .modal-dialog { 
            max-width: 1050px; 
            margin: 1.75rem auto; 
         }
         .table td, .table td { 
            border: none !important;
         }
         .responsive-pdf {
            width: 100%;
            height: 90vh; /* Ajuste la hauteur à 90% de la hauteur de la fenêtre */
         }

         @media (max-width: 768px) {
            .responsive-pdf {
               width: 100%;
               height: 70vh; /* Réduit la hauteur sur mobile pour éviter l'étirement */
            }
         }

         @media (max-width: 480px) {
            .responsive-pdf {
               width: 100%;
               height: 60vh; /* Encore moins de hauteur sur les très petits écrans */
            }
         }

         .button-container-right {
            text-align: right;
            margin-top: 10px; /* Optionnel */
         }
      </style>
      <style>

/* ou 'pixelated' si tu préfères des bords plus francs */
#<?= $uniq ?> .canvas.sig-base { image-rendering: auto; }
/* Container & bouton zoom (inchangé) */
#<?= $uniq ?> .signature-container{position:relative;display:inline-block}
#<?= $uniq ?> .zoom-btn{position:absolute;top:-28px;right:0;background:#007bff;color:#fff;border:0;padding:5px 10px;border-radius:3px;cursor:pointer;font-size:12px;z-index:1}
#<?= $uniq ?> .zoom-btn:hover{background:#0056b3}

/* Overlay plein écran */
#<?= $uniq ?> .signature-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:200000}
#<?= $uniq ?> .signature-modal.active{display:block}
#<?= $uniq ?> .modal-wrapper{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:10px}

/* >>> Renommées pour éviter Bootstrap <<< */
#<?= $uniq ?> .cri-modal-content{
  background:#fff;border-radius:10px;position:relative;
  width:100%;height:100%;max-width:1200px;max-height:600px;
  display:flex !important;                 /* évite la pile verticale bootstrap */
  flex-direction:row !important;           /* canvas + panneau côte à côte */
  overflow:hidden;
}

#<?= $uniq ?> .cri-canvas-wrapper{
  flex:1 1 auto;display:flex;align-items:center;justify-content:center;
  padding:20px;background:#f8f9fa;min-width:0;
}

#<?= $uniq ?> .modal-canvas{border:2px solid #333;background:#fff;touch-action:none;max-width:100%;max-height:100%;cursor:crosshair}

#<?= $uniq ?> .cri-controls-panel{
  flex:0 0 150px;background:#e9ecef;display:flex;flex-direction:column;
  justify-content:center;padding:20px;border-left:1px solid #dee2e6
}
#<?= $uniq ?> .cri-controls-panel button{margin:10px 0;padding:12px 20px;border:0;border-radius:5px;cursor:pointer;font-size:14px;font-weight:700;transition:.2s}
#<?= $uniq ?> .btn-validate{background:#28a745;color:#fff}
#<?= $uniq ?> .btn-validate:hover{background:#218838}
#<?= $uniq ?> .btn-cancel{background:#dc3545;color:#fff}
#<?= $uniq ?> .btn-cancel:hover{background:#c82333}
#<?= $uniq ?> .btn-clear{background:#ffc107;color:#000}
#<?= $uniq ?> .btn-clear:hover{background:#e0a800}

/* Mobile uniquement */
@media (max-width: 1024px) {
  #<?= $uniq ?> .signature-modal.active { inset: 0; }
  #<?= $uniq ?> .cri-modal-content{
    width: 100svw;   /* sinon 100vw si svw non supporté */
    height: 100svh;  /* sinon 100vh */
    max-width: none;
    max-height: none;
    border-radius: 0;
  }
  #<?= $uniq ?> .cri-canvas-wrapper{ padding: max(12px, env(safe-area-inset-left)); }
  #<?= $uniq ?> .cri-controls-panel{ flex-basis: 110px; padding: 10px; }
}

/* corrige le sélecteur */
#<?= $uniq ?> canvas.sig-base { image-rendering: auto; }

/* overlay “tournez l’écran” */
#<?= $uniq ?> .rotate-gate{ position:absolute; inset:0; display:none;
  align-items:center; justify-content:center; background:rgba(0,0,0,.65);
  color:#fff; z-index: 1000; text-align:center; padding: 24px; }
#<?= $uniq ?> .rotate-gate.show{ display:flex; }



</style>

      
      <?php
         function isMobile() {
            return preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', $_SERVER['HTTP_USER_AGENT']); // Vérifie si l'utilisateur est sur un appareil mobile
         }
      
         echo "<form action=\"" . PLUGIN_GESTION_WEBDIR . "/front/traitement.php\" method=\"post\" name=\"formReport\">"; // Formulaire pour envoyer les données
         echo Html::hidden('REPORT_ID', ['value' => $ID]);
         echo Html::hidden('DOC', ['value' => $Doc_Name]);
         echo Html::hidden('id_document', ['value' => $id]);

         if($DOC->signed == 0){ // ----------------------------------- NON SIGNE -----------------------------------
            // tableau bootstrap -> glpi | Mobile ou Ordinateur
            if (isMobile()) { // ----------------------------------- MOBILE -----------------------------------
               echo '<div class="table-responsive" >';
               echo "<table class='table'>"; 

               echo 'Document : <strong>'.$Doc_Name.'</strong>';
               echo '<br><br>';
               echo "<strong><u>STATUS</u></strong>";
               echo '<br>';
               echo 'Non Signé';
               echo '<br><br>';
               
                  $DocUrlSharePoint  = $DOC->doc_url;
                  function AffichageNoSigneMobile($DocUrlSharePoint){
                     // Bouton pour voir le PDF en plein écran
                     echo "<tr>";
                        echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>'; // Bouton pour voir le PDF en plein écran
                     echo "</tr><br><br>";
                  }

                  if ($config->fields['SharePointLinkDisplay'] == 1){
                     // Utilisation
                     try {
                        // Construire le chemin complet du fichier
                        $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                        $filePath = $folderPath . $Doc_Name; // Chemin du fichier

                        // Remplacer tous les doubles slashs par un simple slash
                        $filePath = preg_replace('#/+#', '/', $filePath);

      /////////////////////////////// NEWS OK Non Signer ///////////////////////////////
                        if ($DOC->save == 'SharePoint'){
                           // Obtenir l'URL de téléchargement direct du fichier
                           $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath);  
                        }
                        if ($DOC->save == 'Local'){
                           $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;
                        } 
                        if ($DOC->save == 'Sage'){
                           $fileDownloadUrl = $DOC->doc_url;
                        }

                        // Étape 4 : Affichez le PDF via <embed>
                        echo "<tr>";
                           if ($DOC->save == 'SharePoint'){
                              echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                    style='width:100%; height:600px;' frameborder='0'></iframe>";
                           }
                           if ($DOC->save == 'Local'){
                              echo "
                                 <style>
                                 .pdf-container {
                                 width: 100%;
                                 max-width: 100vw;
                                 aspect-ratio: 1 / 1.414; /* Ratio A4 en portrait */
                                 overflow: hidden;
                                 }
                                 .pdf-container embed {
                                 width: 100%;
                                 height: 100%;
                                 object-fit: contain;
                                 }
                                 </style>

                                 <div class='pdf-container'>
                                 <embed 
                                    src='".$fileDownloadUrl."'
                                    type='application/pdf' 
                                    />
                                 </div>";
                           }
                           if ($DOC->save == 'Sage'){
                              echo "
                                 <style>
                                 .pdf-container {
                                 width: 100%;
                                 max-width: 100vw;
                                 aspect-ratio: 1 / 1.414; /* Ratio A4 en portrait */
                                 overflow: hidden;
                                 }
                                 .pdf-container embed {
                                 width: 100%;
                                 height: 100%;
                                 object-fit: contain;
                                 }
                                 </style>

                                 <div class='pdf-container'>
                                 <embed 
                                    src='".$fileDownloadUrl."'
                                    type='application/pdf' 
                                    />
                                 </div>";
                           }
                        // Bouton pour voir le PDF en plein écran
                        echo "<tr>";
                        echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>'; //   Bouton pour voir le PDF en plein écran
                        echo "</tr><br><br>";
      /////////////////////////////// NEWS OK Non Signer ///////////////////////////////

                     } catch (Exception $e) {
                        AffichageNoSigneMobile($DocUrlSharePoint);
                     }
                  }else{
                     AffichageNoSigneMobile($DocUrlSharePoint);
                  }

                  echo "<tr>";   
                     // Bouton de capture photo -->
                     echo '<label for="capture-photo">Prendre une photo</label><br>';
                     echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">';// Bouton de capture photo
                     echo '<br>';
                     echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>';// Champ caché pour stocker la photo
                  echo "</tr><br>";
                  
                  echo "<tr>";

                        echo '<label for="name">Non / Prénom</label><br>';
                        echo "<input type='text' id='name' name='name' placeholder='Nom / Prenom du client' required=''>";

                  echo "</tr><br>";

                  // TABLEAU 3 : Canvas pour signature et bouton de suppression
                  echo "<tr><br>";
                        echo '<label for="name">Signature</label><br>';
                         echo "<div id='".$uniq."' class='cri-signature-root' style='position:relative'>";
                           // petit canvas + bouton zoom + bouton effacer
                           echo "  <div class='signature-container'>";
                           echo "    <button type='button' class='zoom-btn'>Agrandir <i class='fa-solid fa-up-right-and-down-left-from-center'></i></button>";
                           echo "    <canvas id='sig-canvas-".$uniq."' width='320' height='80' class='sig-base' style='border:1px solid #ccc;'></canvas>";
                           echo "  </div>";
                           echo "  <br>";
                           echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton' style='margin:5px 0 0 0; padding:5px 10px;'>Supprimer la signature</button>";

                           // modal interne pour le zoom (overlay)
                           echo "  <div class='signature-modal' aria-hidden='true'>";
                           echo "    <div class='modal-wrapper'>";                       // <- on garde
                           echo "      <div class='cri-modal-content'>";                 // <- renommé
                           echo "      <div class='rotate-gate'>\n";
                           echo "        <div>\n";
                           echo "          <div style='font-size:18px;font-weight:700;margin-bottom:8px'>\n";
                           echo "            Tournez votre téléphone en mode paysage\n";
                           echo "          </div>\n";
                           echo "          <div style='opacity:0.9'>La zone de signature va s’agrandir automatiquement.</div>\n";
                           echo "        </div>\n";
                           echo "      </div>\n";
                           echo "        <div class='cri-canvas-wrapper'>";              // <- renommé
                           echo "          <canvas id='modal-canvas-".$uniq."' class='modal-canvas'></canvas>";
                           echo "        </div>";
                           echo "        <div class='cri-controls-panel'>";              // <- renommé
                           echo "          <button type='button' class='btn-validate'>Valider</button>";
                           echo "          <button type='button' class='btn-clear'>Effacer</button>";
                           echo "          <button type='button' class='btn-cancel'>Annuler</button>";
                           echo "        </div>";
                           echo "      </div>";
                           echo "    </div>";
                           echo "  </div>";
                        echo "</div>";

                  echo "</tr><br>";
                  
                  if ($config->fields['MailTo'] == 1){
                     // Mail
                     echo "<tr><br>";
                           echo 'Mail client';
                              echo'<br><h5 style="font-weight: normal; margin-top: -0px;"> Cocher pour envoyer le PDF par email. </h5>';

                              echo '<input type="checkbox" name="mailtoclient" value="1">&emsp;';
                           echo "<input type='mail' id='mail' name='email' value='".$email."' style='widtd: 250px;'>";
                     echo "</tr><br>";
                  }
            
               echo "</table>"; 
               echo "</div>";
               //----------------------------------- FIN MOBILE -----------------------------------
            } else { // ----------------------------------- ORDINATEUR -----------------------------------
               echo '<div class="table-responsive">';
               echo "<table class='table'>"; 

                  $DocUrlSharePoint  = $DOC->doc_url;
                  function AffichageNoSigneNoMobile($Doc_Name, $DocUrlSharePoint){
                     echo "<tr>";
                        echo "<td class='table-secondary' style='width: 20%;'>"; // Réduit la largeur de la colonne de gauche
                           echo 'Document : <strong>'.$Doc_Name.'</strong>';
                           echo '<br><br><br>';
                           echo "<strong><u>STATUS</u></strong>";
                           echo '<br>';
                           echo 'Non Signé';
                        echo "</td>";
                        
                        echo "<td style='width: 80%;'>"; // Augmente la largeur de la colonne droite pour le PDF
                           // Affiche le PDF intégré avec une classe CSS pour le responsive
                           echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>'; // Affiche le PDF intégré avec une classe CSS pour le responsive
                        echo "</td>";
                     echo "</tr>";
                  }

                  if ($config->fields['SharePointLinkDisplay'] == 1){    
                     // Utilisation
                     try {
                        // Construire le chemin complet du fichier
                        $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                        $filePath = $folderPath . $Doc_Name; // Chemin du fichier

                        // Remplacer tous les doubles slashs par un simple slash
                        $filePath = preg_replace('#/+#', '/', $filePath);

      /////////////////////////////// NEWS OK Non Signer ///////////////////////////////
                        if ($DOC->save == 'SharePoint'){
                           // Obtenir l'URL de téléchargement direct du fichier
                           $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath);  
                        }
                        if ($DOC->save == 'Local'){
                           $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;
                        }
                        if ($DOC->save == 'Sage'){
                           $fileDownloadUrl = $DOC->doc_url;
                        }

                        // Étape 4 : Affichez le PDF via <embed>
                        echo "<tr>";
                           echo "<td class='table-secondary' style='width: 20%;'>"; // Réduit la largeur de la colonne de gauche
                              echo 'Document : <strong>'.$Doc_Name.'</strong>';
                              echo '<br><br><br>';
                              echo "<strong><u>STATUS</u></strong>";
                              echo '<br>';
                              echo 'Non Signé';
                           echo "</td>";
                           
                           echo "<td style='width: 80%;'>"; // Augmente la largeur de la colonne droite pour le PDF
                              // Affiche le PDF intégré avec une classe CSS pour le responsive
                              if ($DOC->save == 'SharePoint'){
                                 echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                       style='width:100%; height:600px;' frameborder='0'></iframe>";
                              }
                              if ($DOC->save == 'Local'){
                                 echo "<iframe src='$fileDownloadUrl' style='width:100%; height:600px;' frameborder='0'></iframe>";
                              }
                              if ($DOC->save == 'Sage'){
                                 echo "<iframe src='$fileDownloadUrl' width='100%' height='600px' style='border:none;'></iframe>";
                              }
                           echo "</td>";
                        echo "</tr>";
      /////////////////////////////// NEWS OK Non Signer ///////////////////////////////

                        // Voir PDF
                        echo "<tr>";
                           echo "<td class='table-secondary'>";
                           echo "</td>";
                           echo "<td>";
                              echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>'; // Bouton pour voir le PDF en plein écran
                           echo "</td>";
                        echo "</tr>";
                     } catch (Exception $e) {
                        AffichageNoSigneNoMobile($Doc_Name, $DocUrlSharePoint);
                     }
                  }else{
                     AffichageNoSigneNoMobile($Doc_Name, $DocUrlSharePoint);
                  }

                  // enregistrer un fichier 
                  echo "<tr>";
                     echo "<td class='table-secondary'>"; // Réduit la largeur de la colonne de gauche
                        echo 'Ajouter un fichier / image';
                     echo "</td>";
                     
                     echo "<td>"; // Augmente la largeur de la colonne droite pour le PDF
                        //echo '<label for="capture-photo">Prendre une photo</label><br>';
                        echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">'; // Bouton de capture photo
                        echo '<br>';
                        echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>'; // Champ caché pour stocker la photo
                     echo "</td>";
                  echo "</tr>";
               
                  // signature
                  echo "<tr>";
                     echo "<td>";
                     echo "</td>";
                     echo "<td>";
                        echo '<b> ______________ SIGNATURE CLIENT ______________<b>';
                     echo "</td>";
                  echo "</tr>";

                  // TABLEAU 1
                  echo "<tr>";
                     echo "<td class='table-secondary'>";
                        echo 'Nom / Prenom du client';
                     echo "</td>";

                     echo "<td>"; // Augmente la largeur de la colonne droite pour le PDF
                        echo "<input type='text' id='name' name='name' placeholder='Nom / Prenom du client' required=''>"; // Champ pour le nom du client
                     echo "</td>";
                  echo "</tr>";

                  // TABLEAU 3 : Canvas pour signature et bouton de suppression
                  echo "<tr>";
                     echo "<td class='table-secondary'>";
                        echo 'Signature client';
                     echo "</td>";

                     echo "<td>";
                        echo "<div id='".$uniq."' class='cri-signature-root' style='position:relative'>";
                           // petit canvas + bouton zoom + bouton effacer
                           echo "  <div class='signature-container'>";
                           echo "    <button type='button' class='zoom-btn'>Agrandir <i class='fa-solid fa-up-right-and-down-left-from-center'></i></button>";
                           echo "    <canvas id='sig-canvas-".$uniq."' width='320' height='80' class='sig-base' style='border:1px solid #ccc;'></canvas>";
                           echo "  </div>";
                           echo "  <br>";
                           echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton' style='margin:5px 0 0 0; padding:5px 10px;'>Supprimer la signature</button>";

                           // modal interne pour le zoom (overlay)
                           echo "  <div class='signature-modal' aria-hidden='true'>";
                           echo "    <div class='modal-wrapper'>";                       // <- on garde
                           echo "      <div class='cri-modal-content'>";                 // <- renommé
                           echo "      <div class='rotate-gate'>\n";
                           echo "        <div>\n";
                           echo "          <div style='font-size:18px;font-weight:700;margin-bottom:8px'>\n";
                           echo "            Tournez votre téléphone en mode paysage\n";
                           echo "          </div>\n";
                           echo "          <div style='opacity:0.9'>La zone de signature va s’agrandir automatiquement.</div>\n";
                           echo "        </div>\n";
                           echo "      </div>\n";
                           echo "        <div class='cri-canvas-wrapper'>";              // <- renommé
                           echo "          <canvas id='modal-canvas-".$uniq."' class='modal-canvas'></canvas>";
                           echo "        </div>";
                           echo "        <div class='cri-controls-panel'>";              // <- renommé
                           echo "          <button type='button' class='btn-validate'>Valider</button>";
                           echo "          <button type='button' class='btn-clear'>Effacer</button>";
                           echo "          <button type='button' class='btn-cancel'>Annuler</button>";
                           echo "        </div>";
                           echo "      </div>";
                           echo "    </div>";
                           echo "  </div>";
                        echo "</div>";
                     echo "</td>";
                  echo "</tr>";

                  if ($config->fields['MailTo'] == 1){
                     // Mail
                     echo "<tr>";
                        echo "<td class='table-secondary'>";
                           echo 'Mail client';
                              echo'<br><h5 style="font-weight: normal; margin-top: -0px;"> Cocher pour envoyer le PDF par email. </h5>';
                        echo "</td>";

                        echo "<td>";
                              echo '<input type="checkbox" name="mailtoclient" value="1">&emsp;';
                           echo "<input type='mail' id='mail' name='email' value='".$email."' style='widtd: 250px;'>";
                        echo "</td>";
                     echo "</tr>";
                  }
            
               echo "</table>"; 
               echo "</div>";
            } // ----------------------------------- FIN ORDINATEUR -----------------------------------
            if(Session::haveRight("plugin_gestion_sign", CREATE)){
               echo '<div class="button-container-right" style="text-align: right;">';
                  echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signé" class="submit">'; // Bouton pour signer
               echo '</div>';

               echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';  // Champ caché pour stocker la signature
            }
         }else{ // ----------------------------------- SIGNE -----------------------------------
            echo '<div class="table-responsive">';
            echo "<table class='table'>"; 

            if (isMobile()) { // -------------------------------------------------------------------------------- MOBILE ----------------------------------------------------------------
               echo 'Document : <strong>'.$Doc_Name.'</strong>';
               echo '<br><br>';
               echo "<strong><u>STATUS</u></strong>";
               echo '<br>';
               echo 'Signé le : '.$DOC->date_creation.' ';
               echo '<br>';
               echo 'Par : '.$DOC->users_ext.' ';
               echo '<br><br>';
               echo 'Livré par : '.getUserName($DOC->users_id).' ';
               echo '<br><br>';

                  $DocUrlSharePoint  = $DOC->doc_url;
                  function AffichageSigneMobile($DocUrlSharePoint){
                     // Bouton pour voir le PDF en plein écran
                     echo "<tr>";
                        echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';   // Bouton pour voir le PDF en plein écran
                     echo "</tr><br><br>";
                  }

                  if ($config->fields['SharePointLinkDisplay'] == 1){
                     // Utilisation
                     try {
                        // Construire le chemin complet du fichier
                        $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                        $filePath = $folderPath . $Doc_Name; // Chemin du fichier

                        // Remplacer tous les doubles slashs par un simple slash
                        $filePath = preg_replace('#/+#', '/', $filePath);

                        if ($DOC->save == 'SharePoint'){
                           // Obtenir l'URL de téléchargement direct du fichier
                           $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath);  
                        }
                        if ($DOC->save == 'Local'){
                           $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;
                        }

                        // Étape 4 : Affichez le PDF via <embed>
                        echo "<tr>";
                        // Affiche le PDF intégré avec une classe CSS pour le responsive
                           if ($DOC->save == 'SharePoint'){
                              echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                    style='width:100%; height:600px;' frameborder='0'></iframe>";
                           }
                           if ($DOC->save == 'Local'){
                              echo "
                                 <style>
                                 .pdf-container {
                                 width: 100%;
                                 max-width: 100vw;
                                 aspect-ratio: 1 / 1.414; /* Ratio A4 en portrait */
                                 overflow: hidden;
                                 }
                                 .pdf-container embed {
                                 width: 100%;
                                 height: 100%;
                                 object-fit: contain;
                                 }
                                 </style>

                                 <div class='pdf-container'>
                                 <embed 
                                    src='".$fileDownloadUrl."' 
                                    type='application/pdf' 
                                    />
                                 </div>";
                           }
                        echo "</tr>";

                        // Bouton pour voir le PDF en plein écran
                        echo "<tr>";
                        echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>'; //   Bouton pour voir le PDF en plein écran
                        echo "</tr><br><br>";

                     } catch (Exception $e) {
                        AffichageSigneMobile($DocUrlSharePoint);
                     }
                  }else{
                     AffichageSigneMobile($DocUrlSharePoint);
                  }
                           // ----------------------------------- FIN MOBILE -----------------------------------
            }else{// -------------------------------------------------------------------------------- ORDINATEUR----------------------------------------------------------------
               // Affichage du PDF en mode image
               echo "<tr>";
                  echo "<td class='table-secondary' style='width: 30%;'>"; // Réduit la largeur de la colonne de gauche
                     echo 'Document : <strong>'.$Doc_Name.'</strong>';
                     echo '<br><br><br>';
                     echo "<strong><u>STATUS</u></strong>";
                     echo '<br>';
                     echo 'Signé le : '.$DOC->date_creation.' ';
                     echo '<br>';
                     echo 'Par : '.$DOC->users_ext.' ';
                     echo '<br><br>';
                     echo 'Livré par : '.getUserName($DOC->users_id).' ';
                  echo "</td>";

                  $DocUrlSharePoint  = $DOC->doc_url;
                  function AffichageSigneNoMobile($DocUrlSharePoint){
                           echo "<td style='width: 70%;'>"; // Augmente la largeur de la colonne droite pour le PDF
                           // Affiche le PDF intégré avec une classe CSS pour le responsive
                           echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';   // Affiche le PDF intégré avec une classe CSS pour le responsive
                        echo "</td>";
                     echo "</tr>";
                  }

                  if ($config->fields['SharePointLinkDisplay'] == 1){
                     // Utilisation
                     try {

                        // Construire le chemin complet du fichier
                        $folderPath = (!empty($DOC->url_bl)) ? $DOC->url_bl . "/" : "";
                        $filePath = $folderPath . $Doc_Name; // Chemin du fichier

                        // Remplacer tous les doubles slashs par un simple slash
                        $filePath = preg_replace('#/+#', '/', $filePath);

      /////////////////////////////// NEWS OK Signer ///////////////////////////////
                        if ($DOC->save == 'SharePoint'){
                           // Obtenir l'URL de téléchargement direct du fichier
                           $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($filePath); 
                        }
                        if ($DOC->save == 'Local'){
                           $fileDownloadUrl = 'document.send.php?docid='.$DOC->doc_id;
                        }

                        // Étape 4 : Affichez le PDF via <embed>
                           echo "<td style='width: 70%;'>"; // Augmente la largeur de la colonne droite pour le PDF
                              // Affiche le PDF intégré avec une classe CSS pour le responsive
                              if ($DOC->save == 'SharePoint'){
                                 echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                       style='width:100%; height:600px;' frameborder='0'></iframe>";
                              }
                              if ($DOC->save == 'Local'){
                                 echo "<iframe src='$fileDownloadUrl' style='width:100%; height:600px;' frameborder='0'></iframe>";
                              }                        
                           echo "</td>";
                        echo "</tr>";
      /////////////////////////////// NEWS OK Signer ///////////////////////////////

                        // Voir PDF
                        echo "<tr>";
                           echo "<td class='table-secondary'>";
                           echo "</td>";
                           echo "<td>";
                              echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';      // Bouton pour voir le PDF en plein écran
                           echo "</td>";
                        echo "</tr>";
                     } catch (Exception $e) {                        
                        AffichageSigneNoMobile($DocUrlSharePoint);
                     }
                  }else{
                     AffichageSigneNoMobile($DocUrlSharePoint);
                  }
            } // -------------------------------------------------------------------------------- FIN ORDINATEUR ----------------------------------------------------------------
            echo "</table>"; 
            echo "</div>";
         } // ----------------------------------- FIN SIGNE -----------------------------------
      Html::closeForm();
         ?>
         <script>
            // //--------------------------------------------------- Gestion de la capture de photo
            document.getElementById('capture-photo').addEventListener('change', function(event) {
               const file = event.target.files[0];

               if (file) {
                  // Vérifiez si le fichier est une image
                  if (!file.type.startsWith('image/')) {
                     alert("Le fichier sélectionné n'est pas une image.");
                     return;
                  }

                  // Vérifiez si le fichier est au format PNG ou JPEG
                  if (file.type !== 'image/png' && file.type !== 'image/jpeg') {
                     alert("Le fichier doit être au format PNG ou JPEG.");
                     return;
                  }

                  const reader = new FileReader();
                  reader.onload = function(e) {
                     document.getElementById('photo-base64').value = e.target.result;
                  };
                  reader.readAsDataURL(file);
               }
            });

            //--------------------------------------------------- signature
            (function(){
               const root = document.getElementById('<?= $uniq ?>');
               if (!root) return;

               // Elements
               const originalCanvas = root.querySelector('#sig-canvas-<?= $uniq ?>');
               const modalCanvas    = root.querySelector('#modal-canvas-<?= $uniq ?>');
               const modalOverlay   = root.querySelector('.signature-modal');
               const btnZoom        = root.querySelector('.zoom-btn');
               const btnClearBase   = root.querySelector('#sig-clearBtn-<?= $uniq ?>');
               const btnValidate    = root.querySelector('.btn-validate');
               const btnClearModal  = root.querySelector('.btn-clear');
               const btnCancel      = root.querySelector('.btn-cancel');

               // Contexts
               const originalCtx = originalCanvas.getContext('2d');
               const modalCtx    = modalCanvas.getContext('2d');

               // Canvas/calque d'export (non affiché)
               const modalExportCanvas = document.createElement('canvas');
               const modalExportCtx    = modalExportCanvas.getContext('2d');

               // ---- constantes d'épaisseur (en pixels CSS) ----
               const TARGET_BASE_LINE   = 1.8; // épaisseur VISIBLE voulue dans le petit canvas
               const VISUAL_MODAL_LINE  = 1.6; // un poil plus fin visuellement dans le modal

               // ---- setup générique d'un ctx ----
               function setup(ctx, lw){
                  ctx.strokeStyle = '#000';
                  ctx.lineWidth   = lw;
                  ctx.lineCap     = 'round';
                  ctx.lineJoin    = 'round';
               }

               // ---- prise en compte du DPR (HiDPI) ----
               function fixDPR(canvas, ctx, cssW, cssH){
                  const dpr = window.devicePixelRatio || 1;
                  canvas.style.width  = cssW + 'px';
                  canvas.style.height = cssH + 'px';
                  canvas.width  = Math.round(cssW * dpr);
                  canvas.height = Math.round(cssH * dpr);
                  ctx.setTransform(dpr, 0, 0, dpr, 0, 0); // 1 unité = 1 px CSS
               }

               // ---- petit canvas (base) ----
               const baseCSSW = originalCanvas.clientWidth  || 320;
               const baseCSSH = originalCanvas.clientHeight || 80;
               const DPR_BASE = fixDPR(originalCanvas, originalCtx, baseCSSW, baseCSSH);
               // épaisseur visible voulue * DPR
               setup(originalCtx, TARGET_BASE_LINE);

               // ---- modal : applique styles après avoir fixé taille & DPR ----
               function applyModalStyle(){
                  const ratio = modalExportCanvas.width / originalCanvas.width; // deux tailles *internes* (DPR inclus)
                  const exportLine = Math.max(1, TARGET_BASE_LINE * ratio);     // pour conserver l’épaisseur après réduction
                  setup(modalCtx,       VISUAL_MODAL_LINE);                     // visuel modal
                  setup(modalExportCtx, exportLine);                            // calque d’export (épais)
                  }

                  // Dessin (Pointer Events)
                  let currentCanvas = originalCanvas;
                  let currentCtx    = originalCtx;
                  let drawing = false;
                  let lastPos = {x:0,y:0};

                  function getPos(e, canvas){
                  const rect = canvas.getBoundingClientRect();
                  const t = e.touches?.[0] || e.changedTouches?.[0] || e;
                  return { x: t.clientX - rect.left, y: t.clientY - rect.top };
               }

               function start(e, canvas){
                  e.preventDefault();
                  currentCanvas = canvas;
                  currentCtx    = canvas.getContext('2d');
                  drawing = true;
                  lastPos = getPos(e, canvas);
                  if (e.pointerId != null) canvas.setPointerCapture(e.pointerId);
               }

               // ---- dessin : si on dessine dans le modal, doubler sur le calque export ----
               function move(e){
                  if (!drawing) return;
                  const p = getPos(e, currentCanvas);
                  currentCtx.beginPath();
                  currentCtx.moveTo(lastPos.x, lastPos.y);
                  currentCtx.lineTo(p.x, p.y);
                  currentCtx.stroke();

                  if (currentCanvas === modalCanvas) {
                     modalExportCtx.beginPath();
                     modalExportCtx.moveTo(lastPos.x, lastPos.y);
                     modalExportCtx.lineTo(p.x, p.y);
                     modalExportCtx.stroke();
                  }
                  lastPos = p;
               }

               function end(e){
                  if (!drawing) return;
                  drawing = false;
                  currentCtx.beginPath();
                  if (e && e.pointerId != null) { try { currentCanvas.releasePointerCapture(e.pointerId); } catch{} }
               }

               function bindCanvas(canvas){
                  canvas.addEventListener('pointerdown', (e)=>start(e, canvas));
                  canvas.addEventListener('pointermove', move);
                  canvas.addEventListener('pointerup', end);
                  canvas.addEventListener('pointercancel', end);
                  // éviter zoom iOS/scroll pendant dessin
                  canvas.addEventListener('touchstart', (e)=>e.preventDefault(), {passive:false});
                  canvas.addEventListener('touchmove',  (e)=>e.preventDefault(), {passive:false});
               }

               bindCanvas(originalCanvas);
               bindCanvas(modalCanvas);

               // Effacer base
               btnClearBase.addEventListener('click', ()=>{
                  originalCtx.clearRect(0,0,originalCanvas.width, originalCanvas.height);
                  setup(originalCtx, TARGET_BASE_LINE);
               });
               btnClearModal.addEventListener('click', ()=>{
                  modalCtx.clearRect(0,0,modalCanvas.width, modalCanvas.height);
                  modalExportCtx.clearRect(0,0,modalExportCanvas.width, modalExportCanvas.height);
                  applyModalStyle();
               });

               // --- helpers ---
               const isMobileScreen = () => window.innerWidth <= 1024;
               const isLandscape    = () => window.matchMedia("(orientation: landscape)").matches;
               const rotateGate     = root.querySelector('.rotate-gate');

               // Taille “desktop” (modal d’origine) : on cale sur l’espace dispo du wrapper
               function sizeModalCanvasDesktop(){
               const wrapper = root.querySelector('.cri-canvas-wrapper');
               const r = wrapper.getBoundingClientRect();
               // on garde ton ratio ~3:1, SANS toucher au layout du modal
               const pad = 20, aspect = 3;
               let w = Math.max(360, Math.floor(r.width  - pad*2));
               let h = Math.max(120, Math.floor(r.height - pad*2));
               if (w / h > aspect) { w = Math.floor(h * aspect); } else { h = Math.floor(w / aspect); }

               fixDPR(modalCanvas, modalCtx, w, h);
                  modalExportCanvas.width  = modalCanvas.width;
                  modalExportCanvas.height = modalCanvas.height;
                  applyModalStyle();
               }

               // Taille “mobile paysage” : plein écran (moins le panneau de boutons)
               function sizeModalCanvasMobile(){
                  const panelW = Math.max(100, root.querySelector('.cri-controls-panel').getBoundingClientRect().width || 120);
                  const pad = 20, aspect = 3;
                  let availW = Math.max(320, window.innerWidth  - panelW - pad*2);
                  let availH = Math.max(160, window.innerHeight - pad*2);
                  let w = availW, h = availH;
                  if (w / h > aspect) { w = Math.floor(h * aspect); } else { h = Math.floor(w / aspect); }

                  fixDPR(modalCanvas, modalCtx, w, h);
                  modalExportCanvas.width  = modalCanvas.width;
                  modalExportCanvas.height = modalCanvas.height;
                  applyModalStyle();
               }

               // Affiche/masque l’overlay “tournez” et redimensionne en mobile
               function updateOrientationGate(){
               if (!isMobileScreen()) return; // ne rien faire sur desktop
               if (isLandscape()) {
                  rotateGate.classList.remove('show');
                  sizeModalCanvasMobile();
               } else {
                  rotateGate.classList.add('show');
               }
               }

               // ---- ouverture du modal ----
               btnZoom.addEventListener('click', ()=>{
               document.documentElement.classList.add('no-scroll');
               modalOverlay.classList.add('active');

               if (isMobileScreen()) {
                  // mobile : impose paysage
                  updateOrientationGate();
                  if (isLandscape()) {
                     modalCtx.clearRect(0,0,modalCanvas.width, modalCanvas.height);
                     modalExportCtx.clearRect(0,0,modalExportCanvas.width, modalExportCanvas.height);
                     modalCtx.drawImage(originalCanvas, 0, 0, modalCanvas.width, modalCanvas.height);
                     modalExportCtx.drawImage(originalCanvas, 0, 0, modalExportCanvas.width, modalExportCanvas.height);
                  }
               } else {
                  // desktop : garder la taille du modal d’origine
                  sizeModalCanvasDesktop();
                  modalCtx.clearRect(0,0,modalCanvas.width, modalCanvas.height);
                  modalExportCtx.clearRect(0,0,modalExportCanvas.width, modalExportCanvas.height);
                  modalCtx.drawImage(originalCanvas, 0, 0, modalCanvas.width, modalCanvas.height);
                  modalExportCtx.drawImage(originalCanvas, 0, 0, modalExportCanvas.width, modalExportCanvas.height);
               }
               });

               // Recalcule seulement en mobile
               window.addEventListener('orientationchange', updateOrientationGate);
               window.addEventListener('resize', updateOrientationGate);

               // Fermer modal = retirer no-scroll
               btnCancel.addEventListener('click', ()=>{
                  modalOverlay.classList.remove('active');
                  document.documentElement.classList.remove('no-scroll');
               });
               btnValidate.addEventListener('click', ()=>{
                  const tw = originalCanvas.width, th = originalCanvas.height;
                  originalCtx.clearRect(0,0,tw,th);
                  originalCtx.save();
                  originalCtx.imageSmoothingEnabled = true;
                  originalCtx.imageSmoothingQuality = 'high';
                  originalCtx.drawImage(modalExportCanvas, 0, 0, tw, th);
                  originalCtx.restore();

                  modalOverlay.classList.remove('active');
                  document.documentElement.classList.remove('no-scroll');
               });

               // ---- valider : copie sans lissage depuis le calque d’export ----
               btnValidate.addEventListener('click', ()=>{
                  const tw = originalCanvas.width;
                  const th = originalCanvas.height;

                  originalCtx.clearRect(0,0,tw,th);
                  originalCtx.save();
                  originalCtx.imageSmoothingEnabled = true;       // <= activer
                  originalCtx.imageSmoothingQuality = 'high';     // 'low' | 'medium' | 'high'
                  originalCtx.drawImage(modalExportCanvas, 0, 0, tw, th);
                  originalCtx.restore();

                  modalOverlay.classList.remove('active');
               });

               // Annuler
               btnCancel.addEventListener('click', ()=>{
                  modalOverlay.classList.remove('active');
                  end({});
               });

               // Remplir le champ caché à l'envoi
               const submitBtn  = document.getElementById('sig-submitBtn');
               const hiddenArea = document.getElementById('sig-dataUrl');
               if (submitBtn && hiddenArea && !submitBtn.dataset.sigInit) {
                  submitBtn.dataset.sigInit = '1';
                  submitBtn.addEventListener('click', function(){
                  hiddenArea.value = originalCanvas.toDataURL();
                  });
               }

               // anti double-tap zoom iOS
               document.addEventListener('touchend', (function(){
                  let last = 0;
                  return function(e){
                  const now = Date.now();
                  if (now - last < 300) e.preventDefault();
                  last = now;
                  };
               })(), {passive:false});

            })();

            // Set up tde UI
               var sigText = document.getElementById("sig-dataUrl");    // Récupère l'élément sig-dataUrl
               var submitBtn = document.getElementById("sig-submitBtn");      // Récupère l'élément sig-submitBtn

               submitBtn.addEventListener("click", function(e) {     // Ajoute un événement de clic au bouton de soumission
                  var dataUrl = canvas.toDataURL();
                  sigText.innerHTML = dataUrl;
               }, false);
               
               //--------------------------------------------------- BTN SUPPRIMER
               var clearBtn = document.getElementById("sig-clearBtn");     // Récupère l'élément sig-clearBtn
               clearBtn.addEventListener("click", function(e) {         // Ajoute un événement de clic au bouton de suppression
                  clearCanvas();
                  sigImage.setAttribute("src", "");
               }, false);
            
               function clearCanvas() {         // Fonction pour effacer le canvas
                  ctx.clearRect(0, 0, canvas.width, canvas.height); // Efface le contenu du canvas
                  ctx.beginPath(); // Réinitialise le chemin de dessin pour éviter que l'ancienne signature ne réapparaisse
               }

               // Sélectionne le bouton de suppression et lui ajoute l'événement de clic
               var clearBtn = document.getElementById("sig-clearBtn");     // Récupère l'élément sig-clearBtn
               clearBtn.addEventListener("click", function(e) {      // Ajoute un événement de clic au bouton de suppression
                  clearCanvas();
               }, false);
         </script>
      <?php 
   }
}
