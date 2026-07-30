<?php
/**
 * Migration 1.7.0_alpha1
 *
 * OBJECTIFS :
 *   1. Créer glpi_plugin_gestion_devices (serial + ip + name + last_seen + status)
 *   2. Migrer glpi_plugin_gestion_remote_sign_requests : remplacer device_id/device_token par device_serial
 *   3. Supprimer glpi_plugin_gestion_baseitems (Base Description / Info)
 *   4. Supprimer glpi_plugin_gestion_signaturedevices (ancien schéma token)
 *   5. Nettoyer glpi_plugin_gestion_configs : retirer toute colonne relative à device_sign / base_description
 *
 * @return bool
 */
function update_170_alpha1(): bool
{
    global $DB;

    $errors = [];

    // =========================================================================
    // ÉTAPE 1 : Créer la nouvelle table glpi_plugin_gestion_devices
    // =========================================================================
    $createDevices = <<<SQL
    CREATE TABLE IF NOT EXISTS `glpi_plugin_gestion_devices` (
      `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `serial`        VARCHAR(190) NOT NULL,
      `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
      `name`          VARCHAR(255)          DEFAULT NULL,
      `last_seen`     TIMESTAMP             DEFAULT NULL,
      `status`        ENUM('active','banned') NOT NULL DEFAULT 'active',
      `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_serial` (`serial`),
      KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;

    if (!$DB->doQuery($createDevices)) {
        $errors[] = 'Création glpi_plugin_gestion_devices : ' . $DB->error();
    }

    // =========================================================================
    // ÉTAPE 2 : Migrer glpi_plugin_gestion_remote_sign_requests
    //           - Ajouter device_serial (varchar 190)
    //           - Copier les données depuis device_id (best-effort)
    //           - Supprimer device_id et device_token
    // =========================================================================
    $rsr = 'glpi_plugin_gestion_remote_sign_requests';

    if ($DB->tableExists($rsr)) {

        // 2a. Ajouter device_serial si absent
        $cols = _gestion_170_table_columns($DB, $rsr);
        if (!in_array('device_serial', $cols, true)) {
            $sql = "ALTER TABLE `$rsr`
                    ADD COLUMN `device_serial` VARCHAR(190) DEFAULT NULL
                    AFTER `device_token`";
            if (!$DB->doQuery($sql)) {
                $errors[] = "Ajout device_serial dans $rsr : " . $DB->error();
            }
        }

        // 2b. Copier device_id → device_serial (best-effort, ignore si erreur)
        if (in_array('device_id', $cols, true)) {
            $DB->doQuery("UPDATE `$rsr` SET `device_serial` = `device_id` WHERE `device_serial` IS NULL");
        }

        // 2c. Supprimer device_token
        if (in_array('device_token', $cols, true)) {
            if (!$DB->doQuery("ALTER TABLE `$rsr` DROP COLUMN `device_token`")) {
                $errors[] = "Suppression device_token dans $rsr : " . $DB->error();
            }
        }

        // 2d. Supprimer device_id
        $cols = _gestion_170_table_columns($DB, $rsr); // recharger après modifs
        if (in_array('device_id', $cols, true)) {
            if (!$DB->doQuery("ALTER TABLE `$rsr` DROP COLUMN `device_id`")) {
                $errors[] = "Suppression device_id dans $rsr : " . $DB->error();
            }
        }

        // 2e. Ajouter index sur device_serial si absent
        $idxRes = $DB->doQuery("SHOW INDEX FROM `$rsr` WHERE Key_name = 'idx_device_serial'");
        if ($idxRes && $DB->numrows($idxRes) === 0) {
            $DB->doQuery("ALTER TABLE `$rsr` ADD KEY `idx_device_serial` (`device_serial`)");
        }
    }

    // =========================================================================
    // ÉTAPE 3 : Supprimer glpi_plugin_gestion_baseitems
    //           (Base Description / Info — supprimé partout)
    // =========================================================================
    if ($DB->tableExists('glpi_plugin_gestion_baseitems')) {
        if (!$DB->doQuery('DROP TABLE `glpi_plugin_gestion_baseitems`')) {
            $errors[] = 'Suppression glpi_plugin_gestion_baseitems : ' . $DB->error();
        }
    }

    // =========================================================================
    // ÉTAPE 4 : Supprimer glpi_plugin_gestion_signaturedevices (ancien schéma token)
    //           Migration best-effort : on insère dans glpi_plugin_gestion_devices
    //           les appareils actifs connus (serial non nul), sans token.
    // =========================================================================
    $oldDev = 'glpi_plugin_gestion_signaturedevices';
    if ($DB->tableExists($oldDev)) {

        // Migration best-effort des serials existants
        $res = $DB->doQuery(
            "SELECT serial FROM `$oldDev` WHERE is_active = 1 AND serial IS NOT NULL AND serial != '' GROUP BY serial"
        );
        if ($res && $DB->numrows($res) > 0) {
            while ($row = $DB->fetchassoc($res)) {
                $serial = $DB->escape(trim($row['serial']));
                // INSERT IGNORE pour ne pas écraser si déjà présent
                $DB->doQuery(
                    "INSERT IGNORE INTO `glpi_plugin_gestion_devices`
                     (`serial`, `ip`, `name`, `status`, `date_creation`)
                     VALUES ('$serial', '', NULL, 'active', NOW())"
                );
            }
        }

        if (!$DB->doQuery("DROP TABLE `$oldDev`")) {
            $errors[] = "Suppression $oldDev : " . $DB->error();
        }
    }

    // =========================================================================
    // ÉTAPE 5 : Nettoyer glpi_plugin_gestion_configs
    //           Supprimer colonnes obsolètes si elles existent
    //           (BaseDescription, BaseInfo, DeviceId, DeviceToken, etc.)
    // =========================================================================
    $configTable = 'glpi_plugin_gestion_configs';
    if ($DB->tableExists($configTable)) {
        $configCols = _gestion_170_table_columns($DB, $configTable);
        $colsToDrop = [
            'BaseDescription',
            'BaseInfo',
            'base_description',
            'base_info',
            'DeviceId',
            'DeviceToken',
        ];
        $dropClauses = [];
        foreach ($colsToDrop as $col) {
            if (in_array($col, $configCols, true)) {
                $dropClauses[] = "DROP COLUMN `$col`";
            }
        }
        if (!empty($dropClauses)) {
            $sql = "ALTER TABLE `$configTable` " . implode(', ', $dropClauses);
            if (!$DB->doQuery($sql)) {
                $errors[] = "Nettoyage colonnes config : " . $DB->error();
            }
        }
    }

    // =========================================================================
    // RÉSULTAT
    // =========================================================================
    if (!empty($errors) && class_exists('Toolbox')) {
        foreach ($errors as $err) {
            Toolbox::logInFile('plugin-gestion', '[update_170_alpha1] ERREUR: ' . $err . PHP_EOL);
        }
    }

    return empty($errors);
}

/**
 * Helper : retourne la liste des colonnes d'une table.
 *
 * @param DBmysql $DB
 * @param string  $table
 * @return string[]
 */
function _gestion_170_table_columns(DBmysql $DB, string $table): array
{
    $cols = [];
    $res  = $DB->doQuery("SHOW COLUMNS FROM `" . $DB->escape($table) . "`");
    if ($res) {
        while ($row = $DB->fetchassoc($res)) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}
