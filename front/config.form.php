<?php
include('../../../inc/includes.php');
global $DB;

$plugin = new Plugin();
if (!$plugin->isInstalled('gestion') || !$plugin->isActivated('gestion')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

$config = new PluginGestionConfig();

function encryptArray($array, array $stored = []) {
   static $include_keys_map = null;
   if ($include_keys_map === null) {
      $include_keys_map = array_flip(['SageToken']);
   }
   $encrypted_array = [];

   foreach ($array as $key => $value) {
       // Secrets chiffrés avec la clé de GLPI. Un secret illisible laissé vide n'est pas écrasé, et rien ne part
       // en clair si la clé de GLPI est illisible (cf. PluginGestionCrypto::valueToSave()).
       if (isset($include_keys_map[$key]) && is_scalar($value)) {
           $to_save = PluginGestionCrypto::valueToSave((string)$value, $stored[$key] ?? null);
           if ($to_save !== null) {
               $encrypted_array[$key] = $to_save;
           }
       } else {
           $encrypted_array[$key] = $value;
       }
   }
   return $encrypted_array;
}

function pluginGestionCheckCSRF(array $data): void {
   if (!empty($data['plugin_gestion_csrf_token'])) {
      Session::checkCSRF([
         '_glpi_csrf_token' => (string)$data['plugin_gestion_csrf_token']
      ], true);
      return;
   }

   Session::checkCSRF($data, true);
}

// ── Actions device kiosque (ban / unban / delete) — traitement avant le formulaire principal ──
$deviceActionPayload = (string)($_POST['device_action_submit'] ?? '');
if ($deviceActionPayload !== '' || (isset($_POST['action_device']) && isset($_POST['device_row_id']))) {
   Session::checkRight('config', UPDATE);
   pluginGestionCheckCSRF($_POST);

   $devId     = 0;
   $devAction = '';
   if ($deviceActionPayload !== '') {
      [$devAction, $devIdRaw] = array_pad(explode(':', $deviceActionPayload, 2), 2, '');
      $devId = (int)$devIdRaw;
   } else {
      $devId     = (int)$_POST['device_row_id'];
      $devAction = (string)$_POST['action_device'];
   }
   $devTable  = 'glpi_plugin_gestion_devices';

   if ($devId > 0 && $DB->tableExists($devTable)) {
      switch ($devAction) {
         case 'ban':
            $DB->update($devTable, ['status' => 'banned'], ['id' => $devId]);
            Session::addMessageAfterRedirect(__('Appareil banni.', 'gestion'), false, WARNING);
            break;
         case 'unban':
            $DB->update($devTable, ['status' => 'active'], ['id' => $devId]);
            Session::addMessageAfterRedirect(__('Appareil débanni.', 'gestion'), false, INFO);
            break;
         case 'delete':
            $DB->delete($devTable, ['id' => $devId]);
            Session::addMessageAfterRedirect(__('Appareil supprimé.', 'gestion'), false, INFO);
            break;
      }
   }
   Html::back();
}

if (isset($_POST["update"])) {
   pluginGestionCheckCSRF($_POST);
   $encrypted_post = encryptArray($_POST, $config->fields);

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
   // ===== AJOUTS POUR SIGNATURE BL À L'AJOUT DE TÂCHE =====
      if (isset($encrypted_post['TaskSignatureUsers']) && is_array($encrypted_post['TaskSignatureUsers'])) {
         $ids = array_map('intval', $encrypted_post['TaskSignatureUsers']);
         $encrypted_post['TaskSignatureUsers'] = json_encode(array_values($ids));
      }
      if (isset($encrypted_post['TaskSignatureTriggerStates']) && is_array($encrypted_post['TaskSignatureTriggerStates'])) {
         $ids = array_map('intval', $encrypted_post['TaskSignatureTriggerStates']);
         $encrypted_post['TaskSignatureTriggerStates'] = json_encode(array_values($ids));
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

   // ── Devices kiosque : gérés via action_device (ban/unban/delete) ci-dessus.
   // ── Base Description / Info : supprimé en v1.7.0_alpha1 — aucun traitement.
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
