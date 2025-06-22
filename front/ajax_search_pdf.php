<?php
header('Content-Type: application/json');

include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer
require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

global $DB, $CFG_GLPI;
$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();

$search = isset($_GET['q']) ? $_GET['q'] : '';
$search = strtolower(trim($search));
$results = [];

// SharePoint
$sharepoint_results = $sharepoint->searchSharePointGlobal($search);
if (is_array($sharepoint_results)) {
    $results = array_merge($results, $sharepoint_results);
}

// Local
$folder = GLPI_PLUGIN_DOC_DIR . "/gestion";
if (strlen($search) >= 2 && is_dir($folder)) {

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
    );

    $DOC = $DB->query("SELECT folder_name FROM `glpi_plugin_gestion_configsfolder` WHERE params = 3")->fetch_object();

    foreach ($rii as $file) {
        if (!$file->isFile()) continue;

        if (
            stripos($file->getFilename(), $search) !== false &&
            strtolower($file->getExtension()) === 'pdf'
        ) {
            $fullpath = $file->getPathname();
            $relative_path = strstr($fullpath, '_plugins');
            $filename = $file->getFilename();
            $path_only = dirname($relative_path) . '/';
            $webUrl = $path_only;

            $signed = stripos($webUrl, $DOC->folder_name) !== false;

            $badge = $signed
                ? ' <span style="color:white;background-color:#28a745;padding:2px 6px;border-radius:4px;font-size:11px;">✅ SIGNÉ</span>'
                : '';

            $results[] = [
                'id'       => md5($relative_path),
                'text'     => $filename,
                'filename' => $filename,
                'folder'   => $webUrl,
                'save'     => 'Local',
                'signed'   => $signed ? 1 : 0,
                'html'     => $filename . $badge
            ];
        }
    }
}

// Sortie JSON finale
echo json_encode($results);
