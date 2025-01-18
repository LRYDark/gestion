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
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $config->showFormHeader(['colspan' => 4]);
      echo "<tr><th colspan='2'>" . __('Gestion', 'rp') . "</th></tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Affichage du PDF après signature", "gestion") . "</td><td>";
            Dropdown::showYesNo('DisplayPdfEnd', $config->DisplayPdfEnd(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Envoie des PDF par mail", "gestion") . "</td><td>";
            Dropdown::showYesNo('MailTo', $config->MailTo(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td> Gabarit : Modèle de notifications </td>";
         echo "<td>";

         //notificationtemplates_id
         Dropdown::show('NotificationTemplate', [
            'name' => 'gabarit',
            'value' => $config->gabarit(),
            'display_emptychoice' => 1,
            'specific_tags' => [],
            'itemtype' => 'NotificationTemplate',
            'displaywith' => [],
            'emptylabel' => "-----",
            'used' => [],
            'toadd' => [],
            'entity_restrict' => 0,
         ]); 
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Enregistrement dans ZenDoc par mail", "gestion") . "</td><td>";
            echo Html::input('ZenDocMail', ['value' => $config->ZenDocMail(), 'size' => 40]);// bouton configuration du bas de page line 1
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      
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

      echo "<tr><th colspan='2'>" . __("Positionnement des éléments (0 pour masqué)", 'rp') . "</th></tr>";
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Position de la signature sur le PDF", "gestion") . "</td><td>";
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="SignatureX">Signature X</label>';
                     echo Html::input('SignatureX', ['value' => $config->SignatureX(), 'size' => 10]);
                  echo '</div>';
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="SignatureY">Signature Y</label>';
                     echo Html::input('SignatureY', ['value' => $config->SignatureY(), 'size' => 10]);
                  echo '</div>';
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="SignatureSize">Signature taille</label>';
                     echo Html::input('SignatureSize', ['value' => $config->SignatureSize(), 'size' => 10]);
                  echo '</div>';
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Position nom du signataire ", "gestion") . "</td><td>";
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="SignataireX">Position X</label>';
                     echo Html::input('SignataireX', ['value' => $config->SignataireX(), 'size' => 10]);
                  echo '</div>';
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="SignataireY">Position Y</label>';
                     echo Html::input('SignataireY', ['value' => $config->SignataireY(), 'size' => 10]);
                  echo '</div>';
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Position date de signature", "gestion") . "</td><td>";
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="DateX">Position X</label>';
                     echo Html::input('DateX', ['value' => $config->DateX(), 'size' => 10]);
                  echo '</div>';
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="DateY">Position Y</label>';
                     echo Html::input('DateY', ['value' => $config->DateY(), 'size' => 10]);
                  echo '</div>';
            echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Position du nom du technicien", "gestion") . "</td><td>";
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="TechX">Position X</label>';
                     echo Html::input('TechX', ['value' => $config->TechX(), 'size' => 10]);
                  echo '</div>';
               echo '<div style="display: flex; align-items: center; gap: 5px;">';
                  echo '<label for="TechY">Position Y</label>';
                     echo Html::input('TechY', ['value' => $config->TechY(), 'size' => 10]);
                  echo '</div>';
            echo "</td>";
         echo "</tr>";

      if($config->fields['ConfigModes'] == 1){
         echo "<tr><th colspan='2'>" . __("Configuration de l'affichage et Tâche cron", 'rp') . "</th></tr>";
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Prévisualisation du PDF avant signature (cela peut provoquer des ralentissements). Vérifiez également la configuration de SharePoint pour le partage par lien.", "gestion") . "</td><td>";
               Dropdown::showYesNo('SharePointLinkDisplay', $config->SharePointLinkDisplay(), -1);
            echo "</td>";
         echo "</tr>";

         // Générer les options du menu déroulant
         $dropdownValues = [];
         for ($i = 100; $i <= 20000; $i += 100) {
            $dropdownValues[$i] = $i; // La clé et la valeur sont identiques dans ce cas
         }
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Nombre d'éléments maximum à afficher", "gestion") . "</td><td>";
               // Afficher le menu déroulant avec Dropdown::show()
               Dropdown::showFromArray(
                  'NumberViews',  // Nom de l'identifiant du champ
                  $dropdownValues,    // Tableau des options
                  [
                     'value'      => $config->NumberViews(),        // Valeur sélectionnée par défaut (optionnel)
                  ]
               );
            echo "</td>";
         echo "</tr>";
         //--------------------------------------------
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("_________________________________________________________________________", "gestion") . "</td>";
         echo "</tr>";
         
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Extraction d'un tracker dans le PDF", "gestion") . "</td><td>";
               Dropdown::showYesNo('ExtractYesNo', $config->ExtractYesNo(), -1);
            echo "</td>";
         echo "</tr>";

         if($config->ExtractYesNo() == 1){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Séparateurs pour l'extraction du tracker", "gestion") . "</td><td>";
                  echo Html::input('extract', ['value' => $config->extract(), 'size' => 60]);// bouton configuration du bas de page line 1
               echo "</td>";
            echo "</tr>";
         }
         //--------------------------------------------
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("_________________________________________________________________________", "gestion") . "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Envoyé un mail si le contenu d'un tracker est détécté (Tâche Cron)", "gestion") . "</td><td>";
               Dropdown::showYesNo('MailTrackerYesNo', $config->MailTrackerYesNo(), -1);
            echo "</td>";
         echo "</tr>";
         
         if($config->MailTrackerYesNo() == 1){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Mail", "gestion") . "</td><td>";
                  echo Html::input('MailTracker', ['value' => $config->MailTracker(), 'size' => 60]);// bouton configuration du bas de page line 1
               echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
               echo "<td> Gabarit : Modèle de notifications pour la Tâche Cron (Tracker) </td>";
               echo "<td>";

               //notificationtemplates_id
               Dropdown::show('NotificationTemplate', [
                  'name' => 'gabarit_tracker',
                  'value' => $config->gabarit_tracker(),
                  'display_emptychoice' => 1,
                  'specific_tags' => [],
                  'itemtype' => 'NotificationTemplate',
                  'displaywith' => [],
                  'emptylabel' => "-----",
                  'used' => [],
                  'toadd' => [],
                  'entity_restrict' => 0,
               ]); 
            echo "</td></tr>";
         }
         //--------------------------------------------
         echo "<tr><th colspan='2'>" . __('Connexion SharePoint (API Graph)', 'rp') . "</th></tr>";

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
            echo "<td>" . __("Chemin du Site (/sites/XXXX)", "gestion") . "</td><td>";
               echo Html::input('SitePath', ['value' => $config->SitePath(), 'size' => 80]);// bouton configuration du bas de page line 1
            echo "</td>";
         echo "</tr>";

         if(!empty($config->TenantID())){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Test de connexion", "gestion") . "</td><td>";
   
                  ?><button id="openModalButton" type="button" class="btn btn-primary">Test de connexion</button>

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
                        $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
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
                        // Étape 2 : Récupérer l'ID du site
                        $siteId = '';
                        $siteId = $sharepoint->getSiteId($config->Hostname(), $config->SitePath());
                        echo "Site ID : $siteId\n";
               
                        // Vous pouvez maintenant utiliser $siteId pour d'autres appels API
                     } catch (Exception $e) {
                           echo "Erreur : " . $e->getMessage();
                     }
               
                  echo <<<HTML
                                       <div class="modal-footer">
                                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                       </div>
                                    </form>
                              </div>
                           </div>
                        </div>
                  </div>
                  HTML;
               echo "</td>";
            echo "</tr>";

            if ($result['message'] == 'Connexion validée : Accès SharePoint réussi.'){
               echo "<tr><th colspan='2'>" . __("Bibliothèques principlale du site", 'rp') . "</th></tr>";

               echo "<tr class='tab_bg_1'>";
                  echo "<td>" . __("Global (ID de la Bibliothèques principlale du site)", "gestion") ;
                     //Récupérer les bibliothèques de documents du site
                     $drives = $sharepoint->getDrives($siteId);

                     // Afficher toutes les bibliothèques disponibles
                     echo "<br><br>Bibliothèques disponibles sur le site :<br>";
                     foreach ($drives as $drive) {
                           echo "- Nom : " . $drive['name'] . "<br>";
                     }
                  echo  "</td><td>";
                     echo Html::input('Global', ['value' => $config->Global(), 'size' => 30]);// bouton configuration du bas de page line 1
                  echo "</td>";
               echo "</tr>";

               echo "<tr><th colspan='2'>" . __("Dossiers d'enregistrement du Sites (Voir SharePoint le nom des dossiers contenu dans la bibliothèque principale)", 'rp') . "</th></tr>";
               
               echo "<tr class='tab_bg_1'>";
                  echo "<td>" . __("Ajouter un dossier (Nom du dossier)", "gestion") . "</td><td>";
                     echo Html::input('AddFileSite', ['value' => $config->AddFileSite(), 'size' => 40]);// bouton configuration du bas de page line 1
                  echo "</td>";
               echo "</tr>";
               
               global $DB;
               // Récupération des lignes (params) de la table
               $queryRows = "SELECT * FROM `glpi_plugin_gestion_configsfolder`;";

               $resultRows = $DB->query($queryRows);

               if ($resultRows && $DB->numrows($resultRows) > 0) {
                  while ($row = $DB->fetchAssoc($resultRows)) {
                     $folder_name = $row['folder_name'];
                     $value = $row['params'];

                     // Générer une ligne HTML pour chaque enregistrement
                     echo "<tr class='tab_bg_1'>";
                     echo "<td>" . __($folder_name, "gestion") . "</td><td>";

                     // Tableau des options pour le champ déroulant
                     $values2 = [
                           0 => __('Dossier de récupération (Racine)', 'gestion'),
                           1 => __('Dossier de récupération (Global - Recursive)', 'gestion'),
                           2 => __('Dossier de destination (Dépot Global)', 'gestion'),
                           5 => __('Envoyé un mail si visible dans le tracker', 'gestion'),
                           6 => __('Supprimer le dossier', 'gestion'),
                           8 => __('__Non attribué__', 'gestion'),
                     ];

                     // Générer un champ déroulant avec les options
                     Dropdown::showFromArray(
                           $folder_name,
                           $values2,
                           [
                              'value' => $value,
                              'class' => 'folder-dropdown', // Ajouter une classe CSS
                              'data-folder' => $folder_name // Ajouter un attribut unique pour JS
                           ]
                     );
                     echo "</td>";
                     echo "</tr>";
                  }

                  ?><script defer>
                     const dropdowns = document.querySelectorAll('.folder-dropdown');

                     // Fonction pour désactiver uniquement l'option avec la valeur `2`
                     function updateDropdowns() {
                        // Récupérer toutes les valeurs actuellement sélectionnées
                        const selectedValues = Array.from(dropdowns).map(dropdown => dropdown.value);

                        // Vérifier si la valeur `2` est sélectionnée
                        const isOption2Selected = selectedValues.includes("2");

                        // Si l'option 2 est sélectionnée, désactiver uniquement celle-ci dans les autres dropdowns
                        dropdowns.forEach(dropdown => {
                           const options = dropdown.querySelectorAll('option'); // Cibler les <option>
                           const currentValue = dropdown.value;

                           options.forEach(option => {
                                 const value = option.value;

                                 if (value === "2" && isOption2Selected && currentValue !== "2") {
                                    option.disabled = true;
                                 } else {
                                    option.disabled = false; // Réactiver si elle devient disponible
                                 }
                           });

                           // Rafraîchir le rendu Select2 après modification des options
                           $(dropdown).select2();
                        });
                     }

                     // Ajouter les événements sur chaque dropdown pour mettre à jour les options dynamiquement
                     dropdowns.forEach(dropdown => {
                        $(dropdown).on('select2:select', updateDropdowns); // Lorsqu'une option est sélectionnée
                        $(dropdown).on('select2:unselect', updateDropdowns); // Si une option est désélectionnée (utile pour multi-sélection)
                     });

                     // Mise à jour initiale
                     updateDropdowns();
                  </script><?php
               } else {
                  echo "<tr><td colspan='2'>Aucun paramètre trouvé ou erreur dans la base de données.</td></tr>";
               }
            }
         }
      }else{
         echo "<tr><th colspan='2'>" . __("Dossier de récupération et de déstination des PDFs en Local", 'rp') . "</th></tr>";
         echo "<tr class='tab_bg_1'><td>" . __("Dossier de récupération des PDFs : ".GLPI_PLUGIN_DOC_DIR."/gestion/unsigned/", "gestion") . "</td></tr>";
         echo "<tr class='tab_bg_1'><td>" . __("Dossier de déstination des PDFs : ".GLPI_PLUGIN_DOC_DIR."/gestion/signed/", "gestion") . "</td></tr>";
      }

      echo "<tr><th colspan='2'>" . __("Dérnière synchronisation : ".$config->LastCronTask(), 'rp') . "</th></tr>";

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
   function AddFileSite()
   {
      if (isset($this->fields['AddFileSite'])) return ($this->fields['AddFileSite']);
   }
   function Global()
   {
      return ($this->fields['Global']);
   }
   function ConfigModes()
   {
      return ($this->fields['ConfigModes']);
   }
   function LastCronTask()
   {
      return ($this->fields['LastCronTask']);
   }
   function SignatureX()
   {
      return ($this->fields['SignatureX']);
   } 
   function SignatureY()
   {
      return ($this->fields['SignatureY']);
   } 
   function SignatureSize()
   {
      return ($this->fields['SignatureSize']);
   } 
   function ExtractYesNo()
   {
      return ($this->fields['ExtractYesNo']);
   } 
   function extract()
   {
      return ($this->fields['extract']);
   } 
   function MailTrackerYesNo()
   {
      return ($this->fields['MailTrackerYesNo']);
   } 
   function MailTracker()
   {
      return ($this->fields['MailTracker']);
   } 
   function EntitiesExtract()
   {
      return ($this->fields['EntitiesExtract']);
   } 
   function SignataireX()
   {
      return ($this->fields['SignataireX']);
   } 
   function SignataireY()
   {
      return ($this->fields['SignataireY']);
   } 
   function DateX()
   {
      return ($this->fields['DateX']);
   } 
   function DateY()
   {
      return ($this->fields['DateY']);
   } 
   function TechX()
   {
      return ($this->fields['TechX']);
   } 
   function TechY()
   {
      return ($this->fields['TechY']);
   }
   function NumberViews()
   {
      return ($this->fields['NumberViews']);
   }
   function ZenDocMail()
   {
      return ($this->fields['ZenDocMail']);
   }
   function SharePointLinkDisplay()
   {
      return ($this->fields['SharePointLinkDisplay']);
   }
   function DisplayPdfEnd()
   {
      return ($this->fields['DisplayPdfEnd']);
   }
   function MailTo()
   {
      return ($this->fields['MailTo']);
   }
   function gabarit()
   {
      return ($this->fields['gabarit']);
   }
   function gabarit_tracker()
   {
      return ($this->fields['gabarit_tracker']);
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

      if (!$DB->tableExists($table)) {

         $migration->displayMessage("Installing $table");

         $query = "CREATE TABLE IF NOT EXISTS $table (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `ConfigModes` TINYINT NOT NULL DEFAULT '0',
                  `TenantID` TEXT NULL,
                  `ClientID` TEXT NULL,
                  `ClientSecret` TEXT NULL,
                  `Hostname` TEXT NULL,
                  `SitePath` TEXT NULL,
                  `Global` VARCHAR(255) NULL,
                  `ZenDocMail` VARCHAR(255) NULL,
                  `NumberViews` INT(10) NOT NULL DEFAULT '800',
                  `SharePointLinkDisplay` TINYINT NOT NULL DEFAULT '0',
                  `MailTo` TINYINT NOT NULL DEFAULT '0',
                  `DisplayPdfEnd` TINYINT NOT NULL DEFAULT '0',
                  `gabarit` INT(10) NOT NULL DEFAULT '0',
                  `SignatureX` FLOAT NOT NULL DEFAULT '36',
                  `SignatureY` FLOAT NOT NULL DEFAULT '44',
                  `SignatureSize` FLOAT NOT NULL DEFAULT '50',
                  `SignataireX` FLOAT NOT NULL DEFAULT '20',
                  `SignataireY` FLOAT NOT NULL DEFAULT '56.5',
                  `DateX` FLOAT NOT NULL DEFAULT '20',
                  `DateY` FLOAT NOT NULL DEFAULT '51.3',
                  `TechX` FLOAT NOT NULL DEFAULT '150',
                  `TechY` FLOAT NOT NULL DEFAULT '37',
                  PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());
         $config->add(['id' => 1,]);

         $query = "CREATE TABLE IF NOT EXISTS glpi_plugin_gestion_configsfolder (
            `id` int {$default_key_sign} NOT NULL auto_increment,
            `folder_name` TEXT NULL,
            `params` TINYINT NOT NULL DEFAULT '8',
            PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->query($query) or die($DB->error());

         $result = $DB->query("SELECT id FROM glpi_notificationtemplates WHERE NAME = 'Gestion Mail PDF' AND comment = 'Created by the plugin gestion'");

         while ($ID = $result->fetch_object()) {
             if (!empty($ID->id)) {
                 // Suppression de la ligne dans glpi_notificationtemplates
                 $deleteTemplateQuery = "DELETE FROM glpi_notificationtemplates WHERE id = {$ID->id}";
                 $DB->query($deleteTemplateQuery);
         
                 // Suppression de la ligne correspondante dans glpi_notificationtemplatetranslations
                 $deleteTranslationQuery = "DELETE FROM glpi_notificationtemplatetranslations WHERE notificationtemplates_id = {$ID->id}";
                 $DB->query($deleteTranslationQuery);
             }
         }
   
         require_once PLUGIN_GESTION_DIR.'/front/MailContent.php';
         $content_html = $ContentHtml;

         // Échapper le contenu HTML
         $content_html_escaped = Toolbox::addslashes_deep($content_html);
   
         // Construire la requête d'insertion
         $insertQuery1 = "INSERT INTO `glpi_notificationtemplates` (`name`, `itemtype`, `date_mod`, `comment`, `css`, `date_creation`) VALUES ('Gestion Mail PDF', 'Ticket', NULL, 'Created by the plugin gestion', '', NULL);";
         // Exécuter la requête
         $DB->query($insertQuery1);
   
         // Construire la requête d'insertion
         $insertQuery2 = "INSERT INTO `glpi_notificationtemplatetranslations` 
            (`notificationtemplates_id`, `language`, `subject`, `content_text`, `content_html`) 
            VALUES (LAST_INSERT_ID(), 'fr_FR', '[GLPI] | Document signé', '', '{$content_html_escaped}')";
         // Exécuter la requête
         $DB->query($insertQuery2);
   
         $ID = $DB->query("SELECT id FROM glpi_notificationtemplates WHERE NAME = 'Gestion Mail PDF' AND comment = 'Created by the plugin gestion'")->fetch_object();
   
         $query= "UPDATE glpi_plugin_gestion_configs SET gabarit = $ID->id WHERE id=1;";
         $DB->query($query) or die($DB->error());
      }else{
         if($DB->tableExists($table) && $_SESSION['PLUGIN_RP_VERSION'] > '1.2.0'){
            include(PLUGIN_RP_DIR . "/install/update_120_130.php");
            update120to130(); 
         }
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
      $table = 'glpi_plugin_gestion_configsfolder';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
