<?php
// plugins/gestion/ajax/quick_add_survey.php
// Crée rapidement une entrée glpi_plugin_gestion_surveys depuis la tablette (mode kiosque)
// en validant device_id/token, sans créer de demande de signature distante.

if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', realpath(__DIR__ . '/../../..'));
}
define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
header('Content-Type: application/json; charset=UTF-8');

global $DB, $CFG_GLPI;
$config  = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

function q_json_end(int $status, array $payload): void {
   if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

if (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1) {
   q_json_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

// Paramètres requis
$device_id = trim((string)($_POST['device_id'] ?? ''));
$token     = trim((string)($_POST['token'] ?? ''));
$save      = trim((string)($_POST['save'] ?? ''));      // 'Sage' | 'SharePoint' | 'Local'
$filename  = trim((string)($_POST['filename'] ?? ''));
$folder    = trim((string)($_POST['folder'] ?? ''));
$signed    = (int)($_POST['signed'] ?? 0);

if ($device_id === '' || $token === '' || $save === '' || $filename === '' || $folder === '') {
   q_json_end(422, ['ok' => false, 'error' => 'missing_fields']);
}

// Vérifier la tablette active
$did = $DB->escape($device_id);
$tok = $DB->escape($token);
$sql_dev = "SELECT id FROM `glpi_plugin_gestion_signaturedevices` WHERE `is_active` = 1 AND `device_id` = '$did' AND `device_token` = '$tok' LIMIT 1";
$res_dev = $DB->doQuery($sql_dev);
if (!$res_dev || $DB->numrows($res_dev) !== 1) {
   q_json_end(403, ['ok' => false, 'error' => 'invalid_device']);
}

// Préparation des champs comme dans survey.form.php
$tickets_id  = 0;
$entities_id = 0;
$tracker     = null;
$doc_id      = 0; // Pas d'enregistrement glpi_documents en mode rapide
$url_bl      = '';
$doc_url     = '';
$pdf_filename = $filename;

if ($save === 'Sage') {
   // Pour Sage, folder = docId (ex: BL199550)
   $url_bl = $DB->escape($folder);

   // Essayer d'extraire des infos pour aligner le nom BL comme survey.form.php
   try {
      require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';
      if (function_exists('parseDocument')) {
         $fields = parseDocument($folder);
         // Construire le nom BL exactement comme dans survey.form.php :
         //   BLID_CLIENT  (avec espaces client -> underscores)
         $base = preg_replace('/\.pdf$/i','', $folder);
         if (!empty($fields['client'])) {
            $pdf_filename = $base . '_' . str_replace(' ', '_', $fields['client']);
         } else {
            // Si pas de client détecté, on garde l'identifiant BL seul
            $pdf_filename = $base;
         }
         if (!empty($fields['tracker'])) {
            $tracker = $fields['tracker'];
         }
      }
   } catch (Throwable $e) {
      // Fallback en cas d'erreur: identifiant BL seul
      $pdf_filename = preg_replace('/\.pdf$/i','', $folder);
   }

   // URL d'aperçu via view_pdf.php avec token temporaire
   $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
   $base  = $proto . $_SERVER['SERVER_NAME'] . PLUGIN_GESTION_WEBDIR;
   $doc_id_for_token = $folder;
   $secret_key = 'GLPI_PDF_SECRET_2024';
   $today = date('Y-m-d');
   $temp_token = hash('sha256', $doc_id_for_token . $today . $secret_key);
   $doc_url = $base . "/ajax/view_pdf.php?id=" . rawurlencode($folder) . "&token=" . $temp_token;

} else if ($save === 'Local') {
   // folder = chemin commençant par _plugins/... et filename = fichier
   $url_bl = $DB->escape($folder);
   // Aperçu en lecture directe depuis /files
   $web_rel = str_replace('_plugins', '', $folder . $filename);
   $doc_url = $rootdoc . "/files" . $web_rel;

} else if ($save === 'SharePoint') {
   $url_bl = $DB->escape($folder);
   $doc_url = $folder;
} else {
   q_json_end(400, ['ok' => false, 'error' => 'unknown_source']);
}

// Déduplication par BL
$bl_esc = $DB->escape($pdf_filename);
$check = $DB->doQuery("SELECT id, doc_url, signed FROM `glpi_plugin_gestion_surveys` WHERE bl = '$bl_esc' LIMIT 1");
if ($check && $DB->numrows($check) === 1) {
   $row = $DB->fetchassoc($check);
   $preview = $row['doc_url'] ?? '';
   if ($preview && strpos($preview, 'document.send.php') !== false) {
      $preview = rtrim($rootdoc, '/') . '/front/' . ltrim($preview, '/');
   }
   $already = (int)($row['signed'] ?? 0) === 1;
   q_json_end(200, [
      'ok' => true,
      'exists' => true,
      'already_signed' => $already,
      'id' => (int)$row['id'],
      'preview_url' => $preview
   ]);
}

// Insertion identique à survey.form.php
$sql = "INSERT INTO `glpi_plugin_gestion_surveys` (`tickets_id`, `entities_id`, `tracker`, `url_bl`, `bl`, `signed`, `doc_id`, `doc_url`, `save`)
        VALUES (" . (int)$tickets_id . ",
                " . (int)$entities_id . ",
                " . (is_null($tracker) ? 'NULL' : ("'" . $DB->escape($tracker) . "'")) . ",
                '" . $DB->escape($url_bl) . "',
                '" . $bl_esc . "',
                " . (int)$signed . ",
                0,
                '" . $DB->escape($doc_url) . "',
                '" . $DB->escape($save) . "')";

if (!$DB->doQuery($sql)) {
   q_json_end(500, ['ok' => false, 'error' => 'db_insert_failed', 'db_error' => $DB->error()]);
}

$id_res = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$bl_esc' LIMIT 1");
if ($id_res && $DB->numrows($id_res) === 1) {
   $row = $DB->fetchassoc($id_res);
   q_json_end(200, ['ok' => true, 'id' => (int)$row['id'], 'preview_url' => $doc_url, 'save' => $save, 'bl' => $pdf_filename]);
}

q_json_end(500, ['ok' => false, 'error' => 'db_select_failed']);
