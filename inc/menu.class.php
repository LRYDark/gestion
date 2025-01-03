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

/**
 * Class PluginGestionMenu
 */
class PluginGestionMenu extends CommonGLPI
{
   static $rightname = 'gestion';

   /**
    * @return translated
    */
   static function getMenuName() {
      return __('Documents', 'gestion');
   }

   /**
    * @return array
    */
   static function getMenuContent() {

      $menu = [];

      if (Session::haveRight('plugin_gestion', READ)) {
         $menu['title']           = self::getMenuName();
         $menu['page']            = PLUGIN_GESTION_NOTFULL_WEBDIR."/front/survey.php";
         $menu['links']['search'] = PluginGestionSurvey::getSearchURL(false);
         if (PluginGestionSurvey::canCreate()) {
            $menu['links']['add'] = PluginGestionSurvey::getFormURL(false);
         }
      }

      $menu['icon'] = self::getIcon();

      return $menu;
   }

   static function getIcon() {
      return "fa-fw ti ti-report";
   }

   static function removeRightsFromSession() {
      if (isset($_SESSION['glpimenu']['admin']['types']['PluginGestionMenu'])) {
         unset($_SESSION['glpimenu']['admin']['types']['PluginGestionMenu']);
      }
      if (isset($_SESSION['glpimenu']['admin']['content']['plugingestionmenu'])) {
         unset($_SESSION['glpimenu']['admin']['content']['plugingestionmenu']);
      }
   }
}
