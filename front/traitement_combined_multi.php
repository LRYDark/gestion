<?php
/*
 * Signature GROUPEE des BL coches d'un ticket => 1 PDF fusionne.
 *
 * Deux modes, une seule chaine de traitement (drapeau POST `bl_only`) :
 *   - defaut  : BL coches + UN rapport d'intervention  => [BL1, BL2, ..., rapport]
 *   - bl_only : BL coches SEULS                        => [BL1, BL2, ...]
 *
 * Commun aux deux : une seule signature client appliquee a chaque document, un
 * PDF fusionne archive selon la config (SharePoint/Local, range par annee/mois),
 * maile au client et a ZenDoc, et chaque BL coche marque signe pointant vers ce
 * PDF fusionne (doc_url).
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

/*
 * Signature différée : ne pas produire DEUX FOIS le même envoi.
 *
 * Ce fichier est un point d'ENTRÉE : il inclut le générateur du plugin RP, qui
 * se tait alors (marqueur `PLUGIN_RP_PDF_EMBEDDED`). La garde est donc posée
 * ici, une seule fois, pour tout le lot.
 *
 * Elle porte sur le LOT entier, pas sur chaque bon : c'est un seul envoi du
 * technicien, un seul PDF fusionné, et donc une seule chose à ne pas refaire.
 */
$gestion_offline_claim = null;
if (class_exists('PluginGestionOfflinequeue')) {
   $gestion_offline_claim = PluginGestionOfflinequeue::claim(
      (string)($_POST['sign_uid'] ?? ''),
      $ticket_id,
      (string)($_POST['sign_captured_at'] ?? '')
   );

   if (!$gestion_offline_claim['go']) {
      // 200 « déjà produit » : la file peut retirer la signature.
      // 409 « en cours » : une tentative travaille encore, l'issue est inconnue,
      // la file doit GARDER l'élément et repasser plus tard.
      $gestion_offline_done = ($gestion_offline_claim['state'] === 'done');
      http_response_code($gestion_offline_done ? 200 : 409);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode([
         'ok'      => $gestion_offline_done,
         'already' => true,
         'state'   => $gestion_offline_claim['state'],
      ], JSON_UNESCAPED_UNICODE);
      exit;
   }
}

/*
 * Deux parcours dans ce fichier, une seule chaine de traitement.
 *
 *  - defaut          : BL coches + UN rapport d'intervention => 1 PDF fusionne ;
 *  - `bl_only`       : BL coches SEULS => 1 PDF fusionne, sans rapport.
 *
 * Le second sert quand le rapport est deja signe — le regenerer n'apporterait
 * rien et en produirait un second — et quand le plugin RP est absent : Gestion
 * doit alors savoir signer plusieurs bons d'un coup, pas seulement un par un.
 *
 * Tout ce qui suit la signature (fusion, archivage date, mise a jour des
 * lignes, mails, nettoyage) est STRICTEMENT identique dans les deux cas : d'ou
 * un drapeau plutot qu'un second fichier, qui aurait divergé.
 */
$bl_only = !empty($_POST['bl_only']);

