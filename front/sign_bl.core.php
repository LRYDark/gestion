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
    * @param array                $opts          ['counter_invoice'=>bool, 'payment_datetime'=>string, 'comment'=>string]
    * @param array|null           $meta          Rempli en retour : ['substituted' => true] quand le PDF
    *                                            source manquait et qu'une page de remplacement en tient lieu.
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
      array $opts = [],
      ?array &$meta = null
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
         // Source injoignable : traitee comme absente, une page de
         // remplacement en tiendra lieu (ci-dessous).
         $existingPdfPath = '';
      }

      $substituted = false;
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
                  'BL %s (source %s) : PDF source introuvable, page de remplacement generee (reference : %s)',
                  $DOC_NAME,
                  (string)($DOC->save ?? '?'),
                  (string)($DOC->url_bl ?? '')
               )
            );
         }
         /*
          * Le bon est signe QUAND MEME : une page generee ici — numero, nom,
          * ticket, signature du client — tient lieu de PDF d'origine, puis suit
          * exactement le chemin d'un vrai bon (photos, PDF joint, archivage).
          * Le technicien garde une trace de la signature, au lieu d'un bon a
          * resigner... contre la meme absence.
          */
         $existingPdfPath = pluginGestionMissingBlPage($DOC, $signaturePath, $clientName, $techName, $opts);
         if ($existingPdfPath === null) {
            return null;
         }
         $substituted = true;
         if ($meta !== null) {
            $meta['substituted'] = true;
         }
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
            // La page de remplacement porte deja sa signature et sa mention de
            // reglement : rien n'est tamponne dessus.
            if (!$substituted && !empty($opts['counter_invoice'])
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

            if ($i === $targetPage && !$substituted) {
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

if (!function_exists('pluginGestionMissingBlPage')) {
   /**
    * Page de remplacement d'un bon dont le PDF d'origine est introuvable.
    *
    * Une page A4 qui tient lieu de bon dans toute la chaine de signature :
    * numero et nom du bon, ticket, entite, date de creation, puis la signature
    * du client avec son nom, la date et le technicien. Elle porte DEJA sa
    * signature : l'appelant ne la tamponne pas.
    *
    * @param object $DOC           Ligne glpi_plugin_gestion_surveys.
    * @param string $signaturePath PNG de la signature deja decode.
    * @param string $clientName    Nom du signataire.
    * @param string $techName      Nom du technicien.
    * @param array  $opts          ['counter_invoice'=>bool, 'comment'=>string]
    *
    * @return string|null Chemin du PDF temporaire, ou null si echec.
    */
   function pluginGestionMissingBlPage(
      object $DOC,
      string $signaturePath,
      string $clientName,
      string $techName,
      array $opts = []
   ): ?string {
      global $DB;

      $config  = PluginGestionConfig::getInstance();
      $bl_name = trim((string)($DOC->bl ?? ''));
      $bl_ref  = function_exists('pluginGestionBlNumber') ? pluginGestionBlNumber($bl_name) : '';
      if ($bl_ref === '') {
         $bl_ref = $bl_name;
      }
      $ticket_id = (int)($DOC->tickets_id ?? 0);
      // Vide quand le bon n'a pas d'entite : la ligne n'apparait alors pas.
      $entity    = '';
      if ((int)($DOC->entities_id ?? 0) > 0) {
         $row = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => (int)$DOC->entities_id],
            'LIMIT'  => 1,
         ])->current();
         if ($row && trim((string)$row['name']) !== '') {
            $entity = (string)$row['name'];
         }
      }
      $source  = trim((string)($DOC->save ?? ''));
      $source  = $source !== '' ? $source : 'inconnue';
      $created = trim((string)($DOC->date_creation ?? ''));
      $tracker = trim((string)($DOC->tracker ?? ''));
      $comment = trim((string)($opts['comment'] ?? ''));
      $is_transport = !empty($opts['transport_dispatch']);
      $transport_carrier = trim((string)($opts['transport_carrier'] ?? 'Transporteur')) ?: 'Transporteur';
      $transport_tracking = trim((string)($opts['transport_tracking'] ?? ''));
      $transport_raw_date = trim((string)($opts['transport_departed_at'] ?? ''));
      $transport_time = strtotime($transport_raw_date);
      $now     = date('d/m/Y H:i');
      $action_time = $is_transport && $transport_time !== false ? date('d/m/Y H:i', $transport_time) : $now;

      // Polices de base FPDF : Windows-1252, d'ou la conversion des accents.
      $enc = static function (string $s): string {
         $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
         return $out === false ? $s : $out;
      };

      try {
         $pdf = new Fpdi();
         $pdf->SetMargins(15, 15, 15);
         $pdf->AddPage();

         $pdf->SetFont('Arial', 'B', 16);
         $pdf->Cell(0, 10, $enc('Bon de livraison - page de remplacement'), 0, 1, 'C');
         $pdf->SetFont('Arial', 'I', 10);
         $pdf->SetTextColor(110, 110, 110);
         $pdf->MultiCell(0, 5, $enc(
            $is_transport
               ? "Le PDF d'origine de ce bon était introuvable au moment de son départ transporteur (source : $source). "
                 . "Cette page en tient lieu et trace sa remise au transporteur."
               : "Le PDF d'origine de ce bon était introuvable au moment de la signature (source : $source). "
                 . "Cette page en tient lieu et porte la signature recueillie."
         ), 0, 'C');
         $pdf->SetTextColor(0, 0, 0);
         $pdf->Ln(6);

         // Seules les informations connues figurent dans le tableau : un bon
         // sans ticket, sans entite ou sans tracker n'a pas de ligne vide.
         $rows = [
            ['Numéro de BL', $bl_ref],
            ['Document',     $bl_name],
         ];
         if ($ticket_id > 0) {
            $rows[] = ['Ticket', '#' . $ticket_id];
         }
         if ($entity !== '') {
            $rows[] = ['Entité', $entity];
         }
         if ($created !== '') {
            $rows[] = ['Créé le', $created];
         }
         if ($tracker !== '') {
            $rows[] = ['Tracker', $tracker];
         }
         foreach ($rows as [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(55, 8, $enc($label), 1, 0, 'L');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 8, $enc($value), 1, 1, 'L');
         }
         $pdf->Ln(4);

         if (!empty($opts['counter_invoice'])) {
            $label = trim((string)($config->fields['CounterInvoiceText'] ?? ''));
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(46, 204, 113);
            $pdf->Cell(0, 8, $enc(($label !== '' ? $label : 'Règlement effectué au comptoir') . ' - ' . $now), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(2);
         }

         if ($comment !== '') {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 8, $enc('Commentaire'), 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(0, 6, $enc($comment), 1);
            $pdf->Ln(4);
         }

         if ($is_transport) {
            // Le départ est une validation logistique, pas une signature client.
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 8, $enc('Validation logistique'), 0, 1);
            $top = $pdf->GetY();
            $pdf->SetDrawColor(47, 63, 100);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetTextColor(47, 63, 100);
            $pdf->Rect(15, $top, 180, 38, 'DF');
            $pdf->SetXY(20, $top + 6);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->Cell(170, 7, $enc('ENVOYÉ PAR TRANSPORTEUR'), 0, 2, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(170, 6, $enc($transport_carrier . ' — ' . $action_time), 0, 2, 'C');
            if ($transport_tracking !== '') {
               $pdf->Cell(170, 6, $enc('Suivi : ' . $transport_tracking), 0, 2, 'C');
            }
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);
         } else {
            // Bloc signature : image a gauche, identite a droite, dans un cadre.
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 8, $enc('Signature du client'), 0, 1);
            $top = $pdf->GetY();
            $pdf->Rect(15, $top, 180, 55);
            if (is_file($signaturePath)) {
            /*
             * Ajustée au bloc sans jamais le déborder, ratio conservé.
             *
             * Le cadre est un `Rect(15, $top, 180, 55)` et l'identité du
             * signataire commence à x=105. La signature dispose donc de 20 à
             * 100 mm (5 mm de respiration avant le texte), et de $top+5 à
             * $top+53 (2 mm de garde avant le trait du bas).
             *
             * Ces bornes valaient 75 x 45 : cinq millimètres de large et huit
             * de haut restaient inutilisés pour rien.
             *
             * À NE PAS CONFONDRE avec le tampon apposé sur le bon de livraison
             * du client (plus haut dans ce fichier, et dans traitement.php) :
             * celui-là n'a pas de cadre, sa largeur vient de la configuration
             * `SignatureSize` et FPDF en déduit la hauteur. Ici, les deux
             * dimensions sont bornées, rien ne peut recouvrir quoi que ce soit.
             */
               $size = @getimagesize($signaturePath);
               $w = 80;
               $h = 0;
               if ($size && $size[0] > 0 && $size[1] > 0) {
                  $h = $w * $size[1] / $size[0];
                  if ($h > 48) {
                     $h = 48;
                     $w = $h * $size[0] / $size[1];
                  }
               }
               $pdf->Image($signaturePath, 20, $top + 5, $w, $h);
            }
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY(105, $top + 8);
            $pdf->Cell(0, 7, $enc('Nom : ' . $clientName), 0, 2);
            $pdf->Cell(0, 7, $enc('Signé le : ' . $now), 0, 2);
            $pdf->Cell(0, 7, $enc('Technicien : ' . $techName), 0, 2);
         }

         /*
          * Pied de page SANS saut automatique : a 20 mm du bas, FPDF ouvre
          * sinon une seconde page pour y poser cette seule ligne.
          */
         $pdf->SetAutoPageBreak(false);
         $pdf->SetY(-20);
         $pdf->SetFont('Arial', 'I', 8);
         $pdf->SetTextColor(110, 110, 110);
         $pdf->Cell(0, 5, $enc(
            "Page générée automatiquement par GLPI (plugin Gestion) le $now, le PDF d'origine du bon n'étant pas disponible."
         ), 0, 0, 'C');

         $path = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/BL_remplacement_' . rand(1, 100000) . '_'
               . preg_replace('/[^A-Za-z0-9_.-]/', '_', $bl_name) . '.pdf';
         $pdf->Output('F', $path);
         return is_file($path) ? $path : null;
      } catch (Throwable $e) {
         if (class_exists('PluginGestionLogger')) {
            PluginGestionLogger::error('signature', 'BL ' . $bl_name . ' : page de remplacement impossible - ' . $e->getMessage());
         }
         return null;
      }
   }
}

