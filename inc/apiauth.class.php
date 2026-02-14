<?php
if (!defined('GLPI_ROOT')) {
   die('Direct access not allowed');
}

final class PluginGestionApiAuth
{
   private static ?string $cached_raw_input = null;

   public static function authenticateRequest(bool $allow_session = false): array
   {
      global $CFG_GLPI;

      $hl_enabled = Config::isHlApiEnabled();
      $legacy_enabled = !empty($CFG_GLPI['enable_api']);

      if (!$hl_enabled && !$legacy_enabled) {
         return self::error(403, 'api_disabled', 'Aucune API GLPI active (ni v2 HL, ni legacy)');
      }

      $headers_norm = self::getHeadersNormalized();
      $authorization = trim((string)($headers_norm['authorization'] ?? ''));

      if ($authorization !== '' && preg_match('/^bearer\s+(.+)$/i', $authorization)) {
         if (!$hl_enabled) {
            return self::error(403, 'hl_api_disabled', 'API High-Level (v2) desactivee');
         }
         return self::authenticateOAuth($headers_norm);
      }

      if (!$legacy_enabled) {
         return self::error(401, 'missing_bearer_token', 'Authorization: Bearer <token> requis (API v2 active uniquement)');
      }

      return self::authenticateLegacy($headers_norm);
   }

   private static function authenticateOAuth(array $headers_norm): array
   {
      $request = self::buildHlRequest();
      try {
         $client = \Glpi\OAuth\Server::validateAccessToken($request);
      } catch (Throwable $e) {
         $detail = method_exists($e, 'getHint') ? (string)$e->getHint() : $e->getMessage();
         return self::error(401, 'invalid_bearer_token', $detail !== '' ? $detail : 'Token OAuth invalide');
      }

      $scopes = self::normalizeScopes($client['scopes'] ?? []);
      if (!in_array('api', $scopes, true)) {
         return self::error(403, 'missing_api_scope', 'Le token OAuth doit contenir le scope api');
      }

      $user_id = (int)($client['user_id'] ?? 0);
      if ($user_id <= 0) {
         return self::error(403, 'user_context_required', 'Ce endpoint requiert un token utilisateur (pas client_credentials)');
      }

      $user = new User();
      if (!$user->getFromDB($user_id)) {
         return self::error(401, 'oauth_user_not_found', 'Utilisateur du token introuvable');
      }
      if ((int)($user->fields['is_deleted'] ?? 0) === 1 || (int)($user->fields['is_active'] ?? 1) !== 1) {
         return self::error(403, 'user_disabled', 'Utilisateur API inactif');
      }

      self::initTemporarySessionForUser($user, $headers_norm);

      return self::successPayload(
         $user,
         'oauth_v2',
         (string)($client['client_id'] ?? ''),
         0,
         $scopes
      );
   }

