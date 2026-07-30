<?php
include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer

require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';

global $DB, $CFG_GLPI;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Smalot\PdfParser\Parser;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();
$doc = new Document();
$combined_mode = !empty($_POST['combined_mode']);

// Ensure optional columns exist in surveys table
try {
    $table = 'glpi_plugin_gestion_surveys';
    $chk = $DB->doQuery("SHOW COLUMNS FROM `$table` LIKE 'tech_ext'");
    if ($chk && $DB->numrows($chk) === 0) {
        @$DB->doQuery("ALTER TABLE `$table` ADD COLUMN `tech_ext` VARCHAR(255) NULL AFTER `users_ext`");
    }
    $chk = $DB->doQuery("SHOW COLUMNS FROM `$table` LIKE 'comment'");
    if ($chk && $DB->numrows($chk) === 0) {
        @$DB->doQuery("ALTER TABLE `$table` ADD COLUMN `comment` TEXT NULL");
    }
    $chk = $DB->doQuery("SHOW COLUMNS FROM `$table` LIKE 'paid'");
    if ($chk && $DB->numrows($chk) === 0) {
        @$DB->doQuery("ALTER TABLE `$table` ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT '0'");
    }
} catch (Throwable $e) {
    // ignore
}

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

    $result = $DB->doQuery($query);

    if ($result && $DB->numrows($result) > 0) {
        $data = $DB->fetchassoc($result);
        $folder_name = $data['folder_name'];
        $used_param = $data['params'];

        if ($used_param == 2) {
            if ($config->SharePointOn() == 1) {
                $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
                if(isset($result['status']) && $result['status'] === true){
                    $FolderDes = 'SharePoint';
                }else{
                    $used_param = 3;
                    gestion_message("Erreur d'enregistrement du PDF dans SharePoint, Enregistrement dans le dossier Local", WARNING);
                }
            } else {
                // SharePoint desactive : ici params=2 = depot LOCAL (cf. libelle config) => pas de tentative SharePoint, pas d'alerte.
                $used_param = 3;
            }
        }

        if ($used_param == 3) {
            $FolderDes = 'Local';
            if (is_dir($folder_name)) {
                $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folder_name;
            } else {
                $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/DocumentsSigned";
            }

            $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folder_name;

            // Vérifie si le dossier existe, sinon le crée
            if (!is_dir($destinationPath)) {
                if (!mkdir($destinationPath, 0755, true)) {
                    // En cas d’échec de création
                    gestion_message("Erreur : impossible de créer le dossier $destinationPath", ERROR);
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

function gestion_message($msg, $msgtype){
    if (class_exists('PluginGestionLogger')) {
        $detail = $msg . (!empty($_POST['DOC']) ? ' (BL: ' . $_POST['DOC'] . ')' : '');
        if ($msgtype === ERROR) {
            PluginGestionLogger::error('signature', $detail);
        } elseif ($msgtype === WARNING) {
            PluginGestionLogger::warning('signature', $detail);
        } else {
            PluginGestionLogger::info('signature', $detail);
        }
    }
    Session::addMessageAfterRedirect(
        __($msg, 'gestion'),
        true,
        $msgtype
    );
}

$signatureBase64 = $_POST['url'] ?? ''; // Assurez-vous que la variable est définie
$DOC_NAME = $_POST['DOC'];
$NAME = $_POST['name'];
$TECHNICIAN_INPUT = isset($_POST['technician']) ? trim($_POST['technician']) : '';
$REPORT_ID = isset($_POST['REPORT_ID']) ? (int)$_POST['REPORT_ID'] : 0; // 0 = quick-sign depuis tablette
$is_quick = ($REPORT_ID === 0);

$tech_id = 0;
if ($TECHNICIAN_INPUT !== '') {
    $esc = $DB->escape($TECHNICIAN_INPUT);
    // 1) correspondances exactes: login, nom, "nom prénom", email
    $sqlTech = "SELECT u.id
                FROM glpi_users u
                LEFT JOIN glpi_useremails ue ON ue.users_id = u.id
                WHERE u.name = '$esc'
                   OR u.realname = '$esc'
                   OR TRIM(CONCAT(u.realname,' ', u.firstname)) = '$esc'
                   OR TRIM(CONCAT(u.firstname,' ', u.realname)) = '$esc'
                   OR ue.email = '$esc'
                LIMIT 1";
    $resTech = $DB->doQuery($sqlTech);
    if ($resTech && $DB->numrows($resTech) > 0) {
        $techrow = $DB->fetchassoc($resTech);
        $tech_id = (int)$techrow['id'];
    } else {
        // 2) fallback: correspondances partielles (LIKE)
        $like = '%' . $DB->escape($TECHNICIAN_INPUT) . '%';
        $sqlTech2 = "SELECT u.id
                     FROM glpi_users u
                     LEFT JOIN glpi_useremails ue ON ue.users_id = u.id
                     WHERE u.name LIKE '$like'
                        OR u.realname LIKE '$like'
                        OR u.firstname LIKE '$like'
                        OR CONCAT(u.realname,' ',u.firstname) LIKE '$like'
                        OR CONCAT(u.firstname,' ',u.realname) LIKE '$like'
                        OR ue.email LIKE '$like'
                     LIMIT 1";
        $resTech2 = $DB->doQuery($sqlTech2);
        if ($resTech2 && $DB->numrows($resTech2) > 0) {
            $techrow = $DB->fetchassoc($resTech2);
            $tech_id = (int)$techrow['id'];
        }
    }
}
// 3) Si pas de saisie (flux normal), utiliser l'utilisateur de session
if ($TECHNICIAN_INPUT === '' && $tech_id <= 0) {
    // Flux normal (pas de saisie): utiliser la session GLPI
    $tech_id = (int)Session::getLoginUserID();
}
// Ne PAS forcer la session en mode signature rapide avec saisie libre
// Ainsi, si aucun utilisateur ne correspond et que c'est du quick-sign,
// $tech_id peut rester 0 pour que le PDF affiche la saisie libre.
$id_document = (int)($_POST['id_document'] ?? 0);

if (empty($_POST['email'])) $_POST['email'] = "vide"; // #GLPI11#
$EMAIL = $_POST["email"];

if (empty($_POST['mailtoclient'])) $_POST['mailtoclient'] = 0;
$MAILTOCLIENT = $_POST["mailtoclient"];

// Générer un nombre entier aléatoire entre 1 et 100
$nombreAleatoire = rand(1, 100000);

$DOC = $DB->doQuery("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = $id_document")->fetch_object();

if (class_exists('PluginGestionLogger')) {
    PluginGestionLogger::info('signature', sprintf(
        'Debut signature BL "%s" (survey #%d, tech #%d%s%s)',
        $DOC_NAME,
        $id_document,
        $tech_id,
        $is_quick ? ', quick-sign' : '',
        $combined_mode ? ', mode combine' : ''
    ));
}

ob_start(); // Démarre la mise en tampon de sortie

// Retirer le préfixe de type MIME, s’il est présent
if (strpos($signatureBase64, 'data:image/png;base64,') === 0) {
    $signatureBase64 = str_replace('data:image/png;base64,', '', $signatureBase64);
}

// Décoder l’image
$signatureData = base64_decode($signatureBase64);
if ($signatureData === false) {
    gestion_message("Erreur lors du décodage de l'image.", ERROR);
}

// Sauvegarder l'image décodée
$signaturePath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/signature'.$nombreAleatoire.'.png';
if (file_put_contents($signaturePath, $signatureData) === false) {
    gestion_message("Échec de la sauvegarde de l'image de signature.", ERROR);
}

if ($DOC->save == "SharePoint"){ //Récup BL depuis sharepoint
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
        gestion_message("Erreur : " . $e->getMessage(), ERROR);
        Html::back();
        exit;
    }

    try {
        // Étape 5 : Télécharger le fichier depuis l'URL
        $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
        $sharepoint->downloadFileFromUrl($downloadUrl, $destinationPath);
    } catch (Exception $e) {
        gestion_message("Erreur : " . $e->getMessage(), ERROR);
        Html::back();
        exit;
    }

    // Vérifiez que le PDF source existe
    $existingPdfPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
}
if ($DOC->save == "Local"){ //Récup BL depuis local
    // Vérifiez que le PDF source existe
    $url = str_replace("_plugins", "", $DOC->url_bl.$DOC->bl);
    $existingPdfPath = GLPI_PLUGIN_DOC_DIR . $url;
}
if ($DOC->save == "Sage"){ //Récup BL depuis local
    // Vérifiez que le PDF source existe
    $existingPdfPath = downloadDocument($DOC->url_bl, GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/Sage_Temp_".$nombreAleatoire.".pdf");
}

if (!file_exists($existingPdfPath)) {
    gestion_message("Le fichier PDF source n'existe pas.", ERROR);
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

        // --- Tampon "Facture payée en magasin" sur chaque page si réglée en comptoir ---
        if (!empty($config->fields['CounterInvoice']) && (int)$config->fields['CounterInvoice'] === 1) { // NEW
            if (!empty($config->fields['CounterInvoicePdf']) && (int)$config->fields['CounterInvoicePdf'] === 1 && !empty($_POST['CounterInvoiceClient']) && (int)$_POST['CounterInvoiceClient'] === 1) {
                // Date/heure du règlement : utilise ta valeur si dispo, sinon l'instant
            $paymentDateTime = isset($paymentDateTime) && $paymentDateTime ? $paymentDateTime : date('d/m/Y H:i');

                // Libellé en UTF-8 → converti pour FPDF
                $label_utf8 = $config->fields['CounterInvoiceText'] . ' — ' . $paymentDateTime;
                if (function_exists('iconv')) {
                    // Windows-1252 garde « — » et les accents
                    $label = iconv('UTF-8', 'windows-1252//TRANSLIT', $label_utf8);
                } else {
                    // Fallback ISO-8859-1 : remplace l’em-dash
                    //$label = utf8_decode(str_replace(['–','—'], '-', $label_utf8));
                    $label = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', str_replace(['–','—'], '-', $label_utf8));
                }

                // Style vert
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor(46, 204, 113);
                $pdf->SetDrawColor(46, 204, 113);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetLineWidth(0.6);

                // Dimensions/position
                $w = $pdf->GetStringWidth($label) + 10;
                $h = 8;
                $x = $pdf->GetPageWidth() - $w - 12;  // coin haut droit
                $y = 14;

                // Tampon
                $pdf->SetXY($x, $y);
                $pdf->Cell($w, $h, $label, 1, 1, 'C', true);

                // ➜ Repasser les couleurs à noir (et traits par défaut)
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetDrawColor(0, 0, 0);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetLineWidth(0.2);
            }
        }
        // --- fin tampon ---

        // Si c'est la page cible, ajoutez la signature
        if ($i === $targetPage) {
            // Ajouter la signature en bas à gauche
            $pdf->Image($signaturePath, $config->fields['SignatureX'], $pdf->GetPageHeight() - $config->fields['SignatureY'], $config->fields['SignatureSize']); // Ajustez la position et la taille

            // Ajouter le nom et la date et tech
            if(!empty($config->fields['SignataireX']) && !empty($config->fields['SignataireY'])){
                $pdf->SetFont('Arial', '', 10);
                $pdf->SetXY($config->fields['SignataireX'], $pdf->GetPageHeight() - $config->fields['SignataireY']); // Position pour "Nom"
                $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $NAME));
            }

            if(!empty($config->fields['DateX']) && !empty($config->fields['DateY'])){
                $pdf->SetXY($config->fields['DateX'], $pdf->GetPageHeight() - $config->fields['DateY']); // Position pour "Date"
                $pdf->Cell(40, 10, date('d/m/Y'));
            }

            if(!empty($config->fields['TechX']) && !empty($config->fields['TechY'])){
                // Afficher le nom du technicien
                // - En signature rapide: si saisie libre et aucun utilisateur trouvé, afficher la saisie
                // - Sinon: afficher l'utilisateur (trouvé ou session)
                $tech_name = '';
                if ($is_quick && $TECHNICIAN_INPUT !== '') {
                    $tech_name = $TECHNICIAN_INPUT;
                } else if ((int)$tech_id > 0) {
                    $tech_name = getUserName($tech_id);
                } else {
                    $tech_name = getUserName(Session::getLoginUserID());
                }
                $pdf->SetFont('Arial', '', 9);
                $pdf->SetXY($config->fields['TechX'], $pdf->GetPageHeight() - $config->fields['TechY']); // Position pour "Nom"
                $pdf->Cell(40, 10, $tech_name);
            }
        }
    }
} catch (Exception $e) {
    gestion_message("Erreur lors de l'importation du fichier PDF : " . $e->getMessage(), ERROR);
    Html::back();
    exit;
}

// Récupérer les photos encodées en base64 (6 max, 2 par page)
$photoPaths = [];
for ($pi = 1; $pi <= 6; $pi++) {
    $photoBase64 = $_POST['photo_base64_' . $pi] ?? '';
    // Rétrocompatibilité : ancien champ unique photo_base64
    if ($pi === 1 && empty($photoBase64)) {
        $photoBase64 = $_POST['photo_base64'] ?? '';
    }

    if (empty($photoBase64) || strpos($photoBase64, 'data:image') !== 0) {
        continue;
    }

    // Retirer le préfixe de type MIME
    $photoBase64Clean = preg_replace('#^data:image/\w+;base64,#i', '', $photoBase64);
    $photoData = base64_decode($photoBase64Clean);

    if ($photoData === false) {
        gestion_message("Erreur lors du décodage de l'image $pi.", ERROR);
        continue;
    }

    // Enregistrer temporairement l'image décodée sous forme brute
    $tempPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_photo' . $nombreAleatoire . '_' . $pi;
    if (file_put_contents($tempPath, $photoData) === false) {
        gestion_message("Erreur lors de la sauvegarde de l'image $pi.", ERROR);
        continue;
    }

    // Déterminer le type de l'image (PNG ou JPEG) et convertir si nécessaire
    $imageInfo = getimagesize($tempPath);
    if ($imageInfo === false) {
        unlink($tempPath);
        gestion_message("Le fichier image $pi n'est pas valide.", ERROR);
        continue;
    }

    // Sauvegarder en JPEG compressé (beaucoup plus léger que PNG)
    $photoPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/photo_capture' . $nombreAleatoire . '_' . $pi . '.jpg';
    $maxDimension = 1600; // Résolution max en pixels (largeur ou hauteur)
    $jpegQuality = 75;    // Qualité JPEG (0-100)

    $image = null;
    if ($imageInfo['mime'] === 'image/jpeg') {
        $image = imagecreatefromjpeg($tempPath);
        // Corriger l'orientation EXIF
        $exif = @exif_read_data($tempPath);
        if ($image && !empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0); break;
            }
        }
    } elseif ($imageInfo['mime'] === 'image/png') {
        $image = imagecreatefrompng($tempPath);
    } else {
        unlink($tempPath);
        gestion_message("Type d'image $pi non pris en charge.", ERROR);
        continue;
    }

    if ($image === false || $image === null) {
        unlink($tempPath);
        gestion_message("Erreur lors de la lecture de l'image $pi.", ERROR);
        continue;
    }

    // Redimensionner si trop grand
    $origW = imagesx($image);
    $origH = imagesy($image);
    if ($origW > $maxDimension || $origH > $maxDimension) {
        $ratio = min($maxDimension / $origW, $maxDimension / $origH);
        $newW = (int)round($origW * $ratio);
        $newH = (int)round($origH * $ratio);
        $resized = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($image);
        $image = $resized;
    }

    if (!imagejpeg($image, $photoPath, $jpegQuality)) {
        imagedestroy($image);
        unlink($tempPath);
        gestion_message("Erreur lors de la compression de l'image $pi.", ERROR);
        continue;
    }
    imagedestroy($image);
    if (file_exists($tempPath)) {
        unlink($tempPath);
    }

    $photoPaths[] = $photoPath;
}