$plugin = new Plugin();
if (!$bl_only
    && (!$plugin->isInstalled('rp') || !$plugin->isActivated('rp') || !class_exists('PluginRpCri'))) {
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

// ---- 1. Generer le rapport RP une seule fois (sauf en mode BL seul) ----
$rp_pdf       = '';
$rp_doc_id    = 0;      // Document GLPI cree par le generateur RP
$rp_repointed = false;  // ce Document a-t-il ete remplace par le PDF fusionne ?
$SeeFilePath = '';
$saved_mailto = $_POST['mailtoclient'] ?? null;
$_POST['mailtoclient'] = 0; // jamais de mail depuis le rapport
if (!$bl_only) {
   try {
      $rp_dir = Plugin::getPhpDir('rp');
      ob_start();
      /*
       * Marqueur lu par le generateur : il produit alors le document sans decider
       * de l'afficher ni de rediriger, ces deux choix appartenant au parcours qui
       * l'appelle. Sans lui, le reglage « Affichage du PDF apres signature » du
       * plugin RP sur Non le ferait rediriger ici meme, coupant la signature des
       * bons avant qu'elle n'ait lieu.
       */
      $GLOBALS['PLUGIN_RP_PDF_EMBEDDED'] = true;
      include $rp_dir . '/front/cripdf.form.php';
      unset($GLOBALS['PLUGIN_RP_PDF_EMBEDDED']);
      ob_end_clean();
      $rp_pdf = $SeeFilePath ?? '';
      /*
       * Le generateur est INCLUS dans cette portee : `$NewDoc` y designe le
       * Document GLPI qu'il vient de creer pour le rapport. On le retient tout
       * de suite, avant que la suite du fichier ne reutilise ce nom — c'est lui
       * qu'il faudra faire pointer vers le PDF fusionne.
       */
      $rp_doc_id = (int)($NewDoc ?? 0);
   } catch (Throwable $e) {
      unset($GLOBALS['PLUGIN_RP_PDF_EMBEDDED']);
      $rp_pdf = '';
   }
}
$_POST['mailtoclient'] = $saved_mailto;

/*
 * Le rapport est INCLUS, pas appele : il s'execute dans cette portee et y
 * reaffecte `$config` avec la configuration du plugin RP. Plus bas,
 * `$config->SharePointOn()` n'existe pas sur cet objet — erreur fatale — et les
 * envois de mail lisaient MailTo et ZenDocMail sur des champs absents : le
 * client ne recevait jamais ses documents signes.
 *
 * `getInstance()` est idempotent : on reprend simplement la bonne.
 */
$config = PluginGestionConfig::getInstance();

/*
 * BL seul : le rapport DEJA signe est joint, sans en produire un nouveau.
 *
 * Ce parcours ne s'ouvre que lorsqu'un rapport signe existe et que le ticket
 * n'a pas bouge depuis (`defaultCombinedMode`) : le client repart donc avec un
 * document complet plutot qu'avec deux moities a rapprocher.
 *
 * Ce sont les PAGES du document archive qui sont recopiees. Rien n'est
 * regenere, rien n'est resigne, et le Document GLPI d'origine reste intact sur
 * le ticket — d'ou une variable distincte de `$rp_pdf` : ce dernier designe un
 * fichier temporaire que le nettoyage final a le droit de supprimer, celui-ci
 * jamais.
 */
$archived_report_pdf = '';
if ($bl_only) {
   $archived_report = PluginGestionCri::reportToMergeOnBlOnly($ticket_id);
   if ($archived_report !== null) {
      $archived_report_pdf = $archived_report['path'];
      /*
       * MEME variable que le rapport fraichement genere, volontairement.
       *
       * Des lors qu'il est fusionne, ce Document doit subir exactement le meme
       * sort : les deux plugins pointent vers le PDF fusionne (etape 5). Sans
       * cela, le tableau « Rapport PDF » du plugin RP continuerait de designer
       * un document qui ne contient pas les bons — et, une fois l'ancien
       * fichier remplace, ouvrirait un fichier absent.
       */
      $rp_doc_id = (int)$archived_report['id'];
   }
}

if (!$bl_only && (empty($rp_pdf) || !file_exists($rp_pdf))) {
   @unlink($signaturePath);
   gestion_multi_message("Generation du rapport impossible.", ERROR);
   Html::back();
   exit;
}

// ---- 2. Signer chaque BL (photos/PDF joint sur le 1er seulement) ----
$signed_bl_pdfs  = [];
$failed_bls      = [];   // id => nom : aucun document n'a pu etre produit
$substituted_bls = [];   // noms : PDF d'origine introuvable, page de remplacement signee
$first = true;
foreach ($valid_bls as $DOC) {
   $meta   = [];
   $signed = pluginGestionRenderSignedBl(
      $DOC,
      $signaturePath,
      $client_name,
      $tech_name,
      $first ? $photoPaths : [],
      $first ? $attachedPdfPath : null,
      ['counter_invoice' => $counter_invoice, 'comment' => trim((string)($_POST['comment'] ?? ''))],
      $meta
   );
   if ($signed && file_exists($signed)) {
      $signed_bl_pdfs[(int)$DOC->id] = $signed;
      if (!empty($meta['substituted'])) {
         $substituted_bls[] = (string)$DOC->bl;
      }
      // Photos et PDF joint vont au premier bon RENDU, quel qu'il soit.
      $first = false;
   } else {
      // Le detail est journalise par pluginGestionRenderSignedBl ; on retient
      // ici le bon, pour l'exclure du marquage et le nommer a l'ecran.
      $failed_bls[(int)$DOC->id] = (string)$DOC->bl;
   }
}

/*
 * Le rapport a joindre vient soit du generateur (mode « Rapport + BL »), soit
 * de l'archive (mode « BL seul »). Une seule variable ensuite : la fusion, le
 * nom du fichier et les libelles n'ont pas a savoir d'ou il sort.
 */
$merged_report_pdf = $bl_only ? $archived_report_pdf : $rp_pdf;
$report_merged     = (!empty($merged_report_pdf) && file_exists($merged_report_pdf));

/*
 * Un PDF d'origine introuvable n'est plus un echec : une page de remplacement
 * a ete signee a sa place (cf. pluginGestionRenderSignedBl). On n'arrive donc
 * ici que si AUCUN document n'a pu etre produit — PDF illisible, page de
 * remplacement impossible — auquel cas il n'y a rien a fusionner.
 */
if (empty($signed_bl_pdfs)) {
   @unlink($signaturePath);
   if ($rp_repointed && !empty($rp_pdf) && file_exists($rp_pdf)) { @unlink($rp_pdf); }
   gestion_multi_message(
      "Signature impossible : aucun document n'a pu etre produit pour "
      . implode(', ', $failed_bls)
      . ". Voir le journal plugin-gestion.log.",
      ERROR
   );
   Html::back();
   exit;
}

if (!empty($failed_bls)) {
   // Echec PARTIEL : ces bons restent non signes (exclus du marquage, etape 6).
   gestion_multi_message(
      "Aucun document n'a pu etre produit, ces bons n'ont pas ete signes : " . implode(', ', $failed_bls),
      WARNING
   );
}

if (!empty($substituted_bls)) {
   // Le lot continue ; le dire, pour que la page de remplacement ne passe pas
   // pour le vrai bon.
   gestion_multi_message(
      "PDF d'origine introuvable, une page de remplacement (numero, nom, signature) a ete signee pour : "
      . implode(', ', $substituted_bls),
      WARNING
   );
}

// ---- 3. Fusion : tous les BL signes (+ le rapport, selon le mode) => 1 PDF ----
/*
 * `$report_merged` decrit ce qui est REELLEMENT dans le PDF produit, la ou
 * `$bl_only` ne dit que ce qui avait ete demande. Les libelles, le nom du
 * fichier et les mails s'appuient desormais dessus : un document annonce
 * « BL seuls » alors qu'il porte le rapport — ou l'inverse — est un document
 * qui ment sur son contenu, et c'est ce que le technicien lit avant de
 * l'envoyer au client.
 */
$merged_prefix = $report_merged ? 'BL_Rapport_T' : 'BL_T';
$mergedPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/" . $merged_prefix . $ticket_id . "_" . date('Ymd_His') . ".pdf";
try {
   $pdf = new Fpdi();
   $sources = array_values($signed_bl_pdfs);
   if ($report_merged) {
      $sources[] = $merged_report_pdf;
   }
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
   if ($rp_repointed && !empty($rp_pdf) && file_exists($rp_pdf)) { @unlink($rp_pdf); }
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
$mergedName = $merged_prefix . $ticket_id . "_" . date('Ymd_His') . ".pdf";
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

      /*
       * Creation recursive du dossier <Entite>/<annee>/<mois>, qui n'existe pas
       * au premier document du mois — et echec ANNONCE. Sans dossier, la copie
       * echoue ; le Document GLPI cree juste apres pointerait alors vers un
       * fichier absent, ce qui produit le « Fichier introuvable sur le disque »
       * des listes. On leve donc l'erreur ici, avant d'enregistrer quoi que ce
       * soit, plutot que de laisser une trace muette derriere soi.
       */
      if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
         throw new RuntimeException("Impossible de créer le dossier d'archivage : $destDir");
      }
      $destPath = $destDir . '/' . $mergedName;
      if (!@copy($mergedPath, $destPath)) {
         throw new RuntimeException("Impossible d'écrire le PDF fusionné dans : $destPath");
      }
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
      if ($merged_doc_id) {
         // GLPI 11 blackliste filepath/sha1sum dans Document::add => reecriture directe.
         pluginGestionFixDocumentFile($merged_doc_id, $merged_url_bl . $mergedName);
      }
      $merged_doc_url = $merged_doc_id ? ("document.send.php?docid=" . $merged_doc_id) : '';
   }
} catch (Throwable $e) {
   gestion_multi_message("Archivage du PDF fusionne en erreur : " . $e->getMessage(), WARNING);
}

