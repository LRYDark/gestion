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

if($config->mode() == 0){ //sahrepoint 
    $results = $sharepoint->searchSharePointGlobal($search);
}
if($config->mode() == 2){ // local
    // Ton dossier de recherche
    $folder = GLPI_PLUGIN_DOC_DIR . "/gestion/Documents";

    if (strlen($search) >= 2 && is_dir($folder)) {
        foreach (scandir($folder) as $file) {
            $fullpath = $folder . '/' . $file;

            if (stripos($file, $search) !== false && pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {

                // Extraire le chemin relatif à partir de "_plugins"
                $relative_path = strstr($fullpath, '_plugins'); // tout après "_plugins"
                $filename = basename($file); // juste le nom du fichier
                $path_only = dirname($relative_path) . '/'; // chemin sans le fichier

                $results[] = [
                    'id'   => $relative_path,  // facultatif ou complet
                    'text' => $filename,
                    'save' => 'Local',
                    'filename' => $filename,      // valeur 1
                    'folder'   => $path_only      // valeur 2
                ];
            }
        }
    }
}

echo json_encode($results);
