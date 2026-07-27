<?php
// Nettoyer tout output buffer existant et désactiver l'affichage des erreurs dans la sortie
while (ob_get_level() > 0) {
    ob_end_clean();
}
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_WARNING);

header('Content-Type: application/json');

include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI

Session::checkLoginUser();
require_once(__DIR__ . '/../vendor/autoload.php'); // Utiliser le chargement automatique de Composer
require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';

global $DB, $CFG_GLPI;

$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();
$mode = (int)$config->mode();

// Vérifier si le paramètre 'ticketId' est défini dans la requête GET
if (!isset($_GET['ticketId']) || (int)$_GET['ticketId'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Le paramètre "ticketId" est manquant ou invalide.']);
    exit;
}

$ticketId = (int)$_GET['ticketId'];

// Gestion de la vérification d'un document pour le mode Sage (mode == 1)
if (isset($_GET['verifyDoc']) && !empty($_GET['verifyDoc']) && $mode === 1) {
    $docId = trim((string)$_GET['verifyDoc']);
    if ($docId === '' || strlen($docId) > 255 || preg_match('/[\x00-\x1F\\\\]/', $docId) || str_contains($docId, '..') || str_contains($docId, '?') || str_contains($docId, '#')) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Identifiant document invalide'
        ]);
        exit;
    }
    
    try {
        $httpStatus = null;
        $exists = documentExiste($docId, $httpStatus);
        
        if ($exists) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Document trouvé',
                'docId' => $docId,
                'httpStatus' => $httpStatus
            ]);
        } else {
            http_response_code(200);
            echo json_encode([
                'success' => false,
                'message' => 'Document non trouvé dans l\'API Sage',
                'docId' => $docId,
                'httpStatus' => $httpStatus
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la vérification: ' . $e->getMessage(),
            'docId' => $docId
        ]);
    }
    exit;
}

// Initialisation du tableau des groupes
$groups = [];
$selected_ids = [];

if ($mode === 0){
    if(!empty($config->fields['Global'])) {
        try {
            $bibliotheque = $config->Global();
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
            http_response_code(500);
            echo json_encode(['error' => 'Erreur SharePoint: ' . $e->getMessage()]);
            exit;
        }
    }
}

if ($mode === 2){
    $relativePath = '_plugins/gestion/Documents';
    $dir = GLPI_ROOT . '/files/' . $relativePath;
    $groups = [];

    if (is_dir($dir)) {
        foreach (scandir($dir) as $file) {
            if (is_file($dir . '/' . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
                $groups[$relativePath . '/' . $file] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
    }
}

if ($mode === 1){
    $result = $DB->doQuery("SELECT bl FROM glpi_plugin_gestion_surveys WHERE tickets_id = " . (int)$ticketId . " AND signed = 0");
    while ($data = $result->fetch_assoc()) {
        $selected_ids[] = $data['bl'];
    }
}

// S'assurer qu'aucun contenu supplémentaire n'est envoyé
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Formater la réponse JSON pour inclure 'folderPath' et 'data' dans un seul objet
http_response_code(200);
echo json_encode([
    'data' => $groups, 
    'selected_ids' => $selected_ids,
    'mode' => $mode // Ajouter le mode pour que le JavaScript sache comment gérer l'interface
]);

// S'assurer qu'aucun contenu supplémentaire n'est ajouté après
exit;

?>