// Ajouter les photos au PDF : 2 images par page
// Page A4 = 210 x 297mm. Marge 10mm. Zone utile par image : 190 x 130mm max.
if (!empty($photoPaths)) {
    $photoChunks = array_chunk($photoPaths, 2);
    foreach ($photoChunks as $chunk) {
        $pdf->AddPage();

        // Calcule les dimensions pour qu'une image tienne dans 190 x 130mm max
        // tout en gardant les proportions
        $maxW = 190;
        $maxH = 130;

        // Image 1 : en haut de page (Y = 10)
        $imgSize1 = getimagesize($chunk[0]);
        if ($imgSize1) {
            $ratio1 = min($maxW / $imgSize1[0], $maxH / $imgSize1[1]);
            $w1 = $imgSize1[0] * $ratio1;
            $h1 = $imgSize1[1] * $ratio1;
            $x1 = 10 + ($maxW - $w1) / 2; // centré horizontalement
            $pdf->Image($chunk[0], $x1, 10, $w1, $h1);
        } else {
            $h1 = $maxH;
            $pdf->Image($chunk[0], 10, 10, $maxW);
        }
        unlink($chunk[0]);

        // Image 2 : en bas de page (si présente)
        if (isset($chunk[1])) {
            // Trait séparateur entre les 2 images
            $separatorY = 10 + $h1 + 5;
            $pdf->Line(20, $separatorY, 190, $separatorY);

            $startY2 = $separatorY + 5;
            $imgSize2 = getimagesize($chunk[1]);
            if ($imgSize2) {
                $ratio2 = min($maxW / $imgSize2[0], $maxH / $imgSize2[1]);
                $w2 = $imgSize2[0] * $ratio2;
                $h2 = $imgSize2[1] * $ratio2;
                $x2 = 10 + ($maxW - $w2) / 2;
                $pdf->Image($chunk[1], $x2, $startY2, $w2, $h2);
            } else {
                $pdf->Image($chunk[1], 10, $startY2, $maxW);
            }
            unlink($chunk[1]);
        }
    }
}

