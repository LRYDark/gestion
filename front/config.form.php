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
   $exclude_keys = ['update', '_glpi_csrf_token', 'is_recursive', 'ConfigModes', 'id', 'key'];
   $encrypted_array = [];

   foreach ($array as $key => $value) {
       if (in_array($key, $exclude_keys) || empty($value)) {
           $encrypted_array[$key] = $value;
       } else {
           $encrypted_array[$key] = encryptData($value);
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
