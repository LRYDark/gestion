<?php
/*
 * Signature GROUPEE : tous les BL coches d'un ticket + 1 rapport => 1 PDF fusionne.
 * - Une seule signature client appliquee a chaque BL et au rapport.
 * - Rapport genere une seule fois.
 * - Fusion [BL1, BL2, ..., rapport] => 1 PDF final.
 * - PDF fusionne : archive selon config (SharePoint/Local), maile client + ZenDoc.
 * - Chaque BL coche est marque signe et pointe vers le PDF fusionne (doc_url).
 */

include('../../../inc/includes.php');
require_once(__DIR__ . '/../vendor/autoload.php');

require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';
require_once PLUGIN_GESTION_DIR . '/front/sign_bl.core.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

global $DB, $CFG_GLPI;

Session::checkLoginUser();

function gestion_multi_message($msg, $type = INFO) {
   if (class_exists('PluginGestionLogger')) {
      if ($type === ERROR) {
         PluginGestionLogger::error('signature-groupee', $msg);
      } elseif ($type === WARNING) {
         PluginGestionLogger::warning('signature-groupee', $msg);
      } else {
         PluginGestionLogger::info('signature-groupee', $msg);
      }
   }
   Session::addMessageAfterRedirect(__($msg, 'gestion'), true, $type);
}

$ticket_id          = isset($_POST['REPORT_ID']) ? (int)$_POST['REPORT_ID'] : 0;
$bl_ids_raw         = $_POST['bl_ids'] ?? [];
$client_mail_enabled = isset($_POST['mailtoclient']) ? (int)$_POST['mailtoclient'] : 0;
$client_email       = isset($_POST['email']) ? trim((string)$_POST['email']) : '';

if (!is_array($bl_ids_raw)) {
   $bl_ids_raw = [$bl_ids_raw];
}
$bl_ids = array_values(array_unique(array_filter(array_map('intval', $bl_ids_raw), fn($v) => $v > 0)));

if ($ticket_id <= 0 || empty($bl_ids)) {
   gestion_multi_message("Ticket ou BL invalide.", ERROR);
   Html::back();
   exit;
}

$plugin = new Plugin();
if (!$plugin->isInstalled('rp') || !$plugin->isActivated('rp') || !class_exists('PluginRpCri')) {
   gestion_multi_message("Le plugin RP n'est pas disponible.", ERROR);
   Html::back();
   exit;
}

$config             = PluginGestionConfig::getInstance();
$sharepoint         = new PluginGestionSharepoint();
$combined_mail_mode = (int)$config->CombinedMailMode();

// ---- Verifier les BL : appartiennent au ticket + non signes ----
$valid_bls = [];
foreach ($bl_ids as $bid) {
   $row = $DB->request([
      'FROM'  => 'glpi_plugin_gestion_surveys',
      'WHERE' => ['id' => $bid, 'tickets_id' => $ticket_id, 'signed' => 0],
      'LIMIT' => 1,
   ])->current();
   if ($row) {
      $valid_bls[] = (object)$row;
   }
}
if (empty($valid_bls)) {
   gestion_multi_message("Aucun BL valide a signer (deja signes ou non lies au ticket).", ERROR);
   Html::back();
   exit;
}

// ---- Technicien (utilisateur de session, flux non quick) ----
$tech_id   = (int)Session::getLoginUserID();
$tech_name = getUserName($tech_id);

// ---- Signature client (decodee une seule fois) ----
$rand = rand(1, 100000);
$signatureBase64 = $_POST['url'] ?? '';
if (strpos($signatureBase64, 'data:image/png;base64,') === 0) {
   $signatureBase64 = str_replace('data:image/png;base64,', '', $signatureBase64);
}
$signatureData = base64_decode($signatureBase64);
$signaturePath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/signature_multi_' . $rand . '.png';
if ($signatureData === false || file_put_contents($signaturePath, $signatureData) === false) {
   gestion_multi_message("Signature invalide.", ERROR);
   Html::back();
   exit;
}