// Récupérer et fusionner le PDF joint (après les images)
// FPDI ne supporte qu'un seul setSourceFile à la fois, donc on sauvegarde
// le PDF intermédiaire puis on crée un nouveau FPDI pour fusionner les 2.
$pdfBase64 = $_POST['pdf_base64'] ?? '';
if (!empty($pdfBase64) && strpos($pdfBase64, 'data:application/pdf;base64,') === 0) {
    $pdfBase64Clean = preg_replace('#^data:application/pdf;base64,#i', '', $pdfBase64);
    $pdfData = base64_decode($pdfBase64Clean);

    if ($pdfData !== false) {
        $tempAttachedPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_attached_pdf_' . $nombreAleatoire . '.pdf';
        $tempIntermediatePath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_intermediate_' . $nombreAleatoire . '.pdf';

        if (file_put_contents($tempAttachedPath, $pdfData) !== false) {
            try {
                // Sauvegarder le PDF en cours (document signé + images)
                $pdf->Output('F', $tempIntermediatePath);

                // Créer un nouveau FPDI pour fusionner les 2 PDF
                $pdfMerged = new FPDI();

                // 1) Importer le PDF intermédiaire (signé + images)
                $intermediateStream = StreamReader::createByFile($tempIntermediatePath);
                $intermediatePageCount = $pdfMerged->setSourceFile($intermediateStream);
                for ($ip = 1; $ip <= $intermediatePageCount; $ip++) {
                    $pdfMerged->AddPage();
                    $tplInt = $pdfMerged->importPage($ip);
                    $pdfMerged->useTemplate($tplInt, 0, 0);
                }

                // 2) Importer le PDF joint
                $attachedStream = StreamReader::createByFile($tempAttachedPath);
                $attachedPageCount = $pdfMerged->setSourceFile($attachedStream);
                for ($ap = 1; $ap <= $attachedPageCount; $ap++) {
                    $pdfMerged->AddPage();
                    $tplAttached = $pdfMerged->importPage($ap);
                    $pdfMerged->useTemplate($tplAttached, 0, 0);
                }

                // Remplacer $pdf par le PDF fusionné
                $pdf = $pdfMerged;
            } catch (Exception $e) {
                gestion_message("Erreur lors de la fusion du PDF joint : " . $e->getMessage(), ERROR);
            }
            if (file_exists($tempAttachedPath)) {
                unlink($tempAttachedPath);
            }
            if (file_exists($tempIntermediatePath)) {
                unlink($tempIntermediatePath);
            }
        }
    }
}

