<?php
include('../../../inc/includes.php');
// Le chargeur GLPI ne connait pas la classe definie dans front/SharePointGraph.php, on l'inclut explicitement.
require_once dirname(__DIR__) . '/front/SharePointGraph.php';

Html::header_nocache();
Session::checkLoginUser();

global $DB, $CFG_GLPI;

/**
 * Radio « Signature Rapport » / « Signature Rapport + BL » en haut du modal combine.
 * onchange => gestion_switchCombinedMode (recharge le formulaire dans le modal).
 *
 * Le rendu vit desormais dans PluginGestionCri, pour que le plugin RP puisse
 * proposer le meme choix depuis ses propres formulaires. Cette fonction reste
 * comme raccourci local : elle est appelee a plusieurs endroits du fichier.
 */
if (!function_exists('gestion_render_combined_radio')) {
   function gestion_render_combined_radio(string $mode, int $bl_id, array $params): string {
      return PluginGestionCri::renderCombinedModeRadio($mode, $bl_id, $params);
   }
}

/**
 * Récapitulatif du ticket affiché en tête des modals de choix.
 *
 * Sans lui, il fallait ouvrir le ticket juste pour savoir de quoi il s'agissait
 * avant de choisir — alors que « Compléter l'intervention » y mène déjà. Les
 * informations sont donc données sur place, avec les composants natifs GLPI
 * (liste, badges), identiques à celles de la page mobile du plugin RP.
 *
 * @param int      $ticket_id ticket associé au BL
 * @param int|null $bl_signed état du BL (0 = à signer, 1 = signé, null = aucun)
 * @param string   $bl_name   nom du document à signer, s'il est renseigné
 */
if (!function_exists('gestion_render_ticket_summary')) {
   function gestion_render_ticket_summary(int $ticket_id, ?int $bl_signed, string $bl_name = ''): string {
      $ticket = new Ticket();
      if ($ticket_id <= 0 || !$ticket->getFromDB($ticket_id) || !$ticket->canViewItem()) {
         return '';
      }

      /**
       * @param bool $stacked valeur placée SOUS le libellé et alignée à gauche.
       *                      Réservé aux valeurs longues, comme l'arborescence
       *                      complète d'une entité : sur deux colonnes elle se
       *                      renvoie à la ligne au milieu des mots et devient
       *                      difficile à lire.
       */
      $row = static function (
         string $label,
         string $value_html,
         bool $stacked = false,
         string $note_html = ''
      ): string {
         $h = '<div class="list-group-item py-2">';
         if ($stacked) {
            $h .= '<div class="text-secondary">' . $label . '</div>';
            $h .= '<div class="fw-bold mt-2">' . $value_html . '</div>';
         } else {
            $h .= '<div class="d-flex justify-content-between align-items-center gap-3">';
            $h .= '<span class="text-secondary">' . $label . '</span>';
            $h .= '<span class="fw-bold text-end">' . $value_html . '</span>';
            $h .= '</div>';
         }
         if ($note_html !== '') {
            // Pleine largeur et `text-break` : un nom de fichier n'a pas
            // d'espaces où se couper, il déborderait dans une colonne étroite.
            $h .= '<div class="text-secondary small mt-3 text-break">' . $note_html . '</div>';
         }
         return $h . '</div>';
      };

      $h  = '<div class="card mb-3">';
      $h .= '<div class="card-header py-2">';
      $h .= '<div>';
      $h .= '<div class="card-title mb-1">'
         . sprintf(__('Ticket #%s', 'gestion'), sprintf('%07d', $ticket_id)) . '</div>';
      $h .= '<div class="text-secondary">'
         . htmlspecialchars((string)($ticket->fields['name'] ?? ''), ENT_QUOTES) . '</div>';
      $h .= '</div>';
      $h .= '</div>';

      $h .= '<div class="list-group list-group-flush">';
      $h .= $row(
         __('Client', 'gestion'),
         htmlspecialchars(
            Dropdown::getDropdownName('glpi_entities', (int)$ticket->fields['entities_id']),
            ENT_QUOTES
         ),
         true
      );
      $h .= $row(
         __('Statut', 'gestion'),
         htmlspecialchars(Ticket::getStatus((int)$ticket->fields['status']), ENT_QUOTES)
      );
      if ($bl_signed !== null) {
         // Nom du document à signer, seulement s'il est renseigné.
         $bl_name = trim($bl_name);
         $note    = $bl_name !== ''
            ? '<i class="ti ti-file-text me-1"></i>' . htmlspecialchars($bl_name, ENT_QUOTES)
            : '';
         $h .= $row(
            __('Bon de livraison', 'gestion'),
            $bl_signed === 0
               ? '<span class="badge bg-warning text-dark">' . __('À faire signer', 'gestion') . '</span>'
               : '<span class="badge bg-success text-white">' . __('Signé', 'gestion') . '</span>',
            false,
            $note
         );
      }
      $h .= '</div>';
      $h .= '</div>';

      return $h;
   }
}

