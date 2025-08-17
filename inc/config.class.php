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

      ?><style>
         .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
         }
         .switch input {
            opacity: 0;
            width: 0;
            height: 0;
         }
         .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
         }
         .slider:before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
         }
         input:checked + .slider {
            background-color: #2196F3;
         }
         input:checked + .slider:before {
            transform: translateX(26px);
         }
      </style><?php

      global $DB;
      $config = new self();
      $config->getFromDB(1);
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';

      $sharepoint = new PluginGestionSharepoint();
         $errorcon = "";
         $checkcon ="";
         $mode = true;

      if($config->SharePointOn() == 1 && $config->SageOn() == 0){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 0 WHERE id = 1";
            $DB->query($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SageSearch = 0 WHERE id = 1";
            $DB->query($update);
      }
      if($config->SharePointOn() == 0 && $config->SageOn() == 1){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 1 WHERE id = 1";
            $DB->query($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SharePointSearch = 0 WHERE id = 1";
            $DB->query($update);
      }
      if($config->SharePointOn() == 0 && $config->SageOn() == 0){
         // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configs SET mode = 2 WHERE id = 1";
            $DB->query($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SageSearch = 0 WHERE id = 1";
            $DB->query($update);
            $update = "UPDATE glpi_plugin_gestion_configs SET SharePointSearch = 0 WHERE id = 1";
            $DB->query($update);
            $mode = false;
      }
      if($config->SageSearch() == 0 && $config->SharePointSearch() == 0){
            $update = "UPDATE glpi_plugin_gestion_configs SET LocalSearch = 1 WHERE id = 1";
            $DB->query($update);
      }

      if($config->mode() == 1){
         // Vérifie s'il existe au moins une ligne avec param = 1
         $query = "SELECT id FROM glpi_plugin_gestion_configsfolder WHERE params = 1";
         $result = $DB->query($query);

         if ($result && $DB->numrows($result) > 0) {
            // Met à jour toutes les lignes avec param = 1 => param = 8
            $update = "UPDATE glpi_plugin_gestion_configsfolder SET params = 8 WHERE params = 1";
            $DB->query($update);
         }
      }

      $config->showFormHeader(['colspan' => 4]);
      echo "<tr><th colspan='2'>" . __('Gestion', 'gestion') . "</th></tr>";

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
         echo "<td>" . __("Conservation du PDF non signé après la signature", "gestion") . "</td><td>";
            Dropdown::showYesNo('ConfigModes', $config->ConfigModes(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Autorisé la connexion à Sage local", "gestion") . "</td><td>";
            Dropdown::showYesNo('SageOn', $config->SageOn(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Autorisé la connexion à Sharepoint", "gestion") . "</td><td>";
            Dropdown::showYesNo('SharePointOn', $config->SharePointOn(), -1);
         echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Recherche de documents dans le dossier local GLPI", "gestion") . "</td><td>";
         $LocalSearch = $config->LocalSearch(); // Valeur réelle depuis la base (0 ou 1)
         // Champ caché pour garantir la soumission de 0 si décoché
         echo '<input type="hidden" name="LocalSearch" value="0">';
         echo '<label class="switch">';
            echo '<input type="checkbox" id="LocalSearch_switch" name="LocalSearch" value="1" ' . ($LocalSearch == 1 ? 'checked' : '') . '>';
            echo '<span class="slider round"></span>';
         echo '</label>';
      echo "</td></tr>";
    
      // -----------------------------------------------------------------------
      echo "<tr>";
         echo "<th colspan='2'>";
            echo '<button type="button" class="accordion-toggle" onclick="toggleConfigSection(this)">';
               echo '<span class="arrow">▶</span> ' . __("Positionnement des éléments (Paramétre : 0 pour masqué)", 'gestion');
            echo '</button>';
         echo "</th>";
      echo "</tr>";

      echo "<tbody class='config-section' style='display: none;'>"; // Début de section masquée
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
      echo "</tbody>"; // Fin de la section masquée

      echo "<tr><th colspan='2'>" . __("Configuration de l'affichage et Tâche cron", 'gestion') . "</th></tr>";
      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Prévisualisation du PDF avant signature <i class='fa-solid fa-circle-info text-secondary' data-bs-toggle='tooltip' data-bs-placement='top' title=\"(cela peut provoquer des ralentissements). Vérifiez également la configuration de SharePoint pour l'autorisation de partage par lien.\"></i>", "gestion") . "</td><td>";
            Dropdown::showYesNo('SharePointLinkDisplay', $config->SharePointLinkDisplay(), -1);
         echo "</td>";
      echo "</tr>";

      // Générer les options du menu déroulant
      $dropdownValues = [];
      for ($i = 10; $i <= 500; $i += 10) {
         $dropdownValues[$i] = $i; // La clé et la valeur sont identiques dans ce cas
      }
      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Nombre d'éléments maximum à afficher par requête", "gestion") . "</td><td>";
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

      if (Plugin::isPluginActive('formcreator')) {
         echo "<tr class='tab_bg_1'>";
            echo "<td> Affichage du formulaire dans un modal (Vide pour désactivé) </td>";
            echo "<td>";
            
            // Récupérer les données depuis la table glpi_plugin_formcreator_forms
            $formcreator_forms = [];
            global $DB;
            $query = "SELECT `id`, `name` FROM `glpi_plugin_formcreator_forms`";
            $result = $DB->query($query);
            
            if ($result) {
               while ($data = $DB->fetchAssoc($result)) {
                  $formcreator_forms[$data['id']] = $data['name'];
               }
            }
            
            // Afficher le dropdown
            Dropdown::showFromArray('formulaire', $formcreator_forms, [
               'value' => $config->formulaire(), // ID par défaut sélectionné
               'display_emptychoice' => 1,
               'emptylabel' => "-----"
            ]);
         echo "</td></tr>";
      }
      
      //--------------------------------------------
      $result = [];
      if(!empty($config->TenantID()) && $config->SharePointOn() == 1){
         // Utilisation
         try {
            $result = $sharepoint->validateSharePointConnection($config->Hostname().':'.$config->SitePath());
            if ($result['status']) {
               $checkcon = 'Connexion API : <i class="fa fa-check-circle fa-xl text-success"></i></i>' . "\n";
               try {              
                  // Étape 2 : Récupérer l'ID du site
                  $siteId = '';
                  $siteId = $sharepoint->getSiteId($config->Hostname(), $config->SitePath());
               } catch (Exception $e) {
                  $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Erreur : '.$e->getMessage().'"></i>';
               }
            } else {
               $checkcon = 'Connexion API : <i class="fa fa-times-circle fa-xl text-danger"></i>' . "\n";
               $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-secondary" data-bs-toggle="tooltip" data-bs-placement="top" title="'.$result['message'].'"></i>';
            }
         } catch (Exception $e) {
            $errorcon = '  <i class="fa-solid fa-circle-info fa-xl text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Erreur inattendue : '.$e->getMessage().'"></i>';
         }      
      }

      if($config->SharePointOn() == 1){
         echo "<tr>";
            echo "<th colspan='2'>";
               echo '<button type="button" class="accordion-toggle" onclick="toggleConfigSection1(this)">';
                  echo '<span class="arrow">▶</span> ' . __('Connexion SharePoint (API Graph) | '.$checkcon . $errorcon, 'gestion');
               echo '</button>';
            echo "</th>";
         echo "</tr>";

         echo "<tbody class='config-section1' style='display: none;'>"; // Début de section masquée
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

            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Recherche de documents dans SharePoint", "gestion") . "</td><td>";
               $SharePointSearch = $config->SharePointSearch(); // Valeur réelle depuis la base (0 ou 1)
               // Champ caché pour garantir la soumission de 0 si décoché
               echo '<input type="hidden" name="SharePointSearch" value="0">';
               echo '<label class="switch">';
                  echo '<input type="checkbox" id="SharePointSearch_switch" name="SharePointSearch" value="1" ' . ($SharePointSearch == 1 ? 'checked' : '') . '>';
                  echo '<span class="slider round"></span>';
               echo '</label>';
            echo "</td></tr>";
         echo "</tbody>"; // Fin de la section masquée
      }

      if($config->SageOn() == 1){
         echo "<tr>";
            echo "<th colspan='2'>";
               echo '<button type="button" class="accordion-toggle" onclick="toggleConfigSection2(this)">';
                  echo '<span class="arrow">▶</span> ' . __('Connexion Sage Local', 'gestion');
               echo '</button>';
            echo "</th>";
         echo "</tr>";

         echo "<tbody class='config-section2' style='display: none;'>"; // Début de section masquée
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Url Api Sage", "gestion") . "</td><td>";
                  echo Html::input('SageUrlApi', ['value' => $config->SageUrlApi(), 'size' => 80]);// bouton configuration du bas de page line 1
               echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Sage Token", "gestion") . "</td><td>";
                  echo Html::input('SageToken', ['value' => $config->SageToken(), 'size' => 80]);// bouton configuration du bas de page line 1
               echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Recherche de documents dans Sage", "gestion") . "</td><td>";
               $SageSearch = $config->SageSearch(); // Valeur réelle depuis la base (0 ou 1)
               // Champ caché pour garantir la soumission de 0 si décoché
               echo '<input type="hidden" name="SageSearch" value="0">';
               echo '<label class="switch">';
                  echo '<input type="checkbox" id="SageSearch_switch" name="SageSearch" value="1" ' . ($SageSearch == 1 ? 'checked' : '') . '>';
                  echo '<span class="slider round"></span>';
               echo '</label>';
            echo "</td></tr>";

         echo "</tbody>"; // Fin de la section masquée
      }

      echo "<tr><th colspan='2'>" . __("Bibliothèques", 'gestion') . "</th></tr>";
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Mode de recheche par defaut :", "gestion") ;

            $values4 = [];
            if($config->SharePointOn() == 1)                $values4[0] = 'Sharepoint';
            if($config->SageOn() == 1)                      $values4[1] = 'Sage Local';
            if($config->mode() != 0 || $mode == false)      $values4[2] = 'Local';

            echo  "</td><td>";
               Dropdown::showFromArray(
                  'mode',
                  $values4,
                  [
                     'value' => $config->mode(),
                  ]
            );
            echo "</td>";
         echo "</tr>";

      if($mode == true && !empty($config->TenantID()) || !empty($config->SageToken())){
         if ($config->SharePointOn() == 1 && !empty($result['status'])) {
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Bibliothèques SahrePoint : <i class='fa-solid fa-circle-exclamation text-warning' data-bs-toggle='tooltip' data-bs-placement='top' title='Attention : toute modification de la bibliothèque après l’utilisation d’une bibliothèque précédent peut entraîner des bugs ou des conflits.'></i>", "gestion") ;
                  //Récupérer les bibliothèques de documents du site
                  $drives = $sharepoint->getDrives($siteId);
                  $values3 = [];
                  // Afficher toutes les bibliothèques disponibles
                  foreach ($drives as $drive) {
                     if ($drive['name'] == 'Documents') {
                        $drive['name'] = 'Documents partages';
                     }
                        $values3[$drive['name']] = $drive['name'];
                  }
               echo  "</td><td>";
                  Dropdown::showFromArray(
                     'Global',
                     $values3,
                     [
                        'value' => $config->Global(),
                     ]
               );
               echo "</td>";
            echo "</tr>";
         }

         echo "<tr><th colspan='2'>" . __("Dossiers d'enregistrement du Sites (Voir SharePoint le nom des dossiers contenu dans la bibliothèque principale)", 'gestion') . "</th></tr>";
         
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

               if($config->SageOn() == 1 && $config->SharePointOn() == 0){
                  // Tableau des options pour le champ déroulant
                     $values2 = [
                        2 => __('Dossier de destination (Dépot Local)', 'gestion'),
                        5 => __('Envoyé un mail si visible dans le tracker', 'gestion'),
                        6 => __('Supprimer le dossier', 'gestion'),
                        8 => __('__Non attribué__', 'gestion'),
                        10 => __("Eléments de recheche", 'gestion'),
                     ];
               }elseif($config->SageOn() == 0 && $config->SharePointOn() == 1){
                  // Tableau des options pour le champ déroulant
                     $values2 = [
                        1 => __('Dossier de récupération (Recursive SharePoint)', 'gestion'),
                        2 => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
                        5 => __('Envoyé un mail si visible dans le tracker', 'gestion'),
                        6 => __('Supprimer le dossier', 'gestion'),
                        8 => __('__Non attribué__', 'gestion'),
                        10 => __("Eléments de recheche", 'gestion'),
                     ];
               }elseif($config->SageOn() == 1 && $config->SharePointOn() == 1){
                  if($config->mode() == 1){
                     // Tableau des options pour le champ déroulant
                     $values2 = [
                        2 => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
                        3 => __('Dossier de destination (Dépot Local)', 'gestion'),
                        5 => __('Envoyé un mail si visible dans le tracker', 'gestion'),
                        6 => __('Supprimer le dossier', 'gestion'),
                        8 => __('__Non attribué__', 'gestion'),
                        10 => __("Eléments de recheche", 'gestion'),
                     ];
                  }else{
                     // Tableau des options pour le champ déroulant
                     $values2 = [
                        1 => __('Dossier de récupération (Recursive SharePoint)', 'gestion'),
                        2 => __('Dossier de destination (Dépot Global SharePoint)', 'gestion'),
                        3 => __('Dossier de destination (Dépot Local)', 'gestion'),
                        5 => __('Envoyé un mail si visible dans le tracker', 'gestion'),
                        6 => __('Supprimer le dossier', 'gestion'),
                        8 => __('__Non attribué__', 'gestion'),
                        10 => __("Eléments de recheche", 'gestion'),
                     ];
                  }
               }

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
   
      echo "<tr><th colspan='2'>" . __("Entités et Tracker", 'gestion') . "</th></tr>";
         //--------------------------------------------
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Extraction d'un tracker", "gestion") . "</td><td>";
               Dropdown::showYesNo('ExtractYesNo', $config->ExtractYesNo(), -1);
            echo "</td>";
         echo "</tr>";

         if($config->ExtractYesNo() == 1){
            if ($config->mode() == 0){
               echo "<tr class='tab_bg_1'>";
                  echo "<td>" . __("Séparateurs pour l'extraction du tracker", "gestion") . "</td><td>";
                     echo Html::input('extract', ['value' => $config->extract(), 'size' => 60]);// bouton configuration du bas de page line 1
                  echo "</td>";
               echo "</tr>";
            }

            //--------------------------------------------
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
         }else{
            if($config->MailTrackerYesNo() == 1){
               // Préparer la requête SQL
               $sql = "UPDATE glpi_plugin_gestion_configs 
                     SET MailTrackerYesNo = ?
                     WHERE id = 1";

               // Exécution de la requête préparée
               $stmt = $DB->prepare($sql);
               $stmt->execute([0]);
            }
         }

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("_________________________________________________________________________", "gestion") . "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __("Extraire l'entité du dossier parent", "gestion") . "</td><td>";
               Dropdown::showYesNo('EntitiesExtract', $config->EntitiesExtract(), -1);
            echo "</td>";
         echo "</tr>";

         if($config->EntitiesExtract() == 1 && $config->mode() == 0){
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Séparateurs pour l'extraction de l'entité depuis la Bibliothèques du site", "gestion") . "</td><td>";
                  echo '<div style="display: flex; align-items: center; gap: 5px;">';
                     echo '<label for="DateX">Après le chemin : </label>';
                        echo Html::input('EntitiesExtractValue', ['value' => $config->EntitiesExtractValue(), 'size' => 60]);// bouton configuration du bas de page line 1
                  echo '</div>';
               echo "</td>";
            echo "</tr>";
         }

         $lastrun = $DB->query("SELECT lastrun FROM glpi_crontasks WHERE name = 'GestionPdf'")->fetch_object();
         if($lastrun->lastrun == NULL){
            $lastrun->lastrun = 'Jamais';
         }

         echo "<tr>";
            echo "<th colspan='2'>";
               echo '<button type="button" class="accordion-toggle" onclick="toggleConfigSection3(this)">';
                  echo '<span class="arrow">▶</span> ' . __("Dérnière synchronisation Cron : ".$lastrun->lastrun, 'gestion');
               echo '</button>';
            echo "</th>";
         echo "</tr>";

         echo "<tbody class='config-section3' style='display: none;'>"; // Début de section masquée
         //echo "<tr><th colspan='2'>" . __("Dérnière synchronisation Cron : ".$lastrun->lastrun, 'gestion') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
               echo "<td>" . __("Recheche des nouveaux documents :", "gestion") . "</td><td>";
               if ($config->mode() == 0) echo "Filtre de recheche, 500 Documents Max par odre de modifictation et d'ajout. <br>";
               echo "Requête : de la date et heure suivante : ";
                  Html::showDateTimeField("LastCronTask", [
                     'value'      => $config->LastCronTask(), 
                     'canedit'    => true,
                     'maybeempty' => true,
                     'mindate'    => '',
                     'mintime'    => '',
                     'maxdate'    => date('Y-m-d H:i:s'),
                     //'maxtime'    => date('H:i:s') // non nécessaire
                  ]);
               echo "-> Jusqu'a la date et heure d'execution de la tâche cron.";
               echo "</td>";
            echo "</tr>";
            echo '<style> button[btn-id="0"] { display: none !important; } </style>';
         echo "</tbody>"; // Fin de la section masquée
 
         echo "<tr><th colspan='2'>" . __("Connexion", 'gestion') . "</th></tr>";
         echo "<tr class='tab_bg_1'>";

         echo "<td>" . __("Statut de connexion", "gestion") . "</td><td>";

            ?><button id="openModalButton" type="button" class="btn btn-primary">Statut de connexion</button>

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
               <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                     <div class="modal-header">
                           <h5 class="modal-title" id="AddGestionModalLabel">Statut de connexion <i class='fa-solid fa-circle-info text-secondary' data-bs-toggle='tooltip' data-bs-placement='top' title="Pensez à vérifier les droits de suppression, de lecture et d'écriture sur le site SharePoint afin d'assurer son bon fonctionnement et une récupération optimale des métadonnées."></i></h5>
                           <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body">
                        <ul class="list-group">
                           <li class="list-group-item d-flex fw-bold">
                              <div class="col-4">Champ</div>
                              <div class="col-6">Statut</div>
                              <div class="col-2 text-center">Validation</div>
                           </li>
            HTML;
               $result = $sharepoint->checkSharePointAccess(); 

               $statusIcons = [
                  1 => '<i class="fa fa-check-circle text-success"></i>', // ✅ Succès
                  0 => '<i class="fa fa-times-circle text-danger"></i>'   // ❌ Échec
               ];
               
               $fields = [
                  'accessToken'      => 'Token d\'accès',
                  'sharePointAccess' => 'Accès SharePoint',
                  'siteID'           => 'Site ID',
                  'graphQuery'       => 'Microsoft Graph Query',
                  'driveAccess'      => 'Accès au Drive : <br> - '.$config->Global(),
                  'permissions'      => 'Permissions SharePoint : <br> - '.$config->Global()
               ];
               
               foreach ($fields as $key => $label) {
                  if (isset($result[$key])) {
                     $status = $result[$key]['status'] ?? 0;
                     $message = htmlspecialchars($result[$key]['message'], ENT_QUOTES, 'UTF-8');
                     $icon = ($key !== 'permissions') ? ($statusIcons[$status] ?? $statusIcons[0]) : ''; // ❌ Retirer icône pour permissions
               
                     echo "<li class='list-group-item d-flex'>";
                     echo "<div class='col-4'><strong>$label</strong></div>";
               
                     // 🔹 Affichage des permissions sous forme de liste (sans icônes)
                     if ($key === 'permissions' && isset($result[$key]['roles']) && !empty($result[$key]['roles'])) {
                           echo "<div class='col-6'><ul class='list-unstyled'>";
                           foreach ($result[$key]['roles'] as $group => $roles) {
                              $roleList = implode(', ', array_map('htmlspecialchars', $roles));
                              echo "<li><strong>$group :</strong> $roleList</li>";
                           }
                           echo "</ul></div>";
                     } else {
                           echo "<div class='col-6'>";
                           
                           // 🔹 Ajout d'une icône d'information uniquement pour `driveAccess`
                           if ($key === 'driveAccess') {
                              if (strpos($message, 'modifier des fichiers') !== false) {
                                 $message .= " <i class='fa-solid fa-circle-exclamation text-warning' data-bs-toggle='tooltip' 
                                                data-bs-placement='top' title='Le plugin ne pourra pas supprimer ou télécharger automatiquement les documents après signature, il ne sera pas fonctionnel à 100%'></i>";
                              } elseif (strpos($message, 'uniquement lire les fichiers') !== false) {
                                 $message .= " <i class='fa-solid fa-circle-info text-secondary' data-bs-toggle='tooltip' 
                                                data-bs-placement='top' title='Le plugin ne pourra pas supprimer, télécharger ou modifier automatiquement les documents après signature'></i>";
                              }
                           }
               
                           echo "$message</div>";
                     }
               
                     echo "<div class='col-2 text-center'>$icon</div>";
                     echo "</li>";
                  }
               }            
            echo <<<HTML
                        </ul>
                     </div>
                     <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                     </div>
                  </div>
               </div>
            </div>
            HTML; 
         echo "</td>";
      echo "</tr>";

      echo <<<JS
         <script>
            function toggleConfigSection(btn) {
               const section = btn.closest('table').querySelector('.config-section');
               const arrow = btn.querySelector('.arrow');
               const isVisible = section.style.display === 'table-row-group';
               section.style.display = isVisible ? 'none' : 'table-row-group';
               arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
            }

            function toggleConfigSection1(btn) {
               const section = btn.closest('table').querySelector('.config-section1');
               const arrow = btn.querySelector('.arrow');
               const isVisible = section.style.display === 'table-row-group';
               section.style.display = isVisible ? 'none' : 'table-row-group';
               arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
            }

            function toggleConfigSection2(btn) {
               const section = btn.closest('table').querySelector('.config-section2');
               const arrow = btn.querySelector('.arrow');
               const isVisible = section.style.display === 'table-row-group';
               section.style.display = isVisible ? 'none' : 'table-row-group';
               arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
            }

            function toggleConfigSection3(btn) {
               const section = btn.closest('table').querySelector('.config-section3');
               const arrow = btn.querySelector('.arrow');
               const isVisible = section.style.display === 'table-row-group';
               section.style.display = isVisible ? 'none' : 'table-row-group';
               arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
            }
         </script>
      JS;

      ?><style>
      .accordion-toggle {
         all: unset;
         background-color: #f0f0f0;
         border: 1px solid #ccc;
         border-radius: 5px;
         padding: 8px 12px;
         font-size: 14px;
         font-weight: bold;
         cursor: pointer;
         display: inline-flex;
         align-items: center;
         gap: 8px;
         transition: background-color 0.3s ease;
      }

      .accordion-toggle:hover {
         background-color: #e0e0e0;
      }

      .accordion-toggle .arrow {
         display: inline-block;
         transition: transform 0.2s ease;
      }
      </style><?php

      
      // --------------------- SECTION : SIGNATURE DÉPORTÉE (tablette) ---------------------
      echo "<tr><th colspan='2'>" . __("Signature déportée (tablette)", 'gestion') . "</th></tr>";

      // ON/OFF
      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Activer la signature déportée", "gestion") . "</td><td>";
            $RemoteSignatureOn = $config->RemoteSignatureOn();
            echo '<input type="hidden" name="RemoteSignatureOn" value="0">';
            echo '<label class="switch">';
               echo '<input type="checkbox" id="RemoteSignatureOn_switch" name="RemoteSignatureOn" value="1" ' . ($RemoteSignatureOn == 1 ? 'checked' : '') . '>';
               echo '<span class="slider round"></span>';
            echo '</label>';
         echo "</td>";
      echo "</tr>";

      // Utilisateurs autorisés
      echo "<tr class='tab_bg_1'>";
         echo "<td>" . __("Utilisateurs GLPI autorisés à déclencher", "gestion") . "</td><td>";
            $selected = $config->RemoteSignatureUsers();
            Dropdown::show('User', [
               'name'     => 'RemoteSignatureUsers[]',
               'multiple' => true,
               'value'    => $selected,
               'width'    => '60%'
            ]);
         echo "</td>";
      echo "</tr>";

      // Tablettes autorisées
      echo '</table>';
      echo "<div style='overflow-x:auto; max-width:100%;'>";
      echo "<table class='tab_cadre' style='width:100%; min-width:700px;'>";
      echo "<tr>
            <th>Device ID</th>
            <th>N° de série (info)</th>
            <th>Token</th>
            <th>Actif</th>
            <th>Supprimer</th>
            </tr>";

      $rows = []; // Ajout pour éviter l'erreur
      $res = $DB->query("SELECT * FROM glpi_plugin_gestion_signaturedevices ORDER BY device_id");
      if ($res) {
         while ($r = $DB->fetchassoc($res)) {
            $rows[] = $r;
         }
      }

      if (count($rows) > 0) {
      foreach ($rows as $r) {
         echo "<tr>";
         echo "<td>" . Html::entities_deep($r['device_id']) . "</td>";
         echo "<td>" . Html::entities_deep($r['serial']) . "</td>";
         echo "<td style='font-family:monospace'>" . Html::entities_deep($r['device_token']) . "</td>";

         // Checkbox pour Actif
         $checked = $r['is_active'] ? "checked" : "";
         echo "<td><input type='checkbox' name='device_active[".$r['id']."]' value='1' $checked></td>";

         echo "<td><input type='checkbox' name='device_delete[]' value='".$r['id']."'></td>";
         echo "</tr>";
      }
      } else {
         echo "<tr><td colspan='5' style='text-align:center;color:#888;'>Aucun appareil trouvé</td></tr>";
      }

      // Ligne d'ajout
      echo "<tr>";
      echo "<td><input type='text' name='new_device_id' placeholder='iPad-Atelier'></td>";
      echo "<td><input type='text' name='new_serial' placeholder='(optionnel)'></td>";
      echo "<td><input type='text' name='new_token' placeholder='laisser vide pour auto'></td>";
      echo "<td><input type='checkbox' name='new_active' checked></td>";
      echo "<td></td>";
      echo "</tr>";

      echo "</table>";
      echo "</div>";

      $config->showFormButtons(['candel' => false]);
      return false;
   }

   // return fonction (retourn les values enregistrées en bdd)
   function formulaire(){
      return ($this->fields['formulaire']);
   }
   function AddFileSite(){
      if (isset($this->fields['AddFileSite'])) return ($this->fields['AddFileSite']);
   }
   function Global(){
      return ($this->fields['Global']);
   }
   function LastCronTask(){
      return ($this->fields['LastCronTask']);
   }
   function SignatureX(){
      return ($this->fields['SignatureX']);
   } 
   function SignatureY(){
      return ($this->fields['SignatureY']);
   } 
   function SignatureSize(){
      return ($this->fields['SignatureSize']);
   } 
   function ExtractYesNo(){
      return ($this->fields['ExtractYesNo']);
   } 
   function extract(){
      return ($this->fields['extract']);
   } 
   function MailTrackerYesNo(){
      return ($this->fields['MailTrackerYesNo']);
   } 
   function MailTracker(){
      return ($this->fields['MailTracker']);
   } 
   function EntitiesExtract(){
      return ($this->fields['EntitiesExtract']);
   } 
   function EntitiesExtractValue(){
      return ($this->fields['EntitiesExtractValue']);
   } 
   function SignataireX(){
      return ($this->fields['SignataireX']);
   } 
   function SignataireY(){
      return ($this->fields['SignataireY']);
   } 
   function DateX(){
      return ($this->fields['DateX']);
   } 
   function DateY(){
      return ($this->fields['DateY']);
   } 
   function TechX(){
      return ($this->fields['TechX']);
   } 
   function TechY(){
      return ($this->fields['TechY']);
   }
   function NumberViews(){
      return ($this->fields['NumberViews']);
   }
   function ZenDocMail(){
      return ($this->fields['ZenDocMail']);
   }
   function SharePointLinkDisplay(){
      return ($this->fields['SharePointLinkDisplay']);
   }
   function DisplayPdfEnd(){
      return ($this->fields['DisplayPdfEnd']);
   }
   function MailTo(){
      return ($this->fields['MailTo']);
   }
   function gabarit(){
      return ($this->fields['gabarit']);
   }
   function ConfigModes(){
      return ($this->fields['ConfigModes']);
   }
   function gabarit_tracker(){
      return ($this->fields['gabarit_tracker']);
   }
   function mode(){
      return ($this->fields['mode']);
   }
   function SharePointOn(){
      return ($this->fields['SharePointOn']);
   }
   function SageOn(){
      return ($this->fields['SageOn']);
   }
   function SageSearch(){
      return ($this->fields['SageSearch']);
   }
   function SharePointSearch(){
      return ($this->fields['SharePointSearch']);
   }
   function LocalSearch(){
      return ($this->fields['LocalSearch']);
   }

   // --- Remote signature getters ---
   function RemoteSignatureOn() {
      return isset($this->fields['RemoteSignatureOn']) ? (int)$this->fields['RemoteSignatureOn'] : 0;
   }
   function RemoteSignatureUsers() {
      $raw = $this->fields['RemoteSignatureUsers'] ?? '[]';
      $arr = json_decode($raw, true);
      return is_array($arr) ? $arr : [];
   }
   function SageUrlApi(){
      return ($this->fields['SageUrlApi']);
   }

   function SageToken()     { return PluginGestionCrypto::decrypt($this->fields['SageToken']     ?? ''); }
   function TenantID()      { return PluginGestionCrypto::decrypt($this->fields['TenantID']      ?? ''); }
   function ClientID()      { return PluginGestionCrypto::decrypt($this->fields['ClientID']      ?? ''); }
   function ClientSecret()  { return PluginGestionCrypto::decrypt($this->fields['ClientSecret']  ?? ''); }
   function Hostname()      { return PluginGestionCrypto::decrypt($this->fields['Hostname']      ?? ''); }
   function SitePath()      { return PluginGestionCrypto::decrypt($this->fields['SitePath']      ?? ''); }
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
                  `ConfigModes` TINYINT NOT NULL DEFAULT '0',
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
      }
      
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.2.0'){
         include(PLUGIN_GESTION_DIR . "/install/update_120_130.php");
         update120to130(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.3.1'){
         include(PLUGIN_GESTION_DIR . "/install/update_132_next.php");
         update(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.4.3'){
         include(PLUGIN_GESTION_DIR . "/install/update_144_next.php");
         update_144_next(); 
      }
      if($DB->tableExists($table) && $_SESSION['PLUGIN_GESTION_VERSION'] > '1.4.4'){
         include(PLUGIN_GESTION_DIR . "/install/update_150_remote.php");
         update_150_remote(); 
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
      $table = 'glpi_plugin_gestion_remote_sign_requests';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
      $table = 'glpi_plugin_gestion_signaturedevices';
      if ($DB->TableExists($table)) {
         $migration->displayMessage("Uninstalling $table");
         $migration->dropTable($table);
      }
   }
}
