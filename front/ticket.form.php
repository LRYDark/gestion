<?php
include ('../../../inc/includes.php');

use Glpi\Event;

Session::haveRight("ticket", UPDATE);
global $DB, $CFG_GLPI;
$doc = new Document();

require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
$sharepoint = new PluginGestionSharepoint();
$config = new PluginGestionConfig();

// Vérifier que le formulaire a été soumis
if (isset($_POST['save_selection']) && isset($_POST['tickets_id'])) {
    $ticketId = (int) $_POST['tickets_id'];

    // Récupérer l'ID de l'entité associée au ticket
    $entityResult = $DB->query("SELECT entities_id FROM glpi_tickets WHERE id = $ticketId")->fetch_object();
    $entityId = $entityResult->entities_id;
    
    $selected_items = isset($_POST['groups_id']) ? $_POST['groups_id'] : [];

    // Récupérer les éléments déjà en base
    $current_items = [];
    $result = $DB->query("SELECT url_bl, bl FROM glpi_plugin_gestion_surveys WHERE tickets_id = $ticketId AND signed = 0");
    while ($data = $result->fetch_assoc()) {
        $current_items[] = $data['url_bl'].'/'.$data['bl'];
    }

    // Identifier les éléments à ajouter et à supprimer
    $items_to_add = array_diff($selected_items, $current_items);
    $items_to_remove = array_diff($current_items, $selected_items);

    // Initialiser le drapeau de succès
    $success = true;

    // Ajouter les nouveaux éléments
    foreach ($items_to_add as $item) {
        if ($config->fields['ConfigModes'] == 0){
            // Chemin du fichier PDF
            $file_path = GLPI_PLUGIN_DOC_DIR . "/gestion/unsigned/" . $item . ".pdf"; 
        }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
            // Étape 3 : Spécifiez le chemin relatif du fichier dans SharePoint
            $file_path = $item . ".pdf"; // Remplacez par le chemin exact de votre fichier
            // Étape 4 : Récupérez l'URL du fichier
            $fileUrl = $sharepoint->getFileUrl($file_path);
        }

        if ($config->fields['ConfigModes'] == 0){
            if (file_exists($file_path)) {
                $file_name = basename($file_path);
                $file_path_bdd = "_plugins/gestion/unsigned/" . $item . ".pdf";
    
                // Préparer les informations pour le document
                $input = [
                    'name'        => 'PDF : Doc - ' . str_replace("?", "°", $item),
                    'filename'    => $file_name,
                    'filepath'    => $file_path_bdd,
                    'mime'        => 'application/pdf',
                    'users_id'    => Session::getLoginUserID(),
                    'entities_id' => $entityId,
                    'tickets_id'  => $ticketId,
                    'is_recursive'=> 1
                ];
    
                $existedoc = $DB->query("SELECT tickets_id FROM `glpi_plugin_gestion_surveys` WHERE bl = '".$DB->escape($item)."'")->fetch_object(); // Récupérer les informations du document
                if(empty($existedoc->tickets_id)){
                    // Ajouter le document dans GLPI
                    $doc = new Document();
                    if ($doc_id = $doc->add($input)) {
                        // Insérer le ticket et l'ID de document dans glpi_plugin_gestion_surveys
                        if (!$DB->query("INSERT INTO glpi_plugin_gestion_surveys (tickets_id, entities_id, bl, doc_id) VALUES ($ticketId, $entityId, '".$DB->escape($item)."', $doc_id)")) {
                            $success = false; // Si l'insertion échoue, mettre le drapeau de succès à false
                        }
                    } else {
                        // Gérer le cas d'erreur lors de l'ajout du document
                        Session::addMessageAfterRedirect(__("Erreur lors de l'ajout du document pour $item.", 'gestion'), false, ERROR);
                        $success = false;
                    }
                }else{
                    Session::addMessageAfterRedirect(__($DB->escape($item)." déjà associé au ticket : ".$existedoc->tickets_id, 'gestion'), false, ERROR);
                    $success = false;
                }
            } else {
                // Gérer le cas où le fichier n'existe pas
                Session::addMessageAfterRedirect(__("Le fichier $file_path n'existe pas.", 'gestion'), false, ERROR);
                $success = false;
            }
        }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
            $tracker = $sharepoint->GetTrackerPdfDownload($file_path);
            if ($sharepoint->checkFileExists($file_path)) {
                // Expression régulière pour extraire les deux parties
                $pattern = '#^(.*)/(.*)$#';

                // Vérification et extraction
                if (preg_match($pattern, $item, $matches)) {
                    $itemUrl = $matches[1]; // xxx/zzzz ou xxx/xxxx/zzzz
                    $item = $matches[2]; // zzzz
                }    
                
                $existedoc = $DB->query("SELECT tickets_id, bl FROM `glpi_plugin_gestion_surveys` WHERE bl = '".$DB->escape($item)."'")->fetch_object(); // Récupérer les informations du document
                if(empty($existedoc->bl)){
                    // Insérer le ticket et l'ID de document dans glpi_plugin_gestion_surveys
                    if (!$DB->query("INSERT INTO glpi_plugin_gestion_surveys (tickets_id, entities_id, url_bl, bl, doc_url, tracker) VALUES ($ticketId, $entityId, '".$DB->escape($itemUrl)."', '".$DB->escape($item)."', '$fileUrl', '$tracker')")) {
                        Session::addMessageAfterRedirect(__("Erreur lors de l'ajout", 'gestion'), false, ERROR);
                        $success = false; // Si l'insertion échoue, mettre le drapeau de succès à false
                    }
                }else{
                    if($existedoc->tickets_id == NULL){
                        // Validation des entrées numériques
                        $ticketId = intval($ticketId);

                        // Préparer la requête SQL
                        $sql = "UPDATE glpi_plugin_gestion_surveys 
                                SET tickets_id = ?, 
                                    url_bl = ?,
                                    tracker = ?
                                WHERE bl = ?";

                        // Exécution de la requête préparée
                        $stmt = $DB->prepare($sql);
                        $stmt->execute([$ticketId, $itemUrl, $tracker, $item]);
                    }elseif($existedoc->tickets_id != $ticketId){
                        Session::addMessageAfterRedirect(__($DB->escape($item)." déjà associé au ticket : ".$existedoc->tickets_id, 'gestion'), false, ERROR);
                        $success = false;
                    }
                }

            } else {
                // Gérer le cas où le fichier n'existe pas
                Session::addMessageAfterRedirect(__("Le fichier $file_path n'existe pas.", 'gestion'), false, ERROR);
                $success = false;
            }

            if ($success) {
                if($config->ExtractYesNo() == 1 && $config->fields['ConfigModes'] == 1){
                    if (!empty($tracker)){
                        Session::addMessageAfterRedirect(__("$item - <strong>Tracker : $tracker</strong>", 'gestion'), false, INFO);
                    }else{
                        $tracker = NULL;
                        Session::addMessageAfterRedirect(__("$item - Aucun tracker", 'gestion'), false, WARNING);
                    }
                }        
            }
        }
    }

    //$UserId = 1;
    // Supprimer les éléments désélectionnés
    foreach ($items_to_remove as $item) {
        // Normaliser les noms des fichiers dans $current_items
        $item = basename($item);
    
        // Validation des entrées numériques
        $ticketId = intval($ticketId);

        // Préparer la requête SQL
        $sql = "UPDATE glpi_plugin_gestion_surveys 
                SET tickets_id = ?
                WHERE bl = ?";

        // Exécution de la requête préparée
        $stmt = $DB->prepare($sql);
        if (!$stmt->execute([0, $item])){
            Session::addMessageAfterRedirect(__("Erreur de suppression des éléments", 'gestion'), true, ERROR);
        }else{
            //Event::log($UserId, "users", 5, "setup", sprintf(__('%s updates an item'), $_SESSION["glpiname"]));
        }
    }

    // Message de confirmation si tout s'est bien passé
    if ($success) {
        //Event::log($UserId, "users", 5, "setup", sprintf(__('%s updates an item'), $_SESSION["glpiname"]));
        Session::addMessageAfterRedirect(__("Les éléments ont été mis à jour avec succès.", 'gestion'), true, INFO);
    }
}

Html::back();