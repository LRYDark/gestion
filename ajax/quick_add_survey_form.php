<?php
// plugins/gestion/ajax/quick_add_survey_form.php
// Create a survey entry from the BL quick-selection modal (logged-in users only).

include('../../../inc/includes.php');
header('Content-Type: application/json; charset=UTF-8');

require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

global $DB, $CFG_GLPI;

function json_end(int $status, array $payload): void {
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

try {
   Session::checkLoginUser();

   if (!Session::haveRightsOr('plugin_gestion_survey', [CREATE, UPDATE])) {
      json_end(403, ['ok' => false, 'error' => 'access_denied']);
   }

   $save       = trim((string)($_POST['save'] ?? ''));
   $filename   = trim((string)($_POST['filename'] ?? ''));
   $folder     = trim((string)($_POST['folder'] ?? ''));
   $signed     = (int)($_POST['signed'] ?? 0);
   $search_pdf = trim((string)($_POST['search_pdf'] ?? ''));

   $tickets_id  = isset($_POST['tickets_id']) ? (int)$_POST['tickets_id'] : 0;
   $entities_id = isset($_POST['entities_id']) ? (int)$_POST['entities_id'] : 0;

   if ($save === '' || $filename === '' || $folder === '') {
      json_end(422, ['ok' => false, 'error' => 'missing_fields']);
   }

   if ($signed === 1) {
      json_end(409, ['ok' => false, 'error' => 'Ce BL est déjà signé.']);
   }

   $doc = new Document();
   $config = new PluginGestionConfig();

   $pdf_filename = $filename;
   $pdf_folder   = $folder;
   $tracker      = null;
   $relatedInvoiceToBL = null;
   $doc_url      = '';
   $NewDoc       = 0;

   if ($save === 'Sage') {
      $searchKey = $search_pdf !== '' ? $search_pdf : $folder;
      if ($searchKey === '') {
         $searchKey = preg_replace('/\.pdf$/i', '', $filename);
      }

      try {
         $fields = parseDocument($searchKey);
         $base = preg_replace('/\.pdf$/i', '', $searchKey);
         if (!empty($fields['client'])) {
            $pdf_filename = $base . '_' . str_replace(' ', '_', $fields['client']);
         } else {
            $pdf_filename = $base;
         }
         $tracker = $fields['tracker'] ?? null;
         $relatedInvoiceToBL = $fields['relatedInvoiceToBL'] ?? null;
      } catch (Throwable $e) {
         $pdf_filename = preg_replace('/\.pdf$/i', '', $searchKey);
      }

      $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
      $host  = $_SERVER['SERVER_NAME'] ?? 'localhost';
      $doc_url = $proto . $host . PLUGIN_GESTION_WEBDIR . '/view_pdf.php?id=' . rawurlencode($searchKey);
   } else if ($save === 'SharePoint') {
      $doc_url = $pdf_folder;
      $tracker = null;
   } else if ($save === 'Local') {
      $input = [
         'name'        => addslashes(str_replace("?", chr(176), $pdf_filename)),
         'filename'    => addslashes($pdf_filename),
         'filepath'    => addslashes($pdf_folder . $pdf_filename),
         'mime'        => 'application/pdf',
         'users_id'    => Session::getLoginUserID(),
         'entities_id' => 0,
         'tickets_id'  => 0,
         'is_recursive'=> 1
      ];

      $NewDoc = $doc->add($input);
      if (!$NewDoc) {
         json_end(500, ['ok' => false, 'error' => 'document_add_failed']);
      }
      $doc_url = 'document.send.php?docid=' . $NewDoc;
   } else {
      json_end(400, ['ok' => false, 'error' => 'unknown_source']);
   }

   $bl_esc = $DB->escape($pdf_filename);
   $check = $DB->doQuery("SELECT id, signed, tickets_id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$bl_esc' LIMIT 1");
   if ($check && $DB->numrows($check) === 1) {
      $row = $DB->fetchassoc($check);
      $already = (int)($row['signed'] ?? 0) === 1;
      if ($already) {
         json_end(409, ['ok' => false, 'error' => 'Ce BL est déjà signé.']);
      }
      json_end(200, [
         'ok' => true,
         'exists' => true,
         'id' => (int)$row['id'],
         'tickets_id' => (int)($row['tickets_id'] ?? 0),
         'bl' => $pdf_filename
      ]);
   }

   $doc_date_sql = ($signed === 1) ? "NOW()" : "NULL";
   $relatedInvoiceSql = ($relatedInvoiceToBL !== null && $relatedInvoiceToBL !== '') ? "'" . $DB->escape($relatedInvoiceToBL) . "'" : "NULL";

   $sql = "INSERT INTO `glpi_plugin_gestion_surveys`
           (`tickets_id`, `entities_id`, `tracker`, `relatedInvoiceToBL`, `url_bl`, `bl`, `signed`, `doc_id`, `doc_url`, `save`, `date_creation`, `doc_date`)
           VALUES (" . (int)$tickets_id . ",
                   " . (int)$entities_id . ",
                   " . (is_null($tracker) ? 'NULL' : ("'" . $DB->escape($tracker) . "'")) . ",
                   " . $relatedInvoiceSql . ",
                   '" . $DB->escape($pdf_folder) . "',
                   '" . $bl_esc . "',
                   0,
                   " . (int)$NewDoc . ",
                   '" . $DB->escape($doc_url) . "',
                   '" . $DB->escape($save) . "',
                   NOW(),
                   $doc_date_sql)";

   if (!$DB->doQuery($sql)) {
      json_end(500, ['ok' => false, 'error' => 'db_insert_failed', 'db_error' => $DB->error()]);
   }

   $id_res = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$bl_esc' LIMIT 1");
   if ($id_res && $DB->numrows($id_res) === 1) {
      $row = $DB->fetchassoc($id_res);
      json_end(200, [
         'ok' => true,
         'id' => (int)$row['id'],
         'tickets_id' => (int)$tickets_id,
         'bl' => $pdf_filename
      ]);
   }

   json_end(500, ['ok' => false, 'error' => 'db_select_failed']);
} catch (Throwable $e) {
   json_end(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
