<?php

/**
 * Journalisation centralisee du plugin gestion.
 *
 * Ecrit dans <GLPI_LOG_DIR>/plugin-gestion.log via Toolbox::logInFile() :
 * l'emplacement suit la configuration GLPI (GLPI_LOG_DIR peut etre deplace)
 * et le reglage "Journaux dans les fichiers" est respecte.
 * Le fichier apparait automatiquement dans
 * Administration > Journaux > Fichier de log (comme plugin-oauthsso.log).
 *
 * Ne JAMAIS journaliser de secrets (tokens CSRF/OAuth, mots de passe, App-Token).
 */
class PluginGestionLogger {

   const LOGFILE = 'plugin-gestion';

   /**
    * Volontairement SANS effet.
    *
    * Le fichier se remplissait de lignes de fonctionnement normal — mails
    * envoyés, bons signés — qui n'apprenaient rien et noyaient les seules
    * lignes qu'on vient y chercher : ce qui a échoué. Le journal ne retient
    * donc plus que les avertissements et les erreurs.
    *
    * La méthode est conservée : elle est appelée depuis une trentaine
    * d'endroits, et les messages correspondants restent affichés à l'écran par
    * `Session::addMessageAfterRedirect()` — c'est le fichier qu'on allège, pas
    * le retour à l'utilisateur. Rétablir la trace complète tient en une ligne :
    * décommenter l'appel ci-dessous.
    */
   static function info(string $context, string $message): void {
      // self::write('INFO', $context, $message);
   }

   static function warning(string $context, string $message): void {
      self::write('WARN', $context, $message);
   }

   static function error(string $context, string $message): void {
      self::write('ERROR', $context, $message);
   }

   /**
    * Journalise les reponses en erreur (HTTP >= 400) des endpoints API tablettes.
    * A appeler depuis les helpers *_end() / *_jexit() avec le payload JSON final.
    */
   static function apiResponse(int $status, array $payload): void {
      if ($status < 400) {
         return;
      }
      // Sous GLPI 11 tout passe par public/index.php : SCRIPT_NAME ne reflete pas
      // le script legacy, on derive donc le nom de l'endpoint depuis l'URL appelee.
      $uri_path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
      $endpoint = basename(is_string($uri_path) && $uri_path !== '' ? $uri_path : 'api');
      $detail   = (string)($payload['error'] ?? '');
      if (!empty($payload['message'])) {
         $detail .= ($detail !== '' ? ' - ' : '') . (string)$payload['message'];
      }
      self::write('ERROR', 'api:' . $endpoint, 'HTTP ' . $status . ($detail !== '' ? ' : ' . $detail : ''));
   }

   private static function write(string $level, string $context, string $message): void {
      try {
         Toolbox::logInFile(self::LOGFILE, '[' . $level . '] [' . $context . '] ' . $message . "\n");
      } catch (\Throwable $e) {
         // La journalisation ne doit jamais faire echouer le flux appelant.
      }
   }
}