if($config->fields['DisplayPdfEnd'] == 1){
    $pdf->Output(); // affichage du PDF
}

// Sauvegarder Temporaire due PDF modifié avec la signature ajoutée
$outputPathTemp = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/".$DOC_NAME;
if (!str_ends_with($outputPathTemp, '.pdf')) {
    $outputPathTemp .= '.pdf';
}

if ($pdf->Output('F', $outputPathTemp) === '') {
    $date = date('Y-m-d H:i:s'); // Format : 2024-11-02 14:30:45
    // Ne pas écraser $tech_id s'il provient de la signature rapide
    //$DB->doQuery("UPDATE glpi_plugin_gestion_surveys SET signed = 1,date_creation = '$date', users_id = $tech_id, users_ext = '$NAME' WHERE BL = '$DOC_NAME'");
    $docLinked = isset($DOC->relatedInvoiceToBL) ? $DOC->relatedInvoiceToBL : null;
    $rawLinkedFromPost = isset($_POST['relatedInvoiceToBL']) ? $_POST['relatedInvoiceToBL'] : null;
    $relatedInvoiceToBL = ($rawLinkedFromPost !== null && $rawLinkedFromPost !== '')
                        ? strtoupper($rawLinkedFromPost)
                        : $docLinked;

    $rawComment = trim($_POST['comment'] ?? '');
    $hasComment = !empty($rawComment);
    $isCounterInvoicePaid = (
        !empty($config->fields['CounterInvoice'])
        && (int)$config->fields['CounterInvoice'] === 1
        && !empty($_POST['CounterInvoiceClient'])
        && (int)$_POST['CounterInvoiceClient'] === 1
    );         
    $updateData = [
        'signed'             => 1,
        'doc_date'      => $date,
        'users_id'           => $tech_id,
        'users_ext'          => $NAME,
        'relatedInvoiceToBL' => $relatedInvoiceToBL,
        'comment'            => $hasComment ? $rawComment : null,
    ];
    if ($isCounterInvoicePaid) {
        $updateData['paid'] = 1;
    }
    if ($is_quick && $TECHNICIAN_INPUT !== '') {
        // store free-text technician if provided (quick-sign only)
        $updateData['tech_ext'] = $TECHNICIAN_INPUT;
    }
    $ok = $DB->update(
        'glpi_plugin_gestion_surveys',
        $updateData,
        [ 'BL' => $DOC_NAME ]
    );
    if ($ok === false) {
        gestion_message("Erreur lors de la mise a jours en Base de donnée.", ERROR);
    }

    // ENVOIE DES MAILS #GLPI11#
    $allow_client_mail = (!$combined_mode) || ((int)$config->CombinedMailMode() === 1);
    if ($allow_client_mail && $MAILTOCLIENT == 1 && $config->fields['MailTo'] == 1 && $EMAIL != 'vide'){        
        $sharepoint->MailSend($EMAIL, $config->fields['gabarit'], $outputPathTemp, "Mail envoye a " . $EMAIL , $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL);
    }

    if ($DOC->tickets_id == 0) {$IdTicket = "Aucun ticket lié";} else { $IdTicket = $DOC->tickets_id; }
    if ($isCounterInvoicePaid) {
        if (!empty($relatedInvoiceToBL)){
            $ValueForSigned = "Bon de Livraison signé et règlement effectué au comptoir : $DOC_NAME <br><br> Documents/Informations associé au bon de livraison : $relatedInvoiceToBL <br><br> Mail client : $EMAIL <br><br> Ticket ID : $IdTicket";
        }else{
            $ValueForSigned = "Bon de Livraison signé et règlement effectué au comptoir : $DOC_NAME <br><br> Mail client : $EMAIL <br><br> Ticket ID : $IdTicket";
        }
        if ($hasComment) {
            $ValueForSigned .= " <br><br> Commentaire : " . $rawComment;
        }
            if (!$combined_mode) {
                if (!empty($config->fields['ZenDocMail'])){ 
                    $sharepoint->MailSend($config->fields['ZenDocMail'].','.$config->fields['CounterInvoiceMail'], 0, $outputPathTemp, " ", $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL, "Bon de Livraison signé + règlement comptoir ", $ValueForSigned);
                }else{
                    $sharepoint->MailSend($config->fields['CounterInvoiceMail'], 0, $outputPathTemp, " ", $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL, "Bon de Livraison signé + règlement comptoir ", $ValueForSigned);
                }
            } else {
                if (!empty($config->fields['CounterInvoiceMail'])) {
                    $sharepoint->MailSend($config->fields['CounterInvoiceMail'], 0, $outputPathTemp, " ", $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL, "Bon de Livraison signé + règlement comptoir ", $ValueForSigned);
                }
            }
    }else{
        if (!$combined_mode && !empty($config->fields['ZenDocMail'])){ 
            $ValueForSigned = "Bon de Livraison signé : $DOC_NAME <br><br> Mail client : $EMAIL <br><br> Ticket ID : $IdTicket";
            if (!empty($relatedInvoiceToBL)) {
                $ValueForSigned .= " <br><br> Documents/Informations associé au bon de livraison : $relatedInvoiceToBL";
            }
            if ($hasComment) {
                $ValueForSigned .= " <br><br> Commentaire : " . $rawComment;
            }
            $sharepoint->MailSend($config->fields['ZenDocMail'], 0, $outputPathTemp, "Envoyé vers ZenDoc", $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL, "Bon de Livraison signé", $ValueForSigned);
        }
    }
    // ENVOIE DES MAILS
    
    if($config->ConfigModes() == 0){
        if($DOC->save == "SharePoint"){
            try {
                $folderPathFile = ""; // Par défaut, $folderPath est vide
                if (!empty($DOC->url_bl)){
                    $folderPathFile = $DOC->url_bl .'/'. $DOC_NAME;
                }             
                $sharepoint->deleteFileByPath($folderPathFile);
            } catch (Exception $e) {
                gestion_message("Erreur : " . $e->getMessage(), ERROR);
            }
        }
        if($DOC->save == "Local"){
            try {
                $queryDelete = "DELETE FROM `glpi_documents` WHERE `id` = " . (int)$DOC->doc_id;
                if ($DB->doQuery($queryDelete)) {
                    unlink($existingPdfPath); // si fichier local alors delete le fichier non signer (configmode = 0 alors suppression du fichier)
                }
            } catch (Exception $e) {
                gestion_message("Erreur de suppression du document non signé : " . $e->getMessage(), ERROR);
            }
        }
        if ($DOC->save == "Sage"){ //Récup BL depuis local
            unlink($existingPdfPath);
        }
    }
}

