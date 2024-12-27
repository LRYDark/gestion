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

   $tableName = 'glpi_plugin_gestion_configs';
   $nonRemovableColumns = ['TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath', 'update', '_glpi_csrf_token', 'is_recursive', 'ConfigModes', 'id', 'Global'];

   if (!empty($_POST["AddFileSite"])) {
      $columnName = $_POST['AddFileSite'];
  
      // Vérifier si la colonne existe dans la table
      $query = "SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = '$tableName'
                  AND TABLE_SCHEMA = DATABASE()
                  AND COLUMN_NAME = '$columnName';";
  
      $result = $DB->query($query);
  
      if (!$DB->numrows($result) > 0) {
          // Ajouter une nouvelle colonne si elle n'existe pas
          $queryAdd = "ALTER TABLE `$tableName` ADD COLUMN `$columnName` TINYINT NOT NULL DEFAULT '8';";
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

   // Vérification et suppression des colonnes
   $queryAllColumns = "SELECT COLUMN_NAME
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_NAME = '$tableName'
                           AND TABLE_SCHEMA = DATABASE();";

   $resultColumns = $DB->query($queryAllColumns);

   while ($row = $DB->fetchassoc($resultColumns)) {
      $currentColumn = $row['COLUMN_NAME'];

      // Ignorer les colonnes non supprimables
      if (in_array($currentColumn, $nonRemovableColumns)) {
         continue;
      }

      // Vérifier si la colonne contient uniquement "6"
      $queryCheckContent = "SELECT COUNT(*) AS count
                  FROM `$tableName`
                  WHERE `$currentColumn` = '6';";

      $resultCheck = $DB->query($queryCheckContent);
      $rowCheck = $DB->fetchassoc($resultCheck);

      if ($rowCheck['count'] > 0) {
         // Supprimer la colonne si elle contient uniquement "6"
         $queryDrop = "ALTER TABLE `$tableName` DROP COLUMN `$currentColumn`;";
         if ($DB->query($queryDrop)) {
            Session::addMessageAfterRedirect(
               __("Le dossier $currentColumn a été supprimée", 'gestion'),
               true,
               INFO
            );
         } else {
            Session::addMessageAfterRedirect(
               __("Erreur lors de la suppression du dossier $currentColumn : " . $DB->error(), 'gestion'),
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
