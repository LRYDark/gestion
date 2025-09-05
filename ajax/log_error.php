<?php
/**
 * plugins/gestion/ajax/log_error.php
 * Logger les erreurs JavaScript côté signature déportée
 */

require_once dirname(__DIR__, 3) . '/inc/includes.php';
@ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');

function get_log_path(): string {
   if (defined('GLPI_LOG_DIR') && GLPI_LOG_DIR) $base = GLPI_LOG_DIR;
   elseif ($t = ini_get('sys_temp_dir'))        $base = $t;
   elseif (function_exists('sys_get_temp_dir')) $base = sys_get_temp_dir();
   else                                         $base = '/var/tmp';
   return rtrim($base, '/').'/device_submit_dbg.log';
}

try {
   // Lire les données JSON du body
   $raw_input = file_get_contents('php://input');
   $data = json_decode($raw_input, true);
   
   if (!is_array($data)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
      exit;
   }
   
   // Ajouter des infos contextuelles
   $log_entry = array_merge($data, [
      'source' => 'javascript_error',
      'user_id' => Session::getLoginUserID(),
      'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
   ]);
   
   // Écrire dans le même fichier que device_submit
   $log_path = get_log_path();
   $log_line = json_encode($log_entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
   
   @file_put_contents($log_path, $log_line, FILE_APPEND | LOCK_EX);
   
   echo json_encode(['ok' => true]);
   
} catch (Throwable $e) {
   http_response_code(500);
   echo json_encode(['ok' => false, 'error' => 'Logging failed']);
}