<?php
include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer

require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

global $DB, $CFG_GLPI;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Smalot\PdfParser\Parser;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();
$doc = new Document();

///////////////// NEW TEST ////////////////////
    $query = "
        SELECT folder_name, params
        FROM glpi_plugin_gestion_configsfolder
        WHERE params IN (2, 3)
        ORDER BY 
            CASE params 
                WHEN 2 THEN 0 
                WHEN 3 THEN 1 
            END
        LIMIT 1
    ";

    $result = $DB->query($query);

    if ($result && $DB->numrows($result) > 0) {
        $data = $DB->fetchassoc($result);
        $folder_name = $data['folder_name'];
        $used_param = $data['params'];

        if ($used_param == 2) {
            $FolderDes = 'SharePoint';
        } 

        if ($used_param == 3) {
            $FolderDes = 'Local';
            $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folder_name;

            // Vérifie si le dossier existe, sinon le crée
            if (!is_dir($destinationPath)) {
                if (!mkdir($destinationPath, 0755, true)) {
                    // En cas d’échec de création
                    message("Erreur : impossible de créer le dossier $destinationPath", ERROR);
                    Html::back();
                    exit;
                }
            }
        }
    } else {
        $FolderDes = 'Local';
        $folder_name = 'DocumentsSigned';
        $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/DocumentsSigned";
    }
///////////////// NEW TEST ////////////////////

function message($msg, $msgtype){
    Session::addMessageAfterRedirect(
        __($msg, 'gestion'),
        true,
        $msgtype
    );
}

$signatureBase64 = $_POST['url'] ?? ''; // Assurez-vous que la variable est définie
$DOC_NAME = $_POST['DOC'];
$NAME = $_POST['name'];
$id_document = $_POST['id_document'];

if (empty($_POST['email'])) $_POST['email'] = " ";
$EMAIL = $_POST["email"];

if (empty($_POST['mailtoclient'])) $_POST['mailtoclient'] = 0;
$MAILTOCLIENT = $_POST["mailtoclient"];

// Générer un nombre entier aléatoire entre 1 et 100
$nombreAleatoire = rand(1, 100000);

$DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id_document'")->fetch_object();

ob_start(); // Démarre la mise en tampon de sortie

// Retirer le préfixe de type MIME, s’il est présent
if (strpos($signatureBase64, 'data:image/png;base64,') === 0) {
    $signatureBase64 = str_replace('data:image/png;base64,', '', $signatureBase64);
}

// Décoder l’image
$signatureData = base64_decode($signatureBase64);
if ($signatureData === false) {
    message("Erreur lors du décodage de l'image.", ERROR);
}

// Sauvegarder l'image décodée
$signaturePath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/signature'.$nombreAleatoire.'.png';
if (file_put_contents($signaturePath, $signatureData) === false) {
    message("Échec de la sauvegarde de l'image de signature.", ERROR);
}

if ($config->mode() == 0){ //Récup BL depuis sharepoint
    try {
        $folderPath = ""; // Par défaut, $folderPath est vide
        if (!empty($DOC->url_bl)){
            $folderPath = $DOC->url_bl . "/";
        }
        // Étape 3 : Définir le chemin relatif du fichier
        $filePath = $folderPath.$DOC_NAME;

        // Étape 4 : Obtenir l'URL de téléchargement
        $downloadUrl = $sharepoint->getDownloadUrl($filePath);
    } catch (Exception $e) {
        message("Erreur : " . $e->getMessage(), ERROR);
        Html::back();
        exit;
    }

    try {
        // Étape 5 : Télécharger le fichier depuis l'URL
        $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
        $sharepoint->downloadFileFromUrl($downloadUrl, $destinationPath);
    } catch (Exception $e) {
        message("Erreur : " . $e->getMessage(), ERROR);
        Html::back();
        exit;
    }

    // Vérifiez que le PDF source existe
    $existingPdfPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
}
if ($config->mode() == 2){ //Récup BL depuis local
    // Vérifiez que le PDF source existe
    $existingPdfPath = GLPI_PLUGIN_DOC_DIR . "/gestion/Documents/" . $DOC->bl;
}

    if (!file_exists($existingPdfPath)) {
        message("Le fichier PDF source n'existe pas.", ERROR);
        Html::back();
        exit;
    }

ob_end_clean(); // Vide le tampon de sortie