if (!function_exists('pluginGestionTransportStampBox')) {
   /**
    * Emprise du tampon « Envoyé par transporteur » sur la page qui le reçoit.
    *
    * Elle part du MÊME coin que la signature client (`SignatureX`, hauteur de
    * page - `SignatureY`) et reste dans la case que la signature peut occuper :
    * `SignatureSize` de large et, au plus, `SignatureSize` / 3,2 de haut — le
    * plafond que le navigateur impose au PNG (`EXPORT_MIN_RATIO` dans
    * scripts_gestion.js).
    *
    * Un cadre ne peut pas occuper toute cette case, une signature si. Relevé
    * sur le BL Sage avec X 36, Y 44, taille 50 : la case va de 36 à 86 mm et de
    * 253 à 268,6 mm, mais le bord de la case « Signature client » du bon passe
    * à 86 mm et la ligne « reçu les marchandises désignées conformes » commence
    * à 266,3 mm. Une signature, transparente, les frôle sans gêne ; un trait de
    * cadre les barrerait. Le tampon garde donc 1 mm à droite et s'arrête à
    * 0,24 fois la largeur : 49 x 12 mm, soit 1,3 mm au-dessus de ce texte.
    *
    * Les réglages sont LUS, jamais modifiés : la signature client garde sa
    * position au millimètre.
    *
    * @param array $cfg        champs de PluginGestionConfig
    * @param float $pageHeight hauteur de la page, en mm
    *
    * @return array{x: float, y: float, w: float, h: float} en mm
    */
   function pluginGestionTransportStampBox(array $cfg, float $pageHeight): array
   {
      $size = (float)($cfg['SignatureSize'] ?? 0);
      if ($size <= 0) {
         $size = 50.0; // valeur par défaut de la configuration
      }
      return [
         'x' => (float)($cfg['SignatureX'] ?? 36),
         'y' => $pageHeight - (float)($cfg['SignatureY'] ?? 44),
         'w' => $size - 1.0,
         'h' => $size * 0.24,
      ];
   }
}

