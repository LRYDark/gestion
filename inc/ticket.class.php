<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

//------------------------------------------------------------------------------------------
class PluginGestionTicket extends CommonDBTM {

   public static $rightname = 'plugin_gestion_sign';
   public  static  $gestion = 0 ;

   public static function getTable($classname = null) {
      if ($classname === null || $classname === static::class) {
         return 'glpi_plugin_gestion_surveys';
      }

      return parent::getTable($classname);
   }

//*--------------------------------------------------------------------------------------------- GESTION ONGLET
   static function getIcon() {
      return "fa-solid fa-file-contract";
   }

   static function getTypeName($nb = 0) { // voir doc glpi 
      return _n('Gestion', 'Gestion', $nb, 'gestion');
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { // voir doc glpi 
      if(Session::haveRight("plugin_gestion_sign", READ)){
         $nb = self::countForItem($item);
         switch ($item->getType()) {
            case 'Ticket' :
                  return self::createTabEntry(self::getTypeName($nb), $nb);
            default :
               return self::getTypeName($nb);
         }
         return '';
      }
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) { // voir doc glpi 
      switch ($item->getType()) {
         case 'Ticket' :
            self::showForTicket($item);
            break;
      }
      return true;
   }

   public static function countForItem(CommonDBTM $item) { 
      if(Session::haveRight("plugin_gestion_sign", READ)){
         return countElementsInTable('glpi_plugin_gestion_surveys', ['tickets_id' => $item->getID()]);
      }
   }

   static function getAllForTicket($ID): array { // fonction qui va récupérer les informations sur le ticket 
      global $DB;

      $request = [
         'SELECT' => '*',
         'FROM'   => 'glpi_plugin_gestion_surveys',
         'WHERE'  => [
            'tickets_id' => $ID,
         ],
         'ORDER'  => ['id DESC'],
      ];

      $vouchers = [];
      foreach ($DB->request($request) as $data) {
         $vouchers[$data['id']] = $data;
      }

      return $vouchers;
   }

   static public function AddDocForm($params) { 
      global $CFG_GLPI, $DB;

      $item = $params['item'];

      if(Session::haveRight("plugin_gestion_add", READ) || Session::haveRight("plugin_gestion_add", UPDATE)){
         if ($item instanceof Ticket) {
            echo PluginGestionTicketConfig::showForTicket($item, true);
            return;
         }
      }
   }

   /**
    * Bandeau « Étape suivante » propre à Gestion : faire signer un bon.
    *
    * Repli du bandeau du plugin RP, et seul bandeau possible quand RP n'est pas
    * là. Il ne prétend rien savoir du parcours des rapports : il constate qu'un
    * bon rattaché à ce ticket n'est pas signé, et ouvre exactement le même
    * formulaire que le bouton du tableau — `gestion_loadCriForm` avec les mêmes
    * paramètres, donc les mêmes règles (choix Rapport/BL si RP est là et
    * autorisé, NoTaskSignMode, etc.). Aucune logique de signature n'est
    * réécrite ici.
    *
    * Reste au SINGULIER : ce parcours ne signe que le bon désigné. Le nombre de
    * bons restants est rappelé plutôt que promis.
    *
    * @param array $params paramètres d'ouverture du modal, identiques à ceux
    *                      des boutons du tableau
    * @return string vide si aucun bon n'attend de signature
    */
   static function getBlNextStepHtml(int $ticket_id, array $params): string {
      global $DB;

      if ($ticket_id <= 0
          || !Session::haveRight('plugin_gestion_sign', READ)
          || !$DB->tableExists('glpi_plugin_gestion_surveys')) {
         return '';
      }

      // Le plus ancien non signé : celui qui attend depuis le plus longtemps,
      // et le même que celui proposé par le formulaire groupé en tête de liste.
      $row = $DB->request([
         'SELECT' => ['id', 'bl'],
         'FROM'   => 'glpi_plugin_gestion_surveys',
         'WHERE'  => ['tickets_id' => $ticket_id, 'signed' => 0],
         'ORDER'  => ['id ASC'],
         'LIMIT'  => 1,
      ])->current();
      if (!$row) {
         return '';
      }

      $nb_unsigned = countElementsInTable(
         'glpi_plugin_gestion_surveys',
         ['tickets_id' => $ticket_id, 'signed' => 0]
      );

      $bl_id   = (int)$row['id'];
      $bl_name = (string)($row['bl'] ?? '');
      $hint    = $nb_unsigned > 1
         ? sprintf(__('%1$s — %2$d bons non signés au total', 'gestion'), $bl_name, $nb_unsigned)
         : $bl_name;

      // Ce bandeau ne désigne aucun bon en particulier : il propose de solder
      // ce qui attend, donc tous les bons sont précochés (comportement par
      // défaut, cf. `one_bl` dans ajax/cri.php).
      $onclick = "gestion_loadCriForm('showCriForm', '" . $bl_id . "', "
         . json_encode($params) . "); return false;";

      /*
       * `btn-info` + liseré : mêmes raisons que le bandeau du plugin RP, dont
       * celui-ci est le repli — les deux doivent se ressembler, sans quoi le
       * même emplacement changerait d'aspect selon qu'un plugin est actif.
       * Ici les boutons du tableau sont eux aussi en `primary`.
       *
       * Classe sémantique et non couleur en dur : `--tblr-info` suit le thème
       * GLPI, sombre compris.
       *
       * `border-left` de 4 px, comme les cartes de rapport du plugin RP : le
       * `card-status-start` de Tabler est un filet de 2 px posé en absolu, dont
       * la forme jurait à côté d'elles.
       */
      $html  = "<div class='card mb-3' style='border-left:4px solid var(--tblr-info);'>";
      $html .= "  <div class='card-body d-flex align-items-center justify-content-between flex-wrap gap-2'>";
      $html .= "    <div>";
      $html .= "      <div class='text-secondary small'>" . __('Étape suivante', 'gestion') . "</div>";
      $html .= "      <div class='fw-bold'>" . __('Signer le bon de livraison', 'gestion') . "</div>";
      if ($hint !== '') {
         $html .= "   <div class='text-secondary small'>" . htmlspecialchars($hint, ENT_QUOTES) . "</div>";
      }
      $html .= "    </div>";
      $html .= "    <button type='button' class='btn btn-info btn-lg' onclick='"
         . htmlspecialchars($onclick, ENT_QUOTES) . "'>";
      $html .= "      <i class='ti ti-signature me-2'></i>" . __('Continuer', 'gestion');
      $html .= "    </button>";
      $html .= "  </div>";
      $html .= "</div>";

      /*
       * Séparateur : ce qu'il reste à faire, puis les bons déjà là.
       *
       * Même trait que celui du plugin RP, aux mêmes dimensions : le même
       * emplacement ne doit pas changer d'aspect selon le plugin qui a rendu
       * le bandeau. Rendu ici et non par l'appelant pour qu'il disparaisse
       * avec lui — sans étape à proposer, il n'y a rien à séparer.
       */
      $html .= "<hr class='mx-auto' style='max-width:50%;border-top-width:4px;opacity:.4;margin-top:4rem;margin-bottom:4rem;'>";

      return $html;
   }

   /**
    * Le PDF part avec le bon.
    *
    * Appelée par GLPI à chaque purge — action massive de l'onglet ticket comme
    * de la liste des BL. Jusqu'ici la ligne disparaissait seule et le PDF
    * restait sur le disque, référencé par plus rien. Un seul endroit fait
    * désormais le ménage, quelle que soit la voie empruntée : c'est aussi ce
    * qui permet de n'offrir QU'UNE façon de supprimer, la barre « Actions »,
    * au lieu d'un bouton par ligne qui doublonnait avec elle.
    *
    * Ne touche pas aux fichiers hébergés hors de GLPI. Un bon stocké dans
    * SharePoint ou servi par Sage reste en place chez son hébergeur : le
    * supprimer à distance depuis un ticket serait irréversible et invisible
    * pour les autres utilisateurs de la bibliothèque. Le cas est signalé.
    */
   function cleanDBonPurge() {
      $doc_id = (int)($this->fields['doc_id'] ?? 0);
      $save   = (string)($this->fields['save'] ?? '');

      /*
       * Un PDF PARTAGÉ survit à la ligne qu'on supprime.
       *
       * Une signature groupée ne produit qu'un fichier : tous les bons du lot y
       * pointent, et le rapport d'intervention du plugin RP aussi quand il en
       * fait partie. Supprimer un bon effaçait alors le document des autres —
       * leur « Voir » ouvrait un fichier disparu, et le rapport avec.
       *
       * On ne purge donc que le dernier à s'en servir. Tant qu'une autre ligne
       * le désigne, seule celle-ci s'en va ; le fichier partira avec la
       * dernière.
       */
      if ($doc_id > 0 && !self::isDocumentShared($doc_id, (int)($this->fields['id'] ?? 0))) {
         $doc = new Document();
         if ($doc->getFromDB($doc_id)) {
            // 2e argument : purge. Un `delete` simple mettrait le Document à la
            // corbeille en laissant le PDF sur le disque, or c'est lui qu'on
            // veut voir partir (Document::cleanDBonPurge s'en charge).
            $doc->delete(['id' => $doc_id], 1);
         }
      }

      if ($save === 'SharePoint' || $save === 'Sage') {
         Session::addMessageAfterRedirect(
            htmlspecialchars(
               sprintf(
                  __('Le fichier reste stocké dans %s : seule la trace GLPI a été supprimée.', 'gestion'),
                  $save
               ),
               ENT_QUOTES
            ),
            true,
            WARNING
         );
      }

      if (class_exists('PluginGestionLogger')) {
         PluginGestionLogger::info(
            'suppression',
            sprintf(
               'Suppression définitive du BL %s (id %d) par %s',
               (string)($this->fields['bl'] ?? ''),
               (int)($this->fields['id'] ?? 0),
               $_SESSION['glpiname'] ?? ''
            )
         );
      }
   }

   /**
    * Ce PDF sert-il encore à quelqu'un d'autre ?
    *
    * Deux façons de partager un document : plusieurs bons signés du même lot,
    * et — quand les deux plugins travaillent ensemble — le rapport
    * d'intervention fusionné avec eux.
    *
    * L'absence du plugin RP ne change rien : sa table est simplement ignorée, et
    * Gestion reste seul maître de ses fichiers.
    *
    * @param int $documents_id identifiant `glpi_documents`
    * @param int $except_id    la ligne en cours de suppression, à ne pas compter
    * @return bool
    */
   static function isDocumentShared(int $documents_id, int $except_id): bool {
      global $DB;

      if ($documents_id <= 0) {
         return false;
      }

      $others = countElementsInTable('glpi_plugin_gestion_surveys', [
         'doc_id' => $documents_id,
         'NOT'    => ['id' => $except_id],
      ]);
      if ($others > 0) {
         return true;
      }

      if ($DB->tableExists('glpi_plugin_rp_cridetails')) {
         return countElementsInTable('glpi_plugin_rp_cridetails', ['id_documents' => $documents_id]) > 0;
      }

      return false;
   }

   static function showForTicket(Ticket $ticket) { // formulaire sur le ticket
      global $DB, $CFG_GLPI;

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '">';
      // Remote signature additions
      echo '<script>
         window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
      </script>';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '" defer></script>';

      /*
       * `isMobile()` a disparu avec le tableau : il ne servait qu'à masquer des
       * colonnes trop larges sur téléphone. La liste s'adapte d'elle-même, et
       * cette fonction — déclarée dans le corps d'une méthode, donc globale —
       * aurait provoqué un « Cannot redeclare » au deuxième appel.
       */

      $ID = $ticket->getField('id'); // recupération de l'id ticket
      $sum = 0;
      $count = 0;

      $params = ['job'           => $ticket->getField('id'),
               'root_doc'      => PLUGIN_GESTION_WEBDIR,
               'root_modal'    => 'ticket-form'];

      if (!$ticket->can($ID, READ)) {
         return false;
      }

      $canedit = false;
      if (Session::haveRight(Entity::$rightname, PURGE)) { // vérification des droits avanat d'affiché le canedit
         $canedit = true;
      } else if (
         $ticket->canEdit($ID)
         && !in_array($ticket->fields['status'], array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()))
      ) {
         $canedit = true;
      }

      /*
       * Pas de titre « Gestion BL » : l'onglet porte déjà ce nom, le répéter en
       * tête de son propre contenu ne renseignait personne et consommait une
       * hauteur d'écran que la liste utilise mieux.
       */
      $out = "";

      /*
       * Bandeau « Étape suivante », au-dessus du tableau.
       *
       * Le technicien qui ouvre cet onglet vient souvent y chercher la
       * signature du BL alors que le parcours complet — prise en charge,
       * atelier, puis BL + rapport ensemble — se décide ailleurs. Le bandeau
       * évite l'aller-retour entre les deux onglets.
       *
       * Deux sources, dans cet ordre :
       *   1. le plugin RP, qui connaît le parcours complet (même recommandation
       *      que son onglet, le bouton flottant et le scanner) ;
       *   2. à défaut, Gestion lui-même, qui sait au moins qu'un bon attend une
       *      signature.
       *
       * Le repli ne sert pas qu'au cas « RP désinstallé » : RP rend une chaîne
       * vide dès qu'aucune de SES étapes ne se dégage (rapport déjà fait, droits
       * manquants, aucune tâche). Le bon restait alors à signer sans que rien ne
       * le dise. `method_exists` couvre un RP plus ancien que cette méthode.
       */
      $next_step = '';
      if (Plugin::isPluginActive('rp')
          && class_exists('PluginRpCriDetail')
          && method_exists('PluginRpCriDetail', 'getNextStepHtml')) {
         $next_step = PluginRpCriDetail::getNextStepHtml((int)$ID);
      }
      if ($next_step === '') {
         $next_step = self::getBlNextStepHtml((int)$ID, $params);
      }
      $out .= $next_step;

      $number = self::countForItem($ticket);
      $rand   = mt_rand();

      if(Session::haveRight("plugin_gestion_sign", READ) || Session::haveRight("plugin_gestion_sign", PURGE)){
         if ($number) {
            $out .= "<div class='spaced'>";

            if(Session::haveRight("plugin_gestion_sign", PURGE)){
               if ($canedit) {
                  $out .= Html::getOpenMassiveActionsForm('mass'.__CLASS__.$rand);
                  if( Session::haveRight("plugin_gestion_sign", PURGE)){
                     $massiveactionparams =  [
                        'num_displayed'    => $number,
                        'container'        => 'mass'.__CLASS__.$rand,
                        'rand'             => $rand,
                        'display'          => false,
                        'specific_actions' => [
                           'purge'  => _x('button', 'Supprimer définitivement de GLPI')
                        ]
                     ];
                  }
                  $out .= Html::showMassiveActions($massiveactionparams);
               }
            }

            /*
             * Liste plutôt que tableau, alignée sur l'onglet RP.
             *
             * Les deux onglets décrivent la même chose — un document produit
             * pour un ticket, signé ou non — et le disaient de deux façons :
             * ici un tableau à cinq colonnes qui débordait sur téléphone, là
             * une liste. Une seule forme désormais : libellé fort, précisions
             * en gris dessous, statut et actions à droite.
             *
             * Les cases à cocher des actions massives restent en place : elles
             * doivent demeurer DANS le formulaire ouvert plus haut, sans quoi
             * la suppression en lot ne recevrait plus aucune sélection.
             */
            // `my-2` et non `mb-3` : la carte est encadrée par les DEUX barres
            // « Actions ». Avec une marge en bas seulement, celle du haut
            // collait à la carte et celle du bas s'en écartait. Marge réduite,
            // les barres n'ont pas à respirer autant qu'une carte isolée.
            $out .= "<div class='card my-2'>";
            $out .= "  <div class='list-group list-group-flush'>";

            $showuserlink = Session::haveRight('user', READ) ? 1 : 0;
            $can_purge    = Session::haveRight('plugin_gestion_sign', PURGE);

            foreach (self::getAllForTicket($ID) as $data) {

               $blId     = (int)$data['id'];
               $blName   = (string)($data['bl'] ?? '');
               $isSigned = ((int)$data['signed'] === 1);
               $sigDate  = !empty($data['doc_date']) ? $data['doc_date'] : ($data['date_creation'] ?? null);

               $out .= "<div class='list-group-item py-2'>";
               $out .= "  <div class='d-flex justify-content-between align-items-start flex-wrap gap-2'>";

               // ---- Identité du bon ----
               $out .= "    <div class='d-flex align-items-start gap-2 text-break'>";
               if ($can_purge && $canedit) {
                  $out .= "<div class='pt-1'>" . Html::getMassiveActionCheckBox(__CLASS__, $blId) . "</div>";
               }
               /*
                * Statut collé au nom, et non dans la colonne de droite.
                *
                * À droite il formait une troisième pile sous le bouton et la
                * date : trois éléments empilés pour une ligne qui n'en dit
                * qu'un. Contre le nom, il se lit d'un seul mouvement — « ce
                * bon-là est à faire signer » — et la colonne de droite retrouve
                * sa seule action.
                */
               /*
                * Les deux pastilles sont bâties sur le MÊME gabarit.
                *
                * « Signé » portait une icône, « À faire signer » non : leurs
                * hauteurs différaient d'une ligne à l'autre, et le texte n'était
                * pas aligné pareil. Une seule structure — icône + libellé, en
                * `inline-flex` centré — leur donne la même hauteur quel que soit
                * l'état.
                */
               $badge = "<span class='badge d-inline-flex align-items-center "
                  . ($isSigned ? 'bg-success text-white' : 'bg-warning text-dark') . "'>"
                  . "<i class='ti " . ($isSigned ? 'ti-check' : 'ti-clock') . " me-1'></i>"
                  . ($isSigned ? __('Signé', 'gestion') : __('À faire signer', 'gestion'))
                  . "</span>";

               $out .= "      <div>";
               $out .= "        <div class='d-flex align-items-center flex-wrap gap-2'>";
               $out .= "          <span class='fw-bold'>" . htmlspecialchars($blName, ENT_QUOTES) . "</span>";
               $out .= "          " . $badge;
               $out .= "        </div>";
               $out .= "        <div class='text-secondary small mt-1'>";
               $out .= __('ID', 'gestion') . ' ' . $blId;
               $tech = getUserName($data['users_id'], $showuserlink);
               if (trim(strip_tags((string)$tech)) !== '') {
                  $out .= ' &middot; ' . __('Technicien', 'gestion') . ' : ' . $tech;
               }
               $signataire = trim((string)($data['users_ext'] ?? ''));
               if ($signataire !== '') {
                  $out .= "<br>" . __('Signataire', 'gestion') . ' : <strong>'
                     . htmlspecialchars($signataire, ENT_QUOTES) . '</strong>';
               }
               $out .= "        </div>";
               $out .= "      </div>";
               $out .= "    </div>";

               /*
                * ---- Action et date ----
                *
                * Une seule action ici. La suppression passe par la barre
                * « Actions » de GLPI, en bas : un bouton « Supprimer » par
                * ligne EN PLUS de cette barre offrait deux fois la même chose.
                * Le nettoyage du PDF est fait par `cleanDBonPurge()`, donc
                * identique quelle que soit la voie empruntée.
                */
               $out .= "    <div class='text-end'>";
               /*
                * `one_bl` : ce bouton désigne CE bon-là. Lui seul est précoché
                * dans la liste de signature ; les autres restent visibles et
                * cochables. Sans ce marqueur, cliquer « Signer » sur une ligne
                * en aurait précoché trois.
                */
               $row_params            = $params;
               $row_params['one_bl']  = 1;
               $onclick = "gestion_loadCriForm('showCriForm', '" . $blId . "', " . json_encode($row_params) . "); return false;";
               // Taille normale et non `btn-sm` : ce bouton porte le numéro de
               // BL et se manipule au doigt sur un téléphone — il était trop
               // petit pour ce qu'on lui demande.
               $out .= "      <button type='button' class='btn "
                  . ($isSigned ? 'btn-outline-secondary' : 'btn-primary')
                  . "' onclick='" . htmlspecialchars($onclick, ENT_QUOTES) . "'>"
                  . "<i class='far fa-file-pdf me-2'></i>"
                  . ($isSigned ? __('Voir', 'gestion') : __('Signer', 'gestion'))
                  . "</button>";

               if (!empty($sigDate)) {
                  $label = $isSigned ? __('Signé le', 'gestion') : __('Créé le', 'gestion');
                  $out .= "      <div class='text-secondary small mt-1'>"
                     . $label . ' : ' . Html::convDateTime($sigDate) . "</div>";
               }
               $out .= "    </div>";

               $out .= "  </div>";
               $out .= "</div>";
            }

            $out .= "  </div>";
            $out .= "</div>";

            if(Session::haveRight("plugin_gestion_sign", PURGE)){
               if ($canedit) {
                  $massiveactionparams['ontop'] = false;
                  $out .= Html::showMassiveActions($massiveactionparams);
                  $out .= Html::closeForm(false);
               }
            }

         } else {
            $out .= "<p class='center b'>".__('Aucun BL / BC associé', 'gestion')."</p>";
         }
      }
      echo $out;
   }

