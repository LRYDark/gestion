<?php
/*
 * Coeur de tamponnage d'un BL (signature + nom + date + technicien + photos + PDF joint).
 * Extrait de front/traitement.php pour etre reutilisable par la signature GROUPEE
 * (front/traitement_combined_multi.php) SANS toucher au flux 1-BL existant.
 *
 * Cette fonction NE FAIT QUE produire le PDF signe (fichier temporaire) :
 * pas de mise a jour BDD, pas d'envoi mail, pas d'archivage. Le caller s'en charge.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

if (!function_exists('pluginGestionRenderSignedBl')) {
   /**
    * Genere le PDF signe d'un BL et renvoie le chemin du fichier temporaire (ou null en cas d'echec).
    *
    * @param object               $DOC           Ligne glpi_plugin_gestion_surveys (objet).
    * @param string               $signaturePath Chemin du PNG de signature deja decode.
    * @param string               $clientName    Nom/prenom du client (signataire).
    * @param string               $techName      Nom du technicien a afficher.
    * @param array                $photoPaths    Chemins d'images JPG deja decodees (optionnel).
    * @param string|null          $attachedPdfPath Chemin d'un PDF joint deja decode (optionnel).
    * @param array                $opts          ['counter_invoice'=>bool, 'payment_datetime'=>string]
    *
    * @return string|null Chemin du PDF signe temporaire, ou null si echec.
    */
   function pluginGestionRenderSignedBl(
      object $DOC,
      string $signaturePath,
      string $clientName,
      string $techName,
      array $photoPaths = [],
      ?string $attachedPdfPath = null,
      array $opts = []
   ): ?string {
      global $DB, $CFG_GLPI;

      $config     = PluginGestionConfig::getInstance();
      $sharepoint = new PluginGestionSharepoint();
      $rand       = rand(1, 100000);
      $DOC_NAME   = (string)$DOC->bl;

      // ---- 1. Recuperer le PDF source du BL (local / SharePoint / Sage) ----
      $existingPdfPath = '';

      /*
       * LA COPIE LOCALE D'ABORD, quand elle est bien celle de CE bon.
       *
       * `pluginGestionLocalSourcePdf()` (setup.php) verifie que le Document
       * GLPI rattache porte le numero du bon : apres une signature groupee,
       * `doc_id` designe le PDF FUSIONNE du lot, qu'il ne faut surtout pas
       * prendre pour la source d'un bon. La source distante reste le repli.
       */
      $existingPdfPath = pluginGestionLocalSourcePdf($DOC);

      try {
         if ($existingPdfPath !== '') {
            // Rien a faire : la copie locale sert de source.
         } elseif ($DOC->save == "SharePoint") {
            /*
             * Deuxieme tentative, comme pour Sage (cf. downloadDocument).
             *
             * Un telechargement rate ne prouve pas que le document est absent :
             * une coupure reseau ou un jeton Graph expire au mauvais moment
             * donnent le meme resultat qu'une suppression. On reessaie une fois
             * avant de conclure ; si le fichier arrive, personne n'a rien vu.
             */
            $folderPath      = !empty($DOC->url_bl) ? ($DOC->url_bl . "/") : "";
            $filePath        = $folderPath . $DOC_NAME;
            $existingPdfPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_" . $rand . ".pdf";

            for ($sp_try = 1; $sp_try <= 2; $sp_try++) {
               try {
                  $downloadUrl = $sharepoint->getDownloadUrl($filePath);
                  $sharepoint->downloadFileFromUrl($downloadUrl, $existingPdfPath);
               } catch (Throwable $sp_e) {
                  // Avalee tant qu'il reste un essai ; relancee au dernier.
                  if ($sp_try >= 2) {
                     throw $sp_e;
                  }
               }
               if (file_exists($existingPdfPath)) {
                  break;
               }
               if ($sp_try < 2) {
                  usleep(700000);
               }
            }
         } elseif ($DOC->save == "Local") {
            $url = str_replace("_plugins", "", $DOC->url_bl . $DOC->bl);
            $existingPdfPath = GLPI_PLUGIN_DOC_DIR . $url;
         } elseif ($DOC->save == "Sage") {
            $existingPdfPath = downloadDocument($DOC->url_bl, GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/Sage_Temp_" . $rand . ".pdf");
         }
      } catch (Throwable $e) {
         /*
          * L'echec etait muet : la fonction rendait `null` sans dire pourquoi,
          * et l'appelant ne pouvait annoncer qu'un « Signature des BL
          * impossible » qui ne nommait ni le bon ni la cause. Sur un ticket a
          * trois bons, impossible de savoir lequel avait echoue.
          */
         if (class_exists('PluginGestionLogger')) {
            PluginGestionLogger::error(
               'signature',
               sprintf(
                  'BL %s (source %s) : recuperation du PDF impossible — %s',
                  $DOC_NAME,
                  (string)($DOC->save ?? '?'),
                  $e->getMessage()
               )
            );
         }
         return null;
      }

      if (empty($existingPdfPath) || !file_exists($existingPdfPath)) {
         /*
          * On arrive ici APRES la seconde tentative : le document est donc bien
          * absent de sa source, ou celle-ci reste injoignable.
          *
          * `downloadDocument()` ne leve pas d'exception sur une reponse
          * HTTP >= 400 — il supprime le fichier et rend malgre tout son chemin.
          * Seule l'absence du fichier trahit l'echec, d'ou ce controle.
          */
         if (class_exists('PluginGestionLogger')) {
            /*
             * `warning` et non `error` : un document absent de sa source n'est
             * pas une défaillance du plugin. La cause précise — 404, accès
             * refusé, source injoignable — a déjà été journalisée juste avant
             * par la couche qui a interrogé Sage ou SharePoint.
             */
            PluginGestionLogger::warning(
               'signature',
               sprintf(
                  'BL %s (source %s) : PDF source introuvable, bon non signe (reference : %s)',
                  $DOC_NAME,
                  (string)($DOC->save ?? '?'),
                  (string)($DOC->url_bl ?? '')
               )
            );
         }
         return null;
      }

      // ---- 2. Tamponner signature / nom / date / technicien ----
      $pdf = new Fpdi();
      try {
         $stream     = StreamReader::createByFile($existingPdfPath);
         $pageCount  = $pdf->setSourceFile($stream);
         $targetPage = $pageCount > 1 ? $pageCount : 1;

         for ($i = 1; $i <= $pageCount; $i++) {
            $pdf->AddPage();
            $tplIdx = $pdf->importPage($i);
            $pdf->useTemplate($tplIdx, 0, 0);

            // Tampon "Facture payee en magasin" (optionnel, comme traitement.php)
            if (!empty($opts['counter_invoice'])
                && !empty($config->fields['CounterInvoice']) && (int)$config->fields['CounterInvoice'] === 1
                && !empty($config->fields['CounterInvoicePdf']) && (int)$config->fields['CounterInvoicePdf'] === 1) {
               $paymentDateTime = !empty($opts['payment_datetime']) ? $opts['payment_datetime'] : date('d/m/Y H:i');
               $label_utf8 = ($config->fields['CounterInvoiceText'] ?? '') . ' - ' . $paymentDateTime;
               if (function_exists('iconv')) {
                  $label = iconv('UTF-8', 'windows-1252//TRANSLIT', $label_utf8);
               } else {
                  $label = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', str_replace(['–', '—'], '-', $label_utf8));
               }
               $pdf->SetFont('Arial', 'B', 11);
               $pdf->SetTextColor(46, 204, 113);
               $pdf->SetDrawColor(46, 204, 113);
               $pdf->SetFillColor(255, 255, 255);
               $pdf->SetLineWidth(0.6);
               $w = $pdf->GetStringWidth($label) + 10;
               $h = 8;
               $x = $pdf->GetPageWidth() - $w - 12;
               $y = 14;
               $pdf->SetXY($x, $y);
               $pdf->Cell($w, $h, $label, 1, 1, 'C', true);
               $pdf->SetTextColor(0, 0, 0);
               $pdf->SetDrawColor(0, 0, 0);
               $pdf->SetFillColor(255, 255, 255);
               $pdf->SetLineWidth(0.2);
            }

            if ($i === $targetPage) {
               $pdf->Image($signaturePath, $config->fields['SignatureX'], $pdf->GetPageHeight() - $config->fields['SignatureY'], $config->fields['SignatureSize']);

               if (!empty($config->fields['SignataireX']) && !empty($config->fields['SignataireY'])) {
                  $pdf->SetFont('Arial', '', 10);
                  $pdf->SetXY($config->fields['SignataireX'], $pdf->GetPageHeight() - $config->fields['SignataireY']);
                  $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $clientName));
               }
               if (!empty($config->fields['DateX']) && !empty($config->fields['DateY'])) {
                  $pdf->SetXY($config->fields['DateX'], $pdf->GetPageHeight() - $config->fields['DateY']);
                  $pdf->Cell(40, 10, date('d/m/Y'));
               }
               if (!empty($config->fields['TechX']) && !empty($config->fields['TechY'])) {
                  $pdf->SetFont('Arial', '', 9);
                  $pdf->SetXY($config->fields['TechX'], $pdf->GetPageHeight() - $config->fields['TechY']);
                  $pdf->Cell(40, 10, $techName);
               }
            }
         }
      } catch (Throwable $e) {
         return null;
      }

      // ---- 3. Photos (2 par page) ----
      if (!empty($photoPaths)) {
         $photoChunks = array_chunk($photoPaths, 2);
         foreach ($photoChunks as $chunk) {
            $pdf->AddPage();
            $maxW = 190;
            $maxH = 130;

            $imgSize1 = @getimagesize($chunk[0]);
            if ($imgSize1) {
               $ratio1 = min($maxW / $imgSize1[0], $maxH / $imgSize1[1]);
               $w1 = $imgSize1[0] * $ratio1;
               $h1 = $imgSize1[1] * $ratio1;
               $x1 = 10 + ($maxW - $w1) / 2;
               $pdf->Image($chunk[0], $x1, 10, $w1, $h1);
            } else {
               $h1 = $maxH;
               $pdf->Image($chunk[0], 10, 10, $maxW);
            }

            if (isset($chunk[1])) {
               $separatorY = 10 + $h1 + 5;
               $pdf->Line(20, $separatorY, 190, $separatorY);
               $startY2 = $separatorY + 5;
               $imgSize2 = @getimagesize($chunk[1]);
               if ($imgSize2) {
                  $ratio2 = min($maxW / $imgSize2[0], $maxH / $imgSize2[1]);
                  $w2 = $imgSize2[0] * $ratio2;
                  $h2 = $imgSize2[1] * $ratio2;
                  $x2 = 10 + ($maxW - $w2) / 2;
                  $pdf->Image($chunk[1], $x2, $startY2, $w2, $h2);
               } else {
                  $pdf->Image($chunk[1], 10, $startY2, $maxW);
               }
            }
         }
      }

      // ---- 4. Sortie temporaire ----
      $outputPathTemp = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SIGNED_" . $rand . "_" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $DOC_NAME);
      if (!str_ends_with($outputPathTemp, '.pdf')) {
         $outputPathTemp .= '.pdf';
      }

      // ---- 5. Fusion d'un PDF joint (apres tamponnage) ----
      if (!empty($attachedPdfPath) && file_exists($attachedPdfPath)) {
         try {
            $tempIntermediatePath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_intermediate_' . $rand . '.pdf';
            $pdf->Output('F', $tempIntermediatePath);

            $pdfMerged = new Fpdi();
            $interStream = StreamReader::createByFile($tempIntermediatePath);
            $interCount  = $pdfMerged->setSourceFile($interStream);
            for ($ip = 1; $ip <= $interCount; $ip++) {
               $pdfMerged->AddPage();
               $pdfMerged->useTemplate($pdfMerged->importPage($ip), 0, 0);
            }
            $attStream = StreamReader::createByFile($attachedPdfPath);
            $attCount  = $pdfMerged->setSourceFile($attStream);
            for ($ap = 1; $ap <= $attCount; $ap++) {
               $pdfMerged->AddPage();
               $pdfMerged->useTemplate($pdfMerged->importPage($ap), 0, 0);
            }
            $pdf = $pdfMerged;
            if (file_exists($tempIntermediatePath)) {
               @unlink($tempIntermediatePath);
            }
         } catch (Throwable $e) {
            // En cas d'echec de fusion, on garde le PDF tamponne seul.
         }
      }

      if ($pdf->Output('F', $outputPathTemp) === '' || file_exists($outputPathTemp)) {
         /*
          * Nettoyage des sources TEMPORAIRES telechargees, et d'elles seules.
          *
          * Le test portait sur `$DOC->save`, en supposant qu'une source distante
          * impliquait forcement une copie temporaire. Depuis que le Document
          * GLPI deja rattache au bon peut servir de source, cette supposition
          * est fausse : on aurait supprime l'ORIGINAL du disque, laissant un
          * Document GLPI pointant dans le vide.
          *
          * Le dossier de travail est desormais le seul critere.
          */
         $tmp_dir = str_replace('\\', '/', GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/");
         if (str_starts_with(str_replace('\\', '/', (string)$existingPdfPath), $tmp_dir)
             && is_file($existingPdfPath)) {
            @unlink($existingPdfPath);
         }
         return file_exists($outputPathTemp) ? $outputPathTemp : null;
      }

      return null;
   }
}
