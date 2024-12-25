<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionConfig extends CommonDBTM
{
   static private $_instance = null;

   function __construct()
   {
      global $DB;

      if ($DB->tableExists($this->getTable())) {
         $this->getFromDB(1);
      }
   }

   static function canCreate()
   {
      return Session::haveRight('config', UPDATE);
   }

   static function canView()
   {
      return Session::haveRight('config', READ);
   }

   static function canUpdate()
   {
      return Session::haveRight('config', UPDATE);
   }

   static function getTypeName($nb = 0)
   {
      return __("Gestion Bl ", "gestion");
   }

   static function getInstance()
   {
      if (!isset(self::$_instance)) {
         self::$_instance = new self();
         if (!self::$_instance->getFromDB(1)) {
            self::$_instance->getEmpty();
         }
      }
      return self::$_instance;
   }

   static function showConfigForm() //formulaire de configuration du plugin
   {
      $config = new self();
      $config->getFromDB(1);

      $config->showFormHeader(['colspan' => 4]);
      echo "<tr><th colspan='2'>" . __('Gestion', 'rp') . "</th></tr>";

      // Mode de configuration de récupération
         $values = [
            0 => __('Dossier Local','gestion'),
            1 => __('SharePoint (Graph)','gestion'),
         ];
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Mode de configuration des Sauvegardes/Récupérations des PDF", "gestion") . "</td><td>";
               Dropdown::showFromArray(
                  'ConfigModes',
                  $values,
                  [
                     'value' => $config->fields['ConfigModes']
                  ]
               );
            echo "</td>";
         echo "</tr>";
      // -----------------------------------------------------------------------

      if($config->fields['ConfigModes'] == 1){
         echo "<tr><th colspan='2'>" . __('Configuration de SharePoint (API Graph)', 'rp') . "</th></tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Tenant ID", "gestion") . "</td><td>";
               echo Html::input('TenantID', ['value' => $config->TenantID(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Client ID", "gestion") . "</td><td>";
               echo Html::input('ClientID', ['value' => $config->ClientID(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Client Secret", "gestion") . "</td><td>";
               echo Html::input('ClientSecret', ['value' => $config->ClientSecret(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Nom d’hôte", "gestion") . "</td><td>";
               echo Html::input('Hostname', ['value' => $config->Hostname(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Chemin du Site", "gestion") . "</td><td>";
               echo Html::input('SitePath', ['value' => $config->SitePath(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Test de connexion", "gestion") . "</td><td>";
               if(!empty($config->TenantID())){
                  require_once 'SharePointGraph.php';
                  $sharepoint = new PluginGestionSharepoint();
         
            ?><button id="openModalButton" type="button" class="btn btn-primary">Ouvrir le modal</button>

            <script type="text/javascript">
               $(document).ready(function() {
                  $('#openModalButton').on('click', function() {
                        $('#customModal').modal('show');
                  });
               });
            </script><?php

            // Modal HTML
            echo <<<HTML
            <div class="modal fade" id="customModal" tabindex="-1" aria-labelledby="AddGestionModalLabel" aria-hidden="true">
                  <div class="modal-dialog">
                     <div class="modal-content">
                        <div class="modal-header">
                              <h5 class="modal-title" id="AddGestionModalLabel">Test de connexion</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
            HTML;
            // Utilisation
            try {
               $result = $sharepoint->validateSharePointConnection($config->TenantID(), $config->ClientID(), $config->ClientSecret(), $config->Hostname().':'.$config->SitePath());
               if ($result['status']) {
                  echo $result['message'] . "\n";
               } else {
                  echo $result['message'] . "\n";
               }
            } catch (Exception $e) {
               echo "Erreur inattendue : " . $e->getMessage() . "\n";
            }

            echo '<br><br><br>';
            // Utilisation
            try {
               // Étape 1 : Obtenir le token d'accès
               $accessToken = $sharepoint->getAccessToken($config->TenantID(), $config->ClientID(), $config->ClientSecret());
               echo "Token d'accès obtenu avec succès !\n";
      
               // Étape 2 : Récupérer l'ID du site
               $siteId = '';
               $siteId = $sharepoint->getSiteId($accessToken, $config->Hostname(), $config->SitePath());
               echo "Site ID : $siteId\n";
      
               // Vous pouvez maintenant utiliser $siteId pour d'autres appels API
            } catch (Exception $e) {
                  echo "Erreur : " . $e->getMessage();
            }

            /*// Utilisation affiche des dossiers du site
               try {

                  // Étape 2 : Récupérer les bibliothèques de documents du site
                  $drives = $sharepoint->getDrives($accessToken, $siteId);

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
                  $contents = $sharepoint->listFolderContents($accessToken, $driveId, $folderPath);

                  // Affichage des résultats
                  echo "<br><br>Contenu du dossier 'BL':<br>";
                  foreach ($contents as $item) {
                        echo "- " . $item['name'] . " (" . ($item['folder'] ? "Dossier" : "Fichier") . ")<br>";
                  }
               } catch (Exception $e) {
                  echo "Erreur : " . $e->getMessage();
               }*/
         }
            echo <<<HTML
                                 <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                    <button type="submit" name="save_selection" class="btn btn-primary">Sauvegarder</button>
                                 </div>
                              </form>
                        </div>
                     </div>
                  </div>
            </div>
            HTML;
         echo "</td>";
      echo "</tr>";
      }

      $config->showFormButtons(['candel' => false]);
      return false;
   }

   // Fonction pour charger la clé de cryptage à partir du fichier
   private function loadEncryptionKey() {
      // Chemin vers le fichier de clé de cryptage
      $file_path = GLPI_ROOT . '/config/glpicrypt.key';
      return file_get_contents($file_path);
   }

   // return fonction (retourn les values enregistrées en bdd)
   function key()
   {
      return ($this->fields['key']);
   }
   function TenantID(){
      return openssl_decrypt(base64_decode($this->fields['TenantID']), 'aes-256-cbc', $this->loadEncryptionKey(), 0, '1234567890123456');   
   }
   function ClientID(){
      return openssl_decrypt(base64_decode($this->fields['ClientID']), 'aes-256-cbc', $this->loadEncryptionKey(), 0, '1234567890123456');
   }
   function ClientSecret(){
      return openssl_decrypt(base64_decode($this->fields['ClientSecret']), 'aes-256-cbc', $this->loadEncryptionKey(), 0, '1234567890123456');
   }
   function Hostname(){
      return openssl_decrypt(base64_decode($this->fields['Hostname']), 'aes-256-cbc', $this->loadEncryptionKey(), 0, '1234567890123456');
   }
   function SitePath(){
      return openssl_decrypt(base64_decode($this->fields['SitePath']), 'aes-256-cbc', $this->loadEncryptionKey(), 0, '1234567890123456');
   }
   // return fonction

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         return __("Gestion BL", "gestion");
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
   {

      if ($item->getType() == 'Config') {
         self::showConfigForm();
      }
      return true;
   }

   function decryptData($data) {
      // Clé de cryptage - Doit correspondre à la clé utilisée pour le cryptage
      $encryption_key = 'votre_clé_de_cryptage';
      return openssl_decrypt(base64_decode($data), 'aes-256-cbc', $encryption_key, 0, '1234567890123456');
   }
  
   static function install(Migration $migration)
   {
      global $DB;

      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

      $table = self::getTable();
      $config = new self();
      if ($DB->tableExists($table)) {
         $query = "DROP TABLE $table";
         $DB->query($query) or die($DB->error());
      }
      if (!$DB->tableExists($table)) {

         $migration->displayMessage("Installing $table");

         $query = "CREATE TABLE IF NOT EXISTS $table (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `ConfigModes` TINYINT NOT NULL DEFAULT '0',
                  `TenantID` VARCHAR(255) NULL,
                  `ClientID` VARCHAR(255) NULL,
                  `ClientSecret` VARCHAR(255) NULL,
                  `SiteUrl` VARCHAR(255) NULL,
                  `Hostname` VARCHAR(255) NULL,
                  `SitePath` VARCHAR(255) NULL,
                  PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());
         $config->add(['id' => 1,]);
      }
   }

   static function uninstall(Migration $migration)
   {
      global $DB;

      $table = self::getTable();
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