if (!function_exists('pluginGestionDrawTransportStamp')) {
   /**
    * Tampon « Envoyé par transporteur » dans la case de la signature client.
    *
    * Un tampon métier explicite, jamais une imitation de signature client. Il
    * tient dans l'emprise de la signature (pluginGestionTransportStampBox).
    * L'ancien cadre, 1,8 fois plus large et haut de 22 mm sur fond plein,
    * masquait le texte du bon et mordait sur la case « Total » voisine.
    *
    * Le texte s'adapte à la case : le titre prend la plus grande taille qui
    * tient (9 pt au plus), les détails un même corps, 85 % du titre au plus,
    * réduit si l'un d'eux est trop long ; à 5 pt, la fin d'une ligne qui
    * déborde encore est coupée. Le bloc est centré verticalement. Sans fond :
    * comme la signature, le tampon ne masque rien.
    *
    * `Text()` plutôt que `Cell()` : il n'ouvre jamais de page, quelle que soit
    * la hauteur configurée.
    *
    * @param \FPDF  $pdf        sa page courante reçoit le tampon
    * @param array  $cfg        champs de PluginGestionConfig
    * @param string $carrier    transporteur (UTF-8)
    * @param string $departedAt date de départ, tout format lu par strtotime()
    * @param string $tracking   numéro de suivi, '' s'il n'y en a pas
    *
    * @return array emprise utilisée, cf. pluginGestionTransportStampBox()
    */
   function pluginGestionDrawTransportStamp(\FPDF $pdf, array $cfg, string $carrier, string $departedAt, string $tracking): array
   {
      // Polices de base FPDF : Windows-1252, d'où la conversion des accents.
      $enc = static function (string $text): string {
         $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
         return $converted === false ? $text : $converted;
      };

      $box      = pluginGestionTransportStampBox($cfg, $pdf->GetPageHeight());
      $carrier  = trim($carrier) !== '' ? trim($carrier) : 'Transporteur';
      $tracking = trim($tracking);
      $lines    = [
         ['text' => $enc('ENVOYÉ PAR TRANSPORTEUR'), 'style' => 'B'],
         ['text' => $enc($carrier . ' — ' . date('d/m/Y H:i', strtotime($departedAt) ?: time())), 'style' => ''],
      ];
      if ($tracking !== '') {
         $lines[] = ['text' => $enc('Suivi : ' . $tracking), 'style' => ''];
      }

      $pt       = 25.4 / 72; // 1 point, en mm
      $leading  = 1.15;      // interligne, en multiple du corps
      $detail   = 0.85;      // corps des détails, en fraction du titre
      $min_size = 5.0;
      $inner_w  = max(1.0, $box['w'] - 3.0); // 1,5 mm de marge de chaque côté
      $inner_h  = max(1.0, $box['h'] - 2.0); // 1 mm en haut et en bas

      // Plus grand corps de titre qui laisse tenir toutes les lignes en hauteur.
      $height_units = 1 + $detail * (count($lines) - 1);
      $title_max    = min(9.0, $inner_h / ($height_units * $leading * $pt));

      // GetStringWidth() est proportionnel au corps : une mesure à 10 pt suffit.
      $fit = static function (array $line, float $max) use ($pdf, $inner_w, $min_size): float {
         $pdf->SetFont('Arial', $line['style'], 10);
         $width_at_10 = $pdf->GetStringWidth($line['text']);
         return $width_at_10 > 0 ? max($min_size, min($max, 10 * $inner_w / $width_at_10)) : $max;
      };
      // Les détails partagent un même corps : celui de la ligne la plus longue.
      $title_size  = $fit($lines[0], $title_max);
      $detail_size = $title_size * $detail;
      foreach (array_slice($lines, 1) as $line) {
         $detail_size = min($detail_size, $fit($line, $title_size * $detail));
      }
      foreach ($lines as $i => &$line) {
         $line['size'] = $i === 0 ? $title_size : $detail_size;
         $pdf->SetFont('Arial', $line['style'], $line['size']);
         if ($pdf->GetStringWidth($line['text']) > $inner_w) {
            // Même au plus petit corps, la ligne déborde : on coupe sa fin.
            $text = $line['text'];
            while ($text !== '' && $pdf->GetStringWidth($text . "\x85") > $inner_w) {
               $text = substr($text, 0, -1);
            }
            $line['text'] = rtrim($text) . "\x85"; // « … » en Windows-1252
         }
      }
      unset($line);

      $block_h = 0.0;
      foreach ($lines as $line) {
         $block_h += $line['size'] * $leading * $pt;
      }

      $pdf->SetDrawColor(47, 63, 100);
      $pdf->SetTextColor(47, 63, 100);
      $pdf->SetLineWidth(.3);
      $pdf->Rect($box['x'], $box['y'], $box['w'], $box['h'], 'D');

      $top = $box['y'] + ($box['h'] - $block_h) / 2;
      foreach ($lines as $line) {
         $line_h = $line['size'] * $leading * $pt;
         $pdf->SetFont('Arial', $line['style'], $line['size']);
         // Ligne de base placée pour centrer la hauteur des capitales (0,72 em).
         $pdf->Text(
            $box['x'] + ($box['w'] - $pdf->GetStringWidth($line['text'])) / 2,
            $top + $line_h / 2 + 0.36 * $line['size'] * $pt,
            $line['text']
         );
         $top += $line_h;
      }

      $pdf->SetTextColor(0, 0, 0);
      $pdf->SetDrawColor(0, 0, 0);
      $pdf->SetLineWidth(.2);

      return $box;
   }
}