/*
 * Archivage local rate : on ARRETE avant de marquer quoi que ce soit.
 *
 * Marquer les bons signes en les faisant pointer vers un document inexistant
 * les rendrait insignables — `signed = 1` les retire de toutes les listes — au
 * profit d'un PDF que personne ne pourra jamais ouvrir. Le technicien perd sa
 * signature, mais rien n'est casse : il recommence une fois le dossier
 * accessible. SharePoint n'entre pas dans ce controle : son echec est deja
 * signale et ne produit pas de Document local a verifier.
 */
if ($FolderDes !== 'SharePoint' && $merged_doc_id <= 0) {
   @unlink($signaturePath);
   foreach ($signed_bl_pdfs as $p) { if (file_exists($p)) { @unlink($p); } }
   if ($rp_repointed && !empty($rp_pdf) && file_exists($rp_pdf)) { @unlink($rp_pdf); }
   gestion_multi_message(
      "Archivage impossible : aucun bon n'a ete marque signe. Verifiez les droits d'ecriture du dossier de destination.",
      ERROR
   );
   Html::back();
   exit;
}

/*
 * ---- 5 bis. Le rapport RP pointe vers le MEME document que les bons ----
 *
 * Le rapport et les bons ne font plus qu'un PDF : il n'y a plus qu'un document
 * a montrer, et les deux plugins doivent designer celui-la. Le plugin RP
 * continuait de pointer vers son propre PDF de rapport — que ce fichier-ci
 * supprime apres la fusion. Resultat : « Visualiser » depuis le tableau
 * « Rapport PDF » de RP ouvrait un fichier absent (404 sur document.send.php),
 * alors que « Voir » depuis l'onglet Gestion fonctionnait.
 *
 * Le Document RP devenu inutile est purge : son fichier vient d'etre fusionne
 * dans un autre, le garder ne laisserait qu'une ligne pointant dans le vide.
 *
 * Sans document fusionne local — destination SharePoint — on ne repointe rien :
 * RP garde son propre PDF, qui n'est alors pas supprime (cf. nettoyage final).
 * Chaque plugin reste ainsi autonome quand la fusion ne produit pas de document
 * GLPI a partager.
 */