// Créer un nouvel objet FPDI / ajouter signature
$pdf = new FPDI();
try {
    $stream = StreamReader::createByFile($existingPdfPath);
    $pageCount = $pdf->setSourceFile($stream);
    $targetPage = $pageCount > 1 ? $pageCount : 1; // Utilisez la dernière page si plusieurs pages, sinon la première

    for ($i = 1; $i <= $pageCount; $i++) {
        $pdf->AddPage();
        $tplIdx = $pdf->importPage($i);
        $pdf->useTemplate($tplIdx, 0, 0);

        // Si c'est la page cible, ajoutez la signature
        if ($i === $targetPage) {
            // Ajouter la signature en bas à gauche
            $pdf->Image($signaturePath, $config->fields['SignatureX'], $pdf->GetPageHeight() - $config->fields['SignatureY'], $config->fields['SignatureSize']); // Ajustez la position et la taille

            // Ajouter le nom et la date et tech
            if(!empty($config->fields['SignataireX']) && !empty($config->fields['SignataireY'])){
                $pdf->SetFont('Arial', '', 10);
                $pdf->SetXY($config->fields['SignataireX'], $pdf->GetPageHeight() - $config->fields['SignataireY']); // Position pour "Nom"
                $pdf->Cell(40, 10, $NAME);
            }

            if(!empty($config->fields['DateX']) && !empty($config->fields['DateY'])){
                $pdf->SetXY($config->fields['DateX'], $pdf->GetPageHeight() - $config->fields['DateY']); // Position pour "Date"
                $pdf->Cell(40, 10, date('d/m/Y'));
            }

            if(!empty($config->fields['TechX']) && !empty($config->fields['TechX'])){
                $tech = getUserName(Session::getLoginUserID());
                $pdf->SetFont('Arial', '', 12);
                $pdf->SetXY($config->fields['TechX'], $pdf->GetPageHeight() - $config->fields['TechY']); // Position pour "Nom"
                $pdf->Cell(40, 10, $tech);
            }
        }
    }
} catch (Exception $e) {
    message("Erreur lors de l'importation du fichier PDF : " . $e->getMessage(), ERROR);
    Html::back();
    exit;
}

// Récupérer la photo encodée en base64
$photoBase64 = $_POST['photo_base64'] ?? '';

if (!empty($photoBase64) && strpos($photoBase64, 'data:image') === 0) {
    // Retirer le préfixe de type MIME
    $photoBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $photoBase64);
    $photoData = base64_decode($photoBase64);

    if ($photoData === false) {
        message("Erreur lors du décodage de l'image.", ERROR);
    }

    // Enregistrer temporairement l'image décodée sous forme brute
    $tempPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_photo'.$nombreAleatoire.'';
    if (file_put_contents($tempPath, $photoData) === false) {
        message("Erreur lors de la sauvegarde de l'image de la photo.", ERROR);
    }

    // Déterminer le type de l'image (PNG ou JPEG) et convertir si nécessaire
    $imageInfo = getimagesize($tempPath);
    if ($imageInfo === false) {
        unlink($tempPath); // Supprimer le fichier temporaire
        message("Le fichier image n'est pas valide.", ERROR);
    }

    $photoPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/photo_capture'.$nombreAleatoire.'.png'; // Le chemin final de l'image en PNG

    // Si l'image est au format JPEG, la convertir en PNG et corriger l'orientation
    if ($imageInfo['mime'] === 'image/jpeg') {
        $image = imagecreatefromjpeg($tempPath);
        if ($image === false) {
            unlink($tempPath);
            message("Erreur lors de la création de l'image JPEG.", ERROR);
        }

        // Corriger l'orientation de l'image à l'aide des métadonnées EXIF
        $exif = exif_read_data($tempPath);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $image = imagerotate($image, 180, 0);
                    break;
                case 6:
                    $image = imagerotate($image, -90, 0);
                    break;
                case 8:
                    $image = imagerotate($image, 90, 0);
                    break;
            }
        }

        if (!imagepng($image, $photoPath)) {
            imagedestroy($image);
            unlink($tempPath);
            message("Erreur lors de la conversion de l'image JPEG en PNG.", ERROR);
        }
        imagedestroy($image);
    } elseif ($imageInfo['mime'] === 'image/png') {
        // Si l'image est déjà un PNG, on la copie simplement
        if (!rename($tempPath, $photoPath)) {
            unlink($tempPath);
            message("Erreur lors de la sauvegarde de l'image PNG.", ERROR);
        }
    } else {
        unlink($tempPath);
        message("Type d'image non pris en charge.", ERROR);
    }

    // Ajouter une nouvelle page pour la photo dans le PDF
    $pdf->AddPage();
    $pdf->Image($photoPath, 10, 10, 180); // Positionner la photo pour remplir la majorité de la page
    unlink($photoPath); // Supprimer l'image temporaire
}

if($config->fields['DisplayPdfEnd'] == 1){
    $pdf->Output(); // affichage du PDF
}

// Sauvegarder Temporaire due PDF modifié avec la signature ajoutée
$outputPathTemp = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/".$DOC_NAME;

