<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('gestion') || !$plugin->isActivated('gestion')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

$config = new PluginGestionConfig();

function encryptData($data, $encryption_key) {
   return base64_encode(openssl_encrypt($data, 'aes-256-cbc', $encryption_key, 0, '1234567890123456'));
}

function encryptArray($array) {
   $exclude_keys = ['update', '_glpi_csrf_token', 'is_recursive', 'ConfigModes', 'id', 'key'];
   $encrypted_array = [];
   $special_encryption_key = '82}4G)Ar2?WwYR4hsT[3d4]s+'; // Clé différente pour EncryptionKey

   foreach ($array as $key => $value) {
       if (in_array($key, $exclude_keys) || empty($value)) {
           $encrypted_array[$key] = $value;
       } else {
           if ($key === 'EncryptionKey') {
               $encrypted_array[$key] = encryptData($value, $special_encryption_key); // Utilise la clé différente
           } else {
               $encrypted_array[$key] = encryptData($value, $_POST['EncryptionKey']);
           }
       }
   }

   return $encrypted_array;
}

if (isset($_POST["update"])) {
   $config->check($_POST['id'], UPDATE);
   if($config->fields['key'] == 1){
      $encrypted_post = encryptArray($_POST);
   }else{
      $encrypted_post = $_POST;
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