   /**
    * Association automatique des BL d'un ticket (creation + ouverture), IDEMPOTENTE.
    * Extrait les numeros BL (BL + 6 chiffres) du titre + description + suivis + taches,
    * puis associe / insere dans glpi_plugin_gestion_surveys avec dedoublonnage par
    * bl_number => jamais de doublon. Remplace l'ancienne association du plugin warrantycheck.
    * Activable via le reglage AutoAssociateBl ; uniquement en mode Sage.
    */
   static function autoAssociateBl($ticket_id, $preload = null) {
      global $DB;

      $ticket_id = (int)$ticket_id;
      if ($ticket_id <= 0) {
         return;
      }

      $config = PluginGestionConfig::getInstance();
      if ((int)$config->AutoAssociateBl() !== 1 || (int)$config->mode() !== 1) {
         return; // desactive ou hors mode Sage
      }

      // --- Texte du ticket : titre + description + suivis + taches ---
      // A la creation (hook item_add) on recoit $item->fields (preload) : plus fiable
      // que re-interroger la BDD selon l'etat transactionnel du chemin de creation.
      if (is_array($preload) && (array_key_exists('content', $preload) || array_key_exists('name', $preload))) {
         $ticketRow = $preload;
      } else {
         $ticketRow = $DB->request([
            'SELECT' => ['name', 'content', 'entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $ticket_id],
            'LIMIT'  => 1,
         ])->current();
      }
      if (!$ticketRow) {
         return;
      }
      $text = ' ' . (string)($ticketRow['name'] ?? '') . ' ' . (string)($ticketRow['content'] ?? '');
      foreach ($DB->request([
         'SELECT' => ['content'],
         'FROM'   => 'glpi_itilfollowups',
         'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticket_id],
      ]) as $f) {
         $text .= ' ' . (string)($f['content'] ?? '');
      }
      foreach ($DB->request([
         'SELECT' => ['content'],
         'FROM'   => 'glpi_tickettasks',
         'WHERE'  => ['tickets_id' => $ticket_id],
      ]) as $tk) {
         $text .= ' ' . (string)($tk['content'] ?? '');
      }

      // --- Extraction des numeros BL (regex d'abord => si aucun, zero appel Sage) ---
      // On remplace les balises par des ESPACES (et NON strip_tags qui colle les
      // paragraphes : "<p>bl203846</p><p>texte</p>" -> "bl203846texte" cassait le match).
      $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = preg_replace('/<[^>]*>/u', ' ', $text);
      // Numero = BL|BC + 4 a 8 chiffres, NON suivi d'un chiffre. Pas de \b final
      // (sinon "bl203846texte" ne matchait pas) -> tolere du texte colle apres.
      $nbMatch = preg_match_all('/\bB[LC]\s?\d{4,8}(?!\d)/i', $text, $m);
      if (!$nbMatch || empty($m[0])) {
         return;
      }
      $numbers = [];
      foreach ($m[0] as $raw) {
         $num = function_exists('pluginGestionBlNumber')
            ? pluginGestionBlNumber($raw)
            : strtoupper(preg_replace('/\s+/', '', $raw));
         if ($num !== '') {
            $numbers[$num] = $num;
         }
      }
      if (empty($numbers)) {
         return;
      }

      $entities_id = (int)($ticketRow['entities_id'] ?? 0);
      require_once PLUGIN_GESTION_DIR . '/front/SageApi.php';

      foreach ($numbers as $serial) {
         try {
            // Dedoublonnage par bl_number : jamais 2 fois le meme BL.
            $existing = $DB->request([
               'SELECT' => ['id', 'tickets_id'],
               'FROM'   => 'glpi_plugin_gestion_surveys',
               'WHERE'  => ['bl_number' => $serial],
               'LIMIT'  => 1,
            ])->current();

            if ($existing) {
               // Existe : on associe seulement s'il n'a pas encore de ticket.
               if ((int)$existing['tickets_id'] === 0) {
                  $DB->update('glpi_plugin_gestion_surveys', ['tickets_id' => $ticket_id], ['id' => (int)$existing['id']]);
               }
               continue; // deja sur un ticket => on ne touche pas (pas de doublon)
            }

            // Inconnu : valider via Sage puis inserer (avec bl_number).
            $fields = parseDocument($serial);
            if (!is_array($fields)) {
               continue;
            }
            $client    = (string)($fields['client'] ?? '');
            $file_path = $serial . '_' . str_replace(' ', '_', $client);
            $tracker   = $fields['tracker'] ?? null;
            $doc_url   = function_exists('plugin_gestion_build_view_pdf_url')
               ? plugin_gestion_build_view_pdf_url($serial, true)
               : '';

            $DB->insert('glpi_plugin_gestion_surveys', [
               'tickets_id'    => $ticket_id,
               'entities_id'   => $entities_id,
               'url_bl'        => $serial,
               'bl'            => $file_path,
               'bl_number'     => $serial,
               'doc_url'       => $doc_url,
               'tracker'       => ($tracker !== null && $tracker !== '') ? $tracker : null,
               'save'          => 'Sage',
               'date_creation' => date('Y-m-d H:i:s'),
            ]);
         } catch (Throwable $e) {
            // BL absent de Sage (404) ou erreur reseau : ignore silencieusement.
            continue;
         }
      }
   }

   /**
    * Hook item_add (Ticket) : association des BL a la creation du ticket.
    */
   static function autoAssociateBlOnAdd($item) {
      if ($item instanceof Ticket) {
         $id = (int)$item->getID();
         if ($id > 0) {
            // On passe $item->fields (titre + description soumis) : plus fiable que la BDD.
            self::autoAssociateBl($id, is_array($item->fields ?? null) ? $item->fields : null);
         }
      }
   }

   static function install(Migration $migration) { // fonction intsllation de la table en BDD
      global $DB;

      $default_charset = DBConnection::getDefaultCharset();
      $default_collation = DBConnection::getDefaultCollation();
      $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

      $table = 'glpi_plugin_gestion_surveys';

      if (!$DB->tableExists($table)) {
         $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL auto_increment,
                     `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                     `users_id` int {$default_key_sign} NULL,
                     `users_ext` VARCHAR(255) NULL,
                     `tracker` VARCHAR(255) NULL,
                     `url_bl` VARCHAR(255) NULL,
                     `bl` VARCHAR(255) NULL,
                     `bl_number` VARCHAR(50) NULL,
                     `signed` int NOT NULL DEFAULT '0',
                     `date_creation` TIMESTAMP NULL,
                     `doc_id` int {$default_key_sign} NULL,
                     `doc_url` TEXT NULL,
                     `doc_date` TIMESTAMP NULL,
                     PRIMARY KEY (`id`),
                     KEY `tickets_id` (`tickets_id`),
                     KEY `entities_id` (`entities_id`),
                     UNIQUE KEY `bl_number` (`bl_number`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
         $DB->doQuery($query) or die($DB->error());
      }
   }

   static function uninstall(Migration $migration) {

      $table = 'glpi_plugin_gestion_surveys';
      $migration->dropTable($table);
   }
}