$client_name = (string)($_POST['name'] ?? '');
$counter_invoice = !empty($_POST['CounterInvoiceClient']) && (int)$_POST['CounterInvoiceClient'] === 1;

// ---- Photos (decodees une seule fois) ----
$photoPaths = [];
for ($pi = 1; $pi <= 6; $pi++) {
   $photoBase64 = $_POST['photo_base64_' . $pi] ?? '';
   if (empty($photoBase64) || strpos($photoBase64, 'data:image') !== 0) {
      continue;
   }
   $clean = preg_replace('#^data:image/\w+;base64,#i', '', $photoBase64);
   $data  = base64_decode($clean);
   if ($data === false) {
      continue;
   }
   $tmp = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/temp_photo_multi' . $rand . '_' . $pi;
   if (file_put_contents($tmp, $data) === false) {
      continue;
   }
   $info = @getimagesize($tmp);
   if ($info === false) { @unlink($tmp); continue; }
   $jpg = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/photo_multi' . $rand . '_' . $pi . '.jpg';
   $img = null;
   if ($info['mime'] === 'image/jpeg') {
      $img = @imagecreatefromjpeg($tmp);
      $exif = @exif_read_data($tmp);
      if ($img && !empty($exif['Orientation'])) {
         switch ($exif['Orientation']) {
            case 3: $img = imagerotate($img, 180, 0); break;
            case 6: $img = imagerotate($img, -90, 0); break;
            case 8: $img = imagerotate($img, 90, 0); break;
         }
      }
   } elseif ($info['mime'] === 'image/png') {
      $img = @imagecreatefrompng($tmp);
   }
   if (!$img) { @unlink($tmp); continue; }
   $ow = imagesx($img); $oh = imagesy($img); $maxD = 1600;
   if ($ow > $maxD || $oh > $maxD) {
      $r = min($maxD / $ow, $maxD / $oh);
      $nw = (int)round($ow * $r); $nh = (int)round($oh * $r);
      $res = imagecreatetruecolor($nw, $nh);
      imagecopyresampled($res, $img, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
      imagedestroy($img); $img = $res;
   }
   if (imagejpeg($img, $jpg, 75)) {
      $photoPaths[] = $jpg;
   }
   imagedestroy($img);
   @unlink($tmp);
}

// ---- PDF joint (decode une seule fois) ----
$attachedPdfPath = null;
$pdfBase64 = $_POST['pdf_base64'] ?? '';
if (!empty($pdfBase64) && strpos($pdfBase64, 'data:application/pdf;base64,') === 0) {
   $clean = preg_replace('#^data:application/pdf;base64,#i', '', $pdfBase64);
   $data  = base64_decode($clean);
   if ($data !== false) {
      $attachedPdfPath = GLPI_PLUGIN_DOC_DIR . '/gestion/FilesTempSharePoint/attached_multi_' . $rand . '.pdf';
      if (file_put_contents($attachedPdfPath, $data) === false) {
         $attachedPdfPath = null;
      }
   }
}

// ---- 1. Generer le rapport RP une seule fois ----
$rp_pdf = '';
$SeeFilePath = '';
$saved_mailto = $_POST['mailtoclient'] ?? null;
$_POST['mailtoclient'] = 0; // jamais de mail depuis le rapport
try {
   $rp_dir = Plugin::getPhpDir('rp');
   ob_start();
   include $rp_dir . '/front/cripdf.form.php';
   ob_end_clean();
   $rp_pdf = $SeeFilePath ?? '';
} catch (Throwable $e) {
   $rp_pdf = '';
}
$_POST['mailtoclient'] = $saved_mailto;

if (empty($rp_pdf) || !file_exists($rp_pdf)) {
   @unlink($signaturePath);
   gestion_multi_message("Generation du rapport impossible.", ERROR);
   Html::back();
   exit;
}

// ---- 2. Signer chaque BL (photos/PDF joint sur le 1er seulement) ----
$signed_bl_pdfs = [];
$first = true;
foreach ($valid_bls as $DOC) {
   $signed = pluginGestionRenderSignedBl(
      $DOC,
      $signaturePath,
      $client_name,
      $tech_name,
      $first ? $photoPaths : [],
      $first ? $attachedPdfPath : null,
      ['counter_invoice' => $counter_invoice]
   );
   if ($signed && file_exists($signed)) {
      $signed_bl_pdfs[(int)$DOC->id] = $signed;
   }
   $first = false;
}

if (empty($signed_bl_pdfs)) {
   @unlink($signaturePath);
   if (!empty($rp_pdf) && file_exists($rp_pdf)) { @unlink($rp_pdf); }
   gestion_multi_message("Signature des BL impossible.", ERROR);
   Html::back();
   exit;
}

// ---- 3. Fusion : tous les BL signes puis le rapport => 1 PDF ----
$mergedPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/BL_Rapport_T" . $ticket_id . "_" . date('Ymd_His') . ".pdf";
try {
   $pdf = new Fpdi();
   $sources = array_values($signed_bl_pdfs);
   $sources[] = $rp_pdf;
   foreach ($sources as $src) {
      if (!file_exists($src)) { continue; }
      $stream = StreamReader::createByFile($src);
      $pageCount = $pdf->setSourceFile($stream);
      for ($i = 1; $i <= $pageCount; $i++) {
         $pdf->AddPage();
         $pdf->useTemplate($pdf->importPage($i), 0, 0);
      }
   }
   $pdf->Output('F', $mergedPath);
} catch (Throwable $e) {
   @unlink($signaturePath);
   foreach ($signed_bl_pdfs as $p) { @file_exists($p) && @unlink($p); }
   if (file_exists($rp_pdf)) { @unlink($rp_pdf); }
   gestion_multi_message("Fusion des PDF impossible : " . $e->getMessage(), ERROR);
   Html::back();
   exit;
}

// ---- 4. Determiner la destination d'archivage (comme traitement.php) ----
$FolderDes = 'Local';
$folder_name = 'DocumentsSigned';
$q = "SELECT folder_name, params FROM glpi_plugin_gestion_configsfolder WHERE params IN (2,3)
      ORDER BY CASE params WHEN 2 THEN 0 WHEN 3 THEN 1 END LIMIT 1";
$res = $DB->doQuery($q);
if ($res && $DB->numrows($res) > 0) {
   $d = $DB->fetchassoc($res);
   $folder_name = $d['folder_name'];
   $used = $d['params'];
   if ($used == 2 && $config->SharePointOn() == 1) {
      // SharePoint actif : on valide la connexion (sinon repli local).
      $chk = $sharepoint->validateSharePointConnection($config->Hostname() . ':' . $config->SitePath());
      $FolderDes = (isset($chk['status']) && $chk['status'] === true) ? 'SharePoint' : 'Local';
   } else {
      // SharePoint desactive (ou params=3) : depot LOCAL, pas de tentative SharePoint.
      $FolderDes = 'Local';
   }
}

// ---- 5. Archiver le PDF fusionne + recuperer son URL ----
$first_bl   = $valid_bls[0];
$entity_id  = (int)($first_bl->entities_id ?? 0);
$EntitiesName = 'AUTRES';
if ($entity_id > 0) {
   $er = $DB->doQuery("SELECT name FROM glpi_entities WHERE id = $entity_id")->fetch_object();
   if ($er && !empty($er->name)) { $EntitiesName = $er->name; }
}
$mergedName = "BL_Rapport_T" . $ticket_id . "_" . date('Ymd_His') . ".pdf";
$year = date('Y');
$monthsFr = [1=>'janvier','fevrier','mars','avril','mai','juin','juillet','aout','septembre','octobre','novembre','decembre'];
$monthName = $monthsFr[(int)date('n')] ?? strtolower(date('F'));
$folderPath = rtrim($folder_name . '/' . $EntitiesName, '/') . '/' . $year . '/' . $monthName;

$merged_doc_url = '';
$merged_doc_id  = 0;
$merged_url_bl  = '';
try {
   if ($FolderDes == 'SharePoint') {
      $sharepoint->uploadFileToFolder($folderPath, $mergedName, $mergedPath);
      $merged_url_bl  = $folderPath . '/';
      $merged_doc_url = $sharepoint->getFileUrl($folderPath . '/' . $mergedName);
   } else {
      $destDir = GLPI_PLUGIN_DOC_DIR . "/gestion/" . $folderPath;
      if (!is_dir($destDir)) { @mkdir($destDir, 0755, true); }
      $destPath = $destDir . '/' . $mergedName;
      copy($mergedPath, $destPath);
      $merged_url_bl = "_plugins/gestion/" . $folderPath . '/';
      $doc = new Document();
      $input = [
         'name'        => addslashes($mergedName),
         'filename'    => addslashes($mergedName),
         'filepath'    => addslashes($merged_url_bl . $mergedName),
         'mime'        => 'application/pdf',
         'users_id'    => $tech_id,
         'entities_id' => 0,
         'tickets_id'  => 0,
         'is_recursive'=> 1,
      ];
      $merged_doc_id  = (int)$doc->add($input);
      $merged_doc_url = $merged_doc_id ? ("document.send.php?docid=" . $merged_doc_id) : '';
   }
} catch (Throwable $e) {
   gestion_multi_message("Archivage du PDF fusionne en erreur : " . $e->getMessage(), WARNING);
}

// ---- 6. Marquer chaque BL signe (pointant vers le PDF fusionne) ----
$now = date('Y-m-d H:i:s');
$rawComment = trim($_POST['comment'] ?? '');
foreach ($valid_bls as $DOC) {
   $update = [
      'signed'    => 1,
      'doc_date'  => $now,
      'users_id'  => $tech_id,
      'users_ext' => $client_name,
      'doc_url'   => $merged_doc_url,
      'url_bl'    => $merged_url_bl,
      'doc_id'    => $merged_doc_id,
      'save'      => $FolderDes,
      'comment'   => $rawComment !== '' ? $rawComment : null,
   ];
   if ($counter_invoice) {
      $update['paid'] = 1;
   }
   $DB->update('glpi_plugin_gestion_surveys', $update, ['id' => (int)$DOC->id]);
}

// ---- 7. Mails (PDF fusionne) ----
// Le groupe produit toujours UN PDF fusionne et supprime le mail separe du rapport :
// on envoie donc le fusionne une seule fois (independamment de CombinedMailMode).
if ($client_mail_enabled == 1 && !empty($config->fields['MailTo'])
    && $client_email !== '' && $client_email !== 'vide') {
   $sharepoint->MailSend($client_email, $config->fields['gabarit'], $mergedPath, "Mail envoye a " . $client_email, null, null, null, null);
}
if (!empty($config->fields['ZenDocMail'])) {
   $bl_list = implode(', ', array_map(fn($d) => $d->bl, $valid_bls));
   $msg = "BL signes + Rapport d'intervention.<br><br>Ticket ID : $ticket_id<br><br>BL : $bl_list";
   $sharepoint->MailSend($config->fields['ZenDocMail'], 0, $mergedPath, "Envoye vers ZenDoc", null, null, null, null, "BL + Rapport signes", $msg);
}

// ---- 8. Nettoyage ----
@unlink($signaturePath);
foreach ($signed_bl_pdfs as $p) { if (file_exists($p)) { @unlink($p); } }
if (file_exists($rp_pdf)) { @unlink($rp_pdf); }
if ($attachedPdfPath && file_exists($attachedPdfPath)) { @unlink($attachedPdfPath); }
if (file_exists($mergedPath)) { @unlink($mergedPath); }

gestion_multi_message(count($valid_bls) . " BL + Rapport signes et fusionnes.", INFO);
Html::back();
