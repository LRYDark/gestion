<?php
include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer

require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

global $DB, $CFG_GLPI;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();

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

// Générer un nombre entier aléatoire entre 1 et 100
$nombreAleatoire = rand(1, 100000);

$DOC = $DB->query("SELECT * FROM `glpi_plugin_gestion_tickets` WHERE bl = '$DOC_NAME'")->fetch_object();
$DOC_FILES = $DB->query("SELECT * FROM `glpi_documents` WHERE id = $DOC->doc_id")->fetch_object();

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
$signaturePath = PLUGIN_GESTION_DIR.'/FilesTempSharePoint/signature'.$nombreAleatoire.'.png';
if (file_put_contents($signaturePath, $signatureData) === false) {
    message("Échec de la sauvegarde de l'image de signature.", ERROR);
}

if ($config->fields['ConfigModes'] == 0){
    $originalPath = $DOC_FILES->filepath;
    $modifiedPath = str_replace('_plugins', '', $originalPath);

    // Vérifiez que le PDF source existe
    $existingPdfPath = GLPI_PLUGIN_DOC_DIR . $modifiedPath;
    if (!file_exists($existingPdfPath)) {
        message("Le fichier PDF source n'existe pas.", ERROR);
        Html::back();
        exit;
    }
}elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 

    try {
        $folderPath = ""; // Par défaut, $folderPath est vide
        if (!empty($DOC->url_bl)){
           $folderPath = $DOC->url_bl . "/";
        }
        // Étape 3 : Définir le chemin relatif du fichier
        $filePath = $folderPath.$DOC_NAME.".pdf";

        // Étape 4 : Obtenir l'URL de téléchargement
        $downloadUrl = $sharepoint->getDownloadUrl($filePath);

        // Étape 5 : Télécharger le fichier depuis l'URL
        $destinationPath = PLUGIN_GESTION_DIR."/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
        $sharepoint->downloadFileFromUrl($downloadUrl, $destinationPath);
    } catch (Exception $e) {
        message("Erreur : " . $e->getMessage(), ERROR);
    }

    // Vérifiez que le PDF source existe
    $existingPdfPath = PLUGIN_GESTION_DIR."/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";;
    if (!file_exists($existingPdfPath)) {
        message("Le fichier PDF source n'existe pas.", ERROR);
        Html::back();
        exit;
    }
}

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
    $tempPath = PLUGIN_GESTION_DIR.'/FilesTempSharePoint/temp_photo'.$nombreAleatoire.'';
    if (file_put_contents($tempPath, $photoData) === false) {
        message("Erreur lors de la sauvegarde de l'image de la photo.", ERROR);
    }

    // Déterminer le type de l'image (PNG ou JPEG) et convertir si nécessaire
    $imageInfo = getimagesize($tempPath);
    if ($imageInfo === false) {
        unlink($tempPath); // Supprimer le fichier temporaire
        message("Le fichier image n'est pas valide.", ERROR);
    }

    $photoPath = PLUGIN_GESTION_DIR.'/FilesTempSharePoint/photo_capture'.$nombreAleatoire.'.png'; // Le chemin final de l'image en PNG

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


if ($config->fields['ConfigModes'] == 0){
    // Sauvegarder le PDF modifié avec la signature ajoutée
    $outputPath = GLPI_PLUGIN_DOC_DIR . "/gestion/signed/" . $DOC_NAME . ".pdf";

    if ($pdf->Output('F', $outputPath) === '') {
        $date = date('Y-m-d H:i:s'); // Format : 2024-11-02 14:30:45
        $tech_id = Session::getLoginUserID();
        $DB->query("UPDATE glpi_plugin_gestion_tickets SET signed = 1,date_creation = '$date', users_id = $tech_id, users_ext = '$NAME' WHERE BL = '$DOC_NAME'");
        $savepath = "_plugins/gestion/signed/" . $DOC_NAME . ".pdf";
        if ($DB->query("UPDATE glpi_documents SET filepath = '$savepath' WHERE id = $DOC_FILES->id")){
            unlink($existingPdfPath);
            unlink($signaturePath);
        }
        message('Documents : '. $DOC_NAME.' signé', INFO);
    }else{
        message("Erreur lors de la signature et/ou de l'enregistrement du documents : ". $DOC_NAME, ERROR);
    }
}elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
    
    $outputPath = PLUGIN_GESTION_DIR . "/FilesTempSharePoint/".$DOC_NAME.".pdf";

    if ($pdf->Output('F', $outputPath) === '') {
        $date = date('Y-m-d H:i:s'); // Format : 2024-11-02 14:30:45
        $tech_id = Session::getLoginUserID();
        $DB->query("UPDATE glpi_plugin_gestion_tickets SET signed = 1,date_creation = '$date', users_id = $tech_id, users_ext = '$NAME' WHERE BL = '$DOC_NAME'");
        echo 'point de dep<br>';
        // Utilisation
        try {
            $folderPathFile = ""; // Par défaut, $folderPath est vide
            if (!empty($DOC->url_bl)){
               $folderPathFile = $DOC->url_bl;
            }
            $folderPath = $folderPathFile;
            $itemId = $DOC_NAME.".pdf"; // Nom du fichier à rechercher
            
            // Étape 3 : Récupérez l'ID du fichier
            $fileId = $sharepoint->getFileIdByName($folderPath, $itemId);

            // Étape 3 : Supprimez le fichier
            $sharepoint->deleteFile($fileId);

            // Sauvegarder le PDF modifié avec la signature ajoutée
            try {
                // Requête SQL pour récupérer le folder_name où params = 3
                $query = "SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 2 LIMIT 1";
                $result = $DB->query($query); // Exécuter la requête avec le gestionnaire de base de données GLPI

                if (!$result) {
                    throw new Exception("Erreur lors de l'exécution de la requête SQL.");
                }

                // Vérifier si une ligne correspondante existe
                $folderPath = ""; // Par défaut, $folderPath est vide
                if ($row = $DB->fetchAssoc($result)) {
                    $folderPath = $row['folder_name']; // Récupérer le folder_name si params = 3
                }

                $fileName = $DOC_NAME.".pdf"; // Nom du fichier après téléversement

                // Étape 3 : Téléverser le fichier
                $sharepoint->uploadFileToFolder($folderPath, $fileName, $outputPath);

                if (!empty($folderPath)){
                    $folderPath = $folderPath . "/";
                }
                // Étape 3 : Spécifiez le chemin relatif du fichier dans SharePoint
                $file_path = $folderPath . $DOC_NAME . ".pdf"; // Remplacez par le chemin exact de votre fichier

                // Étape 4 : Récupérez l'URL du fichier
                $fileUrl = $sharepoint->getFileUrl($file_path);

            } catch (Exception $e) {
                message("Erreur : " . $e->getMessage(), ERROR);
            }
            
        } catch (Exception $e) {
            message("Erreur : " . $e->getMessage(), ERROR);
        }
       
        if ($DB->query("UPDATE glpi_documents SET link = '$fileUrl' WHERE id = $DOC_FILES->id")){
            unlink($existingPdfPath);
            unlink($signaturePath);
            unlink($outputPath);
        }
        message('Documents : '. $DOC_NAME.' signé', INFO);
    }else{
        message("Erreur lors de la signature et/ou de l'enregistrement du documents : ". $DOC_NAME, ERROR);
    }
}
Html::back();
?>