$action = $_POST['action'] ?? '';
switch ($action) {
   case 'showCriForm' :
      $PluginGestionCri = new PluginGestionCri();
      $params           = $_POST["params"] ?? [];
      if (!is_array($params)) {
         // Meme garde que rp/ajax/cri.php : un `params` scalaire force ferait
         // lire des offsets de chaine sur chaque drapeau, et PHP 8 leve.
         $params = [];
      }
      $bl_id            = (int)($_POST["modal"] ?? 0);
      $force_bl         = !empty($params['force_bl']);       // « Signer le BL seul quand meme »
      $force_combined   = !empty($params['force_combined']); // « Signer Rapport + BL »
      $force_rp         = !empty($params['force_rp']);       // « Signature Rapport » seul (bascule radio)
      /*
       * Quel(s) bon(s) precocher dans la liste.
       *
       * Par defaut TOUS : la signature groupee sert a solder ce qui attend, et
       * c'est le cas de presque toutes les portes — bandeaux, page mobile,
       * scanner OCR, bouton flottant, bouton « Signature BL ».
       *
       * `one_bl` renverse la regle et n'est pose que la ou l'utilisateur a
       * clique sur UN bon precis : ligne du tableau des BL, colonne
       * « Signature » de la liste, fiche d'un BL, lien du planning. Tout
       * precocher dans ces cas-la faisait signer trois bons a qui n'en avait
       * designe qu'un. Les autres restent visibles, et cochables.
       */
      $check_only = !empty($params['one_bl']) ? $bl_id : 0;

      // Charger le BL pour connaitre son ticket associe et son etat de signature.
      $bl_row = null;
      if ($bl_id > 0) {
         $bl_row = $DB->request([
            'SELECT' => ['id', 'tickets_id', 'signed', 'bl'],
            'FROM'   => 'glpi_plugin_gestion_surveys',
            'WHERE'  => ['id' => $bl_id],
            'LIMIT'  => 1,
         ])->current();
      }

      $ticket_id = $bl_row ? (int)$bl_row['tickets_id'] : 0;
      $is_signed = $bl_row ? ((int)$bl_row['signed'] === 1) : false;
      $job       = $ticket_id > 0 ? $ticket_id : (int)($params['job'] ?? 0);
      $rp_active = Plugin::isPluginActive('rp')
                   && class_exists('PluginRpCri')
                   && class_exists('PluginRpConfig');

      /*
       * Droit de VOIR le formulaire de rapport, et pas seulement d'etre connecte.
       *
       * Ce point d'entree rend le formulaire du plugin RP, qui expose les taches,
       * les suivis et les adresses du ticket. Le point d'entree de RP, lui, exige
       * la regle d'acces du plugin ET la visibilite du ticket (rp/ajax/cri.php).
       * Passer par ici ne doit pas etre une porte plus permissive que celle-la,
       * d'autant que le rapport d'intervention y renvoie desormais lui-meme.
       *
       * `canUse` et non `checkUseAjax` : un refus n'est pas une erreur. Ne pas
       * avoir le droit de produire un rapport n'empeche pas de signer un bon —
       * on retombe alors sur la signature du BL seul, exactement comme lorsque
       * le plugin RP est absent.
       *
       * Sans niveau explicite : celui declare pour la fonctionnalite
       * (rp/inc/access.class.php) reste la reference unique.
       */
      $rp_allowed = false;
      if ($rp_active && $ticket_id > 0 && class_exists('PluginRpAccess')) {
         $rp_ticket  = new Ticket();
         $rp_allowed = $rp_ticket->getFromDB($ticket_id)
                       && $rp_ticket->canViewItem()
                       && PluginRpAccess::canUse('rapport_tech');
      }

      /*
       * Comptage des taches (le formulaire RP exige >=1 tache). Identique au
       * formulaire RP : jointure glpi_users + filtre is_private selon use_publictask.
       *
       * Calcule AVANT le parcours force_combined : la page mobile du plugin RP
       * demande directement « Rapport + BL » pour ne pas reposer une question
       * deja posee, et sans ce controle en amont elle contournait la regle qui
       * exige au moins une tache.
       */
      $task_count = 0;
      if ($rp_active && $ticket_id > 0) {
         $rp_config   = PluginRpConfig::getInstance();
         $only_public = (int)($rp_config->fields['use_publictask'] ?? 0) === 1;
         $private_sql = $only_public ? "AND gt.is_private = 0" : "";
         $task_row    = $DB->doQuery(
            "SELECT COUNT(gt.id) AS nb
             FROM glpi_tickettasks gt
             INNER JOIN glpi_users gu ON gt.users_id = gu.id
             WHERE gt.tickets_id = " . $ticket_id . " " . $private_sql
         )->fetch_object();
         $task_count  = (int)($task_row->nb ?? 0);
      }

      // Radio « Rapport seul » / « Rapport + BL » : rendu du formulaire choisi DANS le modal.
      // (force_combined = « Signer Rapport + BL » ; force_rp = bascule vers « Rapport seul ».)
      /*
       * `$force_bl` entre ici comme les deux autres.
       *
       * Il en etait exclu : choisir « Signature BL » dans le radio rechargeait
       * un formulaire SANS radio, et le technicien ne pouvait plus revenir a
       * « Rapport + BL » sans refermer et rouvrir le modal. Les trois modes
       * doivent reafficher le meme choix, seul le formulaire dessous change.
       *
       * Le `force_bl` du parcours SANS tache n'est pas concerne : ce bloc exige
       * `$task_count > 0`, il continue donc son chemin habituel plus bas.
       */
      if (($force_combined || $force_rp || $force_bl)
          && $rp_allowed && $ticket_id > 0 && !$is_signed && $task_count > 0) {
         /*
          * Le technicien vient du rapport d'atelier du plugin RP, ou il y a
          * repondu « le client repart avec ». Sa reponse reste affichee ici,
          * au-dessus de tout : sans elle il perdrait la main sur le sort du
          * materiel en venant signer le BL, et devrait rouvrir le modal.
          *
          * Rendue HORS du formulaire, donc jamais postee : elle ne sert qu'a
          * rebasculer, c'est le formulaire d'atelier qui commande la livraison.
          */
         /*
          * `form-container` : la MEME gouttiere que celle du formulaire qui
          * suit. Ces cartes sont rendues avant lui, donc en dehors ; sans ce
          * conteneur elles s'affichaient 30 px plus larges que toutes les
          * autres, decalees vers la gauche.
          *
          * Conteneur distinct de celui du formulaire, jamais imbrique : deux
          * gouttieres l'une dans l'autre doubleraient la marge.
          */
         echo '<div class="form-container">';
         // `method_exists` : methode NEUVE du plugin RP. Sur une version
         // anterieure, la classe existe mais pas la methode, et l'appel
         // remplacerait le formulaire par une page blanche.
         if (!empty($params['from_atelier'])
             && class_exists('PluginRpPreparation')
             && method_exists('PluginRpPreparation', 'showDestinationCard')) {
            PluginRpPreparation::showDestinationCard($ticket_id, 'form_rapport');
         }
         /*
          * `force_bl` n'est honore que si signer le bon seul est encore
          * legitime. Le lien a pu etre construit avant qu'une tache ne soit
          * ajoutee — bandeau « Etape suivante » deja affiche, modal reste
          * ouvert, bouton flottant charge plus tot. Dans ce cas on retombe sur
          * « Rapport + BL », et l'option BL n'apparait meme plus dans le choix.
          */
         $bl_allowed = (PluginGestionCri::defaultCombinedMode($ticket_id) === 'bl');
         $force_bl   = $force_bl && $bl_allowed;

         $mode = $force_rp ? 'rp' : ($force_bl ? 'bl' : 'both');
         echo gestion_render_combined_radio($mode, $bl_id, $params);
         echo '</div>';
         if ($force_bl) {
            // Liste a cocher, meme pour un seul bon : le parcours ne doit pas
            // changer de forme selon leur nombre.
            $PluginGestionCri->showCombinedMultiForm($ticket_id, ['bl_only' => true, 'check_only' => $check_only]);
         } elseif ($force_rp) {
            $_POST['modal'] = 'form_rapport';
            $rp = new PluginRpCri();
            /*
             * PAS de mode integre ici, contrairement au formulaire combine :
             * l'action reste celle du plugin RP, et sa reponse EST le PDF. Sans
             * le `target="_blank"` que RP pose lui-meme, la page du ticket etait
             * remplacee par le document, le contexte JS mourait avec elle et le
             * modal ne se refermait jamais.
             *
             * `bl_choice` : le choix « Rapport / Rapport + BL » est deja rendu
             * juste au-dessus par ce fichier — RP ne doit pas en poser un second.
             */
            $rp->showForm($ticket_id, ['modal' => 'form_rapport', 'bl_choice' => false]);
         } else {
            $unsigned_count = countElementsInTable('glpi_plugin_gestion_surveys', ['tickets_id' => $ticket_id, 'signed' => 0]);
            if ($unsigned_count > 1) {
               $PluginGestionCri->showCombinedMultiForm($ticket_id, ['check_only' => $check_only]);
            } else {
               $PluginGestionCri->showCombinedForm($ticket_id, $bl_id, []);
            }
         }
         break;
      }

      /*
       * Parcours Rapport + BL (sauf si on force explicitement la signature BL seule).
       *
       * `force_combined` / `force_rp` ne sont plus exclus ici : quand ils sont
       * demandes ET qu'au moins une tache existe, le bloc precedent a deja
       * repondu. Ceux qui arrivent jusqu'ici sont donc sans tache, et doivent
       * recevoir le message et les choix prevus pour ce cas plutot que de
       * basculer silencieusement sur la signature du BL seul.
       */
      /*
       * `$rp_active` et NON `$rp_allowed` : ce bloc ne rend pas que du HTML RP.
       *
       * Il porte aussi la redirection du scanner et la regle « exiger au moins
       * une tache » (NoTaskSignMode), qui sont des regles de l'ENTREPRISE, pas
       * des droits de l'utilisateur. Les faire dependre du droit rapport les
       * aurait rendues contournables : il suffisait de ne pas avoir ce droit
       * pour se voir offrir la signature du BL seul, precisement ce que le
       * reglage interdit. Le controle de droit est applique plus bas, au seul
       * endroit qui produit le formulaire de rapport.
       */
      if (!$force_bl && $rp_active && $ticket_id > 0 && !$is_signed) {
         $context   = (string)($params['root_modal'] ?? '');
         $on_ticket = ($context === 'ticket-form');   // onglet « Gestion BL » du ticket
         $is_scan   = ($context === 'scan-form');     // scanner OCR (survey.php)

         $ticket_url = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front/ticket.form.php?id=' . $ticket_id;
         // « Compléter l'intervention » : même parcours que le bouton de la page
         // mobile du plugin RP — le ticket s'ouvre avec le formulaire de tâche
         // déjà déplié, puis la signature du rapport enchaîne automatiquement.
         $task_url   = $ticket_url . '&rp_action=newtask';

         // Scanner OCR : on redirige TOUJOURS vers le ticket (inchange).
         if ($is_scan) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['redirect' => $ticket_url, 'reason' => 'go_to_ticket'], JSON_UNESCAPED_SLASHES);
            break;
         }

         // $task_count est calcule plus haut, avant le parcours force_combined.

         // ---- Au moins une tache : rapport generable ----
         if ($task_count > 0) {
            /*
             * Rapport generable, mais pas par celui-ci : on signe le bon seul,
             * exactement comme lorsque le plugin RP est absent. Un refus sec
             * priverait de la signature du BL, a laquelle il a bien droit.
             */
            if (!$rp_allowed) {
               $PluginGestionCri->showForm($job, [
                  'modal'      => $bl_id,
                  'root_modal' => $params["root_modal"] ?? '',
               ]);
               break;
            }
            if ($on_ticket) {
               /*
                * Sur le ticket : le choix « Que signe le client ? », puis le
                * formulaire correspondant.
                *
                * Le mode preselectionne n'est plus « Rapport + BL » en toutes
                * circonstances : quand le rapport d'intervention est DEJA signe,
                * le technicien revient signer les bons restants, et regenerer un
                * second rapport ne repond a rien. `defaultCombinedMode()` decide,
                * et c'est la meme methode qui autorise l'option « Signature BL ».
                */
               $mode = PluginGestionCri::defaultCombinedMode($ticket_id);

               // Meme gouttiere que le formulaire qui suit : rendue avant lui,
               // la carte serait sinon plus large que toutes les autres.
               echo '<div class="form-container">'
                  . gestion_render_combined_radio($mode, $bl_id, $params)
                  . '</div>';

               $unsigned_count = countElementsInTable('glpi_plugin_gestion_surveys', ['tickets_id' => $ticket_id, 'signed' => 0]);

               if ($mode === 'bl') {
                  // BL seul : liste a cocher meme pour UN bon, pour que le
                  // parcours ne change pas de forme selon leur nombre.
                  $PluginGestionCri->showCombinedMultiForm($ticket_id, ['bl_only' => true, 'check_only' => $check_only]);
               } elseif ($unsigned_count > 1) {
                  $PluginGestionCri->showCombinedMultiForm($ticket_id, ['check_only' => $check_only]);
               } else {
                  $PluginGestionCri->showCombinedForm($ticket_id, $bl_id, []);
               }
            } else {
               // Hors ticket (survey.form.php, planning) AVEC tache : popup 2 choix.
               // Le récapitulatif évite d'ouvrir le ticket juste pour savoir de
               // quoi il s'agit avant de choisir.
               // `gestion-choice` : repère du modal de choix, que le CSS ouvre
               // en feuille du bas sur téléphone (usage à une main).
               /*
                * La question posee suit le MEME arbitrage que le choix radio de
                * l'onglet ticket (`defaultCombinedMode`). Rapport deja signe :
                * on ne propose pas d'en refaire un, on propose de signer le bon.
                * Sans cela, cet ecran restait le seul a exiger un second
                * rapport pour signer un bon.
                */
               $bl_mode = PluginGestionCri::defaultCombinedMode($ticket_id);

               echo '<div class="gestion-choice">';
               echo '<div class="alert alert-important alert-info d-flex">';
               echo '<b>' . ($bl_mode === 'bl'
                  ? __("Un ticket est associé à ce BL, et son rapport d'intervention est déjà signé. Signez le BL ici, ou complétez d'abord l'intervention.", 'gestion')
                  : __("Un ticket est associé à ce BL. Signez le Rapport + BL ici, ou complétez d'abord l'intervention.", 'gestion')) . '</b>';
               echo '</div>';
               echo gestion_render_ticket_summary($ticket_id, $bl_row ? (int)$bl_row['signed'] : null, (string)($bl_row['bl'] ?? ''));
               echo '<div class="text-center mt-2 d-flex gap-2 justify-content-center flex-wrap gestion-choice-actions">';
               echo '<a href="' . htmlspecialchars($task_url, ENT_QUOTES) . '" class="btn btn-outline-primary">'
                  . __("Compléter l'intervention", 'gestion') . '</a>';
               $btn_params = $params;
               if ($bl_mode === 'bl') {
                  $btn_params['force_bl'] = 1;
                  $btn_label = __('Signer le BL', 'gestion');
               } else {
                  $btn_params['force_combined'] = 1;
                  $btn_label = __('Signer Rapport + BL', 'gestion');
               }
               $onclick = "gestion_signBlOnly(this, '" . $bl_id . "', " . json_encode($btn_params) . "); return false;";
               echo '<button type="button" class="btn btn-primary" onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '">'
                  . $btn_label . '</button>';
               echo '</div>';
               echo '</div>';
            }
            break;
         }

         // ---- Aucune tache : comportement configurable (NoTaskSignMode) ----
         //   1 = bloquer (exiger une tache) ; 0 = autoriser « Signer le BL seul »
         $no_task_mode = (int)PluginGestionConfig::getInstance()->NoTaskSignMode();

         if (!$on_ticket) {
            echo '<div class="gestion-choice">';
         }

         echo '<div class="alert alert-important alert-warning d-flex">';
         if ($on_ticket) {
            echo '<b>' . __("Le ticket associé ne contient aucune tâche : le rapport ne peut pas être généré. Ajoutez une tâche au ticket pour signer le rapport.", 'gestion') . '</b>';
         } else {
            echo '<b>' . __("Un ticket est associé à ce BL : merci de créer une tâche au ticket pour générer le rapport.", 'gestion') . '</b>';
         }
         echo '</div>';

         // Récapitulatif sous le message, comme dans le cas précédent :
         // on décide sans quitter le modal.
         if (!$on_ticket) {
            echo gestion_render_ticket_summary($ticket_id, $bl_row ? (int)$bl_row['signed'] : null, (string)($bl_row['bl'] ?? ''));
         }

         echo '<div class="text-center mt-2 d-flex gap-2 justify-content-center flex-wrap gestion-choice-actions">';

         // Hors ticket : le bouton ouvre directement le formulaire de tâche,
         // puisque c'est précisément la tâche qui manque.
         if (!$on_ticket) {
            echo '<a href="' . htmlspecialchars($task_url, ENT_QUOTES) . '" class="btn btn-primary">'
               . __("Compléter l'intervention", 'gestion') . '</a>';
         }

         // « Signer le BL seul » uniquement si NoTaskSignMode = 0 (message + BL seul).
         // gestion_signBlOnly REMPLACE le contenu du modal courant (pas un 2e modal de meme id).
         if ($no_task_mode === 0) {
            $btn_params = $params;
            $btn_params['force_bl'] = 1;
            $onclick = "gestion_signBlOnly(this, '" . $bl_id . "', " . json_encode($btn_params) . "); return false;";
            $btn_class = $on_ticket ? 'btn btn-primary' : 'btn btn-outline-primary';
            echo '<button type="button" class="' . $btn_class . '" onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '">'
               . __("Signer le BL seul quand même", 'gestion') . '</button>';
         }
         echo '</div>';
         if (!$on_ticket) {
            echo '</div>'; // .gestion-choice
         }
         break;
      }

      /*
       * Signature BL seule : force_bl, ou pas de ticket / rp inactif / deja signe.
       *
       * Depuis l'onglet d'un ticket, on propose la liste a cocher plutot que le
       * bon seul : c'est le seul endroit ou plusieurs bons peuvent attendre
       * ensemble, et les signer un par un obligeait a rouvrir le modal autant de
       * fois. Vrai AUSSI quand le plugin RP est absent — Gestion doit savoir le
       * faire seul.
       *
       * Ailleurs (survey.form.php, planning, scanner), le contexte designe UN
       * bon precis : la liste n'aurait aucun sens, on garde le formulaire unique.
       */
      /*
       * AUCUNE condition sur `root_modal` : le formulaire ne doit pas changer
       * de forme selon la porte empruntee.
       *
       * Ces ecrans mènent tous ici — onglet « Gestion BL », bandeau « Étape
       * suivante », page mobile RP, boutons flottants, planning, liste des BL,
       * signature a l'ajout de tache. Reserver la liste a cocher a certains
       * d'entre eux aurait recree exactement la divergence qu'on cherche a
       * supprimer : le meme bon signe d'une facon ici, d'une autre la.
       *
       * Seule condition : le bon appartient a un ticket. Sans ticket il n'y a
       * pas d'autres bons a lui associer, la liste n'aurait rien a montrer.
       */
      $bl_ticket_id = $bl_row ? (int)$bl_row['tickets_id'] : 0;

      if ($bl_ticket_id > 0 && !$is_signed) {
         $PluginGestionCri->showCombinedMultiForm($bl_ticket_id, ['bl_only' => true, 'check_only' => $check_only]);
         break;
      }

      // On passe le vrai ticket du BL comme "job" quand on le connait
      // (corrige le ticket/emails errones depuis survey.form.php).
      $PluginGestionCri->showForm($job, ['modal' => $bl_id, 'root_modal' => $params["root_modal"] ?? '']);
      break;

         case 'sendMail' :
      header("Content-Type: application/json; charset=UTF-8");
      $filePath = null;
      try {
         if (!Session::haveRight('plugin_gestion_survey', UPDATE)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => "Acces refuse."]);
            break;
         }

         // CSRF : on journalise mais on n'empêche pas l'envoi (sinon GLPI renvoie du HTML Accès refusé)
         $incomingToken = $_POST['_glpi_csrf_token'] ?? ($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');
         $sessionToken  = $_SESSION['glpicsrftoken'] ?? '';
         if (!$sessionToken || !$incomingToken || !hash_equals((string)$sessionToken, (string)$incomingToken)) {
            // Ne pas journaliser les tokens eux-memes (secrets).
            PluginGestionLogger::warning('resend-mail', 'CSRF mismatch (non bloquant), user #' . (int)Session::getLoginUserID());
         }

         $survey_id = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
         $to        = trim($_POST['to'] ?? '');
         $cc        = trim($_POST['cc'] ?? '');

         if ($survey_id <= 0) {
            throw new InvalidArgumentException("Document introuvable.");
         }
         if ($to === '' && $cc === '') {
            throw new InvalidArgumentException("Merci de saisir au moins un destinataire.");
         }

         $emails = $to;
         if ($cc !== '') {
            $emails = ($emails === '') ? $cc : $emails . ',' . $cc;
         }

         $survey = new PluginGestionSurvey();
         if (!$survey->getFromDB($survey_id)) {
            throw new RuntimeException("Enregistrement introuvable.");
         }

         $sharepoint = new PluginGestionSharepoint();
         $config     = PluginGestionConfig::getInstance();

         $filePath = plugin_gestion_build_signed_pdf_copy($survey, $sharepoint);
         $fileName = $survey->fields['bl'];
         if (substr($fileName, -4) !== '.pdf') {
            $fileName .= '.pdf';
         }

         $tracker = $survey->fields['tracker'] ?? null;
         $sharepoint->MailSend(
            $emails,
            $config->fields['gabarit'],
            $filePath,
            "Mail envoye a ".$emails,
            $survey_id,
            $tracker,
            $survey->fields['doc_url'] ?? null,
            $fileName
         );

         if ($filePath && file_exists($filePath)) {
            @unlink($filePath);
         }

         echo json_encode(['ok' => true, 'message' => "Mail envoye."]);
      } catch (Throwable $e) {
         if (!empty($filePath) && file_exists($filePath)) {
            @unlink($filePath);
         }
         PluginGestionLogger::error('resend-mail', 'Echec renvoi document signe (survey #' . (int)($_POST['survey_id'] ?? 0) . ') : ' . $e->getMessage());
         http_response_code(400);
         echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
      }
      break;
}

