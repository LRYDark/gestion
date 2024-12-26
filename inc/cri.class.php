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
      
      $Doc_Name = $_POST["modal"];
      $config     = PluginGestionConfig::getInstance();
      $documents  = new Document();
      $job        = new Ticket();
      require_once 'SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();
      $job->getfromDB($ID);
      $email = '';
      $DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_tickets` WHERE bl = '$Doc_Name'")->fetch_object();
      $doc_id = $DOC->doc_id;

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
            return preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
        }
        
         echo "<form action=\"" . PLUGIN_GESTION_WEBDIR . "/front/traitement.php\" method=\"post\" name=\"formReport\">";
         echo Html::hidden('REPORT_ID', ['value' => $ID]);
         echo Html::hidden('DOC', ['value' => $Doc_Name]);

         if($DOC->signed == 0){
            // tableau bootstrap -> glpi | Mobile ou Ordinateur
            if (isMobile()) {
               echo '<div class="table-responsive" >';
               echo "<table class='table'>"; 

               echo 'Document : <strong>'.$Doc_Name.'</strong>';
               echo '<br><br>';
               echo "<strong><u>STATUS</u></strong>";
               echo '<br>';
               echo 'Non Signé';
               echo '<br><br>';
               
               if ($config->fields['ConfigModes'] == 0){
                  // Affichage du PDF en mode image
                  echo "<tr>";
                  // Affiche le PDF intégré avec une classe CSS pour le responsive
                  echo "<embed src='document.send.php?docid=$doc_id' type='application/pdf' class='responsive-pdf' />";
                  echo "</tr>";

                  // Bouton pour voir le PDF en plein écran
                  echo "<tr>";
                     echo '<a href="document.send.php?docid=' . $doc_id . '" target="_blank">Voir le PDF en plein écran</a>';
                  echo "</tr><br><br>";
               }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
                  $documents ->getFromDB($doc_id);
                  $DocUrlSharePoint = $documents->fields['link'];

                  // Utilisation
                  try {
                     // Étape 1 : Obtenez votre token d'accès
                     $accessToken = $sharepoint->getAccessToken($config->TenantID(), $config->ClientID(), $config->ClientSecret());
                     $siteId = '';
                     $siteId = $sharepoint->getSiteId($accessToken, $config->Hostname(), $config->SitePath());
                     $drives = $sharepoint->getDrives($accessToken, $siteId);
                     
                     // Trouver la bibliothèque "Documents partagés"
                     $globaldrive = strtolower(trim($config->Global()));
                     $driveId = null;
                     foreach ($drives as $drive) {
                           if (strtolower($drive['name']) === $globaldrive) {
                              $driveId = $drive['id'];
                              break;
                           }
                     }

                     if (!$driveId) {
                        Session::addMessageAfterRedirect(__("Bibliothèque '$globaldrive' introuvable.", 'gestion'), false, ERROR);
                     }

                     $folderPath = 'BL_NON_SIGNE';
                     $itemId = $Doc_Name.".pdf"; // Nom du fichier à rechercher

                     // Étape 3 : Récupérez l'ID du fichier
                     $fileId = $sharepoint->getFileIdByName($accessToken, $driveId, $folderPath, $itemId);

                     if ($fileId) {
                        $itemId = $fileId;
                     } else {
                        echo "Erreur : Fichier '$itemId' introuvable dans le dossier '$folderPath'.\n";
                     }

                     // Étape 3 : Obtenez le lien de partage
                     $shareLink = $sharepoint->createShareLink($accessToken, $driveId, $itemId);

                     // Étape 4 : Affichez le PDF via <embed>
                     echo "<tr>";
                     // Affiche le PDF intégré avec une classe CSS pour le responsive
                     echo "<embed src='$shareLink' type='application/pdf' class='responsive-pdf' />";
                     echo "</tr>";

                     // Bouton pour voir le PDF en plein écran
                     echo "<tr>";
                     echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                     echo "</tr><br><br>";

                  } catch (Exception $e) {
                     // Bouton pour voir le PDF en plein écran
                     echo "<tr>";
                        echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                     echo "</tr><br><br>";
                  }
               }

                  echo "<tr>";   
                     // Bouton de capture photo -->
                     echo '<label for="capture-photo">Prendre une photo</label><br>';
                     echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">';
                     echo '<br>';
                     echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>';
               
                     ?><script>
                        // Gestion de la capture de photo
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
                     </script><?php
                  echo "</tr><br>";
                  
                  echo "<tr>";

                        echo '<label for="name">Non / Prénom</label><br>';
                        echo "<input type='text' id='name' name='name' placeholder='Nom / Prenom du client' required=''>";

                  echo "</tr><br>";

                  // TABLEAU 3 : Canvas pour signature et bouton de suppression
                  echo "<tr><br>";
                        echo '<label for="name">Signature</label><br>';
                        // Canvas aligné à gauche
                        echo "<canvas id='sig-canvas' class='sig' width='320' height='80' style='border: 1px solid #ccc;'></canvas><br>";
                        // Ajout d'un bouton strictement sous le canvas sans espacement inutile

                        echo "<button type='button' id='sig-clearBtn' class='resetButton' style='margin: 5px 0 0 0; padding: 5px 10px;'>Supprimer la signature</button>";

                  echo "</tr><br>";
                  
                  /*// Mail
                  echo "<tr><br>";
                        echo 'Mail client';
                        //if ($config->fields['email'] == 1){
                           echo'<br><h5 style="font-weight: normal; margin-top: -0px;"> Cocher pour envoyer le PDF par email. </h5>';
                        //}

                        //if ($config->fields['email'] == 1){
                           echo '<input type="checkbox" name="mailtoclient" value="1">&emsp;';
                        //}
                        echo "<input type='mail' id='mail' name='email' value='".$email."' style='widtd: 250px;'>";
                  echo "</tr><br>";*/
            
               echo "</table>"; 
               echo "</div>";
            } else {
               echo '<div class="table-responsive">';
               echo "<table class='table'>"; 

                  if ($config->fields['ConfigModes'] == 0){
                     // Affichage du PDF en mode image
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
                           echo "<embed src='document.send.php?docid=$doc_id' type='application/pdf' class='responsive-pdf' />";
                        echo "</td>";
                     echo "</tr>";

                     // Voir PDF
                     echo "<tr>";
                        echo "<td class='table-secondary'>";
                        echo "</td>";
                        echo "<td>";
                           echo '<a href="document.send.php?docid=' . $doc_id . '" target="_blank">Voir le PDF en plein écran</a>';
                        echo "</td>";
                     echo "</tr>";
                  }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 

                     $documents ->getFromDB($doc_id);
                     $DocUrlSharePoint = $documents->fields['link'];

                     // Utilisation
                     try {
                        // Étape 1 : Obtenez votre token d'accès
                        $accessToken = $sharepoint->getAccessToken($config->TenantID(), $config->ClientID(), $config->ClientSecret());
                        $siteId = '';
                        $siteId = $sharepoint->getSiteId($accessToken, $config->Hostname(), $config->SitePath());
                        $drives = $sharepoint->getDrives($accessToken, $siteId);
                        
                        // Trouver la bibliothèque "Documents partagés"
                        $globaldrive = strtolower(trim($config->Global()));
                        $driveId = null;
                        foreach ($drives as $drive) {
                              if (strtolower($drive['name']) === $globaldrive) {
                                 $driveId = $drive['id'];
                                 break;
                              }
                        }

                        if (!$driveId) {
                           Session::addMessageAfterRedirect(__("Bibliothèque '$globaldrive' introuvable.", 'gestion'), false, ERROR);
                        }

                        $folderPath = 'BL_NON_SIGNE';
                        $itemId = $Doc_Name.".pdf"; // Nom du fichier à rechercher

                        // Étape 3 : Récupérez l'ID du fichier
                        $fileId = $sharepoint->getFileIdByName($accessToken, $driveId, $folderPath, $itemId);

                        if ($fileId) {
                           $itemId = $fileId;
                        } else {
                           echo "Erreur : Fichier '$itemId' introuvable dans le dossier '$folderPath'.\n";
                        }

                        // Étape 3 : Obtenez le lien de partage
                        $shareLink = $sharepoint->createShareLink($accessToken, $driveId, $itemId);

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
                              echo "<embed src='$shareLink' type='application/pdf' class='responsive-pdf' />";
                           echo "</td>";
                        echo "</tr>";

                        // Voir PDF
                        echo "<tr>";
                           echo "<td class='table-secondary'>";
                           echo "</td>";
                           echo "<td>";
                              echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                           echo "</td>";
                        echo "</tr>";
                     } catch (Exception $e) {
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
                              echo '<a href="' . $DocUrlSharePoint . '" target="_blank">Voir le PDF en plein écran</a>';
                           echo "</td>";
                        echo "</tr>";
                     }
                  }

                  // enregistrer un fichier 
                  echo "<tr>";
                     echo "<td class='table-secondary'>"; // Réduit la largeur de la colonne de gauche
                        echo 'Ajouter un fichier / image';
                     echo "</td>";
                     
                     echo "<td>"; // Augmente la largeur de la colonne droite pour le PDF
                        //echo '<label for="capture-photo">Prendre une photo</label><br>';
                        echo '<input type="file" id="capture-photo" accept="image/*" capture="environment">';
                        echo '<br>';
                        echo '<textarea name="photo_base64" id="photo-base64" style="display: none;"></textarea>';

                        ?><script>
                           // Gestion de la capture de photo
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
                        </script><?php
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

                     echo "<td>";
                        echo "<input type='text' id='name' name='name' placeholder='Nom / Prenom du client' required=''>";
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
                        echo "<button type='button' id='sig-clearBtn' class='resetButton' style='margin: 5px 0 0 0; padding: 5px 10px;'>Supprimer la signature</button>";
                     echo "</td>";
                  echo "</tr>";
                  
                  /*// Mail
                  echo "<tr>";
                     echo "<td class='table-secondary'>";
                        echo 'Mail client';
                        //if ($config->fields['email'] == 1){
                           echo'<br><h5 style="font-weight: normal; margin-top: -0px;"> Cocher pour envoyer le PDF par email. </h5>';
                        //}
                     echo "</td>";

                     echo "<td>";
                        //if ($config->fields['email'] == 1){
                           echo '<input type="checkbox" name="mailtoclient" value="1">&emsp;';
                        //}
                        echo "<input type='mail' id='mail' name='email' value='".$email."' style='widtd: 250px;'>";
                     echo "</td>";
                  echo "</tr>";*/
            
               echo "</table>"; 
               echo "</div>";
            }
            echo '<div class="button-container-right" style="text-align: right;">';
               echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signé" class="submit">';
            echo '</div>';

            echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';
         }else{
            echo '<div class="table-responsive">';
            echo "<table class='table'>"; 

            if (isMobile()) {
               echo 'Document : <strong>'.$Doc_Name.'</strong>';
               echo '<br><br>';
               echo "<strong><u>STATUS</u></strong>";
               echo '<br>';
               echo 'Signé le : '.$DOC->date_creation.' ';
               echo '<br>';
               echo 'Par : '.$DOC->users_ext.' ';
               echo '<br><br>';
               echo 'Livré par : '.getUserName($DOC->users_id).' ';

               // Affichage du PDF en mode image
               echo "<tr>";
                  // Affiche le PDF intégré avec une classe CSS pour le responsive
                  echo "<embed src='document.send.php?docid=$doc_id' type='application/pdf' class='responsive-pdf' />";
               echo "</tr>";

               // Bouton pour voir le PDF en plein écran
               echo "<tr>";
                  echo '<a href="document.send.php?docid=' . $doc_id . '" target="_blank">Voir le PDF en plein écran</a>';
               echo "</tr>";
            }else{
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
                  
                  echo "<td style='width: 70%;'>"; // Augmente la largeur de la colonne droite pour le PDF
                     // Affiche le PDF intégré avec une classe CSS pour le responsive
                     echo "<embed src='document.send.php?docid=$doc_id' type='application/pdf' class='responsive-pdf' />";
                  echo "</td>";
               echo "</tr>";

               // Bouton pour voir le PDF en plein écran
               echo "<tr>";
                  echo '<a href="document.send.php?docid=' . $doc_id . '" target="_blank">Voir le PDF en plein écran</a>';
               echo "</tr>";
         }
            echo "</table>"; 
            echo "</div>";
         }
      Html::closeForm();
         ?>
         <script>
            //--------------------------------------------------- signature
               window.requestAnimFrame = (function(callback) {
                  return window.requestAnimationFrame ||
                     window.webkitRequestAnimationFrame ||
                     window.mozRequestAnimationFrame ||
                     window.oRequestAnimationFrame ||
                     window.msRequestAnimaitonFrame ||
                     function(callback) {
                     window.setTimeout(callback, 1000 / 60);
                     };
               })();

               var canvas = document.getElementById("sig-canvas");
               var ctx = canvas.getContext("2d");
               ctx.strokeStyle = "#222222";
               ctx.lineWidtd = 1;

               var drawing = false;
               var mousePos = {
                  x: 0,
                  y: 0
               };
               var lastPos = mousePos;

               canvas.addEventListener("mousedown", function(e) {
                  drawing = true;
                  lastPos = getMousePos(canvas, e);
               }, false);

               canvas.addEventListener("mouseup", function(e) {
                  drawing = false;
               }, false);

               canvas.addEventListener("mousemove", function(e) {
                  mousePos = getMousePos(canvas, e);
               }, false);

               // Add touch event support for mobile
               canvas.addEventListener("touchmove", function(e) {
                  var touch = e.touches[0];
                  e.preventDefault(); 
                  var me = new MouseEvent("mousemove", {
                     clientX: touch.clientX,
                     clientY: touch.clientY
                  });
                  canvas.dispatchEvent(me);
               }, false);

               canvas.addEventListener("touchstart", function(e) {
                  mousePos = getTouchPos(canvas, e);
                  e.preventDefault(); 
                  var touch = e.touches[0];
                  var me = new MouseEvent("mousedown", {
                     clientX: touch.clientX,
                     clientY: touch.clientY
                  });
                  canvas.dispatchEvent(me);
               }, false);

               canvas.addEventListener("touchend", function(e) {
                  e.preventDefault(); 
                  var me = new MouseEvent("mouseup", {});
                  canvas.dispatchEvent(me);
               }, false);

               function getMousePos(canvasDom, mouseEvent) {
                  var rect = canvasDom.getBoundingClientRect();
                  return {
                     x: mouseEvent.clientX - rect.left,
                     y: mouseEvent.clientY - rect.top
                  }
               }

               function getTouchPos(canvasDom, touchEvent) {
                  var rect = canvasDom.getBoundingClientRect();
                  return {
                     x: touchEvent.touches[0].clientX - rect.left,
                     y: touchEvent.touches[0].clientY - rect.top
                  }
               }

               function renderCanvas() {
                  if (drawing) {
                     ctx.moveTo(lastPos.x, lastPos.y);
                     ctx.lineTo(mousePos.x, mousePos.y);
                     ctx.stroke();
                     lastPos = mousePos;
                  }
               }

               // Prevent scrolling when touching tde canvas
               document.body.addEventListener("touchstart", function(e) {
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);
               document.body.addEventListener("touchend", function(e) {
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);
               document.body.addEventListener("touchmove", function(e) {
                  if (e.target == canvas) {
                     e.preventDefault();
                  }
               }, false);

               (function drawLoop() {
                  requestAnimFrame(drawLoop);
                  renderCanvas();
               })();

               function clearCanvas() {
                  canvas.widtd = canvas.widtd;
               }

            // Set up tde UI
               var sigText = document.getElementById("sig-dataUrl");
               var submitBtn = document.getElementById("sig-submitBtn");

               submitBtn.addEventListener("click", function(e) {
                  var dataUrl = canvas.toDataURL();
                  sigText.innerHTML = dataUrl;
               }, false);
               
               //--------------------------------------------------- BTN SUPPRIMER
               var clearBtn = document.getElementById("sig-clearBtn");
               clearBtn.addEventListener("click", function(e) {
                  clearCanvas();
                  sigImage.setAttribute("src", "");
               }, false);
            
               // Fonction pour effacer le canvas
               function clearCanvas() {
                  ctx.clearRect(0, 0, canvas.width, canvas.height); // Efface le contenu du canvas
                  ctx.beginPath(); // Réinitialise le chemin de dessin pour éviter que l'ancienne signature ne réapparaisse
               }

               // Sélectionne le bouton de suppression et lui ajoute l'événement de clic
               var clearBtn = document.getElementById("sig-clearBtn");
               clearBtn.addEventListener("click", function(e) {
                  clearCanvas();
               }, false);
         </script>
      <?php    
   }
}
