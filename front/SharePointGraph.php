<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer

use Smalot\PdfParser\Parser;

class PluginGestionSharepoint extends CommonDBTM {

    /**
     * Fonction pour obtenir un token d'accès à partir d'Azure AD
     */
    public function getAccessToken() {
        $config         = new PluginGestionConfig();
        $tenantId       = $config->TenantID();
        $clientId       = $config->ClientID();
        $clientSecret   = $config->ClientSecret();

        $token_url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";
        $token_data = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default'
        ];
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            return null;
        }
    
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_status != 200) {
            curl_close($ch);
            return null;
        }
    
        curl_close($ch);
        $token_response = json_decode($response, true);
        return $token_response['access_token'];
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

    // Récupérer les dossiers pour params = 8 (dossiers de recherche)
    public function getFoldersByParams1() {
        global $DB;
        $query = "SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 1";
        $result = $DB->query($query);

        $folders = [];
        while ($row = $DB->fetchassoc($result)) {
            $folders[] = $row['folder_name'];
        }

        return $folders;
    }

    // Récupérer les éléments à rechercher pour params = 10 (ex: "Ticket", "Facture")
    public function getSearchKeywordsByParams10() {
        global $DB;
        $query = "SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = 10";
        $result = $DB->query($query);

        $keywords = [];
        while ($row = $DB->fetchassoc($result)) {
            $keywords[] = $row['folder_name'];
        }

        return $keywords;
    }

    // Fonction pour effectuer la requête de recherche
    public function searchSharePoint() {
        $accessToken = $this->getAccessToken();
        $config = new PluginGestionConfig();
        $NumberViews = $config->NumberViews();
        $Hostname = $config->Hostname();
        $SitePath = $config->SitePath();
        $bibliotheque = $config->Global();

        $pdfFiles = [];
    
        // Récupérer les dossiers concernés en base de données (params = 1)
        $folders = $this->getFoldersByParams1();
        // Si aucun dossier n'est défini pour params = 1, on recherche dans "la bibliotheque"
        if (empty($folders)) {
            $folders = ["$bibliotheque"];
        } else {
            // Ajouter le préfixe "bibliotheque/" à chaque dossier récupéré
            foreach ($folders as &$folder) {
                $folder = "$bibliotheque/$folder";
            }
        }
    
        // Récupérer les éléments à rechercher (params = 10)
        $keywords = $this->getSearchKeywordsByParams10();
        if (empty($keywords)) {
            return $pdfFiles;
        }
    
        $search_url = 'https://graph.microsoft.com/v1.0/search/microsoft.graph.query';
        $SiteUrl = "https://$Hostname"."$SitePath";
        
        foreach ($folders as $folderName) {
            foreach ($keywords as $keyword) {
                $queryString = "$keyword AND filetype:pdf path:\"$SiteUrl/$folderName\"";
    
                // Définition de la requête
                $search_data = [
                    "requests" => [
                        [
                            "entityTypes" => ["driveItem"],
                            "query" => [
                                "queryString" => $queryString
                            ],
                            "size" => $NumberViews,
                            "sortProperties" => [
                                [
                                    "name" => "lastModifiedDateTime",
                                    "isDescending" => true
                                ]
                            ],
                            "region" => "FRA"
                        ]
                    ]
                ];
    
                $search_data_json = json_encode($search_data);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Erreur d'encodage JSON : " . json_last_error_msg());
                }
    
                $nextLink = $search_url;
    
                do {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $nextLink);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $search_data_json);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
                    $response = curl_exec($ch);
    
                    if (curl_errno($ch)) {
                        throw new Exception("Erreur cURL : " . curl_error($ch));
                    }
    
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
    
                    if ($http_status != 200) {
                        throw new Exception("Erreur API (HTTP $http_status) : $response");
                    }
    
                    // Décodage de la réponse JSON
                    $responseObj = json_decode($response, true);
                    if (!isset($responseObj['value'][0]['hitsContainers'][0]['hits'])) {
                        break;
                    }
    
                    // Extraire les informations demandées
                    foreach ($responseObj['value'][0]['hitsContainers'][0]['hits'] as $hit) {
                        if (isset($hit['resource']['name'])) {
                            $pdfFiles[] = [
                                'name' => $hit['resource']['name'],
                                'lastModifiedDateTime' => $hit['resource']['lastModifiedDateTime'] ?? 'Non disponible',
                                'webUrl' => $hit['resource']['webUrl'] ?? 'Non disponible'
                            ];
                        }
                    }
    
                    // Vérifier s'il y a une page suivante (pagination)
                    $nextLink = $responseObj['@odata.nextLink'] ?? null;
    
                } while ($nextLink);
            }
        }
    
        return $pdfFiles;
    }
      
    /**
     * Fonction de test de connexion au SharePoint
     */
    public function validateSharePointConnection($sitePath) {
        $config         = new PluginGestionConfig();
        $tenantId       = $config->TenantID();
        $clientId       = $config->ClientID();
        $clientSecret   = $config->ClientSecret();
        $accessToken    = $this->getAccessToken();

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
                'message' => "Connexion validée : Accès API SharePoint réussi."
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
        $bibliotheque = $config->Global();

        if($bibliotheque == 'Documents partages'){
            $globaldrive = strtolower(trim('Documents'));
        }else{
            $globaldrive = strtolower(trim($bibliotheque));
        }

        $siteId = '';
        $siteId = $this->getSiteId($config->Hostname(), $config->SitePath());
        $drives = $this->getDrives($siteId);

        // Trouver la bibliothèque "Documents partagés"
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

    /**
     * Fonction pour effectuer la requête de recherche pour la tâche cron
     */
     public function searchSharePointCron($startDate = null, $endDate = null) {
        $accessToken = $this->getAccessToken();
        $config = new PluginGestionConfig();
        $Hostname = $config->Hostname();
        $SitePath = $config->SitePath();
        $bibliotheque = $config->Global();

        $pdfFiles = [];
        
        // Récupérer les éléments à rechercher (params = 10)
        $keywords = $this->getSearchKeywordsByParams10();
        if (empty($keywords)) {
            return $pdfFiles;
        }
    
        $search_url = 'https://graph.microsoft.com/v1.0/search/microsoft.graph.query';
        $SiteUrl = "https://$Hostname"."$SitePath";
        
        foreach ($keywords as $keyword) {
            if ($startDate) {
                $queryString = "$keyword AND filetype:pdf path:\"$SiteUrl/$bibliotheque\" AND created>=$startDate AND created<=$endDate";
            } else {
                // Si $startDate est NULL, on ne met que la condition sur endDate
                $queryString = "$keyword AND filetype:pdf path:\"$SiteUrl/$bibliotheque\" AND created<=$endDate";
            }

            // Définition de la requête
            $search_data = [
                "requests" => [
                    [
                        "entityTypes" => ["driveItem"],
                        "query" => [
                            "queryString" => $queryString
                        ],
                        "size" => 500,
                        "sortProperties" => [
                            [
                                "name" => "lastModifiedDateTime",
                                "isDescending" => true
                            ]
                        ],
                        "region" => "FRA"
                    ]
                ]
            ];

            $search_data_json = json_encode($search_data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Erreur d'encodage JSON : " . json_last_error_msg());
            }

            $nextLink = $search_url;

            do {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $nextLink);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $search_data_json);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new Exception("Erreur cURL : " . curl_error($ch));
                }

                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_status != 200) {
                    throw new Exception("Erreur API (HTTP $http_status) : $response");
                }

                // Décodage de la réponse JSON
                $responseObj = json_decode($response, true);
                if (!isset($responseObj['value'][0]['hitsContainers'][0]['hits'])) {
                    break;
                }

                // Extraire les informations demandées
                foreach ($responseObj['value'][0]['hitsContainers'][0]['hits'] as $hit) {
                    if (isset($hit['resource']['name'])) {
                        $pdfFiles[] = [
                            'name' => $hit['resource']['name'],
                            'lastModifiedDateTime' => $hit['resource']['lastModifiedDateTime'] ?? 'Non disponible',
                            'createdDateTime' => $hit['resource']['createdDateTime'] ?? 'Non disponible',
                            'webUrl' => $hit['resource']['webUrl'] ?? 'Non disponible'
                        ];
                    }
                }

                // Vérifier s'il y a une page suivante (pagination)
                $nextLink = $responseObj['@odata.nextLink'] ?? null;

            } while ($nextLink);
        }
    
        return $pdfFiles;
    } 

    /**
     * Récupérer tous les folder_name depuis la base de données
     */
    public function getFolderNamesFromDatabase($param) {
        global $DB;

        // Exemple de requête pour récupérer tous les folder_name de la base de données
        $query = "SELECT folder_name FROM glpi_plugin_gestion_configsfolder WHERE params = $param";
        $result = $DB->query($query);

        $folderNames = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $folderNames[] = $row['folder_name'];
            }
        }

        return $folderNames;
    }

    /**
     * Vérifier si le sous-dossier contient l'une des valeurs de folderNames
     */
    public function containsAnyFolderName($subfolderName, $folderNames) {
        foreach ($folderNames as $folderName) {
            if (strpos($subfolderName, $folderName) !== false) {
                return true;  // Si le sous-dossier contient l'une des valeurs, on retourne true
            }
        }
        return false;  // Aucun folder_name trouvé dans le nom du sous-dossier
    }

    /**
     * Convertir une date ISO 8601 (avec T et Z) en format standard
     */
    public function convertFromISO8601($isoDate) {
        // Vérifier si le format correspond à une date ISO 8601 avec T et Z
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $isoDate)) {
            // Remplacer le 'T' par un espace et supprimer le 'Z'
            return str_replace(['T', 'Z'], [' ', ''], $isoDate);
        }

        // Retourner l'entrée si ce n'est pas une date ISO 8601 valide
        return $isoDate;
    }

    /**
     * Récupération du tracker sur le PDF
     */
    public function GetTrackerPdfDownload($filePath) {

        $log = GLPI_PLUGIN_DOC_DIR . "/gestion/log.txt";
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';

        // Générer un nombre entier aléatoire entre 1 et 100
        $nombreAleatoire = rand(1, 100000);
        $tracker = '';
        $config         = new PluginGestionConfig();
        $extracteur     = $config->extract();

        if (!empty($extracteur) && $config->ExtractYesNo() == 1) {
            try {  
                // Étape 4 : Obtenir l'URL de téléchargement
                $downloadUrl = $this->getDownloadUrl($filePath);
            } catch (Exception $e) {
                throw new Exception("Erreur : " . $e->getMessage());
            }
        
            try {
                // Étape 5 : Télécharger le fichier depuis l'URL
                $destinationPath = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/SharePoint_Temp_".$nombreAleatoire.".pdf";
                $this->downloadFileFromUrl($downloadUrl, $destinationPath);
            } catch (Exception $e) {
                throw new Exception("Erreur : " . $e->getMessage());
            }
            
            try {
                if (file_exists($autoloadPath)) {
                    require_once($autoloadPath);
                } else {
                    file_put_contents($log, "autoload introuvable : $autoloadPath\n", FILE_APPEND);
                    $tracker = NULL;
                }
                
                if (class_exists('Smalot\PdfParser\Parser')) {
                    try {
                        $parser = new Parser();
                    } catch (Exception $e) {
                        file_put_contents($log, "Erreur lors de l'initialisation du Parser : " . $e->getMessage() . "\n", FILE_APPEND);
                    }

                    try {
                        $pdf = $parser->parseFile($destinationPath);
                        // Extraire le texte brut
                        $text = $pdf->getText();
                
                        if (empty($text)) {
                            file_put_contents($log, "Impossible d'extraire le texte. Le PDF pourrait contenir uniquement des images.", FILE_APPEND);
                        }
                    } catch (Exception $e) {
                        file_put_contents($log, "Erreur lors de l'extraction du text brut : " . $e->getMessage() . "\n", FILE_APPEND);
                    }

                    try {
                        // Nettoyer le texte extrait
                        $cleanText = trim($text);
                        
                        // Rechercher le texte dynamique entre "Instruction de livraison" et "Tracker"
                        if (preg_match("$extracteur", $cleanText, $matches)) {
                            $tracker = trim($matches[1]);
                        }
                    } catch (Exception $e) {
                        file_put_contents($log, "Erreur lors de l'extraction du tracker depuis le text brut : " . $e->getMessage() . "\n", FILE_APPEND);
                    }

                } else {
                    file_put_contents($log, "Classe Parser NON disponible\n", FILE_APPEND);
                    $tracker = NULL;
                }
            
            } catch (Exception $e) {
                file_put_contents($log, "Erreur général de récupération du tracker  : " . $e->getMessage() . "\n", FILE_APPEND);
                $tracker = NULL;
            }

            unlink($destinationPath);
        }
        return $tracker;
    }

    public function MailSend($EMAIL, $gabarit_id, $outputPath = NULL, $message = NULL, $id_survey = NULL, $tracker = NULL, $url = NULL){
        global $DB, $CFG_GLPI;

        // Validation de l'email
        if (!filter_var($EMAIL, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("L'adresse email est invalide : $EMAIL");
        }

        //BALISES
        $Balises = array(
            array('Balise' => '##gestion.id##'             , 'Value' => sprintf("%07d", $id_survey)),
            array('Balise' => '##gestion.tracker##'        , 'Value' => sprintf("%07d", $tracker)),
            array('Balise' => '##gestion.url##'            , 'Value' => sprintf("%07d", $url)),
        );

        // Fonction pour remplacer les balises
        $remplacerBalises = function($corps) use ($Balises) {
            foreach ($Balises as $balise) {
                $corps = str_replace($balise['Balise'], $balise['Value'], $corps);
            }
            return $corps;
        };

        $mmail = new GLPIMailer(); // génération du mail
        $config = new PluginGestionConfig();
    
        $NotifMailTemplate = $DB->query("SELECT * FROM glpi_notificationtemplatetranslations WHERE notificationtemplates_id=$gabarit_id")->fetch_object();
        if (!$NotifMailTemplate) {
            throw new RuntimeException("Aucun template trouvé pour l'ID : $gabarit_id");
        }    
            $BodyHtml = html_entity_decode($NotifMailTemplate->content_html, ENT_QUOTES, 'UTF-8');
            $BodyText = html_entity_decode($NotifMailTemplate->content_text, ENT_QUOTES, 'UTF-8');
    
        $footer = $DB->query("SELECT value FROM glpi_configs WHERE name = 'mailing_signature'")->fetch_object();
        if(!empty($footer->value)){$footer = html_entity_decode($footer->value, ENT_QUOTES, 'UTF-8');}else{$footer='';}
    
        // For exchange
            $mmail->AddCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
    
        if (empty($CFG_GLPI["from_email"])){
            // si mail expediteur non renseigné    
            $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"], false);
        }else{
            //si mail expediteur renseigné  
            $mmail->SetFrom($CFG_GLPI["from_email"], $CFG_GLPI["from_email_name"], false);
        }
    
        $mmail->AddAddress($EMAIL);
        
        if($outputPath != NULL){
            $mmail->addAttachment($outputPath); // Ajouter un attachement (documents)
        }

        $mmail->isHTML(true);
        $mmail->Subject = $remplacerBalises($NotifMailTemplate->subject);
        $mmail->Body = GLPIMailer::normalizeBreaks($remplacerBalises($NotifMailTemplate->content_html)) . $footer;
        $mmail->AltBody = GLPIMailer::normalizeBreaks($remplacerBalises($NotifMailTemplate->content_text)) . $footer;
    
            // envoie du mail
            if(!$mmail->send()) {
                Session::addMessageAfterRedirect(__("Erreur lors de l'envoi du mail : " . $mmail->ErrorInfo, 'gestion'), true, ERROR);
            }else{
                if($gabarit_id == $config->fields['gabarit'] && $message != NULL){
                    Session::addMessageAfterRedirect(__($message, 'gestion'), true, INFO);
                }
            }
            
        $mmail->ClearAddresses();
    }

    /* ##########################################################################  CHECK */
    /**
     * Fonction globale pour tester l'accès à l'API Microsoft Graph et SharePoint
     */
    public function checkSharePointAccess() {
        $config = new PluginGestionConfig();
        $tenantId = $config->TenantID();
        $clientId = $config->ClientID();
        $clientSecret = $config->ClientSecret();
        $hostname = $config->Hostname();
        $sitePath = $config->SitePath();

        $results = [
            'accessToken' => ['status' => false, 'message' => ''],
            'sharePointAccess' => ['status' => false, 'message' => ''],
            'siteID' => ['status' => false, 'message' => ''],
            'graphQuery' => ['status' => false, 'message' => '']
        ];

        // 1. Obtenir le token d'accès en utilisant `getAccessToken()`
        try {
            $accessToken = $this->getAccessToken(); 

            if ($accessToken) {
                $results['accessToken']['status'] = true;
                $results['accessToken']['message'] = "Token obtenu avec succès";
            } else {
                throw new Exception("Impossible d'obtenir un token d'accès");
            }
        } catch (Exception $e) {
            $results['accessToken']['message'] = $e->getMessage();
            return $results;
        }

        // Définition des headers pour les requêtes suivantes
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        // 2. Vérifier l'accès à SharePoint
        try {
            $checkSharePointUrl = "https://graph.microsoft.com/v1.0/sites/root";
            $response = $this->apiRequest($checkSharePointUrl, $headers);

            if (isset($response['id'])) {
                $results['sharePointAccess']['status'] = true;
                $results['sharePointAccess']['message'] = "Accès à SharePoint autorisé";
            } else {
                throw new Exception("Accès refusé à SharePoint");
            }
        } catch (Exception $e) {
            $results['sharePointAccess']['message'] = $e->getMessage();
            return $results;
        }

        // 3. Obtenir le Site ID en utilisant `getSiteId()`
        try {
            $siteId = $this->getSiteId($hostname, $sitePath);

            if ($siteId) {
                $results['siteID']['status'] = true;
                $results['siteID']['message'] = $siteId;
            } else {
                throw new Exception("Impossible de récupérer l'ID du site");
            }
        } catch (Exception $e) {
            $results['siteID']['message'] = $e->getMessage();
            return $results;
        }

        // 4. Tester si Microsoft Graph Query est accessible
        try {
            $graphQueryResult = $this->testGraphQueryAccess();

            if ($graphQueryResult['status']) {
                $results['graphQuery']['status'] = true;
                $results['graphQuery']['message'] = $graphQueryResult['message']; // Message par défaut
            } else {
                throw new Exception($graphQueryResult['message']);
            }
        } catch (Exception $e) {
            $results['graphQuery']['message'] = $e->getMessage();
            if (isset($graphQueryResult['response']['error']['message'])) {
                $results['graphQuery']['message'] .= ' -> ' . $graphQueryResult['response']['error']['message'];
            }
        }

        // 5. Vérifier les permissions sur le Drive ("Documents partagés")
        try {
            $driveId = $this->GetDriveId();
            $permissionsUrl = "https://graph.microsoft.com/v1.0/drives/$driveId/root/permissions";
            $permissionsResponse = $this->apiRequest($permissionsUrl, $headers);

            if (isset($permissionsResponse['value']) && count($permissionsResponse['value']) > 0) {
                $roles = [];

                foreach ($permissionsResponse['value'] as $perm) {
                    if (isset($perm['roles']) && isset($perm['grantedToV2']['siteGroup']['displayName'])) {
                        $roles[$perm['grantedToV2']['siteGroup']['displayName']] = $perm['roles'];
                    }
                }

                $results['permissions']['status'] = true;
                $results['permissions']['message'] = "Permissions récupérées.";
                $results['permissions']['roles'] = $roles;

                // 6. Vérifier l'accès sur le Drive en fonction des rôles
                $driveRoles = array_merge(...array_values($roles)); // Fusionner tous les rôles en un seul tableau

                if (in_array("owner", $driveRoles) || in_array("fullControl", $driveRoles)) {
                    $results['driveAccess']['status'] = true;
                    $results['driveAccess']['message'] = "L'utilisateur a un contrôle total sur le drive.";
                } elseif (in_array("write", $driveRoles)) {
                    $results['driveAccess']['status'] = true;
                    $results['driveAccess']['message'] = "L'utilisateur peut modifier des fichiers.";
                } elseif (in_array("read", $driveRoles)) {
                    $results['driveAccess']['status'] = false;
                    $results['driveAccess']['message'] = "L'utilisateur peut uniquement lire les fichiers.";
                } else {
                    throw new Exception("L'utilisateur n'a pas d'accès spécifique au drive.");
                }

            } else {
                throw new Exception("Impossible de récupérer les permissions.");
            }

        } catch (Exception $e) {
            $results['permissions']['message'] = $e->getMessage();
            $results['driveAccess']['message'] = "Erreur lors de la récupération des permissions.";
        }

        return $results;
    }

    /**
     * Fonction pour tester si Microsoft Graph Query est accessible (sans récupérer les fichiers)
     */
    public function testGraphQueryAccess() {
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            return ['status' => false, 'message' => 'Échec de récupération du token d’accès'];
        }

        $search_url = 'https://graph.microsoft.com/v1.0/search/query';

        // Définition d'une requête minimale pour tester l'accès
        $search_data = [
            "requests" => [
                [
                    "entityTypes" => ["driveItem"], // Vérifie l'accès aux fichiers SharePoint
                    "query" => [
                        "queryString" => "filetype:pdf" // Requête de base sans condition complexe
                    ],
                    "region" => "FRA"
                ]
            ]
        ];

        $search_data_json = json_encode($search_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => false, 'message' => 'Erreur lors de l’encodage JSON : ' . json_last_error_msg()];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $search_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $search_data_json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            return ['status' => false, 'message' => 'Erreur cURL : ' . curl_error($ch)];
        }

        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status == 200) {
            return ['status' => true, 'message' => 'Accès à Microsoft Graph Query validé'];
        } else {
            return ['status' => false, 'message' => "Erreur de requête Graph API. Code HTTP : $http_status", 'response' => json_decode($response, true)];
        }
    }

    /**
     * Fonction générique pour envoyer une requête API avec cURL
     */
    public function apiRequest($url, $headers, $postData = null, $method = "GET") {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        if ($method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus >= 400) {
            throw new Exception("Erreur HTTP $httpStatus lors de la requête à $url");
        }

        return json_decode($response, true);
    }
}