   private static function authenticateLegacy(array $headers_norm): array
   {
      global $CFG_GLPI;

      $app_token = trim((string)($headers_norm['app-token'] ?? $headers_norm['x-app-token'] ?? $_REQUEST['app_token'] ?? ''));
      if ($app_token === '') {
         return self::error(401, 'missing_app_token', 'App-Token manquant (mode legacy)');
      }

      $app_check = self::validateLegacyAppToken($app_token);
      if (empty($app_check['ok'])) {
         return $app_check;
      }

      $authorization = trim((string)($headers_norm['authorization'] ?? ''));
      $user_token = '';
      if ($authorization !== '' && preg_match('/^user_token\s+(.+)$/i', $authorization, $matches)) {
         $user_token = trim((string)$matches[1]);
      }
      if ($user_token === '') {
         $user_token = trim((string)($headers_norm['user-token'] ?? $_REQUEST['user_token'] ?? ''));
      }

      $session_token = trim((string)($headers_norm['session-token'] ?? $_REQUEST['session_token'] ?? ''));

      if ($user_token !== '') {
         if (empty($CFG_GLPI['enable_api_login_external_token'])) {
            return self::error(403, 'external_token_disabled', 'Authentification legacy par user_token desactivee');
         }

         $user = new User();
         if (!$user->getFromDBbyToken($user_token, 'api_token')) {
            return self::error(401, 'invalid_user_token', 'user_token invalide');
         }
         if ((int)($user->fields['is_deleted'] ?? 0) === 1 || (int)($user->fields['is_active'] ?? 1) !== 1) {
            return self::error(403, 'user_disabled', 'Utilisateur API inactif');
         }

         self::initTemporarySessionForUser($user, $headers_norm);

         return self::successPayload(
            $user,
            'legacy_user_token',
            '',
            (int)($app_check['api_client_id'] ?? 0),
            []
         );
      }

      if ($session_token !== '') {
         $user_id = self::startLegacySessionToken($session_token);
         if ($user_id <= 0) {
            return self::error(401, 'invalid_session_token', 'Session-Token invalide');
         }

         $user = new User();
         if (!$user->getFromDB($user_id)) {
            return self::error(401, 'legacy_user_not_found', 'Utilisateur de session introuvable');
         }
         if ((int)($user->fields['is_deleted'] ?? 0) === 1 || (int)($user->fields['is_active'] ?? 1) !== 1) {
            return self::error(403, 'user_disabled', 'Utilisateur API inactif');
         }

         self::applyContextHeaders($headers_norm);

         return self::successPayload(
            $user,
            'legacy_session_token',
            '',
            (int)($app_check['api_client_id'] ?? 0),
            []
         );
      }

      return self::error(401, 'missing_legacy_token', 'Fournir user_token (preferences) ou Session-Token (legacy)');
   }

   private static function buildHlRequest(): \Glpi\Http\Request
   {
      $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
      $path = '/';
      if (!empty($_SERVER['REQUEST_URI'])) {
         $parsed = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
         if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
         }
      }

      $headers = self::getHeadersRaw();
      $body = self::getRawInputBody();

