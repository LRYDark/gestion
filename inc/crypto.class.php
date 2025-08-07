<?php
if (!defined('GLPI_ROOT')) {
   die('Direct access not allowed');
}

final class PluginGestionCrypto
{
   private const CONFIG_DIR = GLPI_ROOT.'/config/';
   private const PLUGIN_KEY = self::CONFIG_DIR.'glpicrypt_plugin_gestion.key';
   private const LEGACY_KEY = self::CONFIG_DIR.'glpicrypt.key';

   private const CIPHER    = 'aes-256-cbc';
   private const IV_BYTES  = 16;               // 128 bits
   private static ?string $key = null;        // mémoïsation

   /* ----------- clé 32 octets, générée au besoin ----------- */
   public static function key(): string
   {
      if (self::$key !== null) {
         return self::$key;
      }
      foreach ([self::PLUGIN_KEY, self::LEGACY_KEY] as $file) {
         if (is_readable($file)) {
            $k = file_get_contents($file);
            if ($k !== false && strlen($k) >= 32) {
               return self::$key = $k;
            }
            throw new RuntimeException("Clé corrompue : $file");
         }
      }
      /* aucune clé → création */
      $k = random_bytes(32);
      if (!is_dir(self::CONFIG_DIR) && !mkdir(self::CONFIG_DIR, 0700, true)) {
         throw new RuntimeException('Impossible de créer le dossier config');
      }
      $tmp = self::PLUGIN_KEY.'.tmp';
      if (file_put_contents($tmp, $k, LOCK_EX) === false ||
          !chmod($tmp, 0600) ||
          !rename($tmp, self::PLUGIN_KEY)) {
         @unlink($tmp);
         throw new RuntimeException('Écriture de la clé échouée');
      }
      return self::$key = $k;
   }

   /* ----------- chiffrement IV aléatoire + HMAC ----------- */
   public static function encrypt(string $plain): string
   {
      $iv     = random_bytes(self::IV_BYTES);
      $cipher = openssl_encrypt($plain, self::CIPHER, self::key(),
                                OPENSSL_RAW_DATA, $iv);
      if ($cipher === false) {
         throw new RuntimeException(openssl_error_string());
      }
      $hmac = hash_hmac('sha256', $cipher, self::key(), true);
      return base64_encode($iv.$hmac.$cipher);
   }

   /* ----------- déchiffrement (nouveau › legacy) ----------- */
   public static function decrypt(?string $stored): ?string
   {
      if ($stored === '' || $stored === null) return null;

      /* nouveau format */
      $bin = base64_decode($stored, true);
      if ($bin !== false && strlen($bin) > (self::IV_BYTES + 32)) {
         $iv     = substr($bin, 0, self::IV_BYTES);
         $hmac   = substr($bin, self::IV_BYTES, 32);
         $cipher = substr($bin, self::IV_BYTES + 32);
         if (hash_equals($hmac,
               hash_hmac('sha256', $cipher, self::key(), true))) {
            $plain = openssl_decrypt($cipher, self::CIPHER, self::key(),
                                     OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) return $plain;
         }
      }

      /* ancien format – IV fixe + double B64 */
      $legacy = openssl_decrypt(base64_decode($stored, true),
                                self::CIPHER, self::key(), 0,
                                '1234567890123456');
      return $legacy === false ? null : $legacy;
   }
}
