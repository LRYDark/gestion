<?php
include('../../../inc/includes.php');
require_once('../vendor/autoload.php');

require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

global $DB;

Session::checkLoginUser();

function gestion_combined_message($msg, $type = INFO) {
   Session::addMessageAfterRedirect(__($msg, 'gestion'), true, $type);
}

$ticket_id = isset($_POST['REPORT_ID']) ? (int)$_POST['REPORT_ID'] : 0;
$bl_id     = isset($_POST['id_document']) ? (int)$_POST['id_document'] : 0;
$client_mail_enabled = isset($_POST['mailtoclient']) ? (int)$_POST['mailtoclient'] : 0;
$client_email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';

if ($ticket_id <= 0 || $bl_id <= 0) {
   gestion_combined_message("Ticket ou BL invalide.", ERROR);
   Html::back();
   exit;
}

$plugin = new Plugin();
if (!$plugin->isInstalled('rp') || !$plugin->isActivated('rp') || !class_exists('PluginRpCri')) {
   gestion_combined_message("Le plugin RP n'est pas disponible. Signature BL seule impossible.", ERROR);
   Html::back();
   exit;
}

$config = PluginGestionConfig::getInstance();
$combined_mail_mode = (int)$config->CombinedMailMode();

// Sécurité : vérifier que le BL appartient bien au ticket et n'est pas signé
$bl_row = $DB->request([
   'FROM'   => 'glpi_plugin_gestion_surveys',
   'WHERE'  => [
      'id'         => $bl_id,
      'tickets_id' => $ticket_id,
      'signed'     => 0
   ],
   'LIMIT' => 1
])->current();

if (!$bl_row) {
   gestion_combined_message("BL introuvable ou déjà signé.", ERROR);
   Html::back();
   exit;
}

// Empecher l'envoi d'email du rapport si mode mail fusionne
if ($combined_mail_mode === 0) {
   $_POST['mailtoclient'] = 0;
}

// Generer le rapport RP (memes champs que le formulaire RP)
$rp_pdf = '';
$SeeFilePath = '';
try {
   $rp_dir = Plugin::getPhpDir('rp');
   ob_start();
   include $rp_dir . '/front/cripdf.form.php';
   ob_end_clean();
   $rp_pdf = $SeeFilePath ?? '';
} catch (Throwable $e) {
   $rp_pdf = '';
}

if (empty($rp_pdf) || !file_exists($rp_pdf)) {
   gestion_combined_message("Generation du rapport impossible.", ERROR);
   Html::back();
   exit;
}

$_POST['mailtoclient'] = $client_mail_enabled;

// Signer le BL via le traitement standard (en mode combine)
$_POST['combined_mode'] = 1;
ob_start();
include PLUGIN_GESTION_DIR . '/front/traitement.php';
ob_end_clean();

$bl_signed_pdf = $GLOBALS['GESTION_LAST_SIGNED_BL_PDF'] ?? '';
if (empty($bl_signed_pdf) || !file_exists($bl_signed_pdf)) {
   if (!empty($rp_pdf) && file_exists($rp_pdf)) {
      @unlink($rp_pdf);
   }
   gestion_combined_message("Signature du BL impossible.", ERROR);
   Html::back();
   exit;
}

// Fusion BL + Rapport
$mergedPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/BL_Rapport_merged_" . date('Ymd_His') . ".pdf";
$pdf = new Fpdi();

try {
   foreach ([$bl_signed_pdf, $rp_pdf] as $src) {
      if (!file_exists($src)) continue;
      $stream = StreamReader::createByFile($src);
      $pageCount = $pdf->setSourceFile($stream);
      for ($i = 1; $i <= $pageCount; $i++) {
         $pdf->AddPage();
         $tplIdx = $pdf->importPage($i);
         $pdf->useTemplate($tplIdx, 0, 0);
      }
   }
   $pdf->Output('F', $mergedPath);
} catch (Throwable $e) {
   if (file_exists($bl_signed_pdf)) {
      @unlink($bl_signed_pdf);
   }
   gestion_combined_message("Fusion des PDF impossible : " . $e->getMessage(), ERROR);
   Html::back();
   exit;
}

// Envoi mails (client/Zendoc)
// Envoi Zendoc (PDF fusionné)
$sharepoint = new PluginGestionSharepoint();

// Envoi mail client (PDF fusionne)
if ($combined_mail_mode === 0 && $client_mail_enabled == 1 && !empty($config->fields['MailTo']) && $client_email !== '' && $client_email !== 'vide') {
   $sharepoint->MailSend($client_email, $config->fields['gabarit'], $mergedPath, "Mail envoye a ". $client_email , $id_survey = NULL, $tracker = NULL, $webUrl = NULL, $fileName = NULL);
}

if (!empty($config->fields['ZenDocMail'])) {
   $doc_name = isset($_POST['DOC']) ? $_POST['DOC'] : '';
   $related  = $bl_row['relatedInvoiceToBL'] ?? '';
   $msg = "BL signé + Rapport d'intervention : $doc_name<br><br>Ticket ID : $ticket_id";
   if (!empty($related)) {
      $msg .= "<br><br>Documents/Informations associé au bon de livraison : $related";
   }
   $sharepoint->MailSend(
      $config->fields['ZenDocMail'],
      0,
      $mergedPath,
      "Envoyé vers ZenDoc",
      $id_survey = NULL,
      $tracker = NULL,
      $webUrl = NULL,
      $fileName = NULL,
      "BL + Rapport signés",
      $msg
   );
}

// Nettoyage des temporaires
if (file_exists($mergedPath)) {
   @unlink($mergedPath);
}
if (file_exists($bl_signed_pdf)) {
   @unlink($bl_signed_pdf);
}

gestion_combined_message("BL + Rapport signés et fusionnés.", INFO);
Html::back();
