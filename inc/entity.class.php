<?php

/**
 * -------------------------------------------------------------------------
 * Credit plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Credit.
 *
 * Credit is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Credit is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Credit. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @author    François Legastelois
 * @copyright Copyright (C) 2017-2023 by Credit plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/credit
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;

class PluginRtEntity extends CommonDBTM
{
    public static $rightname = 'rt';

    public static function getTypeName($nb = 0)
    {
        return _sn('Temps passé (tâches / trajets)', 'Temps passé (tâches / trajets)', $nb, 'rt');
    }

    public static function getIcon()
    {
        return 'ti ti-clock-hour-4';
    }

    /**
     * Construit le tableau HTML "Durée des tâches / Durée des trajets"
     * à partir des mêmes requêtes et variables que ton code existant.
     * (actiontime en secondes, routetime en minutes)
     */
    public static function buildTimesTable(Ticket $ticket): string {
        global $DB;

        $ticketId = (int)$ticket->getField('id');

        // ----- Total tâches (en secondes -> minutes) -----
        $result_total_task = $DB->doQuery("SELECT SUM(actiontime) as TotalTask FROM glpi_tickettasks WHERE tickets_id = $ticketId")->fetch_object();
        if (!empty($result_total_task->TotalTask)) {
            $result_total_min_task = (int) ($result_total_task->TotalTask / 60);
            $h = (int) floor($result_total_min_task / 60);
            $m = $result_total_min_task % 60;
            $result_total_hour_task = $h . 'h' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $result_total_task_label = $result_total_hour_task . ' | ' . $result_total_min_task . ' min';
        } else {
            $result_total_task_label = 'Aucune durée';
        }

        // ----- Total trajets (déjà en minutes) -----
        $result_total_trajet = $DB->doQuery("SELECT SUM(routetime) as TotalTrajet FROM glpi_plugin_rt_tickets WHERE tickets_id = $ticketId")->fetch_object();
        if (!empty($result_total_trajet->TotalTrajet)) {
            $result_total_min_trajet = (int) $result_total_trajet->TotalTrajet;
            $h = (int) floor($result_total_min_trajet / 60);
            $m = $result_total_min_trajet % 60;
            $result_total_hour_trajet = $h . 'h' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $result_total_trajet_label = $result_total_hour_trajet . ' | ' . $result_total_min_trajet . ' min';
        } else {
            $result_total_trajet_label = 'Aucune durée';
        }

        // ----- Tableau identique à ton rendu -----
        $tableau = "<table class='table table-bordered'><thead><tr>"
                    . "<th>Durée des tâches</th><th>Durée des trajets</th>"
                    . "</tr></thead><tbody><tr>"
                    . "<td>{$result_total_task_label}</td><td>{$result_total_trajet_label}</td>"
                    . "</tr></tbody></table>";

        return $tableau;
    }
}
