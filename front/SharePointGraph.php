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

    /**
     * Fonction récursive pour récupérer les fichiers récents dans un dossier SharePoint (incluant les sous-dossiers)
     */
    public function getRecentFilesRecursive($folderPath, $startDate = null, $endDate = null) {
        $accessToken = $this->getAccessToken();
        $driveId = $this->GetDriveId();

        // Construire l'URL en fonction de la valeur de $folderPath
        if (empty($folderPath)) {
            $url = "https://graph.microsoft.com/v1.0/drives/$driveId/root/children";
        } else {
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
        curl_close($ch);

        $files = json_decode($response, true)['value'];
        if (!$files) {
            return [];
        }

        $recentFiles = [];

        foreach ($files as $file) {
            if (isset($file['folder'])) {
                // Si l'élément est un dossier, appeler la fonction récursive
                $subfolderPath = $folderPath . '/' . $file['name'];
                $recentFiles = array_merge($recentFiles, $this->getRecentFilesRecursive($subfolderPath, $startDate, $endDate));
            } elseif (isset($file['file']) && $file['file']['mimeType'] === 'application/pdf') {
                // Vérifier la date de modification
                $lastModified = new DateTime($file['lastModifiedDateTime']);
                $includeFile = true;

                if ($startDate) {
                    $start = new DateTime($startDate);
                    if ($lastModified < $start) {
                        $includeFile = false;
                    }
                }

                if ($endDate) {
                    $end = new DateTime($endDate);
                    if ($lastModified > $end) {
                        $includeFile = false;
                    }
                }

                // Ajouter le fichier si les critères de date sont respectés
                if ($includeFile) {
                    $recentFiles[] = $file;
                }
            }
        }

        return $recentFiles;
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
}