/**
 * Prepare a local copy of the signed PDF to send as attachment.
 */
function plugin_gestion_http_get_bytes(string $url, int $timeout = 15)
{
   $context = stream_context_create([
      'http' => [
         'timeout' => $timeout,
         'ignore_errors' => true,
      ],
      'https' => [
         'timeout' => $timeout,
         'ignore_errors' => true,
      ],
   ]);

   return @file_get_contents($url, false, $context);
}

function plugin_gestion_to_absolute_url(string $url): string
{
   global $CFG_GLPI;

   $url = trim($url);
   if ($url === '' || preg_match('#^https?://#i', $url)) {
      return $url;
   }

   $url_base = trim((string)($CFG_GLPI['url_base'] ?? ''));
   if ($url_base !== '') {
      $parts = parse_url($url_base);
      if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
         $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? (':' . (int)$parts['port']) : '');
         if (str_starts_with($url, '/')) {
            return $origin . $url;
         }
         $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
         return $origin . $root_doc . '/front/' . ltrim($url, '/');
      }
   }

   $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
   $host   = trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
   if ($host !== '') {
      if (str_starts_with($url, '/')) {
         return $scheme . '://' . $host . $url;
      }
      $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
      return $scheme . '://' . $host . $root_doc . '/front/' . ltrim($url, '/');
   }

   return $url;
}

