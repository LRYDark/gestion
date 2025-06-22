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
      </style><?php
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
                                    src='document.send.php?docid=5#zoom=page-width' 
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
                        // Canvas aligné à gauche
                        echo "<canvas id='sig-canvas' class='sig' width='320' height='80' style='border: 1px solid #ccc;'></canvas><br>"; // Canvas pour signature
                        // Ajout d'un bouton strictement sous le canvas sans espacement inutile

                        echo "<button type='button' id='sig-clearBtn' class='resetButton' style='margin: 5px 0 0 0; padding: 5px 10px;'>Supprimer la signature</button>"; // Bouton pour supprimer la signature

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
                        // Canvas aligné à gauche
                        echo "<canvas id='sig-canvas' class='sig' width='320' height='80' style='border: 1px solid #ccc;'></canvas>";
                        // Ajout d'un bouton strictement sous le canvas sans espacement inutile
                        echo "<br>";
                        echo "<button type='button' id='sig-clearBtn' class='resetButton' style='margin: 5px 0 0 0; padding: 5px 10px;'>Supprimer la signature</button>";  // Bouton pour supprimer la signature
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
                                    src='document.send.php?docid=5#zoom=page-width' 
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
               window.requestAnimFrame = (function(callback) { // Fonction pour animer le canvas
                  return window.requestAnimationFrame ||    // Demande d'animation pour les navigateurs
                     window.webkitRequestAnimationFrame ||  // Demande d'animation pour les navigateurs WebKit
                     window.mozRequestAnimationFrame ||     // Demande d'animation pour les navigateurs Mozilla
                     window.oRequestAnimationFrame ||       // Demande d'animation pour les navigateurs Opera
                     window.msRequestAnimaitonFrame ||      // Demande d'animation pour les navigateurs Microsoft
                     function(callback) {
                     window.setTimeout(callback, 1000 / 60);   // Demande d'animation pour les navigateurs anciens
                     };
               })();

               var canvas = document.getElementById("sig-canvas");   // Récupère l'élément canvas
               var ctx = canvas.getContext("2d");                    // Récupère le contexte du canvas
               ctx.strokeStyle = "#222222";                          // Couleur du trait
               ctx.lineWidtd = 1;                                    // Largeur du trait

               var drawing = false;                               // Variable pour dessiner                                 
               var mousePos = {                                   // Position de la souris                       
                  x: 0,                                           // Position x
                  y: 0                                            // Position y                            
               };
               var lastPos = mousePos;                            // Dernière position de la souris                      

               canvas.addEventListener("mousedown", function(e) { // Ajoute un événement de clic de souris
                  drawing = true;                                 // Passe la variable drawing à true
                  lastPos = getMousePos(canvas, e);               // Récupère la position de la souris
               }, false);                                         // Fin de l'événement de clic de souris

               canvas.addEventListener("mouseup", function(e) {   // Ajoute un événement de relâchement de souris
                  drawing = false;
               }, false);

               canvas.addEventListener("mousemove", function(e) { // Ajoute un événement de déplacement de souris
                  mousePos = getMousePos(canvas, e);              // Récupère la position de la souris
               }, false);

               // Add touch event support for mobile
               canvas.addEventListener("touchmove", function(e) { // Ajoute un événement de déplacement tactile
                  var touch = e.touches[0];
                  e.preventDefault(); 
                  var me = new MouseEvent("mousemove", {          // Crée un nouvel événement de souris
                     clientX: touch.clientX,                         
                     clientY: touch.clientY
                  });
                  canvas.dispatchEvent(me);
               }, false);

               canvas.addEventListener("touchstart", function(e) {   // Ajoute un événement de toucher
                  mousePos = getTouchPos(canvas, e);                 // Récupère la position du toucher
                  e.preventDefault(); 
                  var touch = e.touches[0];
                  var me = new MouseEvent("mousedown", {             // Crée un nouvel événement de clic de souris
                     clientX: touch.clientX,
                     clientY: touch.clientY
                  });
                  canvas.dispatchEvent(me);                          // Envoie l'événement de clic de souris
               }, false);

               canvas.addEventListener("touchend", function(e) {   // Ajoute un événement de relâchement tactile
                  e.preventDefault(); 
                  var me = new MouseEvent("mouseup", {});            // Crée un nouvel événement de relâchement de souris
                  canvas.dispatchEvent(me);
               }, false);

               function getMousePos(canvasDom, mouseEvent) {         // Fonction pour récupérer la position de la souris
                  var rect = canvasDom.getBoundingClientRect();      // Récupère les dimensions du canvas
                  return {
                     x: mouseEvent.clientX - rect.left,
                     y: mouseEvent.clientY - rect.top
                  }
               }

               function getTouchPos(canvasDom, touchEvent) {         // Fonction pour récupérer la position du toucher
                  var rect = canvasDom.getBoundingClientRect();      // Récupère les dimensions du canvas
                  return {
                     x: touchEvent.touches[0].clientX - rect.left,
                     y: touchEvent.touches[0].clientY - rect.top
                  }
               }

               function renderCanvas() {        // Fonction pour dessiner sur le canvas
                  if (drawing) {
                     ctx.moveTo(lastPos.x, lastPos.y);
                     ctx.lineTo(mousePos.x, mousePos.y);
                     ctx.stroke();
                     lastPos = mousePos;
                  }
               }

               // Prevent scrolling when touching tde canvas
               document.body.addEventListener("touchstart", function(e) {     // Empêche le défilement lors du toucher du canvas
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);
               document.body.addEventListener("touchend", function(e) {       // Empêche le défilement lors du toucher du canvas
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);
               document.body.addEventListener("touchmove", function(e) {         // Empêche le défilement lors du toucher du canvas
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);

               (function drawLoop() {     // Fonction pour dessiner en boucle
                  requestAnimFrame(drawLoop);
                  renderCanvas();
               })();

               function clearCanvas() {      // Fonction pour effacer le canvas
                  canvas.widtd = canvas.widtd;
               }

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
