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
      $tbl  = 'glpi_plugin_gestion_configsfolder';
      $rows = $_POST['folders'] ?? [];

      foreach ($rows as $key => $data) {
         $name   = trim($data['folder_name'] ?? '');
         $param  = (int)($data['params'] ?? 8);
         $del    = (int)($data['_delete'] ?? 0);

         if (is_numeric($key)) {
            $id = (int)$key;
            if ($del === 1) {
            $DB->delete($tbl, ['id' => $id]);
            } else {
            $DB->update($tbl, [
               'folder_name' => $name,
               'params'      => $param
            ], ['id' => $id]);
            }
         } else {
            // nouvelle ligne
            $has = ($name !== '');
            if ($del !== 1 && $has) {
            $DB->insert($tbl, [
               'folder_name' => $name,
               'params'      => $param
            ]);
            }
         }
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
   //-----------------------------------------------------------

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
