<?php
if (!defined('GLPI_ROOT')) {
   die('Direct access not allowed');
}

final class PluginGestionCrypto
{
   private const CONFIG_DIR = GLPI_ROOT.'/config/';
   private const PLUGIN_KEY = self::CONFIG_DIR.'glpicrypt_plugin_gestion.key';
   private const LEGACY_KEY = self::CONFIG_DIR.'glpicrypt.key';
   private const SODIUM_PREFIX = 'sbox:';

   private const CIPHER    = 'aes-256-cbc';
   private const IV_BYTES  = 16;               // 128 bits
   private static ?string $key = null;        // mémoïsation
   private static ?string $sodium_key = null; // clé dérivée mémoïsée

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

   private static function sodiumKey(): string
   {
      if (self::$sodium_key !== null) {
         return self::$sodium_key;
      }
      if (!function_exists('sodium_crypto_secretbox_keygen')) {
         throw new RuntimeException('Extension sodium requise pour le chiffrement des données du plugin.');
      }

      return self::$sodium_key = hash('sha256', self::key(), true);
   }

   public static function isSodiumCiphertext(?string $stored): bool
   {
      return is_string($stored) && $stored !== '' && str_starts_with($stored, self::SODIUM_PREFIX);
   }

   public static function migrateIfLegacy(?string $stored): ?string
   {
      if ($stored === null || $stored === '' || self::isSodiumCiphertext($stored)) {
         return $stored;
      }

      $plain = self::decryptLegacy($stored);
      if ($plain === null) {
         return $stored;
      }

      return self::encrypt($plain);
   }

   /* ----------- chiffrement sodium (secretbox) ----------- */
   public static function encrypt(string $plain): string
   {
      if ($plain === '') {
         return '';
      }

      if (!function_exists('sodium_crypto_secretbox')) {
         throw new RuntimeException('Extension sodium requise pour le chiffrement des données du plugin.');
      }

      $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      $cipher = sodium_crypto_secretbox($plain, $nonce, self::sodiumKey());

      return self::SODIUM_PREFIX . base64_encode($nonce . $cipher);
   }

   private static function decryptSodium(string $stored): ?string
   {
      if (!function_exists('sodium_crypto_secretbox_open')) {
         throw new RuntimeException('Extension sodium requise pour le déchiffrement des données du plugin.');
      }

      $payload = substr($stored, strlen(self::SODIUM_PREFIX));
      $bin = base64_decode($payload, true);
      if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
         return null;
      }

      $nonce  = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::sodiumKey());

      return ($plain === false) ? null : $plain;
   }

   private static function decryptLegacy(string $stored): ?string
   {
      $bin = base64_decode($stored, true);

      if ($bin !== false && strlen($bin) > (self::IV_BYTES + 32)) {
         $iv     = substr($bin, 0, self::IV_BYTES);
         $hmac   = substr($bin, self::IV_BYTES, 32);
         $cipher = substr($bin, self::IV_BYTES + 32);
         if (hash_equals($hmac, hash_hmac('sha256', $cipher, self::key(), true))) {
            $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
               return $plain;
            }
         }
      }

      $legacy = openssl_decrypt(
         $bin === false ? '' : $bin,
         self::CIPHER,
         self::key(),
         0,
         '1234567890123456'
      );

      return $legacy === false ? null : $legacy;
   }

   /* ----------- déchiffrement (sodium › legacy OpenSSL) ----------- */
   public static function decrypt(?string $stored): ?string
   {
      if ($stored === '' || $stored === null) {
         return null;
      }

      if (self::isSodiumCiphertext($stored)) {
         return self::decryptSodium($stored);
      }

      return self::decryptLegacy($stored);
   }
}
