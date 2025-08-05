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

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/css/signature.css">';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/scripts/signature.js" defer></script>';

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
               // Document
               echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Document :</div>';
                     echo '<strong>'.$Doc_Name.'</strong><br><br>';
                     echo '<strong><u>STATUS</u></strong><br>';
                     echo 'Non Signé';
               echo '</div>';
               
               // Section PDF
               echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Visualisation du document</div>';
                     
                     $DocUrlSharePoint = $DOC->doc_url;
                     
                     if ($config->fields['SharePointLinkDisplay'] == 1){
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

                           // PDF intégré
                           if ($DOC->save == 'SharePoint'){
                                 echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                       style='width:100%; height:400px; border:1px solid #ccc;' frameborder='0'></iframe>";
                           }
                           if ($DOC->save == 'Local'){
                                 echo "
                                    <style>
                                    .pdf-container {
                                       width: 100%;
                                       max-width: 100vw;
                                       aspect-ratio: 1 / 1.414;
                                       overflow: hidden;
                                    }
                                    .pdf-container embed {
                                       width: 100%;
                                       height: 100%;
                                       object-fit: contain;
                                    }
                                    </style>
                                    <div class='pdf-container'>
                                       <embed src='".$fileDownloadUrl."' type='application/pdf' />
                                    </div>";
                           }
                           if ($DOC->save == 'Sage'){
                                 echo "
                                    <style>
                                    .pdf-container {
                                       width: 100%;
                                       max-width: 100vw;
                                       aspect-ratio: 1 / 1.414;
                                       overflow: hidden;
                                    }
                                    .pdf-container embed {
                                       width: 100%;
                                       height: 100%;
                                       object-fit: contain;
                                    }
                                    </style>
                                    <div class='pdf-container'>
                                       <embed src='".$fileDownloadUrl."' type='application/pdf' />
                                    </div>";
                           }
                           
                        } catch (Exception $e) {
                           // Fallback si erreur
                        }
                     }
                     
                     echo '<br><a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                     
               echo '</div>';

               // Section capture photo
               echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Ajouter un fichier / image</div>';
                     echo '<div class="mobile-form-content">';
                        echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">';
                        echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>';
                     echo '</div>';
               echo '</div>';
               
               echo '<div class="mobile-signature-section">';
                  echo '<div class="mobile-form-label">SIGNATURE CLIENT</div>';
                  
                  echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Nom / Prénom du client</div>';
                     echo '<div class="mobile-form-content">';
                           echo '<input type="text" id="name" name="name" placeholder="Nom / Prénom du client" required>';
                     echo '</div>';
                  echo '</div>';
                  
                  echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Signature client</div>';
                     echo '<div class="mobile-form-content">';
                           // Votre code de signature existant
                           echo "<div id='".$uniq."' class='cri-signature-root' style='position:relative'>";
                              echo "<tr><br>";
                                 echo "<div id='".$uniq."' class='cri-signature-root' style='position:relative'>";
                                    // petit canvas + bouton zoom + bouton effacer
                                    echo "  <div class='signature-container'>";
                                    echo "    <button type='button' class='zoom-btn'>Agrandir <i class='fa-solid fa-up-right-and-down-left-from-center'></i></button>";
                                    echo "    <canvas id='sig-canvas-".$uniq."' width='320' height='80' class='sig-base'></canvas>";
                                    echo "  </div>";
                                    echo "  <br>";
                                    echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton' style='margin:5px 0 0 0; padding:5px 10px;'>Supprimer la signature</button>";

                                    // modal interne pour le zoom (overlay)
                                    echo "  <div class='signature-modal' aria-hidden='true'>";
                                    echo "    <div class='modal-wrapper'>";                       // <- on garde
                                    echo "      <div class='cri-modal-content'>";                 // <- renommé
                                    echo "      <div class='rotate-gate'>\n";
                                    echo "        <button type='button' class='rotate-close-btn' aria-label='Fermer'>&times;</button>\n";
                                    echo "        <div>\n";
                                    echo "          <div style='font-size:18px;font-weight:700;margin-bottom:8px'>\n";
                                    echo "            Tournez votre téléphone en mode paysage\n";
                                    echo "          </div>\n";
                                    echo "          <div style='opacity:0.9'>La zone de signature va s'agrandir automatiquement.</div>\n";
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
                           echo "</div>";
                     echo '</div>';
                  echo '</div>';
               echo '</div>';
               
               // Section email (si activée)
               if ($config->fields['MailTo'] == 1){
                  echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Mail client</div>';
                        echo '<div class="mobile-checkbox-group">';
                           echo '<input type="checkbox" name="mailtoclient" value="1" id="send_email">';
                           echo '<label for="send_email">Envoyer le PDF par email</label>';
                        echo '</div>';
                     echo '<div class="mobile-form-content">';
                        echo '<input type="email" id="mail" name="email" value="'.$email.'" placeholder="Email du client">';
                     echo '</div>';
                  echo '</div>';
               }

               // Bouton de soumission (si droits suffisants)
               if(Session::haveRight("plugin_gestion_sign", CREATE)){
                     echo '<div class="mobile-form-row" style="text-align: center;">';
                        echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signer le PDF" class="submit" style="width: 100%; padding: 15px; font-size: 16px; background: #007bff; color: white; border: none; border-radius: 5px;">';
                     echo '</div>';
                     
                     echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';
               }
               
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
                           echo "    <canvas id='sig-canvas-".$uniq."' width='320' height='80' class='sig-base'></canvas>";                           
                           echo "  </div>";
                           echo "  <br>";
                           echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton' style='margin:5px 0 0 0; padding:5px 10px;'>Supprimer la signature</button>";

                           // modal interne pour le zoom (overlay)
                           echo "  <div class='signature-modal' aria-hidden='true'>";
                           echo "    <div class='modal-wrapper'>";                       // <- on garde
                           echo "      <div class='cri-modal-content'>";                 // <- renommé
                           echo "      <div class='rotate-gate'>\n";
                           echo "        <button type='button' class='rotate-close-btn' aria-label='Fermer'>&times;</button>\n";
                           echo "        <div>\n";
                           echo "          <div style='font-size:18px;font-weight:700;margin-bottom:8px'>\n";
                           echo "            Tournez votre téléphone en mode paysage\n";
                           echo "          </div>\n";
                           echo "          <div style='opacity:0.9'>La zone de signature va s'agrandir automatiquement.</div>\n";
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
               if(Session::haveRight("plugin_gestion_sign", CREATE)){
                  echo '<div class="button-container-right" style="text-align: right;">';
                     echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signer le PDF" class="submit">'; // Bouton pour signer
                  echo '</div>';

                  echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';  // Champ caché pour stocker la signature
               }
            } // ----------------------------------- FIN ORDINATEUR -----------------------------------

         }else{ // ----------------------------------- SIGNE -----------------------------------
            echo '<div class="table-responsive">';
            echo "<table class='table'>"; 

            if (isMobile()) { // -------------------------------------------------------------------------------- MOBILE ----------------------------------------------------------------
               echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Document :</div>';
                     echo '<strong>'.$Doc_Name.'</strong><br><br>';
                     echo '<strong><u>STATUS</u></strong><br>';
                     echo 'Signé le : '.$DOC->date_creation.'<br>';
                     echo 'Par : '.$DOC->users_ext.'<br><br>';
                     echo 'Livré par : '.getUserName($DOC->users_id);
               echo '</div>';
               
               // Section PDF signé
               echo '<div class="mobile-form-row">';
                     echo '<div class="mobile-form-label">Document signé</div>';
                     
                     $DocUrlSharePoint = $DOC->doc_url;
                     
                     if ($config->fields['SharePointLinkDisplay'] == 1){
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

                           // PDF intégré signé
                           if ($DOC->save == 'SharePoint'){
                                 echo "<iframe src='https://docs.google.com/gview?url=" . urlencode($fileDownloadUrl) . "&embedded=true' 
                                       style='width:100%; height:400px; border:1px solid #ccc;' frameborder='0'></iframe>";
                           }
                           if ($DOC->save == 'Local'){
                                 echo "
                                    <style>
                                    .pdf-container-signed {
                                       width: 100%;
                                       max-width: 100vw;
                                       aspect-ratio: 1 / 1.414;
                                       overflow: hidden;
                                    }
                                    .pdf-container-signed embed {
                                       width: 100%;
                                       height: 100%;
                                       object-fit: contain;
                                    }
                                    </style>
                                    <div class='pdf-container-signed'>
                                       <embed src='".$fileDownloadUrl."' type='application/pdf' />
                                    </div>";
                           }

                        } catch (Exception $e) {
                           // Fallback si erreur
                        }
                     }
                     
                     echo '<br><a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                     
               echo '</div>';
            
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
      </script>
      <?php
   }
}
