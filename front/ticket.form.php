<?php
include ('../../../inc/includes.php');

Session::haveRight("ticket", UPDATE);
global $DB, $CFG_GLPI;
$doc = new Document();

// Vérifier que le formulaire a été soumis
if (isset($_POST['save_selection']) && isset($_POST['tickets_id'])) {
    $ticketId = (int) $_POST['tickets_id'];

    // Récupérer l'ID de l'entité associée au ticket
    $entityResult = $DB->query("SELECT entities_id FROM glpi_tickets WHERE id = $ticketId")->fetch_object();
    $entityId = $entityResult->entities_id;
    
    $selected_items = isset($_POST['groups_id']) ? $_POST['groups_id'] : [];

    // Récupérer les éléments déjà en base
    $current_items = [];
    $result = $DB->query("SELECT bl FROM glpi_plugin_gestion_tickets WHERE tickets_id = $ticketId AND signed = 0");
    while ($data = $result->fetch_assoc()) {
        $current_items[] = $data['bl'];
    }

    // Identifier les éléments à ajouter et à supprimer
    $items_to_add = array_diff($selected_items, $current_items);
    $items_to_remove = array_diff($current_items, $selected_items);

    // Initialiser le drapeau de succès
    $success = true;

    // Ajouter les nouveaux éléments
    foreach ($items_to_add as $item) {
        $config = new PluginGestionConfig();

        if ($config->fields['ConfigModes'] == 0){
            // Chemin du fichier PDF
            $file_path = GLPI_PLUGIN_DOC_DIR . "/gestion/unsigned/" . $item . ".pdf"; 
        }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
            require_once '../inc/SharePointGraph.php';
            $sharepoint = new PluginGestionSharepoint();

            // Étape 1 : Obtenez votre token d'accès
            $accessToken = $sharepoint->getAccessToken($config->TenantID(), $config->ClientID(), $config->ClientSecret());
            $siteId = '';
            $siteId = $sharepoint->getSiteId($accessToken, $config->Hostname(), $config->SitePath());
            $drives = $sharepoint->getDrives($accessToken, $siteId);
            
            // Trouver la bibliothèque "Documents partagés"
            $globaldrive = strtolower(trim($config->Global()));
            $driveId = null;
            foreach ($drives as $drive) {
                  if (strtolower($drive['name']) === $globaldrive) {
                     $driveId = $drive['id'];
                     break;
                  }
            }

            if (!$driveId) {
                Session::addMessageAfterRedirect(__("Bibliothèque '$globaldrive' introuvable.", 'gestion'), false, ERROR);
            }

            // Étape 3 : Spécifiez le chemin relatif du fichier dans SharePoint
            $file_path = "BL_NON_SIGNE/" . $item . ".pdf"; // Remplacez par le chemin exact de votre fichier
            // Étape 4 : Récupérez l'URL du fichier
            $fileUrl = $sharepoint->getFileUrl($accessToken, $driveId, $file_path);
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
    
                // Ajouter le document dans GLPI
                $doc = new Document();
                if ($doc_id = $doc->add($input)) {
                    // Insérer le ticket et l'ID de document dans glpi_plugin_gestion_tickets
                    if (!$DB->query("INSERT INTO glpi_plugin_gestion_tickets (tickets_id, entities_id, bl, doc_id) VALUES ($ticketId, $entityId, '".$DB->escape($item)."', $doc_id)")) {
                        $success = false; // Si l'insertion échoue, mettre le drapeau de succès à false
                    }
                } else {
                    // Gérer le cas d'erreur lors de l'ajout du document
                    Session::addMessageAfterRedirect(__("Erreur lors de l'ajout du document pour $item.", 'gestion'), false, ERROR);
                    $success = false;
                }
            } else {
                // Gérer le cas où le fichier n'existe pas
                Session::addMessageAfterRedirect(__("Le fichier $file_path n'existe pas.", 'gestion'), false, ERROR);
                $success = false;
            }
        }elseif ($config->fields['ConfigModes'] == 1){ // CONFIG SHAREPOINT 
            if ($sharepoint->checkFileExists($accessToken, $driveId, $file_path)) {
                $file_name = basename($file_path);
                $file_path_bdd = $fileUrl;
    
                // Préparer les informations pour le document
                $input = [
                    'name'        => 'PDF : Doc - ' . str_replace("?", "°", $item),
                    'link'        => $file_path_bdd,
                    'mime'        => 'SharePoint/pdf',
                    'users_id'    => Session::getLoginUserID(),
                    'entities_id' => $entityId,
                    'tickets_id'  => $ticketId,
                    'is_recursive'=> 1
                ];
    
                // Ajouter le document dans GLPI
                $doc = new Document();
                if ($doc_id = $doc->add($input)) {
                    // Insérer le ticket et l'ID de document dans glpi_plugin_gestion_tickets
                    if (!$DB->query("INSERT INTO glpi_plugin_gestion_tickets (tickets_id, entities_id, bl, doc_id) VALUES ($ticketId, $entityId, '".$DB->escape($item)."', $doc_id)")) {
                        $success = false; // Si l'insertion échoue, mettre le drapeau de succès à false
                    }
                } else {
                    // Gérer le cas d'erreur lors de l'ajout du document
                    Session::addMessageAfterRedirect(__("Erreur lors de l'ajout du document pour $item.", 'gestion'), false, ERROR);
                    $success = false;
                }
            } else {
                // Gérer le cas où le fichier n'existe pas
                Session::addMessageAfterRedirect(__("Le fichier $file_path n'existe pas.", 'gestion'), false, ERROR);
                $success = false;
            }
        }
    }

    // Supprimer les éléments désélectionnés
    foreach ($items_to_remove as $item) {
        if (!$DB->query("DELETE FROM glpi_plugin_gestion_tickets WHERE tickets_id = $ticketId AND bl = '".$DB->escape($item)."'")) {
            $success = false; // Si la suppression échoue, mettre le drapeau de succès à false
        }
    }

    // Message de confirmation si tout s'est bien passé
    if ($success) {
        Session::addMessageAfterRedirect(__("Les éléments ont été mis à jour avec succès.", 'gestion'), true, INFO);
    }
}

Html::back();