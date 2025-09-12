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

   //-----------------------------------------------------------
      // --- Signature déportée (tablette) : SAVE ---

      $rows = $_POST['sig'] ?? [];
      $tbl  = 'glpi_plugin_gestion_signaturedevices';

      // Générateur de token hex (64 chars) + vérif unicité
      $genToken = function() use ($DB, $tbl) {
         for ($i = 0; $i < 5; $i++) {
            if (function_exists('random_bytes')) {
               $tok = bin2hex(random_bytes(32));
            } elseif (function_exists('openssl_random_pseudo_bytes')) {
               $tok = bin2hex(openssl_random_pseudo_bytes(32));
            } else {
               // fallback (rare)
               $tok = bin2hex(pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
            }
            // collision check (quasi-improbable, mais on sécurise)
            $exists = $DB->request([
               'FROM'   => $tbl,
               'FIELDS' => new \QueryExpression('COUNT(*) AS c'),
               'WHERE'  => ['device_token' => $tok]
            ])->current();
            if (empty($exists['c'])) {
               return $tok;
            }
         }
         return $tok; // dernier généré
      };

      foreach ($rows as $key => $data) {
         $device_id    = trim($data['device_id']    ?? '');
         $serial       = trim($data['serial']       ?? '');
         $device_token = trim($data['device_token'] ?? '');
         $is_active    = isset($data['is_active']) ? 1 : 0;
         $del          = (int)($data['_delete'] ?? 0);

         if (is_numeric($key)) {
            // --- UPDATE ligne existante
            $id = (int)$key;

            if ($del === 1) {
               $DB->delete($tbl, ['id' => $id]);
            } else {
               // Auto-génère si token vide
               if ($device_token === '') {
                  $device_token = $genToken();
               }
               $DB->update($tbl, [
                  'device_id'    => $device_id,
                  'serial'       => $serial,
                  'device_token' => $device_token,
                  'is_active'    => $is_active
               ], ['id' => $id]);
            }

         } else {
            // --- INSERT nouvelle ligne (key = new_xxx)
            // Si l'utilisateur n'a pas fourni de token, on le génère
            if ($device_token === '') {
               $device_token = $genToken();
            }
            $hasContent = ($device_id !== '' || $serial !== '' || $device_token !== '');
            if ($del !== 1 && $hasContent) {
               $DB->insert($tbl, [
                  'device_id'    => $device_id,
                  'serial'       => $serial,
                  'device_token' => $device_token,
                  'is_active'    => $is_active
               ]);
            }
         }
      }
   // ----------------------------------------------------------

      $rows = $_POST['bi'] ?? [];
      $tbl  = 'glpi_plugin_gestion_baseitems';

      foreach ($rows as $key => $data) {
         $desc = trim($data['description'] ?? '');
         $info = trim($data['info'] ?? '');
         $del  = (int)($data['_delete'] ?? 0);

         if (is_numeric($key)) {
            $id = (int)$key;
            if ($del === 1) {
               $DB->delete($tbl, ['id' => $id]);
            } else {
               $DB->update($tbl, [
                  'description' => $desc,
                  'info'        => $info
               ], ['id' => $id]);
            }
         } else {
            if ($del !== 1 && ($desc !== '' || $info !== '')) {
               $DB->insert($tbl, [
                  'description' => $desc,
                  'info'        => $info
               ]);
            }
         }
      }
   // ----------------------------------------------------------

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
