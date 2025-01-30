<?php
header('Content-Type: application/json');

include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer
require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

global $DB, $CFG_GLPI;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();

// Vérifier si le paramètre 'id' est défini dans la requête GET
if (!isset($_GET['ticketId']) || empty($_GET['ticketId'])) {
    echo json_encode(['error' => 'Le paramètre "id" est manquant ou invalide.']);
    exit;
}

$ticketId = $_GET['ticketId'];

// Initialisation du tableau des groupes
$groups = [];
$selected_ids = [];


// Si le mode est 0, on récupère aussi les fichiers PDF dans un dossier
if ($config->fields['ConfigModes'] == 0) {
    $directory = GLPI_PLUGIN_DOC_DIR . "/gestion/unsigned/";
    if (is_dir($directory)) {
        foreach (scandir($directory) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
                $file_name = pathinfo($file, PATHINFO_FILENAME);
                $groups[$file_name] = $file_name; // Ajout du fichier sans l'extension
            }
        }
    }
} elseif ($config->fields['ConfigModes'] == 1 && !empty($config->fields['Global'])) {
    try {
        $query = "SELECT folder_name, params FROM glpi_plugin_gestion_configsfolder WHERE params IN (0, 1)";
        $result = $DB->query($query);

        $hasValidParams = false;

        while ($row = $DB->fetchAssoc($result)) {
            $hasValidParams = true;
            $folderPath = $row['folder_name'];
            $params = $row['params'];

            if ($params == 0) {
                $contents = $sharepoint->listFolderContents($folderPath);
            } elseif ($params == 1) {
                $contents = $sharepoint->listFolderContentsRecursive($folderPath);
            }

            // Filtrer et ajouter les fichiers PDF
            foreach ($contents as $item) {
                if (strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)) === 'pdf') {
                    $file_name = pathinfo($item['name'], PATHINFO_FILENAME);
                    $fullPath = $item['parentReference']['path'] . '/';

                    // Utiliser une expression régulière pour capturer le chemin après 'root:/'
                    if (preg_match('#/root:/(.+)#', $fullPath, $matches)) {
                        $relativePath = $matches[1];
                        $relativePath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
                    }
                    $groups[$relativePath . $file_name] = $file_name;
                }
            }
        }

        // Si aucune ligne valide n'a été trouvée, utiliser le chemin par défaut
        if (!$hasValidParams) {
            $folderPath = ''; // Récupérer le nom par défaut
            $contents = $sharepoint->listFolderContents($folderPath); // Utiliser listFolderContents
    
            // Filtrer et ajouter les fichiers PDF
            foreach ($contents as $item) {
                if (strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)) === 'pdf') {
                    $file_name = pathinfo($item['name'], PATHINFO_FILENAME);
                    $fullPath = $item['parentReference']['path'] . '/';

                    // Utiliser une expression régulière pour capturer le chemin après 'root:/'
                    if (preg_match('#/root:/(.+)#', $fullPath, $matches)) {
                        $relativePath = $matches[1];
                        $relativePath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
                    }
                    $groups[$relativePath . $file_name] = $file_name;
                }
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
    'folderPath' => $folderPath, 
    'data' => $groups, 
    'selected_ids' => $selected_ids
]);
?>
