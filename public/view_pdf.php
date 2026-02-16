<?php
// view_pdf.php - Version sécurisée SANS exposition du token valide

// IMPORTANT : Définir NOLOGIN avant includes si on a un token
$token = $_GET['token'] ?? '';
if (!empty($token)) {
    define('NOLOGIN', 1);
    define('NOHEADER', 1);
}

include('../../../inc/includes.php');

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit("Missing document ID");
}

$id = $_GET['id'];

// Fonction pour générer/valider les tokens temporaires
function _pdf_preview_secret() {
    if (defined('GLPI_PDF_PREVIEW_SECRET')) return GLPI_PDF_PREVIEW_SECRET;
    $env = getenv('GLPI_PDF_PREVIEW_SECRET');
    if ($env) return $env;
    return hash('sha256', realpath(__DIR__ . '/..') . 'gestion_pdf_preview');
}
function generateTempToken($doc_id, $secret_key = null) {
    if ($secret_key === null || $secret_key === 'GLPI_PDF_SECRET_2024') $secret_key = _pdf_preview_secret();
    $today = date('Y-m-d');
    return hash('sha256', $doc_id . $today . $secret_key);
}

function validateTempToken($doc_id, $provided_token, $secret_key = null) {
    if ($secret_key === null || $secret_key === 'GLPI_PDF_SECRET_2024') $secret_key = _pdf_preview_secret();
    if (empty($provided_token) || empty($doc_id)) {
        return false;
    }
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Valider avec la date d'aujourd'hui
    $valid_today = hash('sha256', $doc_id . $today . $secret_key);
    
    // Valider avec la date d'hier (pour gérer le changement de jour)
    $valid_yesterday = hash('sha256', $doc_id . $yesterday . $secret_key);
    
    return hash_equals($valid_today, $provided_token) || hash_equals($valid_yesterday, $provided_token);
}

// Vérifier l'authentification
$authenticated = false;

if (!empty($token)) {
    // Mode token temporaire (pour tablettes)
    if (validateTempToken($id, $token)) {
        $authenticated = true;
    } else {
        // SÉCURITÉ : NE JAMAIS exposer le token valide
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Access denied',
            'message' => 'Invalid or expired authentication token'
        ]);
        exit;
    }
} else {
    // Mode session normale (pour utilisateurs connectés)
    try {
        Session::checkLoginUser();
        $authenticated = true;
    } catch (Exception $e) {
        // Si pas de token ET pas de session, rediriger vers login
        $redirect_url = urlencode($_SERVER['REQUEST_URI']);
        header("Location: /glpi/front/login.php?redirect=" . $redirect_url);
        exit;
    }
}

if (!$authenticated) {
    http_response_code(401);
    exit("Authentication required");
}

// À ce point, on est authentifié - inclure les dépendances
try {
    require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';
    
    // Vérifier que la fonction existe
    if (!function_exists('streamDocument')) {
        http_response_code(500);
        exit("Function streamDocument not found");
    }
    
    // streamDocument() doit simplement afficher le PDF avec les bons headers
    streamDocument($id);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error loading document: ' . $e->getMessage()]);
    exit;
}

// OPTIONNEL : Ajouter une protection anti-brute force
// function rateLimitCheck($ip) {
//     // Implémenter un système de limitation de tentatives par IP
//     // Par exemple : max 10 tentatives par minute
//     return true;
// }