/*
 * Les DEUX modes passent par ici — rapport fraichement genere comme rapport
 * deja archive : des lors que le PDF fusionne porte le rapport, il est le seul
 * document a montrer, et les deux plugins doivent designer celui-la.
 *
 * `$report_merged` en decide seul : le reglage « Joindre le rapport signe » sur
 * Non le laisse a faux, rien n'est alors fusionne et rien n'est repointe — les
 * deux plugins gardent chacun leur document, exactement comme avant.
 */
if ($report_merged && $rp_doc_id > 0 && $merged_doc_id > 0
    && $DB->tableExists('glpi_plugin_rp_cridetails')) {

   $DB->update(
      'glpi_plugin_rp_cridetails',
      ['id_documents' => $merged_doc_id],
      ['id_ticket' => $ticket_id, 'id_documents' => $rp_doc_id]
   );

   /*
    * Purge SEULEMENT si plus rien d'autre ne s'appuie sur ce document.
    *
    * Mode « Rapport + BL » : il vient d'etre cree, rien ne le reference, la
    * purge a lieu comme avant — `pluginRpDocumentSharedWithBl()` repond non.
    *
    * Mode « BL seul » : il a des jours. Un bon signe plus tot peut deja pointer
    * dessus (`glpi_plugin_gestion_surveys.doc_id`), et le supprimer casserait
    * SON lien — un « Voir » sur un bon signe le mois dernier ouvrirait un
    * fichier absent. On se contente alors de repointer : RP designe le document
    * complet, et l'ancien reste ouvrable pour qui le reference encore.
    *
    * Meme fonction que celle dont RP se sert avant d'ecraser un document
    * (front/cripdf.form.php) : la question « ce PDF appartient-il aussi a des
    * bons ? » ne doit avoir qu'une seule reponse dans les deux plugins.
    */
   $rp_doc_still_used = function_exists('pluginRpDocumentSharedWithBl')
                     && pluginRpDocumentSharedWithBl($rp_doc_id);

   if ($rp_doc_id !== $merged_doc_id && !$rp_doc_still_used) {
      $rp_doc = new Document();
      if ($rp_doc->getFromDB($rp_doc_id)) {
         // 2e argument : purge — le fichier part avec la ligne.
         $rp_doc->delete(['id' => $rp_doc_id], 1);
      }
   }

   $rp_repointed = true;
}

