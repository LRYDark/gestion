<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginGestionSharepoint extends CommonDBTM {
        
    /**
     * Fonction pour obtenir un token d'accès à partir d'Azure AD
     */
    public function getAccessToken() {
        $config         = new PluginGestionConfig();
        $tenantId       = $config->TenantID();
        $clientId       = $config->ClientID();
        $clientSecret   = $config->ClientSecret();

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
    public function getSiteId($hostname, $sitePath) {
        $accessToken = $this->getAccessToken();

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
    public function getDrives($siteId) {
        $accessToken = $this->getAccessToken();

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
    public function listFolderContents($folderPath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();
        $config = new PluginGestionConfig();
        $NumberViews = $config->NumberViews();

        // Construire l'URL en fonction de la valeur de $folderPath
        if (empty($folderPath)) {
            // Si $folderPath est vide, utiliser l'URL pour le dossier racine
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root/children\$top=$NumberViews";
        } else {
            // Sinon, utiliser l'URL pour le dossier spécifié
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath:/children\$top=$NumberViews";
        }

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
     * Fonction récursive pour lister le contenu d'un dossier et de ses sous-dossiers
     */
    public function listFolderContentsRecursive($folderPath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();
        $config = new PluginGestionConfig();
        $NumberViews = $config->NumberViews();

        // Construire l'URL en fonction de la valeur de $folderPath
        if (empty($folderPath)) {
            // Si $folderPath est vide, utiliser l'URL pour le dossier racine
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root/children?\$top=$NumberViews";
        } else {
            // Sinon, utiliser l'URL pour le dossier spécifié
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath:/children?\$top=$NumberViews";
        }

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

        if (!isset($responseObj['value'])) {
            throw new Exception("Impossible de récupérer le contenu du dossier : " . $response);
        }

        $contents = [];
        foreach ($responseObj['value'] as $item) {
            if ($item['folder'] ?? false) {
                // Si l'élément est un dossier, appeler récursivement la fonction
                $subFolderPath = $folderPath . '/' . $item['name'];
                $contents = array_merge($contents, $this->listFolderContentsRecursive($subFolderPath));
            } else {
                // Ajouter le fichier à la liste
                $contents[] = $item;
            }
        }

        return $contents;
    }

    /**
     * Fonction de test de connexion au SharePoint
     */
    public function validateSharePointConnection($sitePath) {
        $config         = new PluginGestionConfig();
        $tenantId       = $config->TenantID();
        $clientId       = $config->ClientID();
        $clientSecret   = $config->ClientSecret();

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
     * Fonction pour obtenir l'URL de téléchargement d'un fichier
     */
    public function getDownloadUrl($filePath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

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
    }
    
    /**
     * Fonction pour télécharger le fichier depuis l'URL de téléchargement
     */
    public function downloadFileFromUrl($downloadUrl, $destinationPath) {
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
    public function getFileUrl($filePath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

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
    public function checkFileExists($filePath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

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
    public function createShareLink($itemId) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

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
    public function getFileIdByName($folderPath, $fileName) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

        // Construire l'URL en fonction de la valeur de $folderPath
        if (empty($folderPath)) {
            // Si $folderPath est vide, utiliser l'URL pour le dossier racine
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root/children";
        } else {
            // Sinon, utiliser l'URL pour le dossier spécifié
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath:/children";
        }

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

    /**
     * Fonction pour téléverser un fichier dans un dossier SharePoint
     */
    public function uploadFileToFolder($folderPath, $fileName, $sourcePath) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

        // Construire l'URL du dossier cible
        $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderPath/$fileName:/content";

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/octet-stream"
        ];

        // Lire le contenu du fichier local à téléverser
        $fileContent = file_get_contents($sourcePath);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus === 200 || $httpStatus === 201) {
            echo "Fichier téléversé avec succès dans le dossier cible.\n";
        } else {
            throw new Exception("Erreur lors du téléversement du fichier : HTTP $httpStatus");
        }
    }

    /**
     * Fonction pour supprimer un fichier dans SharePoint
     */
    public function deleteFile($itemId) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

        $url = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$itemId";

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus === 204) {
            echo "Fichier supprimé avec succès.\n";
        } elseif ($httpStatus === 404) {
            echo "Erreur : Fichier introuvable.\n";
        } else {
            throw new Exception("Erreur : Impossible de supprimer le fichier (HTTP $httpStatus).");
        }
    }

    /**
     * Fonction pour récupéré l'id du répértoire cible du site
     */
    public function GetDriveId() {
        $config = new PluginGestionConfig();

        $siteId = '';
        $siteId = $this->getSiteId($config->Hostname(), $config->SitePath());
        $drives = $this->getDrives($siteId);

        // Trouver la bibliothèque "Documents partagés"
        $globaldrive = strtolower(trim($config->Global()));
        $driveId = null;
        foreach ($drives as $drive) {
            if (strtolower($drive['name']) === $globaldrive) {
                $driveId = $drive['id'];
                break;
            }
        }

        if (!$driveId) {
            throw new Exception("Erreur : Bibliothèque '$globaldrive' introuvable.");
        }else{
            return $driveId;
        }
    }
}