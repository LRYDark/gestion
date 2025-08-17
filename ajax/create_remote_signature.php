<?php
/**
 * plugins/gestion/ajax/create_remote_signature.php
 * Version simplifiée pour créer une demande de signature distante
 */

include ('../../../inc/includes.php');
header('Content-Type: application/json; charset=UTF-8');

global $DB, $CFG_GLPI;
$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

// If missing, go to login
if ($config->fields['RemoteSignatureOn'] == 0) {
   exit;
}

try {
   // Vérifier que l'utilisateur est connecté
   Session::checkLoginUser();

   // Récupérer les paramètres
   $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
   $device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';
   $device_token = isset($_POST['device_token']) ? trim($_POST['device_token']) : '';
   
   // NOUVEAU : Récupérer les paramètres additionnels avec correction des antislashs
   $parameters = isset($_POST['parameters']) ? stripslashes(trim($_POST['parameters'])) : '';
   
   // Valider que c'est du JSON valide si fourni
   $parameters_json = null;
   if (!empty($parameters)) {
      $decoded = json_decode($parameters, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
         http_response_code(400);
         echo json_encode(['ok' => false, 'error' => 'Paramètres JSON invalides: ' . json_last_error_msg()]);
         exit;
      }
      $parameters_json = $parameters;
   }

   // Validation des paramètres
   if ($ticket_id <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'ID ticket manquant']);
      exit;
   }
   
   if ($device_id === '') {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'ID device manquant']);
      exit;
   }
   
   if ($device_token === '') {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Token device manquant']);
      exit;
   }

   // Vérifier que la tablette existe et est active
   $device_sql = "SELECT id, device_id, device_token, is_active 
                  FROM glpi_plugin_gestion_signaturedevices 
                  WHERE device_id = '" . $DB->escape($device_id) . "' 
                  AND is_active = 1 
                  LIMIT 1";
   
   $device_result = $DB->query($device_sql);
   if (!$device_result || $DB->numrows($device_result) === 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Device non trouvé ou inactif']);
      exit;
   }
   
   $device_row = $DB->fetchAssoc($device_result);
   if ($device_row['device_token'] !== $device_token) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Token device invalide']);
      exit;
   }

    // Vérifier s'il existe déjà une demande en attente pour ce ticket
   $check_sql = "SELECT id, status 
                 FROM glpi_plugin_gestion_remote_sign_requests 
                 WHERE tickets_id = " . (int)$ticket_id . " 
                 AND status IN ('pending', 'cancelled') 
                 ORDER BY id DESC
                 LIMIT 1";
   
   $check_result = $DB->query($check_sql);
   if ($check_result && $DB->numrows($check_result) > 0) {
      $existing_row = $DB->fetchAssoc($check_result);
      
      // Si la demande est en attente, la retourner
      if ($existing_row['status'] === 'pending') {
         echo json_encode([
            'ok' => true, 
            'request_id' => (int)$existing_row['id'], 
            'message' => 'Demande existante trouvée'
         ]);
         exit;
      }
      
      // Si la demande a été annulée/refusée, la remettre en pending
      if ($existing_row['status'] === 'cancelled') {
         $now = date('Y-m-d H:i:s');
         $update_sql = "UPDATE glpi_plugin_gestion_remote_sign_requests 
                        SET status = 'pending', 
                            date_creation = '" . $now . "',
                            parameters = " . ($parameters_json ? "'" . $DB->escape($parameters_json) . "'" : "NULL") . ",
                            signer_name = NULL,
                            signer_email = NULL,
                            signature_base64 = NULL
                        WHERE id = " . (int)$existing_row['id'];
         
         if ($DB->query($update_sql)) {
            echo json_encode([
               'ok' => true, 
               'request_id' => (int)$existing_row['id'],
               'message' => 'Demande réactivée après refus'
            ]);
            exit;
         } else {
            // En cas d'erreur de mise à jour, créer une nouvelle demande
         }
      }
   }

   // Créer une nouvelle demande de signature
   $now = date('Y-m-d H:i:s');
   $insert_sql = "INSERT INTO glpi_plugin_gestion_remote_sign_requests 
                  (tickets_id, device_id, device_token, parameters, status, date_creation) 
                  VALUES (" . (int)$ticket_id . ", 
                          '" . $DB->escape($device_id) . "', 
                          '" . $DB->escape($device_token) . "', 
                          " . ($parameters_json ? "'" . $DB->escape($parameters_json) . "'" : "NULL") . ", 
                          'pending', 
                          '" . $now . "')";

   if ($DB->query($insert_sql)) {
      $request_id = $DB->insertId();
      echo json_encode([
         'ok' => true, 
         'request_id' => (int)$request_id,
         'message' => 'Demande de signature créée'
      ]);
   } else {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Erreur lors de la création de la demande']);
   }

} catch (Exception $e) {
   http_response_code(500);
   echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>