      return new \Glpi\Http\Request($method, $path, $headers, $body);
   }

   private static function initTemporarySessionForUser(User $user, array $headers_norm): void
   {
      $auth = new Auth();
      $auth->auth_succeded = true;
      $auth->user = new User();
      $auth->user->getFromDB((int)$user->fields['id']);
      Session::init($auth);

      self::applyContextHeaders($headers_norm);
   }

   private static function startLegacySessionToken(string $session_token): int
   {
      $session_token = trim($session_token);
      if ($session_token === '') {
         return 0;
      }

      $current = session_id();
      if ($current !== '' && $current !== $session_token) {
         session_write_close();
      }
      if ($current !== $session_token) {
         session_id($session_token);
         Session::start();
         Session::loadLanguage();
      }

      return (int)Session::getLoginUserID(false);
   }

   private static function applyContextHeaders(array $headers_norm): void
   {
      $profile_header = trim((string)($headers_norm['glpi-profile'] ?? ''));
      if ($profile_header !== '' && is_numeric($profile_header)) {
         Session::changeProfile((int)$profile_header);
      }

      $entity_header = trim((string)($headers_norm['glpi-entity'] ?? ''));
      if ($entity_header !== '' && is_numeric($entity_header)) {
         $recursive = strtolower((string)($headers_norm['glpi-entity-recursive'] ?? 'false')) === 'true';
         Session::changeActiveEntities((int)$entity_header, $recursive);
      }
   }

   public static function getRawInputBody(): string
   {
      if (self::$cached_raw_input !== null) {
         return self::$cached_raw_input;
      }

      self::$cached_raw_input = (string)(file_get_contents('php://input') ?: '');
      return self::$cached_raw_input;
   }

   private static function validateLegacyAppToken(string $app_token): array
   {
      $ip_txt = (string)Toolbox::getRemoteIpAddress();
      $is_ipv4 = !str_contains($ip_txt, ':');
      $ip_num = $is_ipv4 ? ip2long($ip_txt) : false;

      if ($is_ipv4 && $ip_num === false) {
         return self::error(403, 'invalid_client_ip', 'IP client invalide');
      }

      $where_ip = [];
      if ($is_ipv4) {
         $where_ip = [
            'OR' => [
               'ipv4_range_start' => null,
               [
                  'ipv4_range_start' => ['<=', $ip_num],
                  'ipv4_range_end'   => ['>=', $ip_num],
               ],
            ],
         ];
      } else {
         $where_ip = [
            'OR' => [
               ['ipv6' => null],
               ['ipv6' => $ip_txt],
            ],
         ];
      }

      $api_client = new APIClient();
      $clients = $api_client->find(['is_active' => 1] + $where_ip);
      if (count($clients) === 0) {
         return self::error(403, 'api_client_ip_not_allowed', 'Aucun client API actif pour cette IP');
      }

      $key = new GLPIKey();
      foreach ($clients as $client_id => $client) {
         $encrypted = (string)($client['app_token'] ?? '');
         if ($encrypted === '') {
            continue;
         }
         try {
            $plain = (string)$key->decrypt($encrypted);
         } catch (Throwable $e) {
            $plain = '';
         }
         if ($plain !== '' && hash_equals($plain, $app_token)) {
            return [
               'ok'            => true,
               'api_client_id' => (int)$client_id,
            ];
         }
      }

      return self::error(401, 'invalid_app_token', 'App-Token invalide');
   }

   private static function normalizeScopes(mixed $scopes): array
   {
      if (!is_array($scopes)) {
         return [];
      }

      $out = [];
      foreach ($scopes as $scope) {
         if (is_string($scope) && $scope !== '') {
            $out[] = $scope;
            continue;
         }
         if (is_object($scope) && method_exists($scope, 'getIdentifier')) {
            $id = (string)$scope->getIdentifier();
            if ($id !== '') {
               $out[] = $id;
            }
         }
      }

      return array_values(array_unique($out));
   }

   private static function successPayload(
      User $user,
      string $auth_method,
      string $oauth_client_id,
      int $api_client_id,
      array $scopes
   ): array {
      $login = (string)($user->fields['name'] ?? '');
      $label = trim((string)($user->fields['realname'] ?? '') . ' ' . (string)($user->fields['firstname'] ?? ''));
      if ($label === '') {
         $label = $login !== '' ? $login : ('#' . (int)($user->fields['id'] ?? 0));
      }

      return [
         'ok'              => true,
         'user_id'         => (int)($user->fields['id'] ?? 0),
         'tech_login'      => $login,
         'tech_label'      => $label,
         'auth_method'     => $auth_method,
         'oauth_client_id' => $oauth_client_id,
         'api_client_id'   => $api_client_id,
         'oauth_scopes'    => $scopes,
      ];
   }

   private static function error(int $code, string $error, string $message): array
   {
      return [
         'ok'      => false,
         'code'    => $code,
         'error'   => $error,
         'message' => $message,
      ];
   }

   private static function getHeadersRaw(): array
   {
      if (function_exists('getallheaders')) {
         $raw = getallheaders();
         if (is_array($raw) && !empty($raw)) {
            return $raw;
         }
      }

      $headers = [];
      foreach ($_SERVER as $key => $value) {
         if (!str_starts_with((string)$key, 'HTTP_')) {
            continue;
         }
         $name = str_replace('_', '-', substr((string)$key, 5));
         $name = implode('-', array_map('ucfirst', explode('-', strtolower($name))));
         $headers[$name] = (string)$value;
      }
      return $headers;
   }

   private static function getHeadersNormalized(): array
   {
      $headers = [];
      foreach (self::getHeadersRaw() as $key => $value) {
         $headers[strtolower((string)$key)] = (string)$value;
      }
      return $headers;
   }
}