function plugin_gestion_build_signed_pdf_copy(PluginGestionSurvey $survey, PluginGestionSharepoint $sharepoint): string {
   global $CFG_GLPI;

   $tmpDir = GLPI_PLUGIN_DOC_DIR . "/gestion/FilesTempSharePoint/resend";
   if (!is_dir($tmpDir)) {
      @mkdir($tmpDir, 0755, true);
   }

   $fileName = $survey->fields['bl'];
   if (substr($fileName, -4) !== '.pdf') {
      $fileName .= '.pdf';
   }
   $tempPath = rtrim($tmpDir, '/\\') . '/' . $fileName;

   $saveMode = $survey->fields['save'] ?? '';
   $sourceFound = false;

   if ($saveMode === 'SharePoint') {
      $downloadUrl = $sharepoint->getDownloadUrlByPath($survey->fields['doc_url']);
      if (!$downloadUrl) {
         throw new RuntimeException("Impossible de recuperer le fichier sur SharePoint.");
      }
      $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
      if ($content === false) {
         throw new RuntimeException("Telechargement du document signe impossible.");
      }
      file_put_contents($tempPath, $content);
      $sourceFound = true;
   } elseif ($saveMode === 'Local') {
      $doc = new Document();
      if (!empty($survey->fields['doc_id']) && $doc->getFromDB($survey->fields['doc_id'])) {
         $candidate = GLPI_DOC_DIR . "/" . ltrim($doc->fields['filepath'], '/\\');
         if (is_dir($candidate)) {
            $candidate = rtrim($candidate, '/\\') . '/' . $doc->fields['filename'];
         }
         if (file_exists($candidate)) {
            copy($candidate, $tempPath);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         $basePath = rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/gestion/' . ltrim((string)$survey->fields['url_bl'], '/\\');
         $candidate = is_dir($basePath) ? rtrim($basePath, '/\\') . '/' . $fileName : $basePath . $fileName;
         if (file_exists($candidate)) {
            copy($candidate, $tempPath);
            $sourceFound = true;
         }
      }

      if (!$sourceFound && !empty($survey->fields['doc_id'])) {
         $downloadUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front/document.send.php?docid=' . (int)$survey->fields['doc_id'];
         $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
         if ($content !== false) {
            file_put_contents($tempPath, $content);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         throw new RuntimeException("Fichier signe introuvable en local.");
      }
   } else {
      $downloadUrl = (string)($survey->fields['doc_url'] ?? $survey->fields['url_bl']);
      if (function_exists('plugin_gestion_normalize_view_pdf_url')) {
         $downloadUrl = plugin_gestion_normalize_view_pdf_url($downloadUrl);
      }
      $downloadUrl = plugin_gestion_to_absolute_url($downloadUrl);
      if ($downloadUrl) {
         $content = plugin_gestion_http_get_bytes((string)$downloadUrl);
         if ($content !== false) {
            file_put_contents($tempPath, $content);
            $sourceFound = true;
         }
      }

      if (!$sourceFound) {
         throw new RuntimeException("Source du document signe inconnue.");
      }
   }

   return $tempPath;
}