////////////////// Upload du fichier signé dans SharePoint ou localement //////////////////
    try {
        if ($DOC->entities_id == 0 || $DOC->entities_id == NULL){
            $EntitiesName = "AUTRES";
        }else{
            $entityResult = $DB->doQuery("SELECT name FROM glpi_entities WHERE id = " . (int)$DOC->entities_id)->fetch_object();
            $EntitiesName = $entityResult->name;
        }

        $folderPath = $folder_name. '/' .$EntitiesName; // Chemin du dossier
        $fileName = $DOC_NAME; // Nom du fichier après téléversement    

        // Étape 3 : Téléverser le fichier
        // Add Year/Month subfolders to storage path
        $year = date('Y');
        $monthIndex = (int)date('n');
        $monthsFr = [1=>'janvier','fevrier','mars','avril','mai','juin','juillet','aout','septembre','octobre','novembre','decembre'];
        $monthName = $monthsFr[$monthIndex] ?? strtolower(date('F'));
        $folderPath = rtrim($folderPath, '/'). '/' . $year . '/' . $monthName;
        if ($FolderDes == 'SharePoint'){
            $sharepoint->uploadFileToFolder($folderPath, $fileName, $outputPathTemp);
        } 
        if ($FolderDes == 'Local'){
            $destDir = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folderPath;

            // Crée le dossier s’il n’existe pas
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            // Construire le chemin complet de destination
            $destPath = $destDir . '/' . $fileName;
            if (!str_ends_with($destPath, '.pdf')) {
                $destPath .= '.pdf';
            }
            // Copier le fichier
            copy($outputPathTemp, $destPath);
        }
    } catch (Exception $e) {
        gestion_message("Erreur : " . $e->getMessage(), ERROR);
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
             if (!str_ends_with($DOC_NAME, '.pdf')) {
                $DOC_NAME .= '.pdf';
            }
            $input = ['name'        => addslashes(str_replace("?", "°", $DOC_NAME)),
                    'filename'    => addslashes($DOC_NAME),
                    'filepath'    => addslashes($folderPath . $DOC_NAME),
                    'mime'        => 'application/pdf',
                    'users_id'    => Session::getLoginUserID(),
                    'entities_id' => 0,
                    'tickets_id'  => 0,
                    'is_recursive'=> 1];

            if($NewDoc = $doc->add($input)){
                // GLPI 11 blackliste filepath/sha1sum dans Document::add => reecriture directe.
                pluginGestionFixDocumentFile((int)$NewDoc, $folderPath . $DOC_NAME);
                $fileUrl = "document.send.php?docid=".$NewDoc;
            }else{
                $fileUrl = null;
            }
        }

        $updateFinal = [
            'doc_url'  => (string)($fileUrl ?? ''),
            'url_bl'   => (string)$folderPath,
            'doc_id'   => (int)$NewDoc,
            'save'     => (string)$FolderDes,
            'signed'   => 1,
            'doc_date' => date('Y-m-d H:i:s'),
            'users_id' => (int)$tech_id,
            'users_ext'=> (string)$NAME,
        ];
        if ($is_quick && $TECHNICIAN_INPUT !== '') {
            $updateFinal['tech_ext'] = $TECHNICIAN_INPUT;
        }
        if ($DB->update('glpi_plugin_gestion_surveys', $updateFinal, ['id' => $id_document])) {            //unlink($existingPdfPath);
            unlink($signaturePath);
            if ($combined_mode) {
                $GLOBALS['GESTION_LAST_SIGNED_BL_PDF'] = $outputPathTemp;
            } else {
                unlink($outputPathTemp);
            }
        }

        gestion_message('Documents : '. $DOC_NAME.' signé', INFO);
    } catch (Exception $e) {
        gestion_message("Signé avec erreur, voir votre administrateur : " . $e->getMessage(), ERROR);
    }
                

/*}else{
    gestion_message("Erreur lors de la signature et/ou de l'enregistrement du documents : ". $DOC_NAME, ERROR);
}*/

//Html::back();
?>

