<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginGestionSharepoint extends CommonDBTM {
        
    // Informations de configuration
    /*static private $tenantId = '1806800c-8bf6-4867-843f-ec45787bb03f';
    static private $clientId = '2c4aef6d-452d-4a1e-977d-895a6a2feeec';
    static private $clientSecret = 'Lcw8Q~cN0A5ofIs5YQM3BNHYPAi2Tgwz0UHVpbDH';
    static private $hostname = 'globalinfo763.sharepoint.com';
    static private $sitePath = '/sites/GLPI-BL';*/

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

    /**
     * Fonction pour obtenir les dossiers du site SharePoint
     */
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

    /**
     * Fonction de test de connexion au SharePoint
     */
    function validateSharePointConnection($tenantId, $clientId, $clientSecret, $sitePath) {
        // Étape 1 : Obtenir le token d'accès
        $urlToken = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";
        $postFields = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default'
        ];
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlToken);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        if ($httpStatus !== 200) {
            return [
                'status' => false,
                'message' => "Erreur : Échec de l'obtention du token d'accès (HTTP $httpStatus)."
            ];
        }
    
        $responseObj = json_decode($response, true);
        $accessToken = $responseObj['access_token'] ?? null;
    
        if (!$accessToken) {
            return [
                'status' => false,
                'message' => "Erreur : Token d'accès introuvable."
            ];
        }
    
        // Étape 2 : Tester l'accès au site SharePoint
        $urlSite = "https://graph.microsoft.com/v1.0/sites/$sitePath";
    
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlSite);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        $responseSite = curl_exec($ch);
        $httpStatusSite = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        if ($httpStatusSite === 200) {
            return [
                'status' => true,
                'message' => "Connexion validée : Accès SharePoint réussi."
            ];
        } elseif ($httpStatusSite === 403) {
            return [
                'status' => false,
                'message' => "Erreur : Accès refusé. Assurez-vous que l'application dispose des autorisations nécessaires."
            ];
        } elseif ($httpStatusSite === 401) {
            return [
                'status' => false,
                'message' => "Erreur : Accès non autorisé. Vérifiez les permissions dans Azure AD."
            ];
        } else {
            return [
                'status' => false,
                'message' => "Erreur : Impossible de récupérer l'ID du site (HTTP $httpStatusSite)."
            ];
        }
    }
    
    /**
     * Fonction pour télécharger le fichier depuis l'URL de téléchargement
     */
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

    /**
     * Fonction pour récupérer l'URL d'un fichier dans SharePoint
     */
    function getFileUrl($accessToken, $driveId, $filePath) {
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

        if ($httpStatus === 200) {
            return $responseObj['webUrl'] ?? null;
        } else {
            throw new Exception("Erreur : Impossible de récupérer les métadonnées du fichier (HTTP $httpStatus).");
        }
    }

    /**
     * Fonction pour vérifier si un fichier existe dans SharePoint
     */
    function checkFileExists($accessToken, $driveId, $filePath) {
        $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$filePath";

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); // Requête GET pour récupérer les métadonnées

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus === 200) {
            return true; // Le fichier existe
        } elseif ($httpStatus === 404) {
            return false; // Le fichier n'existe pas
        } else {
            throw new Exception("Erreur lors de la vérification : HTTP $httpStatus");
        }
    }

    /**
     * Fonction pour générer un lien de partage public
     */
    function createShareLink($accessToken, $driveId, $itemId) {
        $url = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$itemId/createLink";

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        $postData = [
            "type" => "view", // Lien de visualisation
            "scope" => "anonymous" // Accessible sans authentification
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseObj = json_decode($response, true);

        if ($httpStatus === 200 && isset($responseObj['link']['webUrl'])) {
            return $responseObj['link']['webUrl'];
        } else {
            throw new Exception("Erreur : Impossible de générer un lien de partage.");
        }
    }

    /**
     * Fonction pour récupérer l'ID d'un fichier spécifique dans un dossier
     */
    function getFileIdByName($accessToken, $driveId, $folderPath, $fileName) {
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
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus === 200) {
            $files = json_decode($response, true)['value'];
            foreach ($files as $file) {
                if ($file['name'] === $fileName) {
                    return $file['id']; // Retourne l'ID du fichier si le nom correspond
                }
            }
            return null; // Aucun fichier trouvé avec ce nom
        } else {
            throw new Exception("Erreur : Impossible de lister les fichiers dans le dossier (HTTP $httpStatus).");
        }
    }



/*
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
}