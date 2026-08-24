<?php
include('../../../inc/includes.php');
require_once(__DIR__ . '/../vendor/autoload.php');

require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

global $DB;

Session::checkLoginUser();

function gestion_combined_message($msg, $type = INFO) {
   if (class_exists('PluginGestionLogger')) {
      if ($type === ERROR) {
         PluginGestionLogger::error('signature-combinee', $msg);
      } elseif ($type === WARNING) {
         PluginGestionLogger::warning('signature-combinee', $msg);
      } else {
         PluginGestionLogger::info('signature-combinee', $msg);
      }
   }
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
$rp_pdf    = '';
$rp_doc_id = 0;   // Document GLPI cree par le generateur RP
$SeeFilePath = '';
try {
   $rp_dir = Plugin::getPhpDir('rp');
   ob_start();
   /*
    * Marqueur lu par le generateur : il produit alors le document sans decider
    * de l'afficher ni de rediriger, ces deux choix appartenant au parcours qui
    * l'appelle. Sans lui, le reglage « Affichage du PDF apres signature » du
    * plugin RP sur Non le ferait rediriger ici meme, coupant la signature du BL
    * avant qu'elle n'ait lieu.
    */
   $GLOBALS['PLUGIN_RP_PDF_EMBEDDED'] = true;
   include $rp_dir . '/front/cripdf.form.php';
   unset($GLOBALS['PLUGIN_RP_PDF_EMBEDDED']);
   ob_end_clean();
   $rp_pdf = $SeeFilePath ?? '';
   /*
    * Le generateur est INCLUS dans cette portee : `$NewDoc` y designe le
    * Document GLPI qu'il vient de creer pour le rapport. On le retient tout de
    * suite — la signature du bon, incluse plus bas, reutilise ce meme nom pour
    * le sien.
    */
   $rp_doc_id = (int)($NewDoc ?? 0);
} catch (Throwable $e) {
   unset($GLOBALS['PLUGIN_RP_PDF_EMBEDDED']);
   $rp_pdf = '';
}

/*
 * Le rapport est INCLUS, pas appele : il s'execute dans cette portee et y
 * reaffecte `$config` avec la configuration du plugin RP. Les envois de mail
 * plus bas lisaient donc MailTo, gabarit et ZenDocMail sur le mauvais objet —
 * champs absents, donc conditions toujours fausses : le client ne recevait
 * jamais ses documents signes, sans la moindre erreur.
 *
 * `getInstance()` est idempotent : on reprend simplement la bonne.
 */
$config = PluginGestionConfig::getInstance();

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

/*
 * ---- Un seul document pour le bon ET le rapport ----
 *
 * Ce qui vient d'être signé est UN document : le bon de livraison suivi du
 * rapport d'intervention. Les deux plugins doivent donc montrer celui-là.
 *
 * Jusqu'ici chacun archivait sa moitié — Gestion le bon seul, RP le rapport
 * seul — et le PDF fusionné, celui que le client reçoit par mail, finissait à
 * la corbeille. « Voir » et « Visualiser » ouvraient deux documents différents,
 * dont aucun ne montrait ce qui avait réellement été signé.
 *
 * On écrit donc le fusionné À LA PLACE du fichier du bon : son Document, son
 * lien et son numéro ne bougent pas, seul son contenu devient complet. La ligne
 * du rapport bascule sur ce même Document, et celui du rapport seul est purgé —
 * son fichier part avec lui, il ne sert plus à rien.
 *
 * Rien de tout cela si l'archivage n'a pas produit de Document local
 * (destination SharePoint) ou si la copie échoue : chaque plugin garde alors le
 * sien, exactement comme avant. Les mails, eux, ne changent pas : ils partent
 * du fusionné temporaire, selon le réglage « Mode d'envoi mail au client ».
 */
if ($rp_doc_id > 0 && file_exists($mergedPath) && filesize($mergedPath) > 0) {

   // La ligne a ete mise a jour par la signature du bon : on relit son Document.
   $signed_row = $DB->request([
      'SELECT' => ['doc_id'],
      'FROM'   => 'glpi_plugin_gestion_surveys',
      'WHERE'  => ['id' => $bl_id],
      'LIMIT'  => 1,
   ])->current();
   $bl_doc_id = (int)($signed_row['doc_id'] ?? 0);

   $rel_path = '';
   $doc_name = '';
   if ($bl_doc_id > 0) {
      $doc_row  = $DB->request([
         'SELECT' => ['name', 'filepath'],
         'FROM'   => 'glpi_documents',
         'WHERE'  => ['id' => $bl_doc_id],
         'LIMIT'  => 1,
      ])->current();
      $rel_path = ltrim(str_replace('\\', '/', (string)($doc_row['filepath'] ?? '')), '/');
      $doc_name = trim((string)($doc_row['name'] ?? ''));
   }
   $abs_path = $rel_path !== '' ? GLPI_DOC_DIR . '/' . $rel_path : '';

   if ($abs_path !== '' && is_file($abs_path) && @copy($mergedPath, $abs_path)) {
      // Le contenu a change : l'empreinte doit suivre, sinon GLPI garde celle
      // du bon seul et toute verification d'integrite echouerait.
      pluginGestionFixDocumentFile($bl_doc_id, $rel_path);

      // Le nom annonce ce que le document contient desormais. Seul l'intitule
      // change : le fichier telecharge garde le nom du bon, sur lequel d'autres
      // traitements s'appuient.
      if ($doc_name !== '' && stripos($doc_name, 'rapport') === false) {
         $DB->update('glpi_documents', ['name' => $doc_name . ' + Rapport'], ['id' => $bl_doc_id]);
      }

      if ($DB->tableExists('glpi_plugin_rp_cridetails')) {
         $DB->update(
            'glpi_plugin_rp_cridetails',
            ['id_documents' => $bl_doc_id],
            ['id_ticket' => $ticket_id, 'id_documents' => $rp_doc_id]
         );

         if ($rp_doc_id !== $bl_doc_id) {
            $rp_doc = new Document();
            if ($rp_doc->getFromDB($rp_doc_id)) {
               // 2e argument : purge — le fichier part avec la ligne.
               $rp_doc->delete(['id' => $rp_doc_id], 1);
            }
         }
      }
   }
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

gestion_combined_message("BL + Rapport signés et fusionnés.", INFO);

/*
 * Affichage du document produit, comme le fait la signature d'un BL seul et
 * celle d'un rapport seul.
 *
 * C'est le PDF FUSIONNÉ que l'on montre : celui du BL et celui du rapport ont
 * été produits dans des tampons refermés, et ne représentent chacun qu'une
 * moitié de ce qui vient d'être signé.
 *
 * Le formulaire porte `target="_blank"` dans ce cas : le document s'ouvre à
 * côté, la page du ticket reste vivante, et le message ci-dessus s'y affiche au
 * rechargement.
 */
if ((int)($config->fields['DisplayPdfEnd'] ?? 0) === 1
    && file_exists($mergedPath)
    && !headers_sent()) {

   $download_name = trim((string)($_POST['DOC'] ?? 'BL_Rapport'));
   if (!str_ends_with(strtolower($download_name), '.pdf')) {
      $download_name .= '.pdf';
   }

   header('Content-Type: application/pdf');
   header('Content-Disposition: inline; filename="' . str_replace('"', '', $download_name) . '"');
   header('Content-Length: ' . filesize($mergedPath));
   readfile($mergedPath);

   // Nettoyage identique au parcours sans affichage : le fichier a été envoyé,
   // il n'a plus de raison d'occuper le disque.
   @unlink($mergedPath);
   if (file_exists($bl_signed_pdf)) {
      @unlink($bl_signed_pdf);
   }
   exit;
}

// Nettoyage des temporaires
if (file_exists($mergedPath)) {
   @unlink($mergedPath);
}
if (file_exists($bl_signed_pdf)) {
   @unlink($bl_signed_pdf);
}

Html::back();