// ---- 6. Marquer chaque BL signe (pointant vers le PDF fusionne) ----
$now = date('Y-m-d H:i:s');
$rawComment = trim($_POST['comment'] ?? '');
foreach ($valid_bls as $DOC) {
   // Aucun document produit pour ce bon : il reste non signe, a refaire.
   if (isset($failed_bls[(int)$DOC->id])) {
      continue;
   }
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
   $bl_list = implode(', ', array_map(fn($d) => $d->bl, array_filter($valid_bls, fn($d) => !isset($failed_bls[(int)$d->id]))));
   $msg = ($report_merged ? "BL signes + Rapport d'intervention." : "BL signes.") . "<br><br>Ticket ID : $ticket_id<br><br>BL : $bl_list";
   if (!empty($substituted_bls)) {
      $msg .= "<br><br>PDF d'origine introuvable, page de remplacement signee pour : " . implode(', ', $substituted_bls);
   }
   $sharepoint->MailSend($config->fields['ZenDocMail'], 0, $mergedPath, "Envoye vers ZenDoc", null, null, null, null, ($report_merged ? "BL + Rapport signes" : "BL signes"), $msg);
}

// ---- 8. Nettoyage ----
@unlink($signaturePath);
foreach ($signed_bl_pdfs as $p) { if (file_exists($p)) { @unlink($p); } }
if ($rp_repointed && !empty($rp_pdf) && file_exists($rp_pdf)) { @unlink($rp_pdf); }
if ($attachedPdfPath && file_exists($attachedPdfPath)) { @unlink($attachedPdfPath); }
gestion_multi_message(count($signed_bl_pdfs) . ($report_merged ? " BL + Rapport signes et fusionnes." : " BL signes et fusionnes."), INFO);

/*
 * Le travail est terminé : la signature ne repartira plus.
 *
 * Posé ICI, avant l'affichage et avant tout `exit` : tous les bons du lot sont
 * signés, fusionnés et enregistrés. Une ligne marquée « faite » alors qu'il
 * resterait du travail empêcherait le rejeu de le finir, et le client n'aurait
 * jamais ses documents.
 */
if (!empty($gestion_offline_claim) && $gestion_offline_claim['go']
    && class_exists('PluginGestionOfflinequeue')) {
   PluginGestionOfflinequeue::complete((string)($_POST['sign_uid'] ?? ''));
}

/*
 * Affichage du document produit, comme les autres signatures.
 *
 * C'est le PDF FUSIONNE que l'on montre : il porte tous les bons signes et le
 * rapport, la seule vue complete de ce qui vient d'etre signe. Le formulaire
 * porte `target="_blank"` dans ce cas, la page du ticket reste donc vivante.
 *
 * Le fichier n'est supprime qu'ici, apres envoi : le nettoyage ci-dessus l'a
 * volontairement laisse de cote.
 */
if ((int)($config->fields['DisplayPdfEnd'] ?? 0) === 1
    && file_exists($mergedPath)
    && !headers_sent()) {

   header('Content-Type: application/pdf');
   // Le nom annonce ce que le fichier contient reellement, comme son nom d'archive.
   header('Content-Disposition: inline; filename="' . ($report_merged ? 'BL_Rapport.pdf' : 'BL.pdf') . '"');
   header('Content-Length: ' . filesize($mergedPath));
   readfile($mergedPath);
   @unlink($mergedPath);
   exit;
}

if (file_exists($mergedPath)) { @unlink($mergedPath); }

Html::back();
