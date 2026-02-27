<?php
include('../../../inc/includes.php');
// Le chargeur GLPI ne connait pas la classe definie dans front/SharePointGraph.php, on l'inclut explicitement.
require_once dirname(__DIR__) . '/front/SharePointGraph.php';

Html::header_nocache();
Session::checkLoginUser();

$action = $_POST['action'] ?? '';
switch ($action) {
   case 'showCriForm' :
      $PluginGestionCri = new PluginGestionCri();
      $params                  = $_POST["params"];
      $PluginGestionCri->showForm($params["job"], ['modal' => $_POST["modal"], 'root_modal' => $params["root_modal"]]);
      break;

         case 'sendMail' :
      header("Content-Type: application/json; charset=UTF-8");
      $filePath = null;
      try {
         if (!Session::haveRight('plugin_gestion_survey', UPDATE)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => "Acces refuse."]);
            break;
         }

         // CSRF : on journalise mais on n'empêche pas l'envoi (sinon GLPI renvoie du HTML Accès refusé)
         $incomingToken = $_POST['_glpi_csrf_token'] ?? ($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');
         $sessionToken  = $_SESSION['glpicsrftoken'] ?? '';
         if (!$sessionToken || !$incomingToken || !hash_equals((string)$sessionToken, (string)$incomingToken)) {
            error_log("[gestion] sendMail CSRF mismatch: session=".(string)$sessionToken." post=".(string)$incomingToken." user=".Session::getLoginUserID());
         }

         $survey_id = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
         $to        = trim($_POST['to'] ?? '');
         $cc        = trim($_POST['cc'] ?? '');

         if ($survey_id <= 0) {
            throw new InvalidArgumentException("Document introuvable.");
         }
         if ($to === '' && $cc === '') {
            throw new InvalidArgumentException("Merci de saisir au moins un destinataire.");
         }

         $emails = $to;
         if ($cc !== '') {
            $emails = ($emails === '') ? $cc : $emails . ',' . $cc;
         }

         $survey = new PluginGestionSurvey();
         if (!$survey->getFromDB($survey_id)) {
            throw new RuntimeException("Enregistrement introuvable.");
         }

         $sharepoint = new PluginGestionSharepoint();
         $config     = PluginGestionConfig::getInstance();

         $filePath = plugin_gestion_build_signed_pdf_copy($survey, $sharepoint);
         $fileName = $survey->fields['bl'];
         if (substr($fileName, -4) !== '.pdf') {
            $fileName .= '.pdf';
         }

         $tracker = $survey->fields['tracker'] ?? null;
         $sharepoint->MailSend(
            $emails,
            $config->fields['gabarit'],
            $filePath,
            "Mail envoye a ".$emails,
            $survey_id,
            $tracker,
            $survey->fields['doc_url'] ?? null,
            $fileName
         );

         if ($filePath && file_exists($filePath)) {
            @unlink($filePath);
         }

         echo json_encode(['ok' => true, 'message' => "Mail envoye."]);
      } catch (Throwable $e) {
         if (!empty($filePath) && file_exists($filePath)) {
            @unlink($filePath);
         }
         http_response_code(400);
         echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
      }
      break;
}

/**
 * Prepare a local copy of the signed PDF to send as attachment.
 */
function plugin_gestion_http_get_bytes(string $url, int $timeout = 15)
{
   $context = stream_context_create([
      'http' => [
         'timeout' => $timeout,
         'ignore_errors' => true,
      ],
      'https' => [
         'timeout' => $timeout,
         'ignore_errors' => true,
      ],
   ]);

   return @file_get_contents($url, false, $context);
}

function plugin_gestion_to_absolute_url(string $url): string
{
   global $CFG_GLPI;

   $url = trim($url);
   if ($url === '' || preg_match('#^https?://#i', $url)) {
      return $url;
   }

   $url_base = trim((string)($CFG_GLPI['url_base'] ?? ''));
   if ($url_base !== '') {
      $parts = parse_url($url_base);
      if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
         $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? (':' . (int)$parts['port']) : '');
         if (str_starts_with($url, '/')) {
            return $origin . $url;
         }
         $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
         return $origin . $root_doc . '/front/' . ltrim($url, '/');
      }
   }

   $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
   $host   = trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
   if ($host !== '') {
      if (str_starts_with($url, '/')) {
         return $scheme . '://' . $host . $url;
      }
      $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
      return $scheme . '://' . $host . $root_doc . '/front/' . ltrim($url, '/');
   }

   return $url;
}

function plugin_gestion_build_signed_pdf_copy(PluginGestionSurvey $survey, PluginGestionSharepoint $sharepoint): string {
   global $CFG_GLPI;

   $tmpDir = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/resend";
   if (!is_dir($tmpDir)) {
      @mkdir($tmpDir, 0755, true);
   }

   $fileName = $survey->fields['bl'];
   if (substr($fileName, -4) !== '.pdf') {
      $fileName .= '.pdf';
   }
   $tempPath = rtrim($tmpDir, '/\\') . '/' . $fileName;

   $saveMode = $survey->fields['save'] ?? '';
   $sourceFound = false;

   if ($saveMode === 'SharePoint') {
      $downloadUrl = $sharepoint->getDownloadUrlByPath($survey->fields['doc_url']);
      if (!$downloadUrl) {
         throw new RuntimeException("Impossible de recuperer le fichier sur SharePoint.");
      }
      $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
      if ($content === false) {
         throw new RuntimeException("Telechargement du document signe impossible.");
      }
      file_put_contents($tempPath, $content);
      $sourceFound = true;
   } elseif ($saveMode === 'Local') {
      $doc = new Document();
      if (!empty($survey->fields['doc_id']) && $doc->getFromDB($survey->fields['doc_id'])) {
         $candidate = GLPI_DOC_DIR . "/" . ltrim($doc->fields['filepath'], '/\\');
         if (is_dir($candidate)) {
            $candidate = rtrim($candidate, '/\\') . '/' . $doc->fields['filename'];
         }
         if (file_exists($candidate)) {
            copy($candidate, $tempPath);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         $basePath = rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/gestion/' . ltrim((string)$survey->fields['url_bl'], '/\\');
         $candidate = is_dir($basePath) ? rtrim($basePath, '/\\') . '/' . $fileName : $basePath . $fileName;
         if (file_exists($candidate)) {
            copy($candidate, $tempPath);
            $sourceFound = true;
         }
      }

      if (!$sourceFound && !empty($survey->fields['doc_id'])) {
         $downloadUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front/document.send.php?docid=' . (int)$survey->fields['doc_id'];
         $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
         if ($content !== false) {
            file_put_contents($tempPath, $content);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         throw new RuntimeException("Fichier signe introuvable en local.");
      }
   } else {
      $downloadUrl = (string)($survey->fields['doc_url'] ?? $survey->fields['url_bl']);
      if (function_exists('plugin_gestion_normalize_view_pdf_url')) {
         $downloadUrl = plugin_gestion_normalize_view_pdf_url($downloadUrl);
      }
      $downloadUrl = plugin_gestion_to_absolute_url($downloadUrl);
      if ($downloadUrl) {
         $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
         if ($content !== false) {
            file_put_contents($tempPath, $content);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         throw new RuntimeException("Source du document signe inconnue.");
      }
   }

   return $tempPath;
}
