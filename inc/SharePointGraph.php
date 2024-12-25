<?php

// Informations de configuration
$tenantId = '1806800c-8bf6-4867-843f-ec45787bb03f';
$clientId = '2c4aef6d-452d-4a1e-977d-895a6a2feeec';
$clientSecret = 'Lcw8Q~cN0A5ofIs5YQM3BNHYPAi2Tgwz0UHVpbDH';
$siteUrl = 'https://globalinfo763.sharepoint.com/sites/GLPI-BL';
$hostname = 'globalinfo763.sharepoint.com';
$sitePath = '/sites/GLPI-BL';

/**
 * Fonction pour obtenir un token d'accès à partir d'Azure AD
 */
function getAccessToken($tenantId, $clientId, $clientSecret) {
    $url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";

    $postFields = [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseObj = json_decode($response, true);

    if (isset($responseObj['access_token'])) {
        return $responseObj['access_token'];
    } else {
        throw new Exception("Impossible d'obtenir le token d'accès : " . $response);
    }
}

/**
 * Fonction pour obtenir l'ID du site SharePoint
 */
function getSiteId($accessToken, $hostname, $sitePath) {
    $url = "https://graph.microsoft.com/v1.0/sites/$hostname:$sitePath";

    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseObj = json_decode($response, true);

    if (isset($responseObj['id'])) {
        return $responseObj['id'];
    } else {
        throw new Exception("Impossible de récupérer l'ID du site : " . $response);
    }
}

// Utilisation
try {
    // Étape 1 : Obtenir le token d'accès
    $accessToken = getAccessToken($tenantId, $clientId, $clientSecret);
    echo "Token d'accès obtenu avec succès !\n";

    // Étape 2 : Récupérer l'ID du site
    $siteId = getSiteId($accessToken, $hostname, $sitePath);
    echo "Site ID : $siteId\n";

    // Vous pouvez maintenant utiliser $siteId pour d'autres appels API
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}


function getDrives($accessToken, $siteId) {
    $url = "https://graph.microsoft.com/v1.0/sites/$siteId/drives";

    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseObj = json_decode($response, true);

    if (isset($responseObj['value'])) {
        return $responseObj['value'];
    } else {
        throw new Exception("Impossible de récupérer les bibliothèques de documents : " . $response);
    }
}






/**
 * Fonction pour lister le contenu d'un dossier
 */
function listFolderContents($accessToken, $driveId, $folderPath) {
    $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath:/children";

    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseObj = json_decode($response, true);

    if (isset($responseObj['value'])) {
        return $responseObj['value'];
    } else {
        echo "<br><br>";
        throw new Exception("Impossible de récupérer le contenu du dossier : " . $response);
    }
}

// Utilisation
try {
    // Étape 1 : Obtenir le token d'accès (réutilisez le code précédent pour cette étape)
    $accessToken = getAccessToken($tenantId, $clientId, $clientSecret);

    // Étape 2 : Récupérer les bibliothèques de documents du site
    $drives = getDrives($accessToken, $siteId);

    // Afficher toutes les bibliothèques disponibles
    echo "<br><br>Bibliothèques disponibles sur le site :<br>";
    foreach ($drives as $drive) {
        echo "- Nom : " . $drive['name'] . " | ID : " . $drive['id'] . "<br>";
    }

    // Trouver la bibliothèque "Documents partagés"
    $driveId = null;
    foreach ($drives as $drive) {
        if ($drive['name'] === 'Documents') {
            $driveId = $drive['id'];
            break;
        }
    }

    if (!$driveId) {
        echo "<br><br>";
        throw new Exception("Bibliothèque 'Documents partagés' introuvable.");
    }

    // Étape 3 : Lister le contenu du dossier "BL"
    $folderPath = "BL"; // Chemin relatif dans la bibliothèque
    $contents = listFolderContents($accessToken, $driveId, $folderPath);

    // Affichage des résultats
    echo "<br><br>Contenu du dossier 'BL':<br>";
    foreach ($contents as $item) {
        echo "- " . $item['name'] . " (" . ($item['folder'] ? "Dossier" : "Fichier") . ")<br>";
    }
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}











/*


function getDownloadUrl($accessToken, $driveId, $filePath) {
    $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$filePath";

    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseObj = json_decode($response, true);

    if ($httpStatus === 200 && isset($responseObj['@microsoft.graph.downloadUrl'])) {
        return $responseObj['@microsoft.graph.downloadUrl'];
    } else {
        throw new Exception("Impossible d'obtenir l'URL de téléchargement : HTTP $httpStatus");
    }
}*/

/**
 * Fonction pour télécharger le fichier depuis l'URL de téléchargement
 *//*
function downloadFileFromUrl($downloadUrl, $destinationPath) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus === 200) {
        // Enregistrer le fichier téléchargé
        file_put_contents($destinationPath, $response);
        echo "Fichier téléchargé avec succès : $destinationPath\n";
    } else {
        throw new Exception("Erreur lors du téléchargement à partir de l'URL : HTTP $httpStatus");
    }
}

// Utilisation
try {
    // Étape 1 : Obtenir le token d'accès
    $accessToken = getAccessToken($tenantId, $clientId, $clientSecret);

    // Étape 2 : Identifier l'ID de la bibliothèque
    $driveId = "b!HhaCvvwDvUiFVihE9wOc4p2C-XsAqJlFmDwulVa6XzmWhDjy_c1mRbzLqiqfq3qU"; // Remplacez par votre ID exact

    // Étape 3 : Définir le chemin relatif du fichier
    $filePath = "BL/DocumentBLTest.docx";

    // Étape 4 : Obtenir l'URL de téléchargement
    $downloadUrl = getDownloadUrl($accessToken, $driveId, $filePath);
    echo "URL de téléchargement obtenue : $downloadUrl\n";

    // Étape 5 : Télécharger le fichier depuis l'URL
    $destinationPath = "DocumentBLTest-local.docx";
    downloadFileFromUrl($downloadUrl, $destinationPath);
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}


echo '<br><br><br>';
// Affichage de l'URL complète pour le dossier BL
$driveId = "b!HhaCvvwDvUiFVihE9wOc4p2C-XsAqJlFmDwulVa6XzmWhDjy_c1mRbzLqiqfq3qU"; // Remplacez par votre ID
$folderPath = "BL"; // Chemin relatif
$url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath:/children";

echo "URL pour accéder au contenu du dossier BL : $url\n";*/