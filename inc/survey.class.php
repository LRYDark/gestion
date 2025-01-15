<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 gestion plugin for GLPI
 Copyright (C) 2016-2022 by the gestion Development Team.

 https://github.com/pluginsglpi/gestion
 -------------------------------------------------------------------------

 LICENSE

 This file is part of gestion.

 gestion is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 gestion is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with gestion. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */


if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Class PluginGestionSurvey
 */
class PluginGestionSurvey extends CommonDBTM {

   static $rightname = "plugin_gestion_survey";

   static function getTypeName($nb = 0) {
      return _n('Gestion', 'Gestion', $nb, 'gestion');
   }

   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab(Log::class, $ong, $options);
      return $ong;
   }

   function canCreateItem() {

      if (!$this->checkEntity()) {
         return false;
      }
      return true;
   }

   /**
    * @return array
    */
   function rawSearchOptions() {
      

      $tab = [];

      $tab[] = [
         'id'                 => 'common',
         'name'               => self::getTypeName(2)
      ];

      $tab[] = [
         'id'                 => '1',
         'table'              => $this->getTable(),
         'field'              => 'bl',
         'name'               => __('Name'),
         'datatype'           => 'itemlink',
         'itemlink_type'      => $this->getType(),
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '2',
         'table'              => 'glpi_tickets',
         'field'              => 'id',
         'name'               => __('Tickets'),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => 'glpi_entities',
         'field'              => 'completename',
         'name'               => __('Entity'),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '4',
         'table'              => $this->getTable(),
         'field'              => 'doc_url',
         'name'               => __('Url du document'),
         'massiveaction'      => false,
         'datatype'           => 'text'
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this->getTable(),
         'field'              => 'signed',
         'name'               => __('Signé'),
         'datatype'           => 'bool'
      ];

      $tab[] = [
         'id'                 => '6',
         'table'              => $this->getTable(),
         'field'              => 'doc_date',
         'name'               => __('Date de création'),
         'datatype'           => 'datetime'
      ];

      $tab[] = [
         'id'                 => '7',
         'table'              => $this->getTable(),
         'field'              => 'date_creation',
         'name'               => __('Date de signature'),
         'datatype'           => 'datetime'
      ];

      $tab[] = [
         'id'                 => '8',
         'table'              => $this->getTable(),
         'field'              => 'users_ext',
         'name'               => __('Signataire'),
         'datatype'           => 'text'
      ];

      $tab[] = [
         'id'                 => '9',
         'table'              => 'glpi_users',
         'field'              => 'name',
         'name'               => __('Users'),
         'datatype'           => 'dropdown'
      ];

      return $tab;
   }


   /**
    * Print survey
    *
    * @param       $ID
    * @param array $options
    *
    * @return bool
    */
   function showForm($ID, $options = []) {
      global $DB;
      $config = new PluginGestionConfig();

      $params = ['job'        => $ID,
      'root_doc'   => PLUGIN_GESTION_WEBDIR];
      
      if (!$this->canView()) {
         return false;
      }

      $this->initForm($ID, $options);
      $this->showFormHeader($options);

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Non du document : ') . "</td>";
            echo "<td>";
            echo $this->fields['bl'].'.pdf';
            echo "</td>";  
            echo "<td>";
            echo '<a href="' . $this->fields['doc_url'] . '" target="_blank"><strong>Voir le Document</strong></a>'; // Bouton pour voir le PDF en plein écran
         echo "</td></tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Entity') . "</td>";
            echo "<td>";
            Dropdown::show('Entity', [
               'name' => 'entities_id',
               'value' => $this->fields["entities_id"],
               'display_emptychoice' => 1,
               'specific_tags' => [],
               'itemtype' => 'Entity',
               'displaywith' => [],
               'emptylabel' => "-----",
               'used' => [],
               'toadd' => [],
               'entity_restrict' => 0,
            ]); 
         echo "</td><td colspan='2'></td></tr>";

         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Ticket') . "</td>";
            echo "<td>";
            Dropdown::show('Ticket', [
               'name' => 'tickets_id', // Le nom du champ
               'value' => $this->fields["tickets_id"], // La valeur sélectionnée par défaut
               'display_emptychoice' => 1, // Afficher un choix vide
               'specific_tags' => [], // Éventuels attributs HTML supplémentaires
               'itemtype' => 'Ticket', // Type d'objet à afficher
               'displaywith' => ['id'], // Champs à afficher pour les tickets
               'emptylabel' => "-----", // Étiquette pour l'option vide
               'used' => [], // Filtrage des tickets déjà utilisés
               'toadd' => [], // Liste personnalisée d'objets à ajouter
               'entity_restrict' => 0, // Autoriser les tickets de toutes les entités
            ]);
         echo "</td><td colspan='2'></td></tr>";

         echo "<tr class='tab_bg_1'><td></td></tr>";
         echo "<tr class='tab_bg_1'><td></td></tr>";
         echo "<tr class='tab_bg_1'><td></td></tr>";
         echo "<tr class='tab_bg_1'><td></td></tr>";
         echo "<tr class='tab_bg_1'><td></td></tr>";

      $signed = '';
      if ($this->fields['signed'] == 1){
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Informations sur le document :') ."</td>";
            echo "<td>";
               echo Html::submit($this->fields['bl'], [
                  'name'    => 'showCriForm',
                  'class'   => 'btn btn-secondary',
                  'onclick' => "gestion_loadCriForm('showCriForm', '$ID', " . json_encode($params) . "); return false;"
              ]);
         echo "</td></tr>";
      }else{
         echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Informations sur le document :')."</td>";
            echo "<td>";
               echo Html::submit($this->fields['bl'], [
                  'name'    => 'showCriForm',
                  'class'   => 'btn btn-primary',
                  'onclick' => "gestion_loadCriForm('showCriForm', '$ID', " . json_encode($params) . "); return false;"
               ]);
         echo "</td></tr>";
      }
                 
      $this->showFormButtons($options);
      Html::closeForm();

      return true;
   }

   /**
    * Prepare input datas for adding the item
    **/
   function prepareInputForAdd($input) {

      if ($input['is_active'] == 1) {
         $dbu = new DbUtils();
         //we must store only one survey by entity
         $condition  = ['is_active' => 1]
                        + $dbu->getEntitiesRestrictCriteria($this->getTable(), 'entities_id', $input['entities_id'], true);
         $found = $this->find($condition);
         if (count($found) > 0) {
            Session::addMessageAfterRedirect(__('Error : only one survey is allowed by entity', 'gestion'), false, ERROR);
            return false;
         }
      }

      return $input;
   }

   /**
    * Prepare input datas for updating the item
    **/
   function prepareInputForUpdate($input){
      global $DB;

      $id = $input['id'];
      $mail = $input['mail'];      
      $query= "UPDATE glpi_plugin_gestion_surveysuser SET alternative_email = '$mail' WHERE survey_id = $id";
      $DB->query($query);

      //active external survey for entity
      if ($input['is_active'] == 1) {
         $dbu = new DbUtils();
         //we must store only one survey by entity (other this one)
         $condition  = ['is_active' => 1,
                        ['NOT' => ['id' => $this->getID()]]]
                       + $dbu->getEntitiesRestrictCriteria($this->getTable(), 'entities_id', $input['entities_id'], true);
         $found = $this->find($condition);
         if (count($found) > 0) {
            Session::addMessageAfterRedirect(__('Error : only one survey is allowed by entity',
                                                'gestion'), false, ERROR);
            return false;
         }
      }

      return $input;
   }
}
