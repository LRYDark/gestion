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

   if (!empty($_POST["AddFileSite"])) {
      $tableName = 'glpi_plugin_gestion_configs';
      $columnName = $_POST['AddFileSite'];
      
      $query = "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_NAME = '$tableName'
                  AND TABLE_SCHEMA = DATABASE()
                  AND COLUMN_NAME = '$columnName';";
      
      $result = $DB->query($query);
      
      if (!$DB->numrows($result) > 0) {
         $queryAdd = "ALTER TABLE `$tableName` ADD COLUMN `$columnName` VARCHAR(255) NULL;";
         if ($DB->query($queryAdd)) {
            Session::addMessageAfterRedirect(
               __('Dossier ajouter avec succès', 'gestion'),
               true,
               INFO
            );
         }else{
            Session::addMessageAfterRedirect(
               __("Erreur lors de l'ajout du dossier", 'gestion'),
               true,
               ERROR
            );
         }
      }else{
         Session::addMessageAfterRedirect(
            __('Le nom du dossier est déjà existant', 'gestion'),
            true,
            INFO
         );

         $nonRemovableColumns = ['TenantID', 'ClientID', 'ClientSecret', 'Hostname', 'SitePath', 'update', '_glpi_csrf_token', 'is_recursive', 'ConfigModes', 'id', 'Global'];
      
         // Vérifier si la colonne est marquée comme non supprimable
         if (in_array($columnName, $nonRemovableColumns)) {
            Session::addMessageAfterRedirect(
               __("La colonne $columnName est protégée et ne peut pas être supprimée.", 'gestion'),
               true,
               WARNING
            );
            Html::back();
            exit;
         }

         // Vérifier si la colonne est vide
         $queryIsEmpty = "
            SELECT COUNT(*)AS count
            FROM `$tableName`
            WHERE `$columnName` IS NOT NULL
            AND `$columnName` != '';
            ";
         $resultEmpty = $DB->query($queryIsEmpty);
         $rowEmpty = $DB->fetchassoc($resultEmpty);

         if ($rowEmpty['count'] == 0) {
            // La colonne est vide, on la supprime
            $queryDrop = "ALTER TABLE `$tableName` DROP COLUMN `$columnName`;";

            if ($DB->query($queryDrop)) {
               Session::addMessageAfterRedirect(
                  __("<br>La colonne $columnName a été supprimée de la table $tableName car elle était vide.", 'gestion'),
                  true,
                  INFO
               );
            } else {
               Session::addMessageAfterRedirect(
                  __("Erreur lors de la suppression de la colonne $columnName : " . $DB->error(), 'gestion'),
                  true,
                  ERROR
               );
            }
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
