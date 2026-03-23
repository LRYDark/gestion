<?php
// ============ CORS Headers ============
$_cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_cors_allowed = getenv('GLPI_API_CORS_ORIGIN') ?: '*';
if ($_cors_allowed === '*' || $_cors_origin === $_cors_allowed) {
    header('Access-Control-Allow-Origin: ' . ($_cors_allowed === '*' ? '*' : $_cors_origin));
} else {
    header('Access-Control-Allow-Origin: ' . $_cors_allowed);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, App-Token, Session-Token, User-Token, X-App-Token, Glpi-Entity, Glpi-Entity-Recursive, Glpi-Profile');
header('Content-Type: application/json; charset=UTF-8');

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';
require_once PLUGIN_GESTION_DIR . '/inc/apiauth.class.php';
require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

function api_prepare_end(int $status, array $payload): void
{
   if (!headers_sent()) {
      header('Content-Type: application/json; charset=UTF-8');
   }
   http_response_code($status);
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

function api_prepare_bool(mixed $value): bool
{
   if (is_bool($value)) {
      return $value;
   }
   $txt = strtolower(trim((string)$value));
   return in_array($txt, ['1', 'true', 'yes', 'on'], true);
}

function api_prepare_input(): array
{
   $input = array_merge($_GET, $_POST);
   $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
   if (str_contains($content_type, 'application/json')) {
      $raw = PluginGestionApiAuth::getRawInputBody();
      if ($raw !== '') {
         $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
         $json = json_decode($raw, true);
         if (is_array($json)) {
            $input = array_merge($input, $json);
         }
      }
   }
   return $input;
}

function api_prepare_preview_url(string $doc_id, string $rootdoc): string
{
   $secret = defined('GLPI_PDF_PREVIEW_SECRET')
       ? GLPI_PDF_PREVIEW_SECRET
       : (getenv('GLPI_PDF_PREVIEW_SECRET') ?: hash('sha256', realpath(__DIR__ . '/../..') . 'gestion_pdf_preview'));
   $token = hash('sha256', $doc_id . date('Y-m-d') . $secret);
   return rtrim($rootdoc, '/') . '/plugins/gestion/view_pdf.php?id=' . rawurlencode($doc_id) . '&token=' . $token;
}

function api_prepare_preview_from_row(array $row, string $rootdoc): string
{
   $save = strtolower((string)($row['save'] ?? ''));
   if ($save === 'sage' && !empty($row['url_bl'])) {
      return api_prepare_preview_url((string)$row['url_bl'], $rootdoc);
   }

   $preview = trim((string)($row['doc_url'] ?? ''));
   if ($preview === '') {
      return '';
   }

   if (str_contains($preview, 'document.send.php')) {
      if (preg_match('#^https?://#i', $preview)) {
         return $preview;
      }
      $rootdoc = rtrim($rootdoc, '/');
      if (str_starts_with($preview, $rootdoc . '/')) {
         return $preview;
      }
      if (str_starts_with($preview, '/')) {
         return $rootdoc . $preview;
      }
      return $rootdoc . '/front/' . ltrim($preview, '/');
   }

   $preview = str_replace('/ajax/view_pdf.php', '/view_pdf.php', $preview);
   $preview = str_replace('/plugins/gestion/public/view_pdf.php', '/plugins/gestion/view_pdf.php', $preview);
   if ($rootdoc !== '') {
      $preview = str_replace($rootdoc . $rootdoc . '/plugins/gestion/view_pdf.php', $rootdoc . '/plugins/gestion/view_pdf.php', $preview);
   }

   // Régénère toujours un lien signé pour les anciens doc_url view_pdf.php (sans token / token expiré).
   $previewPath = (string)(parse_url($preview, PHP_URL_PATH) ?? '');
   if (strpos($previewPath, '/view_pdf.php') !== false) {
      parse_str((string)(parse_url($preview, PHP_URL_QUERY) ?? ''), $params);
      $docId = trim((string)($params['id'] ?? ''));
      if ($docId !== '') {
         return api_prepare_preview_url($docId, $rootdoc);
      }
   }

   return $preview;
}

function api_prepare_remote_technicians(DBmysql $DB, PluginGestionConfig $config): array
{
   try {
      $ids = $config->RemoteSignatureUsers();
      if (is_string($ids)) {
         $decoded = json_decode($ids, true);
         if (is_array($decoded)) {
            $ids = $decoded;
         }
      }
      if (!is_array($ids) || empty($ids)) {
         return [];
      }
      $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
      if (empty($ids)) {
         return [];
      }
      $in = implode(',', $ids);
      $res = $DB->doQuery("SELECT id, name, realname, firstname
                           FROM `glpi_users`
                           WHERE is_deleted = 0
                             AND id IN ($in)
                           ORDER BY realname, firstname, name");
      if (!$res) {
         return [];
      }

      $out = [];
      while ($row = $DB->fetchassoc($res)) {
         $id = (int)($row['id'] ?? 0);
         if ($id <= 0) {
            continue;
         }
         $login = trim((string)($row['name'] ?? ''));
         $label = trim((string)($row['realname'] ?? '') . ' ' . (string)($row['firstname'] ?? ''));
         if ($label === '') {
            $label = $login !== '' ? $login : ('#' . $id);
         }
         $out[] = [
            'id'      => $id,
            'login'   => $login,
            'label'   => $label,
            'display' => $login !== '' ? ($label . ' (' . $login . ')') : $label,
         ];
      }
      return $out;
   } catch (Throwable $e) {
      return [];
   }
}

function api_prepare_local_signed_folders(DBmysql $DB): array
{
   static $cache = null;
   if (is_array($cache)) {
      return $cache;
   }

   $folders = [];
   $resFolders = $DB->doQuery("SELECT folder_name FROM `glpi_plugin_gestion_configsfolder` WHERE params IN (2,3)");
   if ($resFolders) {
      while ($row = $DB->fetchassoc($resFolders)) {
         $fn = strtolower(trim((string)($row['folder_name'] ?? '')));
         if ($fn !== '') {
            $folders[] = $fn;
         }
      }
   }

   if (empty($folders)) {
      $folders[] = 'documentssigned';
   }

   $cache = array_values(array_unique($folders));
   return $cache;
}

function api_prepare_search_results(DBmysql $DB, PluginGestionConfig $config, string $query): array
{
   $search = trim($query);
   if ($search === '') {
      return [];
   }

   $results = [];
   $normalized = strtolower($search);

   // SharePoint (si actif)
   if ((int)$config->SharePointSearch() === 1 && (int)$config->SharePointOn() === 1) {
      try {
         require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
         $sp = new PluginGestionSharepoint();
         $check = $sp->validateSharePointConnection($config->Hostname() . ':' . $config->SitePath());
         if (is_array($check) && !empty($check['status'])) {
            $spResults = $sp->searchSharePointGlobal($normalized);
            if (is_array($spResults)) {
               foreach ($spResults as $item) {
                  if (!is_array($item)) {
                     continue;
                  }
                  $item['source'] = 'sharepoint';
                  $item['save'] = $item['save'] ?? 'SharePoint';
                  if (empty($item['html'])) {
                     $item['html'] = (string)($item['text'] ?? $item['filename'] ?? '');
                  }
                  $item['html'] .= ' <span style="color:white;background-color:#0078d4;padding:2px 6px;border-radius:4px;font-size:11px;">SHAREPOINT</span>';
                  $results[] = $item;
               }
            }
         }
      } catch (Throwable $e) {
         // non bloquant
      }
   }

   // Sage (verification exacte sur identifiant de document complet)
   if ((int)$config->SageSearch() === 1 && (int)$config->SageOn() === 1) {
      $term = trim($search);
      if (preg_match('/^[A-Za-z]{2,}[0-9]{4,}$/', $term)) {
         try {
            $http_status = null;
            $exists = function_exists('documentExiste') ? documentExiste($term, $http_status) : false;
            if ($exists) {
               $results[] = [
                  'id'       => $term,
                  'text'     => $term,
                  'filename' => $term . '.pdf',
                  'folder'   => $term,
                  'save'     => 'Sage',
                  'source'   => 'sage',
                  'signed'   => 0,
                  'html'     => $term . ' <span style="color:white;background-color:#0d6efd;padding:2px 6px;border-radius:4px;font-size:11px;">SAGE</span>',
               ];
            }
         } catch (Throwable $e) {
            // non bloquant
         }
      }
   }

   // Recherche locale
   if ((int)$config->LocalSearch() === 1) {
      $folder = GLPI_PLUGIN_DOC_DIR . '/gestion';
      if (strlen($normalized) >= 2 && is_dir($folder)) {
         $signedFoldersLower = api_prepare_local_signed_folders($DB);

         try {
            $rii = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $file) {
               if (!$file->isFile()) {
                  continue;
               }
               if (strtolower((string)$file->getExtension()) !== 'pdf') {
                  continue;
               }
               if (stripos((string)$file->getFilename(), $normalized) === false) {
                  continue;
               }
               $fullpath = (string)$file->getPathname();
               $relative_path = strstr($fullpath, '_plugins');
               if ($relative_path === false) {
                  continue;
               }
               $filename = (string)$file->getFilename();
               $path_only = dirname($relative_path) . '/';
               $path_only_lower = strtolower($path_only);

               $signed = false;
               foreach ($signedFoldersLower as $sf) {
                  if ($sf !== '' && str_contains($path_only_lower, $sf)) {
                     $signed = true;
                     break;
                  }
               }

               $html = $filename;
               if ($signed) {
                  $html .= ' <span style="color:white;background-color:#198754;padding:2px 6px;border-radius:4px;font-size:11px;">SIGNE</span>';
               }
               $html .= ' <span style="color:white;background-color:#6c757d;padding:2px 6px;border-radius:4px;font-size:11px;">LOCAL</span>';

               $results[] = [
                  'id'       => md5($relative_path),
                  'text'     => $filename,
                  'filename' => $filename,
                  'folder'   => $path_only,
                  'save'     => 'Local',
                  'source'   => 'local',
                  'signed'   => $signed ? 1 : 0,
                  'html'     => $html,
               ];
            }
         } catch (Throwable $e) {
            // non bloquant
         }
      }
   }

   usort($results, static function ($a, $b) {
      $order = ['sage' => 1, 'sharepoint' => 2, 'local' => 3];
      $ao = $order[strtolower((string)($a['source'] ?? ''))] ?? 999;
      $bo = $order[strtolower((string)($b['source'] ?? ''))] ?? 999;
      if ($ao !== $bo) {
         return $ao <=> $bo;
      }
      return strcmp((string)($a['text'] ?? $a['filename'] ?? ''), (string)($b['text'] ?? $b['filename'] ?? ''));
   });

   return array_values($results);
}

function api_prepare_quick_build_preview(string $docId, string $rootdoc): string
{
   $secret = defined('GLPI_PDF_PREVIEW_SECRET')
      ? GLPI_PDF_PREVIEW_SECRET
      : (getenv('GLPI_PDF_PREVIEW_SECRET') ?: hash('sha256', realpath(__DIR__ . '/../..') . 'gestion_pdf_preview'));
   $token = hash('sha256', $docId . date('Y-m-d') . $secret);
   return rtrim($rootdoc, '/') . '/plugins/gestion/view_pdf.php?id=' . rawurlencode($docId) . '&token=' . $token;
}

function api_prepare_quick_sanitize_preview(string $url, string $rootdoc): string
{
   if ($url === '') {
      return '';
   }
   $rootdoc = rtrim($rootdoc, '/');
   $url = str_replace('/ajax/view_pdf.php', '/view_pdf.php', $url);
   $url = str_replace('/plugins/gestion/public/view_pdf.php', '/plugins/gestion/view_pdf.php', $url);
   if ($rootdoc !== '') {
      $url = str_replace($rootdoc . $rootdoc . '/plugins/gestion/view_pdf.php', $rootdoc . '/plugins/gestion/view_pdf.php', $url);
   }
   if (str_contains($url, 'document.send.php')) {
      if (preg_match('#^https?://#i', $url)) {
         return $url;
      }
      if (str_starts_with($url, '/')) {
         return $rootdoc . $url;
      }
      return $rootdoc . '/front/' . ltrim($url, '/');
   }

   // Régénère toujours un lien signé pour les anciens doc_url view_pdf.php (sans token / token expiré).
   $urlPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
   if (strpos($urlPath, '/view_pdf.php') !== false) {
      parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $params);
      $docId = trim((string)($params['id'] ?? ''));
      if ($docId !== '') {
         return api_prepare_quick_build_preview($docId, $rootdoc);
      }
   }

   return $url;
}

function api_prepare_from_search_selection(
   DBmysql $DB,
   PluginGestionConfig $config,
   string $rootdoc,
   string $save,
   string $filename,
   string $folder,
   int $signed
): array {
   $save = trim($save);
   $filename = trim($filename);
   $folder = trim($folder);
   $signed = $signed === 1 ? 1 : 0;

   if ($save === '' || $filename === '' || $folder === '') {
      return ['ok' => false, 'error' => 'missing_fields'];
   }

   $amount_ht = null;
   $amount_ttc = null;
   $tickets_id = 0;
   $entities_id = 0;
   $tracker = null;
   $doc_url = '';
   $pdf_filename = $filename;
   $url_bl = '';
   $relatedInvoiceToBL = null;

   if (strcasecmp($save, 'Sage') === 0) {
      $save = 'Sage';
      $url_bl = $folder;
      try {
         if (function_exists('parseDocument')) {
            $fields = parseDocument($folder);
            if (is_array($fields)) {
               $base = preg_replace('/\.pdf$/i', '', $folder);
               if (!empty($fields['client'])) {
                  $pdf_filename = $base . '_' . str_replace(' ', '_', (string)$fields['client']);
               } else {
                  $pdf_filename = $base;
               }
               if (!empty($fields['tracker'])) {
                  $tracker = (string)$fields['tracker'];
               }
               if (!empty($fields['relatedInvoiceToBL'])) {
                  $relatedInvoiceToBL = (string)$fields['relatedInvoiceToBL'];
               }
            }
         }
         if (function_exists('Montant')) {
            $m = Montant($folder);
            if (is_array($m)) {
               $amount_ht = $m['HT'] ?? $m['ht'] ?? null;
               $amount_ttc = $m['TTC'] ?? $m['ttc'] ?? null;
            }
         }
      } catch (Throwable $e) {
         $pdf_filename = preg_replace('/\.pdf$/i', '', $folder);
      }
      // Store URL WITHOUT token — token is added dynamically when serving
      $doc_url = rtrim($rootdoc, '/') . '/plugins/gestion/view_pdf.php?id=' . rawurlencode($folder);
   } elseif (strcasecmp($save, 'Local') === 0) {
      $save = 'Local';
      $url_bl = $folder;
      $web_rel = str_replace('_plugins', '', rtrim($folder, '/') . '/' . $filename);
      $doc_url = rtrim($rootdoc, '/') . '/files' . $web_rel;
   } elseif (strcasecmp($save, 'SharePoint') === 0) {
      $save = 'SharePoint';
      $url_bl = $folder;
      $doc_url = $folder;
      if ((int)$config->ExtractYesNo() === 1) {
         try {
            require_once PLUGIN_GESTION_DIR . '/front/SharePointGraph.php';
            $sp = new PluginGestionSharepoint();
            $fp = rtrim($folder, '/') . '/' . (preg_match('/\.pdf$/i', $filename) ? $filename : ($filename . '.pdf'));
            $extracted = $sp->GetTrackerPdfDownload($fp);
            if (is_array($extracted)) {
               if (!empty($extracted['tracker'])) {
                  $tracker = (string)$extracted['tracker'];
               }
               if (!empty($extracted['relatedInvoiceToBL'])) {
                  $relatedInvoiceToBL = (string)$extracted['relatedInvoiceToBL'];
               }
            } elseif (!empty($extracted)) {
               $tracker = (string)$extracted;
            }
         } catch (Throwable $e) {
            // non bloquant
         }
      }
   } else {
      return ['ok' => false, 'error' => 'unknown_source'];
   }

   $bl_esc = $DB->escape($pdf_filename);
   $check = $DB->doQuery("SELECT id, doc_url, signed, relatedInvoiceToBL
                          FROM `glpi_plugin_gestion_surveys`
                          WHERE bl = '$bl_esc'
                          LIMIT 1");
   if ($check && $DB->numrows($check) === 1) {
      $row = $DB->fetchassoc($check);
      return [
         'ok'                 => true,
         'exists'             => true,
         'already_signed'     => ((int)($row['signed'] ?? 0) === 1),
         'id'                 => (int)($row['id'] ?? 0),
         'bl'                 => $pdf_filename,
         'preview_url'        => api_prepare_quick_sanitize_preview((string)($row['doc_url'] ?? ''), $rootdoc),
         'relatedInvoiceToBL' => $row['relatedInvoiceToBL'] ?? null,
         'amount_ht'          => $amount_ht,
         'amount_ttc'         => $amount_ttc,
         'save'               => $save,
      ];
   }

   $tracker_sql = $tracker !== null && $tracker !== '' ? ("'" . $DB->escape($tracker) . "'") : 'NULL';
   $related_sql = $relatedInvoiceToBL !== null && $relatedInvoiceToBL !== '' ? ("'" . $DB->escape($relatedInvoiceToBL) . "'") : 'NULL';
   $doc_date_sql = $signed === 1 ? 'NOW()' : 'NULL';

   $sql = "INSERT INTO `glpi_plugin_gestion_surveys`
   (`tickets_id`, `entities_id`, `tracker`, `relatedInvoiceToBL`, `url_bl`, `bl`, `signed`, `doc_id`, `doc_url`, `save`, `date_creation`, `doc_date`)
   VALUES
   (" . (int)$tickets_id . ",
    " . (int)$entities_id . ",
    $tracker_sql,
    $related_sql,
    '" . $DB->escape($url_bl) . "',
    '" . $DB->escape($pdf_filename) . "',
    $signed,
    0,
    '" . $DB->escape($doc_url) . "',
    '" . $DB->escape($save) . "',
    NOW(),
    $doc_date_sql)";

   if (!$DB->doQuery($sql)) {
      return ['ok' => false, 'error' => 'db_insert_failed'];
   }

   $new_id = (int)$DB->insertId();
   if ($new_id <= 0) {
      $res_id = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '$bl_esc' ORDER BY id DESC LIMIT 1");
      if ($res_id && $DB->numrows($res_id) === 1) {
         $row_id = $DB->fetchassoc($res_id);
         $new_id = (int)($row_id['id'] ?? 0);
      }
   }
   if ($new_id <= 0) {
      return ['ok' => false, 'error' => 'db_select_failed'];
   }

   return [
      'ok'                 => true,
      'exists'             => false,
      'already_signed'     => false,
      'id'                 => $new_id,
      'bl'                 => $pdf_filename,
      'preview_url'        => function_exists('plugin_gestion_ensure_pdf_token') ? plugin_gestion_ensure_pdf_token($doc_url) : $doc_url,
      'relatedInvoiceToBL' => $relatedInvoiceToBL,
      'amount_ht'          => $amount_ht,
      'amount_ttc'         => $amount_ttc,
      'save'               => $save,
   ];
}

if (empty($config->fields) || (int)$config->fields['RemoteSignatureOn'] !== 1) {
   api_prepare_end(403, ['ok' => false, 'error' => 'remote_signature_off']);
}

$auth = PluginGestionApiAuth::authenticateRequest(false);
if (empty($auth['ok'])) {
   api_prepare_end((int)($auth['code'] ?? 401), [
      'ok'      => false,
      'error'   => (string)($auth['error'] ?? 'auth_failed'),
      'message' => (string)($auth['message'] ?? 'Authentification API invalide'),
   ]);
}

$input = api_prepare_input();
$raw_bl = trim((string)($input['bl'] ?? ''));
$search_query = trim((string)($input['q'] ?? $input['query'] ?? ''));
$action = strtolower(str_replace(['-', ' '], '_', trim((string)($input['action'] ?? ''))));
$quick_save = trim((string)($input['save'] ?? ''));
$quick_filename = trim((string)($input['filename'] ?? ''));
$quick_folder = trim((string)($input['folder'] ?? ''));
$quick_signed = (int)($input['signed'] ?? 0);
$include_remote_technicians = api_prepare_bool($input['include_remote_technicians'] ?? $input['with_remote_technicians'] ?? '0');
$remote_technicians = $include_remote_technicians ? api_prepare_remote_technicians($DB, $config) : null;

if ($action === 'technicians') {
   api_prepare_end(200, [
      'ok' => true,
      'remote_technicians' => $remote_technicians ?? api_prepare_remote_technicians($DB, $config),
   ]);
}

if ($raw_bl === '' && $search_query !== '') {
   api_prepare_end(200, [
      'ok' => true,
      'mode' => 'search',
      'query' => $search_query,
      'results' => api_prepare_search_results($DB, $config, $search_query),
      'remote_technicians' => $remote_technicians,
      'technician_login' => (string)($auth['tech_login'] ?? ''),
      'technician_label' => (string)($auth['tech_label'] ?? ''),
   ]);
}

if ($raw_bl === '' && $quick_save !== '' && $quick_filename !== '' && $quick_folder !== '') {
   $prepared = api_prepare_from_search_selection($DB, $config, $rootdoc, $quick_save, $quick_filename, $quick_folder, $quick_signed);
   if ($remote_technicians !== null) {
      $prepared['remote_technicians'] = $remote_technicians;
   }
   if (!empty($prepared['ok'])) {
      $prepared['technician_login'] = (string)($auth['tech_login'] ?? '');
      $prepared['technician_label'] = (string)($auth['tech_label'] ?? '');
      api_prepare_end(200, $prepared);
   }
   api_prepare_end(422, $prepared);
}
if ($raw_bl === '') {
   api_prepare_end(422, ['ok' => false, 'error' => 'missing_bl']);
}

$bl = strtoupper(preg_replace('/\s+/', '', $raw_bl));
if (preg_match('/^[0-9]{4,}$/', $bl)) {
   $bl = 'BL' . $bl;
}
if (!preg_match('/^[A-Z]{2,}[0-9]{4,}$/', $bl)) {
   api_prepare_end(422, ['ok' => false, 'error' => 'invalid_bl_format']);
}

$amount_ht = null;
$amount_ttc = null;
try {
   if (function_exists('Montant')) {
      $amounts = Montant($bl);
      if (is_array($amounts)) {
         $amount_ht = $amounts['HT'] ?? $amounts['ht'] ?? null;
         $amount_ttc = $amounts['TTC'] ?? $amounts['ttc'] ?? null;
      }
   }
} catch (Throwable $e) {
   // Non bloquant
}

$bl_esc = $DB->escape($bl);
$bl_like = $DB->escape($bl . '\\_%');

$sql_existing = "SELECT id, bl, signed, doc_url, url_bl, save, relatedInvoiceToBL
                 FROM `glpi_plugin_gestion_surveys`
                 WHERE UPPER(`bl`) = '$bl_esc'
                    OR UPPER(`bl`) LIKE '$bl_like' ESCAPE '\\\\'
                 ORDER BY `signed` ASC, `id` DESC
                 LIMIT 1";
$res_existing = $DB->doQuery($sql_existing);
if ($res_existing && $DB->numrows($res_existing) === 1) {
   $row = $DB->fetchassoc($res_existing);
   $is_signed = (int)($row['signed'] ?? 0) === 1;
   $preview_url = api_prepare_preview_from_row($row, $rootdoc);

   api_prepare_end(200, [
      'ok'                 => true,
      'exists'             => true,
      'already_signed'     => $is_signed,
      'id'                 => (int)$row['id'],
      'bl'                 => (string)($row['bl'] ?? $bl),
      'preview_url'        => $preview_url,
      'relatedInvoiceToBL' => $row['relatedInvoiceToBL'] ?? null,
      'amount_ht'          => $amount_ht,
      'amount_ttc'         => $amount_ttc,
      'technician_login'   => (string)($auth['tech_login'] ?? ''),
      'technician_label'   => (string)($auth['tech_label'] ?? ''),
      'remote_technicians' => $remote_technicians,
   ]);
}

$http_status = null;
$exists = function_exists('documentExiste') ? documentExiste($bl, $http_status) : false;
if (!$exists) {
   api_prepare_end(404, ['ok' => false, 'error' => 'bl_not_found', 'bl' => $bl]);
}

$pdf_filename = $bl;
$tracker = null;
$related = null;
try {
   if (function_exists('parseDocument')) {
      $fields = parseDocument($bl);
      if (is_array($fields)) {
         if (!empty($fields['client'])) {
            $pdf_filename = $bl . '_' . str_replace(' ', '_', (string)$fields['client']);
         }
         if (!empty($fields['tracker'])) {
            $tracker = (string)$fields['tracker'];
         }
         if (!empty($fields['relatedInvoiceToBL'])) {
            $related = (string)$fields['relatedInvoiceToBL'];
         }
      }
   }
} catch (Throwable $e) {
   $pdf_filename = $bl;
}

// Store URL WITHOUT token in DB — token is added dynamically when serving
$doc_url_db = rtrim($rootdoc, '/') . '/plugins/gestion/view_pdf.php?id=' . rawurlencode($bl);
$preview_url = function_exists('plugin_gestion_ensure_pdf_token') ? plugin_gestion_ensure_pdf_token($doc_url_db) : api_prepare_preview_url($bl, $rootdoc);

$tracker_sql = $tracker !== null && $tracker !== '' ? ("'" . $DB->escape($tracker) . "'") : 'NULL';
$related_sql = $related !== null && $related !== '' ? ("'" . $DB->escape($related) . "'") : 'NULL';

$sql_insert = "INSERT INTO `glpi_plugin_gestion_surveys`
(`tickets_id`, `entities_id`, `tracker`, `relatedInvoiceToBL`, `url_bl`, `bl`, `signed`, `doc_id`, `doc_url`, `save`, `date_creation`, `doc_date`)
VALUES
(0, 0, $tracker_sql, $related_sql, '" . $DB->escape($bl) . "', '" . $DB->escape($pdf_filename) . "', 0, 0, '" . $DB->escape($doc_url_db) . "', 'Sage', NOW(), NULL)";

if (!$DB->doQuery($sql_insert)) {
   api_prepare_end(500, ['ok' => false, 'error' => 'db_insert_failed']);
}

$new_id = (int)$DB->insertId();
if ($new_id <= 0) {
   $res_id = $DB->doQuery("SELECT id FROM `glpi_plugin_gestion_surveys` WHERE bl = '" . $DB->escape($pdf_filename) . "' ORDER BY id DESC LIMIT 1");
   if ($res_id && $DB->numrows($res_id) === 1) {
      $row_id = $DB->fetchassoc($res_id);
      $new_id = (int)($row_id['id'] ?? 0);
   }
}

if ($new_id <= 0) {
   api_prepare_end(500, ['ok' => false, 'error' => 'db_select_failed']);
}

api_prepare_end(200, [
   'ok'                 => true,
   'exists'             => false,
   'already_signed'     => false,
   'id'                 => $new_id,
   'bl'                 => $pdf_filename,
   'preview_url'        => $preview_url,
   'relatedInvoiceToBL' => $related,
   'amount_ht'          => $amount_ht,
   'amount_ttc'         => $amount_ttc,
   'technician_login'   => (string)($auth['tech_login'] ?? ''),
   'technician_label'   => (string)($auth['tech_label'] ?? ''),
   'remote_technicians' => $remote_technicians,
]);
