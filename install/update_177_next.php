<?php
/**
 * Migration 1.7.7 : reparation des Documents GLPI crees par le plugin dont le
 * `filepath` a ete vide par GLPI 11 (Document::filterFields blackliste filepath
 * et sha1sum de l'input => document.send.php resolvait le dossier racine et
 * plantait en Safe\fread).
 *
 * Idempotente : ne touche que les documents dont filepath est vide/NULL, et
 * n'ecrit que si le fichier physique existe sous GLPI_DOC_DIR.
 */
function update_177_next() {
   global $DB;

   $fixed   = 0;
   $skipped = 0;

   $fix = static function (int $doc_id, string $relative_path) use ($DB): bool {
      $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
      $fullpath = GLPI_DOC_DIR . '/' . $relative_path;
      if ($relative_path === '' || !is_file($fullpath)) {
         return false;
      }
      $sha1 = @sha1_file($fullpath);
      return (bool)$DB->update('glpi_documents', [
         'filepath' => $relative_path,
         'sha1sum'  => ($sha1 !== false ? $sha1 : null),
      ], ['id' => $doc_id]);
   };

   // ---- 1. Reparation via les BL du plugin (doc_id => url_bl + filename) ----
   $result = $DB->doQuery("
      SELECT d.id AS doc_id, d.filename, s.url_bl
      FROM glpi_documents d
      INNER JOIN glpi_plugin_gestion_surveys s ON s.doc_id = d.id
      WHERE (d.filepath = '' OR d.filepath IS NULL)
        AND d.filename IS NOT NULL AND d.filename <> ''");
   $remaining = [];
   if ($result) {
      while ($row = $result->fetch_assoc()) {
         $candidate = rtrim((string)$row['url_bl'], '/') . '/' . $row['filename'];
         if ($fix((int)$row['doc_id'], $candidate)) {
            $fixed++;
         } else {
            $remaining[(int)$row['doc_id']] = (string)$row['filename'];
         }
      }
   }

   // ---- 2. Restants : recherche du fichier par son nom sous _plugins/gestion ----
   // (couvre les documents dont url_bl est vide/faux ; match UNIQUE exige, sinon skip)
   $need_scan = !empty($remaining);
   if (!$need_scan) {
      // Documents PDF orphelins non lies a un survey mais crees par le plugin.
      $result = $DB->doQuery("
         SELECT id, filename FROM glpi_documents
         WHERE (filepath = '' OR filepath IS NULL)
           AND mime = 'application/pdf'
           AND filename IS NOT NULL AND filename <> ''");
      if ($result && $result->num_rows > 0) {
         $need_scan = true;
      }
   }

   if ($need_scan) {
      $base = GLPI_DOC_DIR . '/_plugins/gestion';
      $map  = [];   // filename => relative path (false si ambigu)
      if (is_dir($base)) {
         $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
         );
         foreach ($it as $file) {
            if (!$file->isFile()) {
               continue;
            }
            $name = $file->getFilename();
            // Dossiers temporaires : jamais une destination valable.
            if (str_contains($file->getPathname(), 'FilesTempSharePoint')) {
               continue;
            }
            $rel = '_plugins/gestion' . str_replace('\\', '/', substr($file->getPathname(), strlen($base)));
            $map[$name] = array_key_exists($name, $map) ? false : $rel;
         }
      }

      $result = $DB->doQuery("
         SELECT id, filename FROM glpi_documents
         WHERE (filepath = '' OR filepath IS NULL)
           AND mime = 'application/pdf'
           AND filename IS NOT NULL AND filename <> ''");
      if ($result) {
         while ($row = $result->fetch_assoc()) {
            $name = (string)$row['filename'];
            if (!isset($map[$name]) || $map[$name] === false) {
               $skipped++;
               continue;
            }
            if ($fix((int)$row['id'], $map[$name])) {
               $fixed++;
            } else {
               $skipped++;
            }
         }
      }
   }

   if ($fixed > 0 || $skipped > 0) {
      Toolbox::logInFile('plugin-gestion', "[update_177] Reparation filepath documents : $fixed corrige(s), $skipped non repare(s) (fichier introuvable ou nom ambigu)\n");
   }
}
