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

      // ---- 1. Recuperer le PDF source du BL (SharePoint / Local / Sage) ----
      $existingPdfPath = '';
      try {
         if ($DOC->save == "SharePoint") {
            $folderPath = !empty($DOC->url_bl) ? ($DOC->url_bl . "/") : "";
            $filePath   = $folderPath . $DOC_NAME;
            $downloadUrl = $sharepoint->getDownloadUrl($filePath);
            $existingPdfPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_" . $rand . ".pdf";
            $sharepoint->downloadFileFromUrl($downloadUrl, $existingPdfPath);
         } elseif ($DOC->save == "Local") {
            $url = str_replace("_plugins", "", $DOC->url_bl . $DOC->bl);
            $existingPdfPath = GLPI_PLUGIN_DOC_DIR . $url;
         } elseif ($DOC->save == "Sage") {
            $existingPdfPath = downloadDocument($DOC->url_bl, GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/Sage_Temp_" . $rand . ".pdf");
         }
      } catch (Throwable $e) {
         return null;
      }

      if (empty($existingPdfPath) || !file_exists($existingPdfPath)) {
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
         // Nettoyage des sources temporaires telechargees.
         if ($DOC->save == "SharePoint" || $DOC->save == "Sage") {
            @unlink($existingPdfPath);
         }
         return file_exists($outputPathTemp) ? $outputPathTemp : null;
      }

      return null;
   }
}
