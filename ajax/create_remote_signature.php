<?php
/**
 * plugins/gestion/ajax/create_remote_signature.php
 * Crée une demande de signature distante (PC → tablette kiosque APPAPPLETAB).
 *
 * Identification tablette : device_serial (sans token).
 * Valide dans glpi_plugin_gestion_devices (status = 'active').
 *
 * @since 1.7.0_alpha1 — Suppression device_id / device_token.
 */

include ('../../../inc/includes.php');
header('Content-Type: application/json; charset=UTF-8');

global $DB, $CFG_GLPI;
$config  = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

if (empty($config->fields['RemoteSignatureOn'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'remote_signature_disabled']);
    exit;
}

try {
    Session::checkLoginUser();
    // NOTE: Ne pas appeler Session::checkCSRF($_POST) ici.
    // En GLPI 11, le CheckCsrfListener valide déjà le token avec preserve_token=true
    // (token conservé en session pour les appels AJAX multiples).
    // Un appel manuel checkCSRF() avec preserve_token=false (défaut) consommerait
    // le token et casserait tous les appels AJAX suivants (ex: polling du statut).

    // ── Paramètres ──────────────────────────────────────────────────────────
    $ticket_id     = (int)($_POST['ticket_id'] ?? 0);
    $device_serial = trim((string)($_POST['device_serial'] ?? ''));

    // Paramètres optionnels (JSON encodé en base64 ou brut)
    $parameters      = '';
    $parameters_json = null;

    if (!empty($_POST['parameters_b64'])) {
        $raw = base64_decode($_POST['parameters_b64'], true);
        if ($raw === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Paramètres (Base64) invalides']);
            exit;
        }
        $parameters = trim($raw);
    } elseif (!empty($_POST['parameters'])) {
        $parameters = trim($_POST['parameters']);
    }

    if ($parameters !== '') {
        $decoded = json_decode($parameters, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Paramètres JSON invalides: ' . json_last_error_msg()]);
            exit;
        }
        // Supprimer base_description / base_info s'ils arrivent en legacy
        unset($decoded['base_description'], $decoded['base_info'], $decoded['base_items']);
        $parameters_json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ── Validation ───────────────────────────────────────────────────────────
    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID ticket manquant']);
        exit;
    }

    if ($device_serial === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Numéro de série tablette manquant']);
        exit;
    }
    if (strlen($device_serial) > 255 || preg_match('/[\x00-\x1F]/', $device_serial)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Numéro de série tablette invalide']);
        exit;
    }

    // ── Vérifier que l'appareil existe et est actif ──────────────────────────
    $devRes = $DB->doQuery(
        "SELECT `id`, `status`, `name`
         FROM `glpi_plugin_gestion_devices`
         WHERE `serial` = '" . $DB->escape($device_serial) . "'
         LIMIT 1"
    );

    if (!$devRes || $DB->numrows($devRes) === 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Appareil inconnu', 'serial' => $device_serial]);
        exit;
    }

    $devRow = $DB->fetchAssoc($devRes);
    if ($devRow['status'] === 'banned') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Appareil banni', 'serial' => $device_serial]);
        exit;
    }

    // ── Vérifier demande existante pour ce ticket + ce serial ────────────────
    $checkRes = $DB->doQuery(
        "SELECT `id`, `status`
         FROM `glpi_plugin_gestion_remote_sign_requests`
         WHERE `tickets_id` = {$ticket_id}
           AND `device_serial` = '" . $DB->escape($device_serial) . "'
           AND `status` IN ('pending', 'cancelled')
         ORDER BY `id` DESC
         LIMIT 1"
    );

    if ($checkRes && $DB->numrows($checkRes) > 0) {
        $existing = $DB->fetchAssoc($checkRes);

        if ($existing['status'] === 'pending') {
            echo json_encode([
                'ok'         => true,
                'request_id' => (int)$existing['id'],
                'message'    => 'Demande existante trouvée',
            ]);
            exit;
        }

        // Réactiver une demande annulée
        if ($existing['status'] === 'cancelled') {
            $ok = $DB->update('glpi_plugin_gestion_remote_sign_requests', [
                'status'          => 'pending',
                'date_creation'   => date('Y-m-d H:i:s'),
                'parameters'      => $parameters_json,
                'signer_name'     => null,
                'signer_email'    => null,
                'signature_base64' => null,
            ], ['id' => (int)$existing['id']]);

            if ($ok) {
                echo json_encode([
                    'ok'         => true,
                    'request_id' => (int)$existing['id'],
                    'message'    => 'Demande réactivée après refus',
                ]);
                exit;
            }
            // En cas d'erreur de mise à jour → créer une nouvelle demande ci-dessous
        }
    }

    // ── Créer une nouvelle demande ────────────────────────────────────────────
    $ok = $DB->insert('glpi_plugin_gestion_remote_sign_requests', [
        'tickets_id'    => $ticket_id,
        'device_serial' => $device_serial,
        'parameters'    => $parameters_json,
        'status'        => 'pending',
        'date_creation' => date('Y-m-d H:i:s'),
    ]);

    if ($ok) {
        echo json_encode([
            'ok'         => true,
            'request_id' => (int)$DB->insertId(),
            'message'    => 'Demande de signature créée',
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors de la création de la demande']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
