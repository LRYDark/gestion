<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('gestion') || !$plugin->isActivated('gestion')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

$config = new PluginGestionConfig();

function encryptArray($array) {
   $include_keys = ['TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath', 'SagePwd', 'SageToken'];
   $encrypted_array = [];

   foreach ($array as $key => $value) {
       // Crypter uniquement les clés définies dans $include_keys
       if (in_array($key, $include_keys) && !empty($value)) {
           //$encrypted_array[$key] = encryptData($value);
           $encrypted_array[$key] = PluginGestionCrypto::encrypt($value);
       } else {
           $encrypted_array[$key] = $value;
       }
   }
   return $encrypted_array;
}

if (isset($_POST["update"])) {
   $config->check($_POST['id'], UPDATE);
   $encrypted_post = encryptArray($_POST);

   // Vérification et mise à jour des dossiers en fonction des entrées dans $_POST
   $queryAllFolders = "SELECT `id`, `folder_name`, `params`
                     FROM `glpi_plugin_gestion_configsfolder`;";
   $resultFolders = $DB->query($queryAllFolders);

   while ($row = $DB->fetchassoc($resultFolders)) {
      $folderId = $row['id'];
      $folderName = $row['folder_name'];
      $currentValue = $row['params']; // La valeur actuelle dans la base

      // Vérifier si $_POST contient une clé correspondant au nom du dossier
      if (isset($_POST[$folderName])) {
         $newValue = $_POST[$folderName]; // La nouvelle valeur pour le dossier

         // Mettre à jour la base de données uniquement si la valeur change
         if ($newValue != $currentValue) {
               $queryUpdate = "UPDATE `glpi_plugin_gestion_configsfolder`
                              SET `params` = '$newValue'
                              WHERE `id` = '$folderId';";
               if ($DB->query($queryUpdate)) {
                  Session::addMessageAfterRedirect(
                     __("Le dossier $folderName a été mis à jour avec succès", 'gestion'),
                     true,
                     INFO
                  );
               } else {
                  Session::addMessageAfterRedirect(
                     __("Erreur lors de la mise à jour du dossier $folderName : " . $DB->error(), 'gestion'),
                     true,
                     ERROR
                  );
               }
         }
      }
   }

   // Ajouter un nouveau dossier si demandé via $_POST["AddFileSite"]
   if (!empty($_POST["AddFileSite"])) {
      $folderName = $_POST['AddFileSite'];

      // Vérifier si le dossier existe déjà
      $query = "SELECT COUNT(*) AS count
               FROM `glpi_plugin_gestion_configsfolder`
               WHERE `folder_name` = '$folderName';";

      $result = $DB->query($query);
      $row = $DB->fetchassoc($result);

      if ($row['count'] == 0) {
         // Ajouter une nouvelle ligne
         $queryAdd = "INSERT INTO `glpi_plugin_gestion_configsfolder` (`folder_name`, `params`) 
                        VALUES ('$folderName', 8);";
         if ($DB->query($queryAdd)) {
               Session::addMessageAfterRedirect(
                  __('Dossier ajouté avec succès', 'gestion'),
                  true,
                  INFO
               );
         } else {
               Session::addMessageAfterRedirect(
                  __("Erreur lors de l'ajout du dossier", 'gestion'),
                  true,
                  ERROR
               );
         }
      } else {
         Session::addMessageAfterRedirect(
               __('Le nom du dossier est déjà existant', 'gestion'),
               true,
               INFO
         );
      }
   }

   // Supprimer des dossiers si nécessaire
   $queryAllFolders = "SELECT `id`, `folder_name`
                     FROM `glpi_plugin_gestion_configsfolder`;";

   $resultFolders = $DB->query($queryAllFolders);

   while ($row = $DB->fetchassoc($resultFolders)) {
      $folderId = $row['id'];

      // Vérifier si la ligne doit être supprimée
      $queryCheckContent = "SELECT COUNT(*) AS count
                           FROM `glpi_plugin_gestion_configsfolder`
                           WHERE `id` = '$folderId' AND `params` = '6';";

      $resultCheck = $DB->query($queryCheckContent);
      $rowCheck = $DB->fetchassoc($resultCheck);

      if ($rowCheck['count'] > 0) {
         // Supprimer la ligne
         $queryDelete = "DELETE FROM `glpi_plugin_gestion_configsfolder` WHERE `id` = '$folderId';";
         if ($DB->query($queryDelete)) {
               Session::addMessageAfterRedirect(
                  __("Le dossier {$row['folder_name']} a été supprimé", 'gestion'),
                  true,
                  INFO
               );
         } else {
               Session::addMessageAfterRedirect(
                  __("Erreur lors de la suppression du dossier {$row['folder_name']} : " . $DB->error(), 'gestion'),
                  true,
                  ERROR
               );
         }
      }
   }

   // ===== AJOUTS POUR SIGNATURE FACTURE COMPTOIR =====

   // Encoder en JSON la liste des utilisateurs autorisés (si fournie)
   if (isset($encrypted_post['CounterInvoiceUsers']) && is_array($encrypted_post['CounterInvoiceUsers'])) { // NEW
      $ids = array_map('intval', $encrypted_post['CounterInvoiceUsers']);
      $encrypted_post['CounterInvoiceUsers'] = json_encode(array_values($ids));
   }
   
   // ===== AJOUTS POUR SIGNATURE DÉPORTÉE =====

   // Encoder en JSON la liste des utilisateurs autorisés (si fournie)
   if (isset($encrypted_post['RemoteSignatureUsers']) && is_array($encrypted_post['RemoteSignatureUsers'])) {
      $ids = array_map('intval', $encrypted_post['RemoteSignatureUsers']);
      $encrypted_post['RemoteSignatureUsers'] = json_encode(array_values($ids));
   }

   // Ajout d'une tablette autorisée (ligne d'ajout dans la config)
   if (!empty($_POST['new_device_id'])) {
      $did    = $DB->escape($_POST['new_device_id']);
      $serial = $DB->escape($_POST['new_serial'] ?? '');
      $token  = trim($_POST['new_token'] ?? '');
      if ($token === '') {
         try {
            $token = bin2hex(random_bytes(32)); // 64 hex
         } catch (Exception $e) {
            $token = bin2hex(openssl_random_pseudo_bytes(32));
         }
      }
      $token  = $DB->escape($token);
      $active = isset($_POST['new_active']) ? 1 : 0;

      $sql = "INSERT INTO `glpi_plugin_gestion_signaturedevices`
              (`device_id`,`serial`,`device_token`,`is_active`)
              VALUES ('$did','$serial','$token',$active)";
      if ($DB->query($sql)) {
         Session::addMessageAfterRedirect(__('Tablette ajoutée', 'gestion'), true, INFO);
      } else {
         Session::addMessageAfterRedirect(__('Erreur ajout tablette: '.$DB->error(), 'gestion'), true, ERROR);
      }
   }

   // Suppression de tablettes sélectionnées
   if (!empty($_POST['device_delete']) && is_array($_POST['device_delete'])) {
      $ids = array_map('intval', $_POST['device_delete']);
      if (count($ids)) {
         $in = implode(',', $ids);
         $DB->query("DELETE FROM `glpi_plugin_gestion_signaturedevices` WHERE id IN ($in)");
         Session::addMessageAfterRedirect(sprintf(__('%d tablette(s) supprimée(s)', 'gestion'), count($ids)), true, INFO);
      }
   }

   // Mise à jour des statuts Actif/Non actif (même si aucune case n'est cochée)
   $res = $DB->query("SELECT id FROM glpi_plugin_gestion_signaturedevices");
   $all_ids = [];
   while ($row = $DB->fetchassoc($res)) {
      $all_ids[] = (int)$row['id'];
   }

   // Si rien n'est coché, device_active n'existe pas dans $_POST → on le traite comme un tableau vide
   $active_ids = (isset($_POST['device_active']) && is_array($_POST['device_active']))
      ? array_map('intval', array_keys($_POST['device_active']))
      : [];

   // (Option bulk) 1 requête pour tout passer à 0, puis 1 requête pour remettre à 1 les cochés
   if (!empty($all_ids)) {
      $in_all = implode(',', $all_ids);
      $DB->query("UPDATE glpi_plugin_gestion_signaturedevices SET is_active = 0 WHERE id IN ($in_all)");

      if (!empty($active_ids)) {
         $in_active = implode(',', $active_ids);
         $DB->query("UPDATE glpi_plugin_gestion_signaturedevices SET is_active = 1 WHERE id IN ($in_active)");
      }
   }

   if(!$config->update($encrypted_post)){
      Session::addMessageAfterRedirect(
         __('Erreur lors de la modification', 'gestion'),
         true,
         ERROR
      );
   }
   Html::back();
}

Html::redirect($CFG_GLPI["root_doc"] . "/front/config.form.php?forcetab=" . urlencode('PluginGestionConfig$1'));