if ($pdf->Output('F', $outputPathTemp) === '') {
    $date = date('Y-m-d H:i:s'); // Format : 2024-11-02 14:30:45
    $tech_id = Session::getLoginUserID();
    $DB->query("UPDATE glpi_plugin_gestion_surveys SET signed = 1,date_creation = '$date', users_id = $tech_id, users_ext = '$NAME' WHERE BL = '$DOC_NAME'");

    if (!empty($config->fields['ZenDocMail'])){ 
        $sharepoint->MailSend($config->fields['ZenDocMail'], $config->fields['gabarit'], $outputPathTemp, "Envoyé vers ZenDoc", $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL);
    }  
    if ($MAILTOCLIENT == 1 && $config->fields['MailTo'] == 1){        
        $sharepoint->MailSend($EMAIL, $config->fields['gabarit'], $outputPathTemp, "Mail envoyé à ". $EMAIL , $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL);
    }
    
    if($config->ConfigModes() == 0){
        if($config->mode() == 0){
            try {
                $folderPathFile = ""; // Par défaut, $folderPath est vide
                if (!empty($DOC->url_bl)){
                    $folderPathFile = $DOC->url_bl .'/'. $DOC_NAME;
                }             
                $sharepoint->deleteFileByPath($folderPathFile);
            } catch (Exception $e) {
                message("Erreur : " . $e->getMessage(), ERROR);
            }
        }
        if($config->mode() == 2){
            try {
                $queryDelete = "DELETE FROM `glpi_documents` WHERE `id` = '$DOC->doc_id';";
                if ($DB->query($queryDelete)) {
                    unlink($existingPdfPath); // si fichier local alors delete le fichier non signer (configmode = 0 alors suppression du fichier)
                }
            } catch (Exception $e) {
                message("Erreur de suppression du document non signé : " . $e->getMessage(), ERROR);
            }
        }
    }
}

////////////////// Upload du fichier signé dans SharePoint ou localement //////////////////
    try {
        if ($DOC->entities_id == 0 || $DOC->entities_id == NULL){
            $EntitiesName = "AUTRES";
        }else{
            $entityResult = $DB->query("SELECT name FROM glpi_entities WHERE id = $DOC->entities_id")->fetch_object();
            $EntitiesName = $entityResult->name;
        }

        $folderPath = $folder_name. '/' .$EntitiesName; // Chemin du dossier
        $fileName = $DOC_NAME; // Nom du fichier après téléversement    

        // Étape 3 : Téléverser le fichier
        if ($FolderDes == 'SharePoint'){
            $sharepoint->uploadFileToFolder($folderPath, $fileName, $outputPathTemp);
        } 
        if ($FolderDes == 'Local'){
            $destDir = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folderPath;

            // Crée le dossier s’il n’existe pas
            if (!is_dir($destDir)) {
                mkdir($destDir);
            }
            // Construire le chemin complet de destination
            $destPath = $destDir . '/' . $fileName;
            // Copier le fichier
            copy($outputPathTemp, $destPath);
        }
    } catch (Exception $e) {
        message("Erreur : " . $e->getMessage(), ERROR);
    }
////////////////// Upload du fichier signé dans SharePoint ou localement //////////////////

    try {
        if (!empty($folderPath)){
            $folderPath = $folderPath . "/";
        }
    
        // Étape 4 : Récupérez l'URL du fichier
        if ($FolderDes == 'SharePoint'){
            //Spécifiez le chemin relatif du fichier dans SharePoint
            $file_path = $folderPath . $fileName; // Remplacez par le chemin exact de votre fichier
            $fileUrl = $sharepoint->getFileUrl($file_path);
            $NewDoc = 0;
        }
        if ($FolderDes == 'Local'){
            $folderPath = "_plugins/gestion/". $folderPath; // Chemin relatif pour le stockage local
            $input = ['name'        => addslashes(str_replace("?", "°", $DOC_NAME)),
                    'filename'    => addslashes($DOC_NAME),
                    'filepath'    => addslashes($folderPath . $DOC_NAME),
                    'mime'        => 'application/pdf',
                    'users_id'    => Session::getLoginUserID(),
                    'entities_id' => 0,
                    'tickets_id'  => 0,
                    'is_recursive'=> 1];

            if($NewDoc = $doc->add($input)){
                $fileUrl = "document.send.php?docid=".$NewDoc;
            }else{
                $fileUrl = null;
            }
        }

        if ($DB->query("UPDATE glpi_plugin_gestion_surveys SET doc_url = '$fileUrl', url_bl = '$folderPath', doc_id = $NewDoc, save = '$FolderDes' WHERE id = $id_document")){
            //unlink($existingPdfPath);
            unlink($signaturePath);
            unlink($outputPathTemp);
        }

        message('Documents : '. $DOC_NAME.' signé', INFO);
    } catch (Exception $e) {
        message("Signé avec erreur, voir votre administrateur informatique : " . $e->getMessage(), ERROR);
    }
                

/*}else{
    message("Erreur lors de la signature et/ou de l'enregistrement du documents : ". $DOC_NAME, ERROR);
}*/

Html::back();
?>
