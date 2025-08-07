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

if ($config->SharePointSearch() == 1 && $config->SharePointOn() == 1 ){
    // SharePoint
    $sharepoint_results = $sharepoint->searchSharePointGlobal($search);
    if (is_array($sharepoint_results)) {
        $results = array_merge($results, $sharepoint_results);
    }
}
if ($config->SageSearch() == 1 && $config->SageOn() == 1){
    // Sage Local
    
}

if ($config->LocalSearch() == 1){
    // Local
    $folder = GLPI_PLUGIN_DOC_DIR . "/gestion";
    if (strlen($search) >= 2 && is_dir($folder)) {

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );

        $folders = [];
        // On récupère tous les folder_name avec params = 3 ou 2
        $res = $DB->query("SELECT folder_name FROM `glpi_plugin_gestion_configsfolder` WHERE params IN (2,3)");

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

                if ($res) {
                    while ($row = $res->fetch_object()) {
                        if (!empty($row->folder_name)) {
                            $folders[] = $row->folder_name;
                        }
                    }
                }

                // Si aucun résultat, on ajoute le nom par défaut
                if (empty($folders)) {
                    $folders[] = "DocumentsSigned";
                }

                // Vérification dans $webUrl
                $signed = false;
                foreach ($folders as $folder) {
                    if (stripos($webUrl, $folder) !== false) {
                        $signed = true;
                        break;
                    }
                }

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
}

// Sortie JSON finale
echo json_encode($results);
