<?php
header('Content-Type: application/json');

include ('../../../inc/includes.php'); // Inclure les fichiers nécessaires de GLPI
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer
require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
require_once PLUGIN_GESTION_DIR.'/front/SageApi.php'; // Assurer l'import de SageApi

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
        error_log("Debug SharePoint - Nombre de résultats: " . count($sharepoint_results));
        
        // Ajouter le badge SharePoint à chaque résultat
        foreach ($sharepoint_results as &$result) {
            // Ajouter la source SharePoint
            $result['source'] = 'sharepoint';
            
            // Ajouter le badge SharePoint au HTML
            if (isset($result['html'])) {
                $result['html'] .= ' <span style="color:white;background-color:#0078d4;padding:2px 6px;border-radius:4px;font-size:11px;">☁️ SHAREPOINT</span>';
            } else {
                $result['html'] = $result['text'] . ' <span style="color:white;background-color:#0078d4;padding:2px 6px;border-radius:4px;font-size:11px;">☁️ SHAREPOINT</span>';
            }
            
            error_log("Debug SharePoint - Résultat: " . json_encode($result));
        }
        
        $results = array_merge($results, $sharepoint_results);
    }
}

if ($config->SageSearch() == 1 && $config->SageOn() == 1){
    // Recherche Sage - L'API ne fait que vérifier l'existence avec l'élément complet
    // Ne proposer des suggestions QUE si le terme ressemble à un document complet
    
    $searchTerm = trim($_GET['q']);
    
    // Vérifier que le terme ressemble à un format de document complet
    // Ex: BL123456 (au moins 2 lettres + 4 chiffres minimum)
    if (preg_match('/^[A-Za-z]{2,}[0-9]{4,}$/', $searchTerm)) {
        try {
            $httpStatus = null;
            $exists = documentExiste($searchTerm, $httpStatus);
            
            if ($exists) {
                // Document trouvé dans l'API Sage
                $results[] = [
                    'id'       => $searchTerm,
                    'text'     => $searchTerm,
                    'filename' => $searchTerm . '.pdf',
                    'folder'   => $searchTerm,
                    'save'     => 'Sage',
                    'source'   => 'sage',
                    'signed'   => 0, // Non signé par défaut pour les documents Sage
                    'html'     => $searchTerm . ' <span style="color:white;background-color:#007bff;padding:2px 6px;border-radius:4px;font-size:11px;">📄 SAGE</span>'
                ];
            }
            // Si le document n'existe pas, on n'ajoute rien aux résultats
        } catch (Exception $e) {
            // Erreur lors de la vérification, on log mais on continue
            error_log("Erreur vérification Sage pour $searchTerm: " . $e->getMessage());
        }
    }
    // Si le format ne correspond pas à un document complet, on ne fait rien
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

                // Badge pour le statut signé/non signé
                $signedBadge = $signed
                    ? ' <span style="color:white;background-color:#28a745;padding:2px 6px;border-radius:4px;font-size:11px;">✅ SIGNÉ</span>'
                    : '';

                // Badge pour la source Local
                $localBadge = ' <span style="color:white;background-color:#6c757d;padding:2px 6px;border-radius:4px;font-size:11px;">💾 LOCAL</span>';

                $results[] = [
                    'id'       => md5($relative_path),
                    'text'     => $filename,
                    'filename' => $filename,
                    'folder'   => $webUrl,
                    'save'     => 'Local',
                    'source'   => 'local',
                    'signed'   => $signed ? 1 : 0,
                    'html'     => $filename . $signedBadge . $localBadge
                ];
            }
        }
    }
}

// Trier les résultats pour mettre les résultats dans l'ordre : Sage, SharePoint, Local
usort($results, function($a, $b) {
    $sourceOrder = ['sage' => 1, 'sharepoint' => 2, 'local' => 3];
    
    $aOrder = isset($a['source']) && isset($sourceOrder[$a['source']]) ? $sourceOrder[$a['source']] : 999;
    $bOrder = isset($b['source']) && isset($sourceOrder[$b['source']]) ? $sourceOrder[$b['source']] : 999;
    
    return $aOrder - $bOrder;
});

// Debug : Log des résultats finaux
error_log("Debug résultats finaux - Nombre total: " . count($results));
foreach ($results as $result) {
    error_log("Debug résultat final: " . json_encode($result));
}

// Sortie JSON finale
echo json_encode($results);
?>