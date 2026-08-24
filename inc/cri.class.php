<?php
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginGestionCri extends CommonDBTM {

   static $rightname = 'plugin_gestion_cri_create';

   /**
    * Limite post_max_size de PHP en octets (0 = illimitee).
    * Sert a bloquer COTE CLIENT les soumissions trop volumineuses : au-dela de
    * cette limite, PHP vide entierement $_POST (photos/PDF en base64) et le
    * kernel GLPI rejette alors la requete avec une erreur CSRF trompeuse.
    */
   static function getPostMaxBytes(): int {
      $v = trim((string)ini_get('post_max_size'));
      if ($v === '' || $v === '0' || $v === '-1') {
         return 0;
      }
      $n = (float)$v;
      switch (strtolower(substr($v, -1))) {
         case 'g':
            $n *= 1024;
            // pas de break : cascade volontaire g->m->k
         case 'm':
            $n *= 1024;
            // pas de break
         case 'k':
            $n *= 1024;
      }
      return (int)$n;
   }

   /**
    * Corps JS de la fonction de controle de taille (a inserer dans les scripts
    * de soumission des 3 formulaires). Estime la taille urlencoded du POST et
    * bloque avec un message clair si elle depasse post_max_size.
    */
   static function getPostSizeCheckJs(): string {
      $limit = self::getPostMaxBytes();
      return '
            var gestionPostMax = ' . $limit . ';
            function gestionPostTooBig(f) {
               if (!gestionPostMax || !f || !f.elements) return false;
               var total = 0;
               for (var gi = 0; gi < f.elements.length; gi++) {
                  var el = f.elements[gi];
                  if (el.name && !el.disabled && typeof el.value === "string") {
                     total += el.name.length + el.value.length + 2;
                  }
               }
               total = Math.round(total * 1.1);
               if (total > gestionPostMax) {
                  alert("Impossible de signer : les pièces jointes dépassent la limite du serveur ("
                     + (total / 1048576).toFixed(1) + " Mo envoyés pour "
                     + (gestionPostMax / 1048576).toFixed(1) + " Mo maximum)."
                     + "\n\nRetirez ou allégez des photos / PDF puis réessayez.");
                  return true;
               }
               return false;
            }';
   }

   /**
    * Bascule « Signature Rapport » / « Signature Rapport + BL ».
    *
    * Rendu unique de ce choix, déplacé ici depuis ajax/cri.php pour que le
    * plugin RP puisse le proposer lui aussi : le technicien qui passe par le
    * rapport d'atelier arrive sur le rapport d'intervention sans jamais croiser
    * le parcours Gestion, et n'avait donc aucun moyen de signer le bon de
    * livraison au passage. Une seconde copie du groupe aurait divergé au
    * premier ajustement.
    *
    * @param string $mode   'rp' (rapport seul) ou 'both' (rapport + BL)
    * @param int    $bl_id  bon de livraison concerné
    * @param array  $params paramètres du modal, réémis à chaque bascule.
    *                       Les drapeaux force_* sont retirés : c'est le choix
    *                       de l'utilisateur qui les repose, sinon un ancien
    *                       forçage écraserait le nouveau.
    */
   /**
    * Un rapport d'intervention SIGNE existe-t-il deja pour ce ticket ?
    *
    * Determine a lui seul si la signature du BL peut se passer du rapport. La
    * reponse vient du plugin RP, qui seul sait ce qu'il considere comme signe
    * (`getSignedTypes`, type 1 = rapport d'intervention) : Gestion ne redevine
    * rien, sans quoi les deux plugins finiraient par ne plus dire la meme chose.
    *
    * Faux si RP est absent : il n'y a alors pas de rapport du tout, et le choix
    * ne se pose pas — Gestion signe des bons, point.
    */
   static function hasSignedRpReport(int $ticket_id): bool {
      if ($ticket_id <= 0
          || !Plugin::isPluginActive('rp')
          || !class_exists('PluginRpCriDetail')
          || !method_exists('PluginRpCriDetail', 'getSignedTypes')) {
         return false;
      }

      if (in_array(1, PluginRpCriDetail::getSignedTypes($ticket_id), true)) {
         return true;
      }

      /*
       * Signature du rapport DESACTIVEE en configuration RP (`sign_rp_tech`).
       *
       * Aucun rapport ne peut alors etre « signe » : s'en tenir a ce seul
       * critere rendrait l'option « Signature BL » inatteignable, et forcerait
       * a regenerer un rapport a chaque bon. Sur ces installations, un rapport
       * d'intervention PRESENT vaut rapport fait — c'est la seule preuve que la
       * configuration permet d'avoir.
       */
      if (!class_exists('PluginRpConfig') || !class_exists('PluginRpTicketActions')) {
         return false;
      }
      $rp_config = PluginRpConfig::getInstance();
      if ((int)($rp_config->fields['sign_rp_tech'] ?? 1) === 1) {
         return false;
      }

      $state = PluginRpTicketActions::getReportState($ticket_id);
      return !empty($state['intervention']);
   }

   /**
    * Mode preselectionne du choix « Que signe le client ? ».
    *
    * Source unique, pour que le choix propose et le choix applique ne puissent
    * pas diverger. Rapport deja signe => `bl` : le technicien qui revient
    * signer les bons restants ne doit pas regenerer un second rapport sans
    * l'avoir demande.
    */
   static function defaultCombinedMode(int $ticket_id): string {
      if (!self::hasSignedRpReport($ticket_id)) {
         return 'both';
      }

      /*
       * Rapport present, mais le ticket a bouge depuis : tache ou suivi ajoute,
       * donc le rapport ne decrit plus l'intervention. On repropose « Rapport +
       * BL » — le technicien peut toujours revenir a « Signature BL » si ces
       * ajouts ne concernent pas le client.
       */
      $changes = self::rpChangesSinceReport($ticket_id);
      if (($changes['tasks'] + $changes['followups']) > 0) {
         return 'both';
      }

      return 'bl';
   }

   /**
    * Passe-plat vers le plugin RP, tolerant a son absence.
    *
    * Gestion ne compte rien lui-meme : c'est RP qui sait ce que son rapport
    * contient (taches publiques ou non, suivis) et donc ce qui le perime.
    */
   private static function rpChangesSinceReport(int $ticket_id): array {
      if (!class_exists('PluginRpCriDetail')
          || !method_exists('PluginRpCriDetail', 'countChangesSinceReport')) {
         return ['tasks' => 0, 'followups' => 0];
      }
      return PluginRpCriDetail::countChangesSinceReport($ticket_id, 1);
   }

   /**
    * De quand date le rapport d'intervention de ce ticket.
    *
    * Affiche sous le choix « Que signe le client ? » lorsque la signature du
    * bon SEUL est possible : elle ne l'est que parce qu'un rapport existe deja,
    * et le technicien doit savoir de quand il date avant de faire signer le
    * client.
    *
    * La date est une INFORMATION, jamais un reproche : seul un ajout de tache
    * ou de suivi depuis le rapport declenche l'avertissement. L'anciennete
    * seule ne prouve rien — un ticket sans mouvement depuis un mois n'a rien
    * de plus a raconter.
    *
    * @return string chaine vide si aucun rapport, ou si RP ne sait pas repondre
    */
   private static function renderRpReportAge(int $ticket_id): string {
      if ($ticket_id <= 0
          || !class_exists('PluginRpCriDetail')
          || !method_exists('PluginRpCriDetail', 'getLastReportDate')) {
         return '';
      }

      $date = PluginRpCriDetail::getLastReportDate($ticket_id, 1);
      if ($date === null) {
         return '';
      }

      $ts = strtotime($date);
      if ($ts === false) {
         return '';
      }

      $days = (int)floor((time() - $ts) / 86400);
      if ($days < 0) {
         $days = 0;
      }

      if ($days === 0) {
         $age = __("aujourd'hui", 'gestion');
      } elseif ($days === 1) {
         $age = __('hier', 'gestion');
      } else {
         $age = sprintf(__('il y a %d jours', 'gestion'), $days);
      }

      /*
       * SEUL ce qui a ete ajoute au ticket perime un rapport.
       *
       * Un seuil d'anciennete avait ete pose a 7 jours : il alertait sur des
       * rapports parfaitement valides. Un ticket sans la moindre tache ni le
       * moindre suivi depuis un mois n'a rien de plus a raconter — regenerer
       * son rapport produirait le meme document. La date reste affichee, pour
       * information, mais elle n'accuse plus rien par elle-meme.
       */
      $changes = self::rpChangesSinceReport($ticket_id);
      $moved   = ($changes['tasks'] + $changes['followups']) > 0;

      /*
       * Ton neutre dans les deux cas.
       *
       * La ligne entiere passait en orange des qu'une tache avait ete ajoutee :
       * pour un fait aussi banal — le ticket a avance depuis le rapport — cela
       * criait bien plus fort que necessaire, et le choix etant deja
       * repositionne sur « Rapport + BL », l'alerte n'apprenait rien de plus.
       * Le gris suffit ; c'est la mise en gras qui porte l'essentiel.
       */
      $h  = '<div class="mt-2 small text-secondary">';
      $h .= '<i class="ti ' . ($moved ? 'ti-refresh' : 'ti-file-check') . ' me-1"></i>';
      $h .= sprintf(
         __("Rapport d'intervention du %1\$s (%2\$s).", 'gestion'),
         htmlspecialchars(Html::convDateTime($date), ENT_QUOTES),
         $age
      );

      if ($moved) {
         $parts = [];
         if ($changes['tasks'] > 0) {
            $parts[] = sprintf(
               _n('%d tâche', '%d tâches', $changes['tasks'], 'gestion'),
               $changes['tasks']
            );
         }
         if ($changes['followups'] > 0) {
            $parts[] = sprintf(
               _n('%d suivi', '%d suivis', $changes['followups'], 'gestion'),
               $changes['followups']
            );
         }
         $h .= ' <strong>'
            . sprintf(
               __('Le ticket a évolué depuis : %s.', 'gestion'),
               implode(', ', $parts)
            )
            . '</strong> '
            . __('Le rapport doit être remis à jour.', 'gestion');
      }

      $h .= '</div>';

      return $h;
   }

   static function renderCombinedModeRadio(string $mode, int $bl_id, array $params): string {
      $base = $params;
      unset($base['force_rp'], $base['force_combined'], $base['force_bl']);
      $data = htmlspecialchars(json_encode($base), ENT_QUOTES);
      $rp   = ($mode === 'rp')   ? 'checked' : '';
      $both = ($mode === 'both') ? 'checked' : '';
      $bl   = ($mode === 'bl')   ? 'checked' : '';

      /*
       * L'option « Signature BL » n'apparait QUE si le rapport est deja signe.
       *
       * Ailleurs elle serait un piege : signer le bon sans le rapport laisse
       * l'intervention sans trace, et c'est precisement ce que le parcours
       * cherche a eviter. Une fois le rapport signe, l'inverse devient vrai —
       * proposer d'en refaire un serait le piege.
       */
      /*
       * L'option n'est offerte QUE si signer le bon seul est legitime.
       *
       * `defaultCombinedMode()` en est le seul juge : rapport present ET ticket
       * inchange depuis. Des qu'une tache ou un suivi s'est ajoute, le rapport
       * ne decrit plus l'intervention — l'option disparait donc entierement,
       * exactement comme s'il n'existait aucun rapport. La laisser visible,
       * meme decochee, aurait suffi a ce qu'on la reprenne par habitude.
       */
      /*
       * Le ticket est repris du BON, pas de `job`.
       *
       * `job` ne designe le ticket que depuis l'onglet ticket. Depuis la liste
       * des BL (hook.php) et depuis la fiche d'un bon (survey.class.php), il
       * porte l'identifiant du BON : le rapport et les modifications etaient
       * alors cherches sur un ticket qui n'existe pas, et « Signature BL » ne
       * pouvait jamais apparaitre sur ces ecrans.
       */
      $ticket_id = (int)($params['job'] ?? 0);
      if ($bl_id > 0) {
         global $DB;
         $bl_row = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_plugin_gestion_surveys',
            'WHERE'  => ['id' => $bl_id],
            'LIMIT'  => 1,
         ])->current();
         if ($bl_row && (int)$bl_row['tickets_id'] > 0) {
            $ticket_id = (int)$bl_row['tickets_id'];
         }
      }

      $allow_bl = (self::defaultCombinedMode($ticket_id) === 'bl');

      /*
       * Taille imposee sur le bouton lui-meme.
       *
       * Ce groupe est depose dans les cartes de plusieurs ecrans, dont les
       * feuilles etirent tout `input` sur la largeur du bloc pour habiller les
       * champs de saisie. Cette regle, plus specifique que celle de Tabler,
       * transformait le bouton natif en ellipse. Un style en ligne est le seul
       * moyen d'etre certain du rendu quel que soit l'ecran d'accueil.
       */
      $size = 'width:1.25rem;height:1.25rem;max-width:none;padding:0;flex-shrink:0;';

      /*
       * Habille en carte, comme le reste des formulaires de signature : la
       * question porte un intitule et se lit d'un coup d'oeil, au lieu de deux
       * boutons flottants dont on devine le role.
       */
      $h  = '<div class="form-card">';
      $h .= '<div class="form-label">' . __('Que signe le client ?', 'gestion') . '</div>';
      $h .= '<div class="form-content">';
      $h .= '<div class="text-center gestion-combined-mode" data-bl="' . $bl_id . '" data-params="' . $data . '">';
      $h .= '<div class="form-check form-check-inline">';
      $h .= '<input class="form-check-input" style="' . $size . '" type="radio" name="gestion_combined_mode" id="gcm_rp" value="rp" ' . $rp . ' onchange="gestion_switchCombinedMode(this);">';
      $h .= '<label class="form-check-label" for="gcm_rp">' . __('Signature Rapport', 'gestion') . '</label>';
      $h .= '</div>';
      $h .= '<div class="form-check form-check-inline">';
      $h .= '<input class="form-check-input" style="' . $size . '" type="radio" name="gestion_combined_mode" id="gcm_both" value="both" ' . $both . ' onchange="gestion_switchCombinedMode(this);">';
      $h .= '<label class="form-check-label" for="gcm_both">' . __('Signature Rapport + BL', 'gestion') . '</label>';
      $h .= '</div>';
      if ($allow_bl) {
         $h .= '<div class="form-check form-check-inline">';
         $h .= '<input class="form-check-input" style="' . $size . '" type="radio" name="gestion_combined_mode" id="gcm_bl" value="bl" ' . $bl . ' onchange="gestion_switchCombinedMode(this);">';
         $h .= '<label class="form-check-label" for="gcm_bl">' . __('Signature BL', 'gestion') . '</label>';
         $h .= '</div>';
      }
      $h .= '</div>';

      /*
       * Mention du rapport, rendue DES QU'IL EXISTE — et non plus seulement
       * quand « Signature BL » est offert.
       *
       * Elle était conditionnée à cette option, donc elle disparaissait avec
       * elle : le seul moment où l'utilisateur a besoin d'une explication —
       * l'option vient de s'effacer parce que le ticket a évolué — était
       * justement celui où plus rien ne l'expliquait.
       *
       * `renderRpReportAge()` rend une chaîne vide s'il n'y a pas de rapport.
       */
      $h .= self::renderRpReportAge($ticket_id);

      $h .= '</div>';
      $h .= '</div>';

      return $h;
   }

   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;
      $uniq = 'cri'.mt_rand(10000,99999);

      // Inclure les fichiers CSS et JS externes
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '">';
      // Remote signature additions
      echo '<script>
         window.GLPI_PLUG_GESTION = "' . PLUGIN_GESTION_WEBDIR . '";
      </script>';
      echo '<script src="' . PLUGIN_GESTION_WEBDIR . '/public/js/scripts_gestion.js?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '" defer></script>';

      // Style CSS inline pour hauteur responsive
         $responsiveIframeStyle = "
            width: 100%; 
            border: 1px solid #dee2e6; 
            border-radius: 6px;
            height: 350px; /* Mobile par défaut */
         ";

      // JavaScript pour ajuster selon la taille d'écran
         $responsiveScript = "
         <style>
         @media (min-width: 768px) {
            .pdf-responsive { height: 500px !important; }
         }
         @media (min-width: 1024px) {
            .pdf-responsive { height: 650px !important; }
         }
         @media (min-width: 1200px) {
            .pdf-responsive { height: 700px !important; }
         }
         </style>
         ";
      // Ajouter le style au début de votre fonction
         echo $responsiveScript;

      // Fonction pour générer le token temporaire (identique à view_pdf.php)
      function generateTempTokenForPreview($doc_id, $secret_key = null) {
          if ($secret_key === null || $secret_key === 'GLPI_PDF_SECRET_2024') {
              $secret_key = defined('GLPI_PDF_PREVIEW_SECRET')
                  ? GLPI_PDF_PREVIEW_SECRET
                  : (getenv('GLPI_PDF_PREVIEW_SECRET') ?: hash('sha256', realpath(__DIR__ . '/..') . 'gestion_pdf_preview'));
          }
          $today = date('Y-m-d');
          return hash('sha256', $doc_id . $today . $secret_key);
      }

      $config     = PluginGestionConfig::getInstance();
      $documents  = new Document();
      $job        = new Ticket();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $job->getfromDB($ID);
      $email = '';

      $id = isset($options['modal']) ? (int)$options['modal'] : (int)($_POST["modal"] ?? 0);
      if ($id <= 0) {
         echo "<div class='alert alert-danger'>BL introuvable.</div>";
         return;
      }
      $DOC = $DB->doQuery("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$id'")->fetch_object();
      $Doc_Name = $DOC->bl;
      $doc_id  = $DOC->doc_id;
      $DocUrlSharePoint = "";
   
      //$email = $DB->doQuery("SELECT GROUP_CONCAT(email SEPARATOR ',') AS emails FROM ( SELECT DISTINCT u.email AS email FROM glpi_useremails u JOIN glpi_users us ON us.id = u.users_id JOIN glpi_tickets t ON t.id = $ID WHERE us.entities_id = t.entities_id AND u.email IS NOT NULL AND u.email <> '' AND us.is_deleted = 0 UNION SELECT DISTINCT e.email FROM glpi_entities e JOIN glpi_tickets t ON t.entities_id = e.id WHERE t.id = $ID AND e.email IS NOT NULL AND e.email <> '' ) AS mails;")->fetch_object();   
      $ticket_id_mails = (int)$ID;
      $sql = " SELECT GROUP_CONCAT(DISTINCT mails.email SEPARATOR ',') AS emails
               FROM (
                  SELECT e.email
                  FROM glpi_tickets t
                  JOIN glpi_entities e ON e.id = t.entities_id
                  WHERE t.id = $ticket_id_mails
                     AND e.email IS NOT NULL
                     AND e.email <> ''

                  UNION ALL

                  SELECT ue.email
                  FROM glpi_tickets t
                  JOIN glpi_profiles_users pu ON pu.entities_id = t.entities_id
                  JOIN glpi_users u           ON u.id = pu.users_id
                  JOIN glpi_useremails ue     ON ue.users_id = u.id
                  WHERE t.id = $ticket_id_mails
                     AND u.is_deleted = 0
                     AND ue.email IS NOT NULL
                     AND ue.email <> ''
               ) AS mails;
               ";

      $res = $DB->doQuery($sql);
      $email = $res->fetch_object();  
      
      if(!empty($email->emails)){
         $email = $email->emails;
      }else{
         $email = '';
      }

      $params = ['job'         => $ID,
                  'form'       => 'formReport',
                  'root_doc'   => PLUGIN_GESTION_WEBDIR];
      
      echo "<form action=\"" . PLUGIN_GESTION_WEBDIR . "/front/traitement.php\" method=\"post\" name=\"formReport\">";
      echo Html::hidden('REPORT_ID', ['value' => $ID]);
      echo Html::hidden('DOC', ['value' => $Doc_Name]);
      echo Html::hidden('id_document', ['value' => $id]);
      
      $baseUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front';

      //Si le plugin est actif, lire la config et poser la clause #GLPI11#
      if (Plugin::isPluginActive('rp') && class_exists('PluginRpConfig')) {
         $configRp = PluginRpConfig::getInstance();

         // Sécuriser l'accès au champ
         $use_publictask = 0;
         if (isset($configRp->fields['use_publictask'])) {
            $use_publictask = (int)$configRp->fields['use_publictask'];
         }

         if ($use_publictask === 1) {
            $is_private = "AND is_private = 0";
         } else {
            $is_private = "";
         }
      }else {
         $is_private = "";
      }

      // Requête pour les tâches #GLPI11#
      $querytask = "SELECT glpi_tickettasks.id FROM glpi_tickettasks INNER JOIN glpi_users ON glpi_tickettasks.users_id = glpi_users.id WHERE tickets_id = $ID $is_private";
      $resulttask = $DB->doQuery($querytask);
      $numbertask = $DB->numrows($resulttask);
      // Si le document est déjà signé, ne pas afficher l'avertissement lié aux tâches
      if ($DOC->signed != 0) { $numbertask = 1; }
      if($numbertask == 0){
            echo "<div class='alert alert-important alert-warning d-flex'>";
            echo "<b>" . __("Attention : vous êtes sur le point de signer un bon de livraison sans avoir ajouté de tâche au ticket associé.") . "</b></div>";
      }
 
      if($DOC->signed == 0){ // ----------------------------------- NON SIGNÉ -----------------------------------         
         echo '<div class="form-container">';
         
         // === CARTE DOCUMENT ===
         echo '<div class="form-card">';
            echo '<div class="document-info">';
               echo '<div class="document-title">Document : ' . $Doc_Name . '</div>';
               echo '<div class="document-status status-unsigned">Non Signé</div>';
            echo '</div>';
         echo '</div>';
         
         /*
          * === CARTE PDF (affichee uniquement si la previsualisation est activee) ===
          *
          * `multi_bl` : ce formulaire sert alors d'hote a la signature groupee de
          * plusieurs bons, et la liste a cocher injectee au-dessus porte deja
          * l'apercu de CHACUN d'eux. Garder ici celui du premier bon afficherait
          * deux fois le meme document et laisserait croire qu'il est le seul
          * concerne.
          */
         if ($config->fields['SharePointLinkDisplay'] == 1 && empty($options['multi_bl'])) {
            echo '<div class="form-card">';
               try {
                  if ($DOC->save == 'SharePoint'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($DOC->doc_url);
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
                     $DocUrlSharePoint = $fileDownloadUrl;
                  }
                  if ($DOC->save == 'Sage'){
                     $DocUrlSharePoint = function_exists('plugin_gestion_normalize_view_pdf_url')
                        ? plugin_gestion_normalize_view_pdf_url((string)$DOC->doc_url)
                        : (string)$DOC->doc_url;
                     if (function_exists('plugin_gestion_ensure_pdf_token')) {
                        $DocUrlSharePoint = plugin_gestion_ensure_pdf_token($DocUrlSharePoint);
                     }
                     $fileDownloadUrl = $DocUrlSharePoint;
                  }

                  echo '<div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">';
                     echo '<span>Visualisation du document</span>';
                     echo '<a href="' . $DocUrlSharePoint . '" target="_blank" class="pdf-link" title="Voir le PDF en plein écran"><i class="ti ti-arrows-maximize"></i> Plein écran</a>';
                  echo '</div>';
                  echo '<div class="form-content">';
                  echo '<object data="' . htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8') . '#view=FitH" '
                     . 'type="application/pdf" class="pdf-viewer pdf-responsive" '
                     . 'style="' . htmlspecialchars($responsiveIframeStyle, ENT_QUOTES, 'UTF-8') . '">'
                     . 'Votre navigateur ne peut pas afficher le PDF.'
                     . '</object>';
                  echo '</div>';
               } catch (Exception $e) {
                  echo '<div class="form-label">Visualisation du document</div>';
                  echo '<div class="form-content">';
                  echo "<p>Erreur lors du chargement du PDF</p>";
                  echo '</div>';
               }
            echo '</div>';
         }

         // === CARTE PIECES JOINTES (images 6 max + PDF 1 max) ===
         echo '<div class="form-card">';
            echo '<div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">';
               echo '<span>Ajouter un fichier / image</span>';
               echo '<input type="file" id="capture-file-input" accept="image/png,image/jpeg,application/pdf" multiple style="display:none;">';
               echo '<button type="button" onclick="document.getElementById(\'capture-file-input\').click();" id="capture-file-btn" class="file-add-btn" title="Prendre des photos ou joindre un PDF"><i class="ti ti-camera"></i> Photo / PDF</button>';
            echo '</div>';
            echo '<div class="form-content">';
               echo '<div style="margin-top:8px;font-size:0.85em;color:#666;">';
                  echo '<span id="capture-photo-counter">0/6 image(s) ajoutée(s)</span>';
                  echo ' &mdash; ';
                  echo '<span id="capture-pdf-counter">0/1 PDF ajouté</span>';
               echo '</div>';
               echo '<div id="capture-file-list" style="margin-top:8px;"></div>';
               for ($pi = 1; $pi <= 6; $pi++) {
                  echo '<textarea name="photo_base64_' . $pi . '" id="photo-base64-' . $pi . '" style="display:none;"></textarea>';
               }
               echo '<textarea name="pdf_base64" id="pdf-base64" style="display:none;"></textarea>';
            echo '</div>';
         echo '</div>';

         echo '<script>
         (function(){
            var input = document.getElementById("capture-file-input");
            if (!input || input.dataset.gestionFileInit === "1") return;
            input.dataset.gestionFileInit = "1";

            var photoCount = 0;
            var maxPhotos = 6;
            var hasPdf = false;
            var photoNames = [];
            var pdfName = "";

            function updateCounters() {
               var pc = document.getElementById("capture-photo-counter");
               if (pc) pc.textContent = photoCount + "/" + maxPhotos + " image(s) ajoutée(s)";
               var pdfC = document.getElementById("capture-pdf-counter");
               if (pdfC) pdfC.textContent = (hasPdf ? "1" : "0") + "/1 PDF ajouté";
               var btn = document.getElementById("capture-file-btn");
               if (photoCount >= maxPhotos && hasPdf) {
                  if (btn) btn.style.display = "none";
               } else {
                  if (btn) btn.style.display = "";
               }
            }

            function rebuildList() {
               var list = document.getElementById("capture-file-list");
               if (!list) return;
               var ignoredItems = list.querySelectorAll(".capture-file-ignored");
               list.innerHTML = "";
               for (var i = 0; i < photoNames.length; i++) {
                  addListItem("img", i + 1, photoNames[i]);
               }
               if (hasPdf) {
                  addListItem("pdf", "pdf", pdfName);
               }
               for (var k = 0; k < ignoredItems.length; k++) {
                  list.appendChild(ignoredItems[k]);
               }
            }

            function removeItem(type, idx) {
               if (type === "pdf") {
                  var pdfTa = document.getElementById("pdf-base64");
                  if (pdfTa) pdfTa.value = "";
                  hasPdf = false;
                  pdfName = "";
               } else {
                  var allValues = [];
                  var allNames = [];
                  for (var i = 1; i <= maxPhotos; i++) {
                     var t = document.getElementById("photo-base64-" + i);
                     if (t && t.value !== "" && i !== idx) {
                        allValues.push(t.value);
                        allNames.push(photoNames[i - 1]);
                     }
                     if (t) t.value = "";
                  }
                  for (var j = 0; j < allValues.length; j++) {
                     var t2 = document.getElementById("photo-base64-" + (j + 1));
                     if (t2) t2.value = allValues[j];
                  }
                  photoCount = allValues.length;
                  photoNames = allNames;
               }
               rebuildList();
               updateCounters();
            }

            function addListItem(type, idx, fileName) {
               var list = document.getElementById("capture-file-list");
               if (!list) return;
               var li = document.createElement("div");
               li.id = "capture-file-item-" + type + "-" + idx;
               li.style.cssText = "display:flex;align-items:center;justify-content:space-between;padding:5px 10px;margin-bottom:3px;border-radius:4px;font-size:0.85em;" +
                  (type === "pdf" ? "background:#e8f4fd;" : "background:#f5f5f5;");
               var left = document.createElement("span");
               left.style.cssText = "display:flex;align-items:center;gap:6px;";
               left.innerHTML = (type === "pdf" ? "&#128196;" : "&#128247;") + " " + fileName;
               var btn = document.createElement("button");
               btn.type = "button";
               btn.textContent = "X";
               btn.style.cssText = "background:#e74c3c;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 8px;font-size:0.8em;flex-shrink:0;";
               (function(t, i) {
                  btn.addEventListener("click", function() { removeItem(t, i); });
               })(type, idx);
               li.appendChild(left);
               li.appendChild(btn);
               list.appendChild(li);
            }

            function addIgnoredItem(fileName, reason) {
               var list = document.getElementById("capture-file-list");
               if (!list) return;
               var li = document.createElement("div");
               li.className = "capture-file-ignored";
               li.style.cssText = "display:flex;align-items:center;justify-content:space-between;padding:5px 10px;margin-bottom:3px;border-radius:4px;font-size:0.85em;background:#fdeaea;opacity:0.7;";
               var left = document.createElement("span");
               left.style.cssText = "text-decoration:line-through;color:#999;";
               left.textContent = fileName;
               var tag = document.createElement("span");
               tag.style.cssText = "font-size:0.8em;color:#e74c3c;font-style:italic;flex-shrink:0;";
               tag.textContent = reason;
               li.appendChild(left);
               li.appendChild(tag);
               list.appendChild(li);
               setTimeout(function() { li.remove(); }, 15000);
            }

            // Compression navigateur : max 1600px, JPEG qualite 0.75 (identique au
            // traitement serveur). Reduit ~10x le poids du POST (limite post_max_size).
            // En cas de probleme, on garde le fichier original (comportement inchange).
            function gestionCompressImageDataUrl(dataUrl, cb) {
               try {
                  var img = new Image();
                  img.onload = function() {
                     try {
                        var maxDim = 1600;
                        var w = img.naturalWidth || img.width;
                        var h = img.naturalHeight || img.height;
                        if (!w || !h) { cb(dataUrl); return; }
                        var ratio = Math.min(1, maxDim / Math.max(w, h));
                        var canvas = document.createElement("canvas");
                        canvas.width = Math.round(w * ratio);
                        canvas.height = Math.round(h * ratio);
                        var ctx = canvas.getContext("2d");
                        ctx.fillStyle = "#ffffff";
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                        var out = canvas.toDataURL("image/jpeg", 0.75);
                        cb(out && out.length > 20 && out.length < dataUrl.length ? out : dataUrl);
                     } catch (err) { cb(dataUrl); }
                  };
                  img.onerror = function() { cb(dataUrl); };
                  img.src = dataUrl;
               } catch (err) { cb(dataUrl); }
            }

            function processFile(file, slot) {
               if (file.type === "application/pdf") {
                  var reader = new FileReader();
                  reader.onload = function(e) {
                     var ta = document.getElementById("pdf-base64");
                     if (ta) ta.value = e.target.result;
                     hasPdf = true;
                     pdfName = file.name;
                     rebuildList();
                     updateCounters();
                  };
                  reader.readAsDataURL(file);
                  return;
               }
               var reader2 = new FileReader();
               reader2.onload = function(e) {
                  gestionCompressImageDataUrl(e.target.result, function(finalDataUrl) {
                     var ta = document.getElementById("photo-base64-" + slot);
                     if (ta) ta.value = finalDataUrl;
                     photoNames[slot - 1] = file.name;
                     rebuildList();
                     updateCounters();
                  });
               };
               reader2.readAsDataURL(file);
            }

            // === Input fichier (galerie / PDF) - pas de ré-ouverture auto ===
            input.addEventListener("change", function(event) {
               var files = event.target.files;
               if (!files || files.length === 0) return;
               var ignored = [];
               for (var f = 0; f < files.length; f++) {
                  var file = files[f];
                  if (file.type === "application/pdf") {
                     if (hasPdf) { ignored.push({name: file.name, reason: "PDF déjà ajouté"}); continue; }
                     hasPdf = true;
                     processFile(file, 0);
                  } else if (file.type === "image/png" || file.type === "image/jpeg") {
                     if (photoCount >= maxPhotos) { ignored.push({name: file.name, reason: "Limite de " + maxPhotos + " images atteinte"}); continue; }
                     photoCount++;
                     processFile(file, photoCount);
                  } else {
                     ignored.push({name: file.name, reason: "Format non supporté"});
                  }
               }
               for (var g = 0; g < ignored.length; g++) {
                  addIgnoredItem(ignored[g].name, ignored[g].reason);
               }
               input.value = "";
            });

            updateCounters();
         })();
         </script>';

         // === CARTE SIGNATURE ===
         echo '<div class="form-card signature-card">';
            echo '<div class="form-label">SIGNATURE CLIENT</div>';
            
            // SOUS-CARTE 1 : Nom du client
            echo '<div class="signature-sub-card">';
               echo '<div class="signature-sub-title">Nom / Prénom du client</div>';
               echo '<input type="text" id="name" name="name" placeholder="Nom / Prénom du client" required>';
            echo '</div>';
            
            // SOUS-CARTE 2 : Canvas signature
            echo '<div class="signature-sub-card">';
               echo '<div class="signature-sub-title">Signature client</div>';
               echo "<div id='".$uniq."' class='cri-signature-root'>";
                  echo "  <div class='signature-container'>";
                  echo "    <button type='button' class='zoom-btn'>Agrandir <i class='fa-solid fa-up-right-and-down-left-from-center'></i></button>";
                  echo "    <canvas id='sig-canvas-".$uniq."' height='190' class='sig-base'></canvas>";
                  echo "  </div>";
                  echo "  <button type='button' id='sig-clearBtn-".$uniq."' class='resetButton'>Supprimer la signature</button>";

                  // Modal interne pour le zoom
                  echo "  <div class='signature-modal' aria-hidden='true'>";
                  echo "    <div class='modal-wrapper'>";
                  echo "      <div class='cri-modal-content'>";
                  echo "        <div class='rotate-gate'>";
                  echo "          <button type='button' class='rotate-close-btn' aria-label='Fermer'>&times;</button>";
                  echo "          <div>";
                  echo "            <div style='font-size:18px;font-weight:700;margin-bottom:8px'>";
                  echo "              Tournez votre téléphone en mode paysage";
                  echo "            </div>";
                  echo "            <div style='opacity:0.9'>La zone de signature va s'agrandir automatiquement.</div>";
                  echo "          </div>";
                  echo "        </div>";
                  echo "        <div class='cri-canvas-wrapper'>";
                  echo "          <canvas id='modal-canvas-".$uniq."' class='modal-canvas'></canvas>";
                  echo "        </div>";
                  echo "        <div class='cri-controls-panel'>";
                  echo "          <button type='button' class='btn-validate'>Valider</button>";
                  echo "          <button type='button' class='btn-clear'>Effacer</button>";
                  echo "          <button type='button' class='btn-cancel'>Annuler</button>";
                  echo "        </div>";
                  echo "      </div>";
                  echo "    </div>";
                  echo "  </div>";
               echo "</div>";
            echo '</div>';
         echo '</div>';

         function isCurrentUserAuthorized($authorized_users_string) {
            $current_user_id = $_SESSION['glpiID'];
            $authorized_users = json_decode($authorized_users_string, true);
            
            return is_array($authorized_users) && in_array($current_user_id, $authorized_users);
         }
            
         // MODIFICATION DANS cri.class.php - VERSION ACTUELLE (DOCUMENT UNIQUEMENT)
         // Remplacer la section signature déportée existante par celle-ci :

         if ($config->fields['RemoteSignatureOn'] == 1 && isCurrentUserAuthorized($config->fields['RemoteSignatureUsers'])) {                 
            // === CARTE SIGNATURE DÉPORTÉE (tablette) ===
            
            $can_remote = false;
            $devices = [];
            // Lire la configuration directement depuis la table du plugin (si les colonnes existent)
            try {
                  $cfgrow = [];
                  $rescfg = $DB->doQuery("SELECT * FROM glpi_plugin_gestion_configs LIMIT 1");
                  if ($rescfg && $DB->numrows($rescfg) > 0) {
                     $cfgrow = $DB->fetchassoc($rescfg);
                  }
                  $enabled = isset($cfgrow['enable_remote_signature']) ? (int)$cfgrow['enable_remote_signature'] : 1;
                  $allowed_users = [];
                  if (isset($cfgrow['remote_allowed_users']) && $cfgrow['remote_allowed_users'] !== '') {
                     $raw = $cfgrow['remote_allowed_users'];
                     if (is_string($raw) && strlen($raw) > 0) {
                        if ($raw[0] === '[') {
                           $decoded = json_decode($raw, true);
                           if (is_array($decoded)) {
                              foreach ($decoded as $u) { $allowed_users[] = (int)$u; }
                           }
                        } else {
                           foreach (preg_split('/[\s,;]+/', $raw) as $u) { if ($u !== '') $allowed_users[] = (int)$u; }
                        }
                     }
                  }
                  $uid = (int)Session::getLoginUserID();
                  $can_remote = (bool)$enabled && (empty($allowed_users) || in_array($uid, $allowed_users, true));
                  if ($can_remote && $DB->tableExists('glpi_plugin_gestion_devices')) {
                     // v1.7.0_alpha1 : nouveau schéma sans token
                     $resdev = $DB->doQuery(
                        "SELECT `id`, `serial`, `name`, `ip`, `last_seen`
                         FROM `glpi_plugin_gestion_devices`
                         WHERE `status` = 'active'
                         ORDER BY `last_seen` DESC"
                     );
                     if ($resdev) {
                        while ($r = $DB->fetchassoc($resdev)) { $devices[] = $r; }
                     }
                  }
            } catch (Throwable $e) {
                  $can_remote = false;
                  $devices = [];
            }
            
            if (!empty($devices)) {
               echo '<div class="form-card">';
               echo '  <div class="form-label">Signature déportée (tablette)</div>';
               echo '  <div class="form-content">';
               echo '    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
            
               // Chargement des appareils kiosque enregistrés (v1.7.0_alpha1 — sans token)
               // Identification : serial uniquement
               global $DB;
               $rows = [];
               if ($DB->tableExists('glpi_plugin_gestion_devices')) {
                  $devRes = $DB->doQuery(
                     "SELECT `serial`, `name`, `ip`, `last_seen`
                      FROM `glpi_plugin_gestion_devices`
                      WHERE `status` = 'active'
                      ORDER BY `last_seen` DESC"
                  );
                  if ($devRes) {
                     while ($r = $DB->fetchassoc($devRes)) { $rows[] = $r; }
                  }
               }

               echo '      <select id="remote-device" style="padding:6px">';
               foreach ($rows as $d) {
                  $serial = Html::entities_deep((string)($d['serial'] ?? ''));
                  $name   = Html::entities_deep((string)($d['name']   ?? ''));
                  $label  = $name !== '' ? $name . ' (' . $serial . ')' : $serial;
                  // value = serial (pas de token)
                  echo '        <option value="' . $serial . '">' . $label . '</option>';
               }
               echo '      </select>';

               $ticket_id_js = isset($ID) ? (int)$ID : 0;
               echo '<input type="hidden" id="remote-ticket-id" value="' . $ticket_id_js . '">';

               if (count($rows) === 0) {
                  echo '<div class="alert alert-important alert-danger glpi-debug-alert" style="z-index:10000">';
                  echo __('Aucune tablette active. Lancez l\'app APPAPPLETAB sur la tablette pour l\'enregistrer automatiquement.', 'gestion');
                  echo '</div>';
               }

               echo '      <button type="button" id="remote-start" class="btn btn-primary">Demander la signature</button>';
               echo '      <span id="remote-status" class="text-muted"></span>';
               echo '    </div>';
               echo '  </div>';
               echo '</div>';
               
               // VERSION CORRIGÉE : Préparation des paramètres automatiques (DOCUMENT UNIQUEMENT)
               $autoParams = [];

               // Document name (depuis la variable existante)
               if (!empty($Doc_Name)) {
                  $autoParams['document_name'] = $Doc_Name;
               }

               // Document URL avec token temporaire valide pour la tablette
               if (!empty($fileDownloadUrl)) {
                  // Extraire le doc_id depuis l'URL existante
                  // (le $DOC->url_bl contient l'identifiant Sage, ex: 'BL199550')
                  $doc_id_remote = '';
                  if (!empty($DOC->url_bl)) {
                     // Priorité : utiliser url_bl directement (identifiant Sage propre)
                     $doc_id_remote = trim((string)$DOC->url_bl);
                  } elseif (preg_match('/[?&]id=([^&#]+)/', $fileDownloadUrl, $matches)) {
                     $doc_id_remote = urldecode($matches[1]);
                  } elseif (preg_match('/[?&]docid=([^&#]+)/', $fileDownloadUrl, $matches)) {
                     $doc_id_remote = urldecode($matches[1]);
                  }

                  if (!empty($doc_id_remote)) {
                     // Utiliser generateTempTokenForPreview (définie ligne 48, même logique
                     // que _pdf_preview_secret() dans view_pdf.php) pour garantir la cohérence.
                     // NE PAS utiliser la clé littérale 'GLPI_PDF_SECRET_2024' :
                     // view_pdf.php la remplace par _pdf_preview_secret() → tokens incompatibles.
                     $temp_token_remote = generateTempTokenForPreview($doc_id_remote);

                     // Construire une URL absolue (https://...) pour que la tablette puisse y accéder
                     $abs_base = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');

                     if (strpos($fileDownloadUrl, 'view_pdf.php') !== false) {
                        // Reconstruire une URL propre absolue (sans doublon de token=)
                        $plain_webdir = '/' . trim((string)PLUGIN_GESTION_NOTFULL_WEBDIR, '/');
                        $autoParams['document_url'] = $abs_base . $plain_webdir
                           . '/view_pdf.php?id=' . rawurlencode($doc_id_remote)
                           . '&token=' . $temp_token_remote;
                     } else {
                        // URL non-view_pdf (ex: SharePoint) : ajouter le token en paramètre
                        $decoded_url = html_entity_decode($fileDownloadUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $sep = (strpos($decoded_url, '?') !== false) ? '&' : '?';
                        $autoParams['document_url'] = $decoded_url . $sep . 'token=' . $temp_token_remote;
                     }
                  } else {
                     // Fallback : URL sans token (SharePoint autonome, etc.)
                     $autoParams['document_url'] = html_entity_decode($fileDownloadUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                  }
               }
               
               // Entity : priorité à glpi_plugin_gestion_surveys.entities_id (entité directe du BL)
               // Fallback : entité du ticket associé (quand entities_id vaut 0)
               $entity_name = '';
               try {
                  $bl_entity_id = (int)($DOC->entities_id ?? 0);
                  if ($bl_entity_id > 0) {
                     $ent_res = $DB->doQuery("SELECT name FROM `glpi_entities` WHERE id = $bl_entity_id LIMIT 1");
                     if ($ent_res && $DB->numrows($ent_res) > 0) {
                        $entity_name = trim((string)($DB->fetchAssoc($ent_res)['name'] ?? ''));
                     }
                  }
                  // Fallback : entité du ticket si entities_id du BL n'est pas renseigné
                  if (empty($entity_name) && (int)$ID > 0) {
                     $ent_res2 = $DB->doQuery("SELECT e.name FROM `glpi_entities` e
                                               JOIN `glpi_tickets` t ON e.id = t.entities_id
                                               WHERE t.id = " . (int)$ID . " LIMIT 1");
                     if ($ent_res2 && $DB->numrows($ent_res2) > 0) {
                        $entity_name = trim((string)($DB->fetchAssoc($ent_res2)['name'] ?? ''));
                     }
                  }
               } catch (Exception $e) {
                  $entity_name = '';
               }
               if (!empty($entity_name)) {
                  $autoParams['entity_name'] = $entity_name;
               }

               // NOUVEAU : Ajouter l'email s'il est disponible
               if (!empty($email)) {
                  $autoParams['client_email'] = $email;
               }
               
               // Convertir en JSON pour JavaScript
               $autoParamsJson = !empty($autoParams) ? json_encode($autoParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null';
               
               echo '<script>
               // Variable globale contenant les paramètres automatiques
               window.REMOTE_SIGN_AUTO_PARAMS = ' . $autoParamsJson . ';
               
               // Script de récupération automatique des signatures déportées
               (function() {
               function initRemoteSignatureCapture() {
                  const stat = document.getElementById("remote-status");
                  if (!stat) {
                     setTimeout(initRemoteSignatureCapture, 2000);
                     return;
                  }
                  
                  // Observer les changements de statut
                  let lastStatus = stat.textContent;
                  const checkStatus = function() {
                     const currentStatus = stat.textContent;
                     if (currentStatus !== lastStatus) {
                     lastStatus = currentStatus;
                     
                     // Si on voit "Signature reçue", récupérer les données
                     if (currentStatus.includes("Signature reçue")) {
                        setTimeout(retrieveSignatureData, 100);
                     }
                     }
                  };
                  
                  setInterval(checkStatus, 500);
                  
                  async function retrieveSignatureData() {
                     try {
                     const sel = document.getElementById("remote-device");
                     if (!sel) return;

                     const opt           = sel.options[sel.selectedIndex];
                     const device_serial = opt.value;  // serial uniquement (v1.7.0_alpha1)
                     const ticket_id     = '.((int)$ID).';

                     const r = await RemoteSign.pollTicket(ticket_id, { device_serial });
                     
                     if (r.ok && r.ready && r.signature_base64) {
                        // Remplir le champ caché
                        const hiddenArea = document.getElementById("sig-dataUrl");
                        if (hiddenArea) {
                           const sigData = r.signature_base64.startsWith("data:") ? r.signature_base64 : "data:image/png;base64," + r.signature_base64;
                           hiddenArea.value = sigData;
                        }
                        
                        // Dessiner sur le canvas
                        const allCanvas = document.querySelectorAll("canvas");
                        if (allCanvas.length > 0) {
                           const canvas = allCanvas[0];
                           const ctx = canvas.getContext("2d");
                           
                           const img = new Image();
                           img.onload = function() {
                           ctx.clearRect(0, 0, canvas.width, canvas.height);
                           
                           const tempCanvas = document.createElement("canvas");
                           tempCanvas.width = img.width;
                           tempCanvas.height = img.height;
                           const tempCtx = tempCanvas.getContext("2d");
                           
                           tempCtx.drawImage(img, 0, 0);
                           
                           const imageData = tempCtx.getImageData(0, 0, tempCanvas.width, tempCanvas.height);
                           const data = imageData.data;
                           
                           for (let i = 0; i < data.length; i += 4) {
                              const alpha = data[i + 3];
                              if (alpha > 0) {
                                 data[i] = 0;
                                 data[i + 1] = 0;
                                 data[i + 2] = 0;
                              }
                           }
                           
                           tempCtx.putImageData(imageData, 0, 0);
                           ctx.drawImage(tempCanvas, 0, 0, canvas.width, canvas.height);
                           };
                           img.onerror = function() {
                           console.error("Erreur chargement signature image");
                           };
                           const sigData = r.signature_base64.startsWith("data:") ? r.signature_base64 : "data:image/png;base64," + r.signature_base64;
                           img.src = sigData;
                        }
                        
                        // Remplir le champ nom
                        const nameField = document.getElementById("name");
                        if (nameField && r.signer_name) {
                           nameField.value = r.signer_name;
                        }
                        
                        // Remplir le champ email
                        const emailField = document.getElementById("mail");
                        if (emailField && r.signer_email) {
                           emailField.value = r.signer_email;
                           const emailCheckbox = document.getElementById("send_email");
                           if (emailCheckbox && r.signer_email.trim() !== "") {
                              emailCheckbox.checked = true;
                           }
                        }
                     }
                     
                     } catch (e) {
                     console.error("Erreur récupération signature:", e);
                     }
                  }
               }

               // Initialiser
               if (document.readyState === "loading") {
                  document.addEventListener("DOMContentLoaded", initRemoteSignatureCapture);
               } else {
                  setTimeout(initRemoteSignatureCapture, 100);
               }
               })();
               </script>';
            }
         }

         ?>
         <style>
         .modal-dialog { 
            max-width: 1050px; 
            margin: 1.75rem auto; 
         }

         .email-combo-container {
            position: relative;
            width: 100%;
            max-width: 400px; /* on garde ta limite */
         }

         .email-input {
            width: 100%;
            padding-right: 32px; /* espace pour le bouton */
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
         }

         /* Bouton flèche collé à droite de l’input */
         .email-dropdown-btn {
            position: absolute;
            right: 8px;                /* toujours au bord droit du conteneur */
            top: 50%;                  /* centré verticalement */
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
         }

         .email-dropdown-btn.open {
            transform: translateY(-50%) rotate(180deg);
         }

         /* Menu exactement de la largeur du conteneur (input + bouton) */
         .email-dropdown {
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            width: 100%;
            max-width: 400px;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-sizing: border-box;
         }

         .email-option {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
         }

         .email-option:hover {
            background: #f5f5f5;
         }

         .email-option:last-child {
            border-bottom: none;
         }
         </style>

         <script>
         function showEmailDropdown() {
            var dropdown = document.getElementById("email_dropdown_list");
            if (!dropdown) return; // pas de dropdown si pas d'emails

            dropdown.style.display = "block";

            // Ajoute l'état "ouvert" sur la flèche
            var btn = document.querySelector(".email-dropdown-btn");
            if (btn) btn.classList.add("open");
         }

         function toggleEmailDropdown() {
            var dropdown = document.getElementById("email_dropdown_list");
            if (!dropdown) return;

            var btn = document.querySelector(".email-dropdown-btn");
            var isOpen = dropdown.style.display === "block";

            dropdown.style.display = isOpen ? "none" : "block";
            if (btn) btn.classList.toggle("open", !isOpen);
         }

         function selectEmail(email) {
            document.getElementById("mail").value = email;

            var dropdown = document.getElementById("email_dropdown_list");
            if (dropdown) dropdown.style.display = "none";

            // Ferme visuellement la flèche
            var btn = document.querySelector(".email-dropdown-btn");
            if (btn) btn.classList.remove("open");
         }

         // Fermer le dropdown si on clique ailleurs
         document.addEventListener("click", function(event) {
            var container = document.querySelector(".email-combo-container");
            var dropdown  = document.getElementById("email_dropdown_list");
            if (!dropdown) return;

            if (!container.contains(event.target)) {
               dropdown.style.display = "none";

               // Ferme visuellement la flèche
               var btn = document.querySelector(".email-dropdown-btn");
               if (btn) btn.classList.remove("open");
            }
         });
         
         // Mitigation: certaines extensions injectent un content_script qui écoute 'focusin' et peuvent
         // casser sur ce champ. On stoppe la propagation du focusin uniquement pour #mail.
         try {
            document.addEventListener('focusin', function(ev){
               var emailInput = document.getElementById('mail');
               if (emailInput && ev.target === emailInput) {
                  // Empêcher d'autres gestionnaires globaux de recevoir ce focusin
                  if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
               }
            }, true);
         } catch(e) {}
         </script>
         <?php

         //$docLinked = isset($DOC->relatedInvoiceToBL) ? 'Document lié : '.$DOC->relatedInvoiceToBL : '';
         echo '<div class="form-card">';
            echo '<div class="form-label">Commentaire</div>';
            echo '<textarea id="comment"
                           name="comment"
                           class="email-input"
                           placeholder="Commentaire éventuel lié au règlement comptoir ou autre..."
                           rows="3">'./*htmlspecialchars($docLinked).*/'</textarea>';
         echo '</div>';

         // === Facturation comptoir (si activée) ===
         if ($config->fields['CounterInvoice'] == 1 && isCurrentUserAuthorized($config->fields['CounterInvoiceUsers'])) { // NEW
            require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';

            echo '<div class="form-card">';
               echo '<div class="form-label">Règlement effectué au comptoir</div>';

               $fields = Montant($DOC->url_bl);
               // Supporte Montant() qui renvoie des chaînes avec virgule (ex: "11,89") ou un array num + cents
               $ttc = null;
               $ht  = null;
               if (isset($fields['TTC'])) {
                  $val = $fields['TTC'];
                  $ttc = is_array($val) ? implode(',', $val) : (string)$val;
               }
               if (isset($fields['HT'])) {
                  $val = $fields['HT'];
                  $ht = is_array($val) ? implode(',', $val) : (string)$val;
               }
               echo '<div class="form-content">';
               echo '<div class="amount-group">';
                  if ($ttc !== null) {
                     echo '<div><strong>Montant TTC :</strong> ' . htmlspecialchars($ttc) . ' €</div>';
                  }
                  if ($ht !== null) {
                     echo '<div><strong>Montant HT :</strong> ' . htmlspecialchars($ht) . ' €</div>';
                  }
               echo '</div>';
               echo '</div><br>';
               
               echo '<div class="form-content">';
                     echo '<div class="checkbox-group">';
                        echo '<input type="checkbox" name="CounterInvoiceClient" value="1" id="CounterInvoiceClient">';
                        echo '<label for="CounterInvoiceClient">Règlement effectué</label>';
                     echo '</div>';      
               echo '</div>';
            echo '</div>';
         }

         // === CARTE EMAIL (si activée) ===
         if ($config->fields['MailTo'] == 1) {
            // Traitement de la variable $email pour créer un tableau
            $emailArray = array();
            if (!empty($email)) {
               $emailArray = array_filter(array_map('trim', explode(',', $email)));
               // Supprimer les doublons et réindexer
               $emailArray = array_values(array_unique($emailArray));
            }
            
            // Premier email par défaut
            $defaultEmail = !empty($emailArray) ? $emailArray[0] : '';
            
            echo '<div class="form-card">';
               echo '<div class="form-label">Mail client</div>';
               echo '<div class="form-content">';
                     echo '<div class="checkbox-group">';
                        echo '<input type="checkbox" name="mailtoclient" value="1" id="send_email">';
                        echo '<label for="send_email">Envoyer le PDF par email</label>';
                     echo '</div>';
                     
                     echo '<div class="email-combo-container">';
                        // Input principal (celui qui sera envoyé)
                        echo '<input type="email" id="mail" name="email" class="email-input" value="' . htmlspecialchars($defaultEmail) . '" placeholder="Email du client" onclick="showEmailDropdown()" onfocus="showEmailDropdown()" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false">';                        
                        // Bouton dropdown si on a des emails
                        if (!empty($emailArray)) {
                           echo '<button type="button" class="email-dropdown-btn" onclick="toggleEmailDropdown()"><i class="fa-solid fa-chevron-down"></i></button>';
                           
                           // Dropdown personnalisé
                           echo '<div id="email_dropdown_list" class="email-dropdown">';
                                 foreach ($emailArray as $emailOption) {
                                    echo '<div class="email-option" onclick="selectEmail(\'' . htmlspecialchars($emailOption, ENT_QUOTES) . '\')">';
                                    echo htmlspecialchars($emailOption);
                                    echo '</div>';
                                 }
                           echo '</div>';
                        }
                        
                     echo '</div>';
                     
               echo '</div>';
            echo '</div>';
         }
        
         // === CARTE ACTIONS ===
         if(Session::haveRight("plugin_gestion_sign", CREATE)){
            echo '<div class="form-card actions-card" id="actions-bottom">';
               echo '<div class="form-content">';
                  echo '<input type="submit" name="add_cri" id="sig-submitBtn" value="Signer le PDF" class="submit-btn">';
               echo '</div>';
            echo '</div>';

            echo '<textarea readonly name="url" id="sig-dataUrl" class="form-control" rows="0" cols="150" style="display: none;"></textarea>';
         }

         // Loader overlay
         echo '<div class="gestion-loader-overlay" id="gestion-loader">';
            echo '<div class="gestion-loader-spinner"></div>';
            echo '<div class="gestion-loader-text">Signature en cours, veuillez patienter...</div>';
         echo '</div>';

         echo '<script>
         (function(){
            var form = document.getElementById("sig-submitBtn");
            if (!form) return;
            var submitted = false;' . self::getPostSizeCheckJs() . '
            form.closest("form").addEventListener("submit", function(e) {
               if (submitted) { e.preventDefault(); return; }
               if (gestionPostTooBig(form.closest("form"))) { e.preventDefault(); return; }
               submitted = true;
               form.disabled = true;
               form.value = "Signature en cours...";
               var loader = document.getElementById("gestion-loader");
               if (loader) loader.classList.add("active");
               // Retire le voile et recharge quand le formulaire vise un
               // nouvel onglet : sans navigation, rien ne le ferait.
               if (typeof gestionAfterSubmit === "function") {
                  gestionAfterSubmit(form.closest("form"));
               }
            });
         })();
         </script>';

         echo '</div>'; // Fin form-container

      } else { // ----------------------------------- SIGNÉ -----------------------------------         
         echo '<div class="form-container">';
         
         // === CARTE DOCUMENT SIGNÉ ===
         echo '<div class="form-card">';
            echo '<div class="document-info">';
               echo '<div class="document-title">Document : ' . $Doc_Name . '</div>';
               echo '<div class="document-status status-signed">Signé</div>';
            echo '</div>';
            
            echo '<div class="signed-details">';
               $signature_date = !empty($DOC->doc_date) ? $DOC->doc_date : $DOC->date_creation;
               echo '<p><strong>Signé le :</strong> ' . $signature_date . '</p>';
               echo '<p><strong>Par :</strong> ' . $DOC->users_ext . '</p>';
               // Quick-sign may store a free-text technician in tech_ext
               $tech_display = '';
               if (isset($DOC->tech_ext) && strlen(trim((string)$DOC->tech_ext)) > 0) {
                  $tech_display = $DOC->tech_ext;
               } else {
                  $tech_display = getUserName($DOC->users_id);
               }
               echo '<p><strong>Livré par :</strong> ' . $tech_display . '</p>';
               if (!empty($DOC->relatedInvoiceToBL)){
                  echo '<p><strong>Document lié :</strong> ' . $DOC->relatedInvoiceToBL . '</p>';
               }
            echo '</div>';
         echo '</div>';
         
         // === CARTE PDF SIGNÉ ===
         echo '<div class="form-card">';

            if ($config->fields['SharePointLinkDisplay'] == 1) {
               try {
                  if ($DOC->save == 'SharePoint'){
                     $DocUrlSharePoint = $DOC->doc_url;
                     $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($DOC->doc_url);
                  }
                  if ($DOC->save == 'Local'){
                     $fileDownloadUrl = $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
                     $DocUrlSharePoint = $fileDownloadUrl;
                  }
                  if ($DOC->save == 'Sage'){
                     $DocUrlSharePoint = function_exists('plugin_gestion_normalize_view_pdf_url')
                        ? plugin_gestion_normalize_view_pdf_url((string)$DOC->doc_url)
                        : (string)$DOC->doc_url;
                     if (function_exists('plugin_gestion_ensure_pdf_token')) {
                        $DocUrlSharePoint = plugin_gestion_ensure_pdf_token($DocUrlSharePoint);
                     }
                     $fileDownloadUrl = $DocUrlSharePoint;
                  }

                  echo '<div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">';
                     echo '<span>Document signé</span>';
                     echo '<a href="' . $DocUrlSharePoint . '" target="_blank" class="pdf-link" title="Voir le PDF en plein écran"><i class="ti ti-arrows-maximize"></i> Plein écran</a>';
                  echo '</div>';
                  echo '<div class="form-content">';
                  echo '<object data="' . htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8') . '#view=FitH" '
                     . 'type="application/pdf" class="pdf-viewer pdf-responsive" '
                     . 'style="' . htmlspecialchars($responsiveIframeStyle, ENT_QUOTES, 'UTF-8') . '">'
                     . 'Votre navigateur ne peut pas afficher le PDF.'
                     . '</object>';
                  echo '</div>';
               } catch (Exception $e) {
                  echo '<div class="form-label">Document signé</div>';
                  echo '<div class="form-content">';
                  echo "<p>Erreur lors du chargement du PDF</p>";
                  echo '</div>';
               }
            } else {
               echo '<div class="form-label">Document signé</div>';
               echo '<div class="form-content"></div>';
            }

         echo '</div>';

         echo '</div>'; // Fin form-container
         
      } // ----------------------------------- FIN SIGNÉ -----------------------------------

      // Bouton flottant "Aller en bas"
      echo '<button type="button" class="fab-go-bottom" title="Aller en bas" aria-label="Aller en bas">↓</button>';

      // Token CSRF standalone : Html::closeForm() emettrait le token PARTAGE de la
      // requete ($CURRENTCSRFTOKEN, reutilise par tous les formulaires rendus dans le
      // meme rendu AJAX). Des qu'un autre POST le consomme, le kernel GLPI 11
      // (CheckCsrfListener) rejette la soumission suivante ("CSRF check failed").
      // Un token standalone est unique a CE formulaire : personne d'autre ne peut le consommer.
      $csrf_standalone = Session::getNewCSRFToken(true);
      if ($csrf_standalone === '' && class_exists('PluginGestionLogger')) {
         // Cas anormal uniquement (jamais atteint en fonctionnement normal).
         PluginGestionLogger::error('csrf-diag', sprintf(
            'Rendu formulaire BL "%s" : token VIDE, user #%s',
            (string)$Doc_Name,
            (string)(Session::getLoginUserID() ?: 'anonyme')
         ));
      }
      echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_standalone]);
      echo '</form>';
      ?>
      <script>
         setTimeout(function() {   
            // 4. Bouton "Aller en bas de la page"
            const goBottomBtn = document.querySelector('.fab-go-bottom');
               if (goBottomBtn) {
               goBottomBtn.addEventListener('click', () => {
                  // Fermer une modale de signature si elle est ouverte
                  const openedModal = document.querySelector('.signature-modal[aria-hidden="false"], .signature-modal:not([aria-hidden])');
                  if (openedModal) {
                     try {
                        const ae = document.activeElement;
                        if (ae && openedModal.contains(ae)) {
                           // Renvoyer le focus sur le bouton d'action hors modale
                           if (goBottomBtn && typeof goBottomBtn.focus === 'function') {
                              goBottomBtn.focus();
                           } else if (document.body && typeof document.body.focus === 'function') {
                              document.body.focus();
                           }
                        }
                     } catch(e) {}
                     // Fermer proprement la modale interne
                     openedModal.classList.remove('active');
                     openedModal.setAttribute('aria-hidden', 'true');
                     openedModal.setAttribute('inert', '');
                  }
                  document.documentElement.classList.remove('no-scroll');
                  document.body.classList.remove('no-scroll');

                  // Cibler la carte Actions si présente, sinon bas de page
                  const target = document.getElementById('actions-bottom');
                  if (target && typeof target.scrollIntoView === 'function') {
                     target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                  } else {
                     window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
                  }
               });
            }
 
            // Vérifier que la fonction existe avant de l'appeler
            if (typeof initializeSignatureGestion === 'function') {
               initializeSignatureGestion('<?php echo $uniq; ?>');
            } else {
               // Si la fonction n'existe pas encore, attendre un peu
               setTimeout(function() {
                  if (typeof initializeSignatureGestion === 'function') {
                        initializeSignatureGestion('<?php echo $uniq; ?>');
                  }
               }, 500);
            }
         }, 100); // Délai de 100ms pour s'assurer que tout est chargé
      </script>
      <?php
   }

   /**
    * Formulaire combiné : Signature Rapport + BL
    */
   function showCombinedForm($ID, $bl_id, $options = []) {
      global $DB, $CFG_GLPI;

      // CSS gestion pour les cartes BL
      echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '">';

      $config     = PluginGestionConfig::getInstance();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $bl_id = (int)$bl_id;
      $DOC = $DB->doQuery("SELECT * FROM `glpi_plugin_gestion_surveys` WHERE id = '$bl_id'")->fetch_object();
      if (!$DOC) {
         echo "<div class='alert alert-danger'>BL introuvable.</div>";
         return;
      }

      $Doc_Name = $DOC->bl;
      $DocUrlSharePoint = "";
      $fileDownloadUrl = "";
      $baseUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front';

      // Préparation des URLs PDF si prévisualisation activée
      if ($config->fields['SharePointLinkDisplay'] == 1) {
         try {
            if ($DOC->save == 'SharePoint'){
               $DocUrlSharePoint = $DOC->doc_url;
               $fileDownloadUrl = $sharepoint->getDownloadUrlByPath($DOC->doc_url);
            }
            if ($DOC->save == 'Local'){
               $fileDownloadUrl = $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
               $DocUrlSharePoint = $fileDownloadUrl;
            }
            if ($DOC->save == 'Sage'){
               $DocUrlSharePoint = function_exists('plugin_gestion_normalize_view_pdf_url')
                  ? plugin_gestion_normalize_view_pdf_url((string)$DOC->doc_url)
                  : (string)$DOC->doc_url;
               if (function_exists('plugin_gestion_ensure_pdf_token')) {
                  $DocUrlSharePoint = plugin_gestion_ensure_pdf_token($DocUrlSharePoint);
               }
               $fileDownloadUrl = $DocUrlSharePoint;
            }
         } catch (Exception $e) {
            $fileDownloadUrl = "";
         }
      }

      // Helper autorisation utilisateur
      $isUserAuthorized = function($authorized_users_string) {
         $current_user_id = $_SESSION['glpiID'];
         $authorized_users = json_decode($authorized_users_string, true);
         return is_array($authorized_users) && in_array($current_user_id, $authorized_users);
      };

      // --------- Section BL (HTML) ---------
      ob_start();
      ?>
      <div class="form-card">
         <div class="document-info">
            <div class="document-title">Document : <?php echo htmlspecialchars($Doc_Name, ENT_QUOTES); ?></div>
            <div class="document-status status-unsigned">Non Signé</div>
         </div>
      </div>

      <?php if ($config->fields['SharePointLinkDisplay'] == 1) { ?>
      <div class="form-card">
         <div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
            <span>Visualisation du document</span>
            <?php if (!empty($DocUrlSharePoint)) { ?>
               <a href="<?php echo $DocUrlSharePoint; ?>" target="_blank" class="pdf-link" title="Voir le PDF en plein écran"><i class="ti ti-arrows-maximize"></i> Plein écran</a>
            <?php } ?>
         </div>
         <div class="form-content">
            <?php if (!empty($fileDownloadUrl)) { ?>
               <object data="<?php echo htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>#view=FitH"
                       type="application/pdf" class="pdf-viewer pdf-responsive">
                  Votre navigateur ne peut pas afficher le PDF.
               </object>
            <?php } ?>
         </div>
      </div>
      <?php } ?>

      <div class="form-card">
         <div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
            <span>Ajouter un fichier / image</span>
            <input type="file" id="capture-file-input" accept="image/png,image/jpeg,application/pdf" multiple style="display:none;">
            <button type="button" onclick="document.getElementById('capture-file-input').click();" id="capture-file-btn" class="file-add-btn" title="Prendre des photos ou joindre un PDF"><i class="ti ti-camera"></i> Photo / PDF</button>
         </div>
         <div class="form-content">
            <div style="margin-top:8px;font-size:0.85em;color:#666;">
               <span id="capture-photo-counter">0/6 image(s) ajoutée(s)</span>
               &mdash;
               <span id="capture-pdf-counter">0/1 PDF ajouté</span>
            </div>
            <div id="capture-file-list" style="margin-top:8px;"></div>
            <?php for ($pi = 1; $pi <= 6; $pi++) { ?>
               <textarea name="photo_base64_<?php echo $pi; ?>" id="photo-base64-<?php echo $pi; ?>" style="display:none;"></textarea>
            <?php } ?>
            <textarea name="pdf_base64" id="pdf-base64" style="display:none;"></textarea>
         </div>
      </div>
      <script>
      (function(){
         var input = document.getElementById("capture-file-input");
         if (!input || input.dataset.gestionFileInit === "1") return;
         input.dataset.gestionFileInit = "1";

         var photoCount = 0;
         var maxPhotos = 6;
         var hasPdf = false;
         var photoNames = []; // noms des fichiers images (index 0 = photo 1)
         var pdfName = "";

         function updateCounters() {
            var pc = document.getElementById("capture-photo-counter");
            if (pc) pc.textContent = photoCount + "/" + maxPhotos + " image(s) ajoutée(s)";
            var pdfC = document.getElementById("capture-pdf-counter");
            if (pdfC) pdfC.textContent = (hasPdf ? "1" : "0") + "/1 PDF ajouté";
            var btn = document.getElementById("capture-file-btn");
            if (photoCount >= maxPhotos && hasPdf) {
               if (btn) btn.style.display = "none";
            } else {
               if (btn) btn.style.display = "";
            }
         }

         function rebuildList() {
            var list = document.getElementById("capture-file-list");
            if (!list) return;
            var ignoredItems = list.querySelectorAll(".capture-file-ignored");
            list.innerHTML = "";
            for (var i = 0; i < photoNames.length; i++) {
               addListItem("img", i + 1, photoNames[i]);
            }
            if (hasPdf) {
               addListItem("pdf", "pdf", pdfName);
            }
            for (var k = 0; k < ignoredItems.length; k++) {
               list.appendChild(ignoredItems[k]);
            }
         }

         function removeItem(type, idx) {
            if (type === "pdf") {
               var pdfTa = document.getElementById("pdf-base64");
               if (pdfTa) pdfTa.value = "";
               hasPdf = false;
               pdfName = "";
            } else {
               // Réindexer les images
               var allValues = [];
               var allNames = [];
               for (var i = 1; i <= maxPhotos; i++) {
                  var t = document.getElementById("photo-base64-" + i);
                  if (t && t.value !== "" && i !== idx) {
                     allValues.push(t.value);
                     allNames.push(photoNames[i - 1]);
                  }
                  if (t) t.value = "";
               }
               for (var j = 0; j < allValues.length; j++) {
                  var t2 = document.getElementById("photo-base64-" + (j + 1));
                  if (t2) t2.value = allValues[j];
               }
               photoCount = allValues.length;
               photoNames = allNames;
            }
            rebuildList();
            updateCounters();
         }

         function addListItem(type, idx, fileName) {
            var list = document.getElementById("capture-file-list");
            if (!list) return;
            var li = document.createElement("div");
            li.id = "capture-file-item-" + type + "-" + idx;
            li.style.cssText = "display:flex;align-items:center;justify-content:space-between;padding:5px 10px;margin-bottom:3px;border-radius:4px;font-size:0.85em;" +
               (type === "pdf" ? "background:#e8f4fd;" : "background:#f5f5f5;");
            var left = document.createElement("span");
            left.style.cssText = "display:flex;align-items:center;gap:6px;";
            left.innerHTML = (type === "pdf" ? "&#128196;" : "&#128247;") + " " + fileName;
            var btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = "X";
            btn.style.cssText = "background:#e74c3c;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 8px;font-size:0.8em;flex-shrink:0;";
            (function(t, i) {
               btn.addEventListener("click", function() { removeItem(t, i); });
            })(type, idx);
            li.appendChild(left);
            li.appendChild(btn);
            list.appendChild(li);
         }

         function addIgnoredItem(fileName, reason) {
            var list = document.getElementById("capture-file-list");
            if (!list) return;
            var li = document.createElement("div");
            li.className = "capture-file-ignored";
            li.style.cssText = "display:flex;align-items:center;justify-content:space-between;padding:5px 10px;margin-bottom:3px;border-radius:4px;font-size:0.85em;background:#fdeaea;opacity:0.7;";
            var left = document.createElement("span");
            left.style.cssText = "text-decoration:line-through;color:#999;";
            left.textContent = fileName;
            var tag = document.createElement("span");
            tag.style.cssText = "font-size:0.8em;color:#e74c3c;font-style:italic;flex-shrink:0;";
            tag.textContent = reason;
            li.appendChild(left);
            li.appendChild(tag);
            list.appendChild(li);
            setTimeout(function() { li.remove(); }, 15000);
         }

         // Compression navigateur : max 1600px, JPEG qualite 0.75 (identique au
         // traitement serveur). Reduit ~10x le poids du POST (limite post_max_size).
         function gestionCompressImageDataUrl(dataUrl, cb) {
            try {
               var img = new Image();
               img.onload = function() {
                  try {
                     var maxDim = 1600;
                     var w = img.naturalWidth || img.width;
                     var h = img.naturalHeight || img.height;
                     if (!w || !h) { cb(dataUrl); return; }
                     var ratio = Math.min(1, maxDim / Math.max(w, h));
                     var canvas = document.createElement("canvas");
                     canvas.width = Math.round(w * ratio);
                     canvas.height = Math.round(h * ratio);
                     var ctx = canvas.getContext("2d");
                     ctx.fillStyle = "#ffffff";
                     ctx.fillRect(0, 0, canvas.width, canvas.height);
                     ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                     var out = canvas.toDataURL("image/jpeg", 0.75);
                     cb(out && out.length > 20 && out.length < dataUrl.length ? out : dataUrl);
                  } catch (err) { cb(dataUrl); }
               };
               img.onerror = function() { cb(dataUrl); };
               img.src = dataUrl;
            } catch (err) { cb(dataUrl); }
         }

         function processFile(file, slot) {
            if (file.type === "application/pdf") {
               var reader = new FileReader();
               reader.onload = function(e) {
                  var ta = document.getElementById("pdf-base64");
                  if (ta) ta.value = e.target.result;
                  hasPdf = true;
                  pdfName = file.name;
                  rebuildList();
                  updateCounters();
               };
               reader.readAsDataURL(file);
               return;
            }
            var reader2 = new FileReader();
            reader2.onload = function(e) {
               gestionCompressImageDataUrl(e.target.result, function(finalDataUrl) {
                  var ta = document.getElementById("photo-base64-" + slot);
                  if (ta) ta.value = finalDataUrl;
                  photoNames[slot - 1] = file.name;
                  rebuildList();
                  updateCounters();
               });
            };
            reader2.readAsDataURL(file);
         }

         input.addEventListener("change", function(event) {
            var files = event.target.files;
            if (!files || files.length === 0) return;
            var ignored = [];
            for (var f = 0; f < files.length; f++) {
               var file = files[f];
               if (file.type === "application/pdf") {
                  if (hasPdf) { ignored.push({name: file.name, reason: "PDF déjà ajouté"}); continue; }
                  hasPdf = true;
                  processFile(file, 0);
               } else if (file.type === "image/png" || file.type === "image/jpeg") {
                  if (photoCount >= maxPhotos) { ignored.push({name: file.name, reason: "Limite de " + maxPhotos + " images atteinte"}); continue; }
                  photoCount++;
                  processFile(file, photoCount);
               } else {
                  ignored.push({name: file.name, reason: "Format non supporté"});
               }
            }
            for (var g = 0; g < ignored.length; g++) {
               addIgnoredItem(ignored[g].name, ignored[g].reason);
            }
            input.value = "";
         });

         updateCounters();
      })();
      </script>

      <div class="form-card">
         <div class="form-label">Commentaire</div>
         <textarea id="comment"
                   name="comment"
                   class="email-input"
                   placeholder="Commentaire éventuel lié au règlement comptoir ou autre..."
                   rows="3"></textarea>
      </div>

      <?php
      // Facturation comptoir (si activée)
      if ($config->fields['CounterInvoice'] == 1 && $isUserAuthorized($config->fields['CounterInvoiceUsers'])) {
         require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';
         $fields = Montant($DOC->url_bl);
         $ttc = null; $ht = null;
         if (isset($fields['TTC'])) {
            $val = $fields['TTC'];
            $ttc = is_array($val) ? implode(',', $val) : (string)$val;
         }
         if (isset($fields['HT'])) {
            $val = $fields['HT'];
            $ht = is_array($val) ? implode(',', $val) : (string)$val;
         }
         ?>
         <div class="form-card">
            <div class="form-label">Règlement effectué au comptoir</div>
            <div class="form-content">
               <div class="amount-group">
                  <?php if ($ttc !== null) { ?>
                     <div><strong>Montant TTC :</strong> <?php echo htmlspecialchars($ttc); ?> €</div>
                  <?php } ?>
                  <?php if ($ht !== null) { ?>
                     <div><strong>Montant HT :</strong> <?php echo htmlspecialchars($ht); ?> €</div>
                  <?php } ?>
               </div>
            </div><br>
            <div class="form-content">
               <div class="checkbox-group">
                  <input type="checkbox" name="CounterInvoiceClient" value="1" id="CounterInvoiceClient">
                  <label for="CounterInvoiceClient">Règlement effectué</label>
               </div>
            </div>
         </div>
         <?php
      }
      $blSectionHtml = ob_get_clean();

      // --------- Formulaire RP ---------
      $_POST['modal'] = 'form_rapport';
      ob_start();
      $rp = new PluginRpCri();
      $rp->showForm($ID, ['modal' => 'form_rapport', 'embedded' => true]);
      $rpHtml = ob_get_clean();

      // Remplacer l'action du formulaire
      $rpHtml = str_replace(
         PLUGIN_RP_WEBDIR . '/front/cripdf.form.php',
         PLUGIN_GESTION_WEBDIR . '/front/traitement_combined.php',
         $rpHtml
      );

      // Injecter les champs BL + mode combiné dans le formulaire
      $hidden = Html::hidden('DOC', ['value' => $Doc_Name])
              . Html::hidden('id_document', ['value' => $bl_id])
              . Html::hidden('combined_mode', ['value' => 1]);

      /*
       * `target="_blank"` quand le reglage « Affichage du PDF apres signature »
       * le demande : le traitement combine renvoie alors le PDF fusionne, qui
       * s'ouvre a cote pendant que la page du ticket reste vivante — meme
       * comportement que la signature d'un rapport seul.
       *
       * Le formulaire de RP arrive sans `target` (mode integre) : on l'ajoute
       * ici, dans la reecriture qui insere deja les champs du BL.
       */
      $target = ((int)($config->fields['DisplayPdfEnd'] ?? 0) === 1) ? ' target="_blank"' : '';
      $rpHtml = preg_replace('/<form\b([^>]*)>/', '<form$1' . $target . '>' . $hidden, $rpHtml, 1);

      // Token CSRF standalone (meme raison que showForm : le token partage du rendu
      // AJAX peut etre consomme par un autre POST avant la soumission).
      $rpHtml = preg_replace(
         '/(<input type="hidden" name="_glpi_csrf_token" value=")[^"]*(")/',
         '${1}' . Session::getNewCSRFToken(true) . '${2}',
         $rpHtml
      );

      // Insérer la section BL après l'ouverture du container principal
      $rpHtml = preg_replace('/<div class="form-container">/', '<div class="form-container">' . $blSectionHtml, $rpHtml, 1);

      // Ajuster le libellé du bouton
      /*
       * Le bouton est vise par son IDENTIFIANT, plus par son texte.
       *
       * Le libelle du plugin RP varie desormais selon que le client signe ou
       * non ; le chercher mot pour mot laissait le bouton du formulaire combine
       * annoncer autre chose que ce qu'il fait. C'est aussi ce qui imposait une
       * seconde recherche sur la version mal encodee du meme libelle : viser
       * l'identifiant rend l'encodage sans objet.
       */
      $rpHtml = preg_replace(
         '/(<input[^>]*id="sig-submitBtn"[^>]*\bvalue=")[^"]*(")/',
         '${1}Signer BL + Rapport${2}',
         $rpHtml,
         1
      );

      echo $rpHtml;

      // Loader overlay + anti-double-clic
      echo '<div class="gestion-loader-overlay" id="gestion-loader">';
         echo '<div class="gestion-loader-spinner"></div>';
         echo '<div class="gestion-loader-text">Signature en cours, veuillez patienter...</div>';
      echo '</div>';
      echo '<script>
      (function(){
         var forms = document.querySelectorAll("form");' . self::getPostSizeCheckJs() . '
         forms.forEach(function(form) {
            var submitBtn = form.querySelector("input[type=submit]");
            if (!submitBtn) return;
            var submitted = false;
            form.addEventListener("submit", function(e) {
               if (submitted) { e.preventDefault(); return; }
               if (gestionPostTooBig(form)) { e.preventDefault(); return; }
               submitted = true;
               submitBtn.disabled = true;
               submitBtn.value = "Signature en cours...";
               var loader = document.getElementById("gestion-loader");
               if (loader) loader.classList.add("active");
               // Formulaire visant un nouvel onglet : la page ne navigue pas,
               // il faut retirer le voile et recharger nous-memes.
               if (typeof gestionAfterSubmit === "function") {
                  gestionAfterSubmit(form);
               }
            });
         });
      })();
      </script>';
   }

   /**
    * Signature GROUPEE de plusieurs BL d'un ticket => 1 PDF fusionne.
    *
    * Chaque bon non signe est propose avec une case a cocher (cochee par
    * defaut) et son apercu : on peut donc en signer un seul, deux, ou tous.
    *
    * Deux modes, selon `$options['bl_only']` :
    *   - false (defaut) : BL coches + UN rapport d'intervention ;
    *   - true           : BL coches SEULS, sans rapport.
    *
    * Le second sert quand le rapport est deja signe — en produire un second
    * n'aurait pas de sens — et quand le plugin RP est absent, cas ou Gestion
    * doit malgre tout savoir signer plusieurs bons d'un coup.
    *
    * Traitement commun : front/traitement_combined_multi.php.
    */
   function showCombinedMultiForm($ticket_id, $options = []) {
      global $DB, $CFG_GLPI;

      /*
       * Mode résolu UNE FOIS, en tête : trois endroits en dépendent (feuille de
       * style, choix de l'hôte, loader). Les laisser relire l'option brute
       * chacun de leur côté, c'était s'exposer à ce qu'ils divergent.
       *
       * Sans le plugin RP, le mode combiné n'a pas d'hôte : on retombe sur
       * « BL seul », qui ne dépend que de Gestion. Les appelants vérifient déjà
       * que RP est actif avant de demander le combiné — mais cette méthode est
       * publique, et la branche combinée instancie `PluginRpCri` et lit
       * `PLUGIN_RP_WEBDIR`. Une classe et une constante absentes sont une
       * erreur fatale, pas une dégradation : l'invariant est donc vérifié ici,
       * là où il est utilisé.
       */
      $bl_only = !empty($options['bl_only'])
                 || !Plugin::isPluginActive('rp')
                 || !class_exists('PluginRpCri')
                 || !defined('PLUGIN_RP_WEBDIR');

      /*
       * Feuille de style emise ici SEULEMENT en mode combine.
       *
       * En mode « BL seul », l'hote est `showForm()`, qui emet deja la sienne —
       * ainsi que `scripts_gestion.js`. Les emettre aussi ici chargerait le
       * script deux fois : jQuery reexecute un `<script src>` injecte, et les
       * blocs d'initialisation repartiraient de zero.
       */
      if (!$bl_only) {
         echo '<link rel="stylesheet" href="' . PLUGIN_GESTION_WEBDIR . '/public/css/signature_gestion.css?r=' . (defined('PLUGIN_GESTION_ASSETS_REV') ? PLUGIN_GESTION_ASSETS_REV : '1') . '">';
      }

      $config = PluginGestionConfig::getInstance();
      require_once PLUGIN_GESTION_DIR.'/front/SharePointGraph.php';
      $sharepoint = new PluginGestionSharepoint();

      $ticket_id = (int)$ticket_id;

      $bls = [];
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_gestion_surveys',
         'WHERE' => ['tickets_id' => $ticket_id, 'signed' => 0],
         'ORDER' => ['id ASC'],
      ]) as $row) {
         $bls[] = (object)$row;
      }
      if (empty($bls)) {
         echo "<div class='alert alert-warning'>Aucun BL a signer.</div>";
         return;
      }

      $previewUrl = function($DOC) use ($sharepoint, $CFG_GLPI) {
         $baseUrl = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/') . '/front';
         try {
            if ($DOC->save == 'SharePoint') return $sharepoint->getDownloadUrlByPath($DOC->doc_url);
            if ($DOC->save == 'Local')      return $baseUrl.'/document.send.php?docid='.$DOC->doc_id;
            if ($DOC->save == 'Sage') {
               $u = function_exists('plugin_gestion_normalize_view_pdf_url') ? plugin_gestion_normalize_view_pdf_url((string)$DOC->doc_url) : (string)$DOC->doc_url;
               if (function_exists('plugin_gestion_ensure_pdf_token')) $u = plugin_gestion_ensure_pdf_token($u);
               return $u;
            }
         } catch (Throwable $e) {}
         return '';
      };

      /*
       * ---- Section BL (liste + cases a cocher + apercu) ----
       *
       * Ce qui est PRECOCHE depend de la porte empruntee :
       *
       *  - un bouton de LIGNE (tableau des BL, planning, scanner, bouton
       *    flottant) designe UN bon precis : lui seul est coche. Les autres
       *    restent visibles et cochables — on peut toujours en ajouter.
       *  - un BANDEAU (« Étape suivante », « Continuer ») ne designe personne :
       *    il propose de solder ce qui attend, donc tout est coche.
       *
       * Tout precocher dans le premier cas faisait signer trois bons a qui
       * n'en avait demande qu'un.
       *
       * `check_only` inconnu ou deja signe : on recoche tout, plutot que de
       * presenter un formulaire ou rien n'est selectionne.
       */
      $check_only = (int)($options['check_only'] ?? 0);
      if ($check_only > 0) {
         $ids = array_map(static fn($b) => (int)$b->id, $bls);
         if (!in_array($check_only, $ids, true)) {
            $check_only = 0;
         }
      }

      ob_start();
      echo '<div class="form-card"><div class="form-label">Bons de livraison à signer (' . count($bls) . ')</div>';
      echo '<div class="form-content">';
      echo '<div style="font-size:.85em;color:#666;margin-bottom:6px;">'
         . ($check_only > 0
            ? 'Cochez un autre BL pour le signer en même temps.'
            : 'Décochez un BL pour ne pas le signer maintenant.')
         . '</div>';
      foreach ($bls as $b) {
         $url     = ($config->fields['SharePointLinkDisplay'] == 1) ? $previewUrl($b) : '';
         $checked = ($check_only === 0 || $check_only === (int)$b->id) ? ' checked' : '';
         echo '<div class="form-card" style="border:1px solid #dee2e6;">';
         echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;text-align:left;">';
         echo '<input type="checkbox" id="gestion_bl_chk_' . (int)$b->id . '" name="bl_ids[]" value="' . (int)$b->id . '"' . $checked . ' style="flex:0 0 auto;width:18px;height:18px;min-width:18px;max-width:18px;margin:0;padding:0;cursor:pointer;">';
         echo '<label for="gestion_bl_chk_' . (int)$b->id . '" style="flex:1 1 auto;min-width:0;margin:0;cursor:pointer;font-size:16px;font-weight:700;color:#343a40;overflow-wrap:anywhere;">' . htmlspecialchars((string)$b->bl, ENT_QUOTES) . '</label>';
         if (!empty($url)) {
            echo '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" class="pdf-link" style="flex:0 0 auto;white-space:nowrap;">Voir le PDF</a>';
         }
         echo '</div>';
         if (!empty($url)) {
            echo '<div class="form-content"><object data="' . htmlspecialchars($url, ENT_QUOTES) . '#view=FitH" type="application/pdf" class="pdf-viewer pdf-responsive" style="width:100%;height:450px;border:1px solid #dee2e6;border-radius:6px;">Votre navigateur ne peut pas afficher le PDF.</object></div>';
         }
         echo '</div>';
      }
      echo '</div></div>';

      /*
       * La liste s'arrete ici, le reste est mis de cote separement.
       *
       * En mode « BL seul », l'hote du formulaire est celui du plugin Gestion :
       * il porte DEJA sa capture photo/PDF et son champ commentaire. Y injecter
       * ceux-ci produirait des identifiants HTML en double — `capture-file-input`,
       * `photo-base64-1`… — et le navigateur ne rattacherait plus les fichiers
       * qu'au premier. Ils ne servent qu'a l'hote RP, qui n'en a pas.
       */
      $blListHtml = ob_get_clean();

      /*
       * Garde-fou « au moins un bon coché », attaché DANS la liste.
       *
       * En mode « BL seul », la liste est injectée en tête du formulaire de
       * l'hôte, donc ce script s'exécute AVANT le sien : son écouteur est
       * enregistré en premier et passe donc en premier. `stopImmediatePropagation`
       * est indispensable — sans lui, l'écouteur de l'hôte s'exécutait quand
       * même, désactivait le bouton et affichait le voile de chargement alors
       * que l'envoi venait d'être annulé : bouton mort, page figée.
       *
       * En mode combiné, la liste est injectée dans le formulaire du plugin RP,
       * qui n'a pas de garde-fou sur les cases : le même script y fait office
       * de contrôle unique.
       */
      $blListHtml .= '<script>
      (function(){
         /*
          * Le formulaire est retrouve par une case, jamais par
          * `document.currentScript` : ce contenu est injecte par jQuery, qui
          * reconstruit les balises `<script>` et laisse `currentScript` a null.
          */
         var box  = document.querySelector("input[name=\'bl_ids[]\']");
         var form = box ? box.closest("form") : null;
         if (!form || form.dataset.gestionBlGuard === "1") { return; }
         form.dataset.gestionBlGuard = "1";
         form.addEventListener("submit", function (e) {
            if (form.querySelectorAll("input[name=\'bl_ids[]\']:checked").length === 0) {
               e.preventDefault();
               e.stopImmediatePropagation();
               alert("Sélectionnez au moins un bon de livraison.");
            }
         });
      })();
      </script>';

      ob_start();

      // ---- Capture photos / PDF (partagee) ----
      echo '<div class="form-card">';
         echo '<div class="form-label" style="display:flex;align-items:center;justify-content:space-between;">';
            echo '<span>Ajouter un fichier / image</span>';
            echo '<input type="file" id="capture-file-input" accept="image/png,image/jpeg,application/pdf" multiple style="display:none;">';
            echo '<button type="button" onclick="document.getElementById(\'capture-file-input\').click();" id="capture-file-btn" class="file-add-btn" title="Prendre des photos ou joindre un PDF"><i class="ti ti-camera"></i> Photo / PDF</button>';
         echo '</div>';
         echo '<div class="form-content">';
            echo '<div style="margin-top:8px;font-size:0.85em;color:#666;">';
               echo '<span id="capture-photo-counter">0/6 image(s) ajoutée(s)</span> &mdash; <span id="capture-pdf-counter">0/1 PDF ajouté</span>';
            echo '</div>';
            echo '<div id="capture-file-list" style="margin-top:8px;"></div>';
            for ($pi = 1; $pi <= 6; $pi++) {
               echo '<textarea name="photo_base64_' . $pi . '" id="photo-base64-' . $pi . '" style="display:none;"></textarea>';
            }
            echo '<textarea name="pdf_base64" id="pdf-base64" style="display:none;"></textarea>';
         echo '</div>';
      echo '</div>';
      echo '<script>
      (function(){
         var input = document.getElementById("capture-file-input");
         if (!input || input.dataset.gestionFileInit === "1") return;
         input.dataset.gestionFileInit = "1";
         var photoCount = 0, maxPhotos = 6, hasPdf = false, photoNames = [], pdfName = "";
         function updateCounters(){
            var pc=document.getElementById("capture-photo-counter"); if(pc)pc.textContent=photoCount+"/"+maxPhotos+" image(s) ajoutée(s)";
            var pdfC=document.getElementById("capture-pdf-counter"); if(pdfC)pdfC.textContent=(hasPdf?"1":"0")+"/1 PDF ajouté";
            var btn=document.getElementById("capture-file-btn"); if(btn)btn.style.display=(photoCount>=maxPhotos&&hasPdf)?"none":"";
         }
         function rebuildList(){
            var list=document.getElementById("capture-file-list"); if(!list)return; list.innerHTML="";
            for(var i=0;i<photoNames.length;i++){ addItem("img",i+1,photoNames[i]); }
            if(hasPdf){ addItem("pdf","pdf",pdfName); }
         }
         function removeItem(type,idx){
            if(type==="pdf"){ var t=document.getElementById("pdf-base64"); if(t)t.value=""; hasPdf=false; pdfName=""; }
            else{
               var vals=[],names=[];
               for(var i=1;i<=maxPhotos;i++){ var t=document.getElementById("photo-base64-"+i); if(t&&t.value!==""&&i!==idx){vals.push(t.value);names.push(photoNames[i-1]);} if(t)t.value=""; }
               for(var j=0;j<vals.length;j++){ var t2=document.getElementById("photo-base64-"+(j+1)); if(t2)t2.value=vals[j]; }
               photoCount=vals.length; photoNames=names;
            }
            rebuildList(); updateCounters();
         }
         function addItem(type,idx,fileName){
            var list=document.getElementById("capture-file-list"); if(!list)return;
            var li=document.createElement("div");
            li.style.cssText="display:flex;align-items:center;justify-content:space-between;padding:5px 10px;margin-bottom:3px;border-radius:4px;font-size:0.85em;"+(type==="pdf"?"background:#e8f4fd;":"background:#f5f5f5;");
            var left=document.createElement("span"); left.style.cssText="display:flex;align-items:center;gap:6px;"; left.innerHTML=(type==="pdf"?"&#128196;":"&#128247;")+" "+fileName;
            var btn=document.createElement("button"); btn.type="button"; btn.textContent="X"; btn.style.cssText="background:#e74c3c;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 8px;font-size:0.8em;flex-shrink:0;";
            (function(t,i){ btn.addEventListener("click",function(){removeItem(t,i);}); })(type,idx);
            li.appendChild(left); li.appendChild(btn); list.appendChild(li);
         }
         // Compression navigateur : max 1600px, JPEG 0.75 (comme le serveur) — limite post_max_size.
         function gestionCompressImageDataUrl(dataUrl,cb){ try{ var img=new Image(); img.onload=function(){ try{ var m=1600,w=img.naturalWidth||img.width,h=img.naturalHeight||img.height; if(!w||!h){cb(dataUrl);return;} var r=Math.min(1,m/Math.max(w,h)); var c=document.createElement("canvas"); c.width=Math.round(w*r); c.height=Math.round(h*r); var x=c.getContext("2d"); x.fillStyle="#ffffff"; x.fillRect(0,0,c.width,c.height); x.drawImage(img,0,0,c.width,c.height); var o=c.toDataURL("image/jpeg",0.75); cb(o&&o.length>20&&o.length<dataUrl.length?o:dataUrl); }catch(e){cb(dataUrl);} }; img.onerror=function(){cb(dataUrl);}; img.src=dataUrl; }catch(e){cb(dataUrl);} }
         function processFile(file,slot){
            if(file.type==="application/pdf"){ var r=new FileReader(); r.onload=function(e){ var ta=document.getElementById("pdf-base64"); if(ta)ta.value=e.target.result; hasPdf=true; pdfName=file.name; rebuildList(); updateCounters(); }; r.readAsDataURL(file); return; }
            var r2=new FileReader(); r2.onload=function(e){ gestionCompressImageDataUrl(e.target.result,function(fin){ var ta=document.getElementById("photo-base64-"+slot); if(ta)ta.value=fin; photoNames[slot-1]=file.name; rebuildList(); updateCounters(); }); }; r2.readAsDataURL(file);
         }
         input.addEventListener("change",function(event){
            var files=event.target.files; if(!files||files.length===0)return;
            for(var f=0;f<files.length;f++){ var file=files[f];
               if(file.type==="application/pdf"){ if(hasPdf)continue; hasPdf=true; processFile(file,0); }
               else if(file.type==="image/png"||file.type==="image/jpeg"){ if(photoCount>=maxPhotos)continue; photoCount++; processFile(file,photoCount); }
            }
            input.value="";
         });
         updateCounters();
      })();
      </script>';

      // ---- Commentaire (une fois) ----
      echo '<div class="form-card"><div class="form-label">Commentaire</div><textarea id="comment" name="comment" class="email-input" placeholder="Commentaire éventuel..." rows="3"></textarea></div>';

      $blExtrasHtml = ob_get_clean();

      /*
       * ---- Hote du formulaire ----
       *
       * Le formulaire groupe n'ecrit ni signature ni champ client : il se greffe
       * sur un formulaire existant, dont il reecrit l'action et le libelle du
       * bouton. Deux hotes possibles :
       *
       *   - mode combine   : le formulaire RAPPORT du plugin RP, qui apporte en
       *                      plus les champs du rapport a generer ;
       *   - mode « BL seul » : le formulaire de signature de Gestion lui-meme,
       *                      qui porte deja signature, nom du client, capture
       *                      photo/PDF et commentaire. C'est aussi le seul
       *                      disponible quand le plugin RP est absent.
       *
       * Dans les deux cas la liste a cocher est injectee en tete du conteneur,
       * et le jeton CSRF est renouvele : celui du rendu AJAX peut avoir ete
       * consomme par un autre POST avant la soumission.
       */
      $target = ((int)($config->fields['DisplayPdfEnd'] ?? 0) === 1) ? ' target="_blank"' : '';

      if ($bl_only) {

         ob_start();
         $this->showForm($ticket_id, ['modal' => (int)$bls[0]->id, 'multi_bl' => true]);
         $html = ob_get_clean();

         $html = str_replace(
            PLUGIN_GESTION_WEBDIR . '/front/traitement.php',
            PLUGIN_GESTION_WEBDIR . '/front/traitement_combined_multi.php',
            $html
         );

         $hidden = Html::hidden('bl_only', ['value' => 1]);
         $inject = $blListHtml;   // sans les extras : l'hote les porte deja
         $label  = 'Signer les BL sélectionnés';
      } else {
         $_POST['modal'] = 'form_rapport';
         ob_start();
         $rp = new PluginRpCri();
         $rp->showForm($ticket_id, ['modal' => 'form_rapport', 'embedded' => true]);
         $html = ob_get_clean();

         $html = str_replace(
            PLUGIN_RP_WEBDIR . '/front/cripdf.form.php',
            PLUGIN_GESTION_WEBDIR . '/front/traitement_combined_multi.php',
            $html
         );

         $hidden = Html::hidden('combined_mode', ['value' => 1]);
         $inject = $blListHtml . $blExtrasHtml;
         $label  = 'Signer tous les BL + Rapport';
      }

      // `target="_blank"` : le PDF fusionne s'ouvre a cote, la page du ticket
      // reste vivante.
      $html = preg_replace('/<form\b([^>]*)>/', '<form$1' . $target . '>' . $hidden, $html, 1);

      $html = preg_replace(
         '/(<input type="hidden" name="_glpi_csrf_token" value=")[^"]*(")/',
         '${1}' . Session::getNewCSRFToken(true) . '${2}',
         $html
      );
      /*
       * Injection par `substr_replace` et non `preg_replace`.
       *
       * Le motif est une chaîne littérale, et le contenu injecté porte des noms
       * de bons de livraison : un `$1` ou un antislash dans l'un d'eux serait
       * lu comme une référence arrière par le remplacement d'une expression
       * régulière, et disparaîtrait silencieusement du formulaire.
       */
      $anchor = '<div class="form-container">';
      $at     = strpos($html, $anchor);
      if ($at !== false) {
         $html = substr_replace($html, $anchor . $inject, $at, strlen($anchor));
      }

      // Bouton vise par son identifiant : le libelle varie d'un hote a l'autre,
      // l'identifiant non.
      $html = preg_replace(
         '/(<input[^>]*id="sig-submitBtn"[^>]*\bvalue=")[^"]*(")/',
         '${1}' . $label . '${2}',
         $html,
         1
      );

      echo $html;

      /*
       * Loader + garde-fous de soumission.
       *
       * En mode « BL seul », l'hote (`showForm`) porte DEJA son loader
       * `#gestion-loader` et son gestionnaire de soumission. En emettre un
       * second dupliquait l'identifiant HTML et, surtout, faisait cohabiter
       * deux ecouteurs sur le meme formulaire : celui de l'hote s'executait en
       * premier, desactivait le bouton et affichait le loader, puis le
       * verificateur de cases annulait l'envoi — bouton mort, loader tournant,
       * rien de signe. La verification des cases est donc injectee AVANT lui
       * (cf. $blListHtml) et coupe la chaine avec `stopImmediatePropagation`.
       */
      if (!$bl_only) {
         echo '<div class="gestion-loader-overlay" id="gestion-loader">';
            echo '<div class="gestion-loader-spinner"></div>';
            echo '<div class="gestion-loader-text">Signature en cours, veuillez patienter...</div>';
         echo '</div>';
         echo '<script>
         (function(){
            var forms = document.querySelectorAll("form");' . self::getPostSizeCheckJs() . '
            forms.forEach(function(form) {
               var submitBtn = form.querySelector("input[type=submit]");
               if (!submitBtn) return;
               var submitted = false;
               form.addEventListener("submit", function(e) {
                  var checked = form.querySelectorAll("input[name=\'bl_ids[]\']:checked").length;
                  if (checked === 0) { e.preventDefault(); alert("Sélectionnez au moins un BL."); return; }
                  if (submitted) { e.preventDefault(); return; }
                  if (gestionPostTooBig(form)) { e.preventDefault(); return; }
                  submitted = true;
                  submitBtn.disabled = true;
                  submitBtn.value = "Signature en cours...";
                  var loader = document.getElementById("gestion-loader");
                  if (loader) loader.classList.add("active");
                  if (typeof gestionAfterSubmit === "function") {
                     gestionAfterSubmit(form);
                  }
               });
            });
         })();
         </script>';
      }
   }
}