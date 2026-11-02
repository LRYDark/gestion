<?php
/**
 * PluginGestionCrypto — chiffrement des secrets du plugin avec la clé de GLPI (GLPIKey).
 *
 * Les secrets étaient chiffrés avec une clé propre au plugin, lue en dur dans `<GLPI>/config/` — qui n'est pas
 * forcément le dossier de configuration de GLPI (GLPI_CONFIG_DIR) — et, faute de clé, une nouvelle était générée
 * sans prévenir. Une mise à jour de GLPI, ou une clé changée par GLPI, rendait alors les secrets illisibles :
 * le formulaire les affichait vides, et l'enregistrer les effaçait.
 *
 * Désormais :
 *  - chiffrement par GLPIKey, au format de GLPI : la clé est celle de GLPI, là où GLPI la range ;
 *  - les champs sont déclarés à GLPI (hook `secured_fields`) : s'il change de clé, il les rechiffre lui-même ;
 *  - les anciennes valeurs restent lisibles (anciennes clés, en lecture seule) et sont réécrites au format GLPI
 *    à la mise à jour du plugin ;
 *  - aucune clé n'est plus jamais générée par le plugin ;
 *  - une valeur illisible est signalée, et un champ laissé vide ne l'écrase pas.
 *
 * Copie propre à ce plugin : la même classe existe, au nom près, dans les autres plugins qui en ont besoin
 * (chacun tourne sans les autres).
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

final class PluginGestionCrypto
{
   /** Préfixes des valeurs de l'ancien chiffrement sodium des plugins (clé dérivée d'un fichier de clé). */
   private const LEGACY_SODIUM_PREFIXES = ['sbox:', 'wc_sodium:'];
   /** Vecteur fixe de l'ancien chiffrement OpenSSL. */
   private const LEGACY_OPENSSL_IV = '1234567890123456';

   private static ?string $glpi_key = null;
   private static ?array $legacy_keys = null;

   /** Chiffre avec la clé de GLPI. '' si $plain est vide, ou si la clé de GLPI est illisible (rien n'est stocké en clair). */
   public static function encrypt(string $plain): string
   {
      if ($plain === '') {
         return '';
      }
      return (new GLPIKey())->encrypt($plain);
   }

   /**
    * Texte clair d'une valeur stockée : '' si elle est vide, null si elle est illisible (clé de GLPI changée ou
    * perdue). Lit le format GLPI, puis les anciens formats du plugin.
    */
   public static function decrypt(?string $stored): ?string
   {
      if ($stored === null || $stored === '') {
         return '';
      }
      return self::decryptGlpi($stored) ?? self::decryptLegacy($stored);
   }

   /** Vrai si une valeur est enregistrée mais ne peut plus être lue. */
   public static function isUnreadable(?string $stored): bool
   {
      return $stored !== null && $stored !== '' && self::decrypt($stored) === null;
   }

   /**
    * Valeur réécrite au format GLPI (migration). La valeur telle quelle si elle y est déjà, null si elle est
    * illisible : on n'y touche pas, une clé restaurée pourra encore la rendre lisible.
    */
   public static function upgrade(?string $stored): ?string
   {
      if ($stored === null || $stored === '' || self::decryptGlpi($stored) !== null) {
         return $stored;
      }
      $plain = self::decryptLegacy($stored);
      if ($plain === null) {
         return null;
      }
      $new = self::encrypt($plain);
      return $new !== '' ? $new : null;
   }

   /**
    * Valeur à enregistrer pour un champ secret saisi dans un formulaire, ou null pour NE PAS toucher à la valeur
    * stockée : champ laissé vide alors que la valeur enregistrée est illisible (l'effacer la perdrait pour de bon),
    * ou clé de GLPI illisible (rien ne doit partir en clair).
    */
   public static function valueToSave(string $submitted, ?string $stored): ?string
   {
      if ($submitted === '') {
         return self::isUnreadable($stored) ? null : '';
      }
      $cipher = self::encrypt($submitted);
      return $cipher !== '' ? $cipher : null;
   }

   /** Avertissement HTML à afficher sous un champ dont la valeur enregistrée est illisible, sinon ''. */
   public static function unreadableNotice(?string $stored): string
   {
      if (!self::isUnreadable($stored)) {
         return '';
      }
      return '<div class="text-danger small mt-1"><i class="ti ti-alert-triangle me-1"></i>'
         . htmlspecialchars(
            __('Valeur enregistrée illisible : la clé de chiffrement de GLPI a changé ou a été perdue. Ressaisissez-la ; tant que le champ reste vide, l\'ancienne valeur est conservée.', 'gestion'),
            ENT_QUOTES,
            'UTF-8'
         )
         . '</div>';
   }

   /* ---------------------------------------------------------------- format GLPI */

   /** Clé de GLPI (lecture seule, GLPIKey signale lui-même une clé absente). */
   private static function glpiKey(): ?string
   {
      if (self::$glpi_key === null) {
         self::$glpi_key = (new GLPIKey())->get() ?? '';
      }
      return self::$glpi_key !== '' ? self::$glpi_key : null;
   }

   /**
    * Même format que GLPIKey::decrypt(), sans son avertissement PHP en cas d'échec : un échec est attendu quand
    * la valeur est encore à l'ancien format.
    */
   private static function decryptGlpi(string $stored): ?string
   {
      $key = self::glpiKey();
      if ($key === null || self::legacySodiumPrefix($stored) !== null) {
         return null;
      }
      $bin = base64_decode($stored, true);
      $len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
      if ($bin === false || strlen($bin) <= $len) {
         return null;
      }
      $nonce = substr($bin, 0, $len);
      try {
         $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bin, $len), $nonce, $nonce, $key);
      } catch (Throwable $e) {
         return null;
      }
      return is_string($plain) ? $plain : null;
   }

   /* ---------------------------------------------------------------- anciens formats (lecture seule) */

   /** Anciennes clés possibles, lues sans jamais en créer : clé « plugin » puis clé de GLPI, dans les deux dossiers. */
   private static function legacyKeys(): array
   {
      if (self::$legacy_keys !== null) {
         return self::$legacy_keys;
      }
      $dirs = [GLPI_ROOT . '/config'];
      if (defined('GLPI_CONFIG_DIR')) {
         $dirs[] = GLPI_CONFIG_DIR;
      }
      $keys = [];
      foreach ($dirs as $dir) {
         foreach (['glpicrypt_plugin_gestion.key', 'glpicrypt.key'] as $name) {
            $file = rtrim($dir, '/\\') . '/' . $name;
            if (is_file($file) && is_readable($file)) {
               $content = @file_get_contents($file);
               if (is_string($content) && $content !== '') {
                  $keys[] = $content;
               }
            }
         }
      }
      // Clé vide : ancien chiffrement OpenSSL quand le fichier de clé manquait.
      $keys[] = '';
      return self::$legacy_keys = array_values(array_unique($keys));
   }

   private static function decryptLegacy(string $stored): ?string
   {
      $prefix = self::legacySodiumPrefix($stored);
      if ($prefix !== null) {
         $bin = base64_decode(substr($stored, strlen($prefix)), true);
         $len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
         if ($bin === false || strlen($bin) <= $len) {
            return null;
         }
         foreach (self::legacyKeys() as $key) {
            if ($key === '') {
               continue;
            }
            try {
               $plain = sodium_crypto_secretbox_open(substr($bin, $len), substr($bin, 0, $len), hash('sha256', $key, true));
            } catch (Throwable $e) {
               $plain = false;
            }
            if (is_string($plain)) {
               return $plain;
            }
         }
         return null;
      }

      $bin = base64_decode($stored, true);
      if ($bin === false) {
         // Pas du base64 : jamais chiffrée (valeur saisie avant le chiffrement), elle est déjà en clair.
         return self::isPlausible($stored) ? $stored : null;
      }
      foreach (self::legacyKeys() as $key) {
         // IV + HMAC + texte chiffré (authentifié : une mauvaise clé est reconnue).
         if ($key !== '' && strlen($bin) > 48) {
            $cipher = substr($bin, 48);
            if (hash_equals(substr($bin, 16, 32), hash_hmac('sha256', $cipher, $key, true))) {
               $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($bin, 0, 16));
               if (is_string($plain)) {
                  return $plain;
               }
            }
         }
         // OpenSSL à vecteur fixe, encodé en base64 deux fois (non authentifié : résultat contrôlé).
         $plain = @openssl_decrypt($bin, 'aes-256-cbc', $key, 0, self::LEGACY_OPENSSL_IV);
         if (is_string($plain) && $plain !== '' && self::isPlausible($plain)) {
            return $plain;
         }
      }
      return null;
   }

   private static function legacySodiumPrefix(string $stored): ?string
   {
      foreach (self::LEGACY_SODIUM_PREFIXES as $prefix) {
         if (str_starts_with($stored, $prefix)) {
            return $prefix;
         }
      }
      return null;
   }

   /** Texte lisible : UTF-8 valide, sans caractère de contrôle (écarte le résultat d'une mauvaise clé). */
   private static function isPlausible(string $plain): bool
   {
      return preg_match('//u', $plain) === 1 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $plain) === 0;
   }
}
