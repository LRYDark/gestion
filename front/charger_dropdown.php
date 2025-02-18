<?php
header('Content-Type: application/json');

include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer
require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

global $DB, $CFG_GLPI;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();
$bibliotheque = $config->Global();

// Vérifier si le paramètre 'id' est défini dans la requête GET
if (!isset($_GET['ticketId']) || empty($_GET['ticketId'])) {
    echo json_encode(['error' => 'Le paramètre "id" est manquant ou invalide.']);
    exit;
}

$ticketId = $_GET['ticketId'];

// Initialisation du tableau des groupes
$groups = [];
$selected_ids = [];

if(!empty($config->fields['Global'])) {
    try {
            $contents = $sharepoint->searchSharePoint();            
            // Filtrer et ajouter les fichiers PDF
            foreach ($contents as $item) {
                if (strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)) === 'pdf') {
                    $file_name = pathinfo($item['name'], PATHINFO_FILENAME);
                    $url = $item['webUrl'] . '/';

                    // Utiliser une expression régulière pour capturer le chemin après 'root:/'
                    if (preg_match("/" . preg_quote($bibliotheque, "/") . "\/(.*)\/[^\/]+\.pdf/", $url, $matches)) {
                        $relativePath = $matches[1];
                        $relativePath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
                    }
                    $groups[$relativePath . '/' . $file_name] = $file_name;
                }
            }

    } catch (Exception $e) {
        // Gestion des erreurs SharePoint
        echo json_encode(['error' => 'Erreur SharePoint: ' . $e->getMessage()]);
        exit;
    }
}

// Formater la réponse JSON pour inclure 'folderPath' et 'data' dans un seul objet
echo json_encode([
    'data' => $groups, 
    'selected_ids' => $selected_ids
]);
?>
