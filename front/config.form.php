<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('gestion') || !$plugin->isActivated('gestion')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

$config = new PluginGestionConfig();

// Fonction pour charger la clé de cryptage à partir du fichier
function loadEncryptionKey() {
   // Chemin vers le fichier de clé de cryptage
   $file_path = GLPI_ROOT . '/config/glpicrypt.key';
   return file_get_contents($file_path);
}

function encryptData($data) {
   // Chargez la clé de cryptage
   $encryption_key = loadEncryptionKey();
   return base64_encode(openssl_encrypt($data, 'aes-256-cbc', $encryption_key, 0, '1234567890123456'));
}

function encryptArray($array) {
   $include_keys = ['TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath'];
   $encrypted_array = [];

   foreach ($array as $key => $value) {
       // Crypter uniquement les clés définies dans $include_keys
       if (in_array($key, $include_keys) && !empty($value)) {
           $encrypted_array[$key] = encryptData($value);
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
