<?php
/**
 * Recherche approfondie du modal « Scanner / Rechercher » (onglet « Dans les
 * tickets »).
 *
 * Le premier onglet résout un identifiant : un numéro de BL, un numéro de
 * ticket, un QR code. Celui-ci répond à une autre question — « où ai-je déjà vu
 * ce numéro de série ? », « quels sont les tickets de ce client ? » — et
 * cherche donc DANS le contenu : titre, description, tâches, suivis, matériel
 * de l'inventaire (n° de série, n° d'inventaire), bons de livraison, numéro de
 * série relevé par le plugin, et jusqu'à l'entité (nom, désignation, adresse).
 *
 * ---- Ce qui survit à la perte d'un plugin ----
 *
 * L'essentiel repose sur des tables de GLPI — tickets, tâches, suivis,
 * inventaire, entités — et continue donc de fonctionner à l'identique quoi
 * qu'il advienne des deux plugins. Chercher un numéro de série reste possible
 * même sans eux : c'est l'inventaire qui le porte, pas nous.
 *
 * Les deux sources qui leur appartiennent — le n° de série relevé sur une
 * intervention (RP) et les bons de livraison (Gestion) — sont interrogées
 * seulement si leur table est là. Un plugin désactivé garde sa table, donc ses
 * données restent trouvables ; désinstallé, il l'emporte avec lui, et il n'y a
 * alors plus rien à y chercher.
 *
 * ---- Pourquoi c'est écrit comme ça : la vitesse ----
 *
 * Ces tables sont les plus grosses de GLPI, et leurs colonnes de texte pèsent
 * chacune plusieurs kilo-octets. Une première version comparait
 * `REPLACE(REPLACE(...LOWER(content)...))` pour ignorer casse et séparateurs :
 * six copies de chaque description construites en mémoire, pour chaque ligne
 * de chaque table. La recherche prenait DIX MINUTES.
 *
 * On fait désormais comme GLPI lui-même (`SQLProvider::makeTextSearch()`) : un
 * `LIKE '%terme%'` nu, sans aucune fonction posée sur la colonne. La casse est
 * déjà ignorée par la collation des tables (`utf8mb4_unicode_ci`), il n'y a
 * donc rien à convertir. Les variantes d'écriture sont préparées côté PHP, en
 * quelques motifs, et non calculées côté SQL sur chaque ligne.
 *
 * Reste le cas « je l'ai tapé avec un tiret, c'est écrit sans » : il demande
 * une comparaison souple, donc coûteuse. Elle n'est lancée qu'en SECOND
 * PASSAGE, si le premier n'a rien donné — on ne paie ce prix que lorsqu'il
 * peut encore rapporter quelque chose.
 *
 * ---- Les droits ne se contournent pas ----
 *
 * L'entité restreint la requête, et chaque ticket retenu passe par
 * `canViewItem()`. Les tâches et suivis privés ne sont fouillés que par ceux
 * qui ont le droit de les lire — sans quoi la recherche révélerait leur
 * contenu par le simple fait de trouver.
 *
 * Jumeau volontaire de `plugins/rp/inc/scansearch.class.php` : chaque
 * plugin doit fonctionner seul, aucun ne peut dépendre de l'autre. Toute
 * correction ici est à reporter là-bas.
 */

class PluginGestionScanSearch
{
   /**
    * Les deux portées, exclusives l'une de l'autre.
    *
    * Elles n'ont rien de comparable en coût : fouiller les tickets impose de
    * lire le texte de centaines de milliers de lignes, retrouver un client se
    * fait sur une table de quelques centaines d'entrées courtes. Les mener
    * ensemble ferait payer la première à qui ne demandait que la seconde —
    * d'où l'interrupteur devant le champ, et non deux recherches cumulées.
    */
   const SCOPE_TICKET = 'ticket';
   const SCOPE_ENTITY = 'entity';

   /** Tickets rendus au maximum : au-delà, mieux vaut affiner que dérouler. */
   const LIMIT = 20;

   /** Lignes lues par source avant le filtrage des droits. */
   const SCAN = 200;

   /** Longueur minimale du terme : en deçà, tout ressort et rien ne sert. */
   const MIN_LENGTH = 3;

   /**
    * Réduit un terme à ce qui l'identifie : minuscules, sans séparateur.
    *
    * @param string $value
    * @return string
    */
   public static function normalize(string $value): string
   {
      $value = mb_strtolower(trim($value), 'UTF-8');
      return (string)preg_replace('/[^a-z0-9]+/', '', $value);
   }

   /**
    * Neutralise les jokers SQL d'un terme saisi.
    *
    * Sans cela, un `%` tapé par mégarde ramènerait la table entière, et un `_`
    * — fréquent dans les références — remplacerait n'importe quel caractère.
    *
    * @param string $value
    * @return string
    */
   private static function likeEscape(string $value): string
   {
      return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
   }

   /**
    * Motifs `LIKE` du premier passage : le terme tel qu'il est tapé, puis
    * débarrassé de ses séparateurs.
    *
    * Deux motifs littéraux valent mieux qu'une colonne transformée : le travail
    * est fait une fois ici, pas une fois par ligne de la table.
    *
    * @param string $q
    * @return string[] motifs déjà échappés, prêts à être insérés
    */
   private static function needles(string $q): array
   {
      global $DB;

      $terms = [trim($q)];

      $norm = self::normalize($q);
      if (strlen($norm) >= 4) {
         $terms[] = $norm;
      }

      /*
       * Doublons éliminés SANS tenir compte de la casse : la collation des
       * tables ignore déjà la casse, donc « 5CD421HJ4F » et « 5cd421hj4f » sont
       * un seul et même motif. Les distinguer ferait balayer deux fois toutes
       * les descriptions pour un résultat identique — c'est le cas le plus
       * courant (un numéro tapé sans séparateur), donc celui qu'il faut rendre
       * le plus rapide.
       *
       * La variante à lettres inversées n'est pas ici : elle ne sert qu'à
       * rattraper une faute de frappe, et ne mérite pas d'alourdir chaque
       * recherche. Le second passage la porte.
       */
      $needles = [];
      $seen    = [];
      foreach ($terms as $term) {
         $key = mb_strtolower($term, 'UTF-8');
         if ($term === '' || isset($seen[$key])) {
            continue;
         }
         $seen[$key] = true;
         $needles[]  = $DB->escape('%' . self::likeEscape($term) . '%');
      }
      return $needles;
   }

   /**
    * Expression régulière du second passage : le terme, séparateurs tolérés
    * entre chaque caractère.
    *
    * `PF-254GY5`, `PF 254 GY5` et `PF.254GY5` répondent alors tous à
    * `pf254gy5`. Le motif ne contient que des lettres et des chiffres — il sort
    * de `normalize()` — donc rien à échapper.
    *
    * @param string $q
    * @return string chaîne vide si le terme ne s'y prête pas
    */
   private static function looseRegex(string $q): string
   {
      global $DB;

      $norm = self::normalize($q);
      if (strlen($norm) < 4) {
         return '';
      }

      $build = static function (string $value): string {
         return implode('[^0-9A-Za-z]{0,2}', str_split($value));
      };

      $variants = [$build($norm)];
      if (preg_match('/^[a-z]{2}[a-z0-9]{2,}$/', $norm)) {
         $variants[] = $build($norm[1] . $norm[0] . substr($norm, 2));
      }

      return $DB->escape(implode('|', $variants));
   }

   /**
    * Condition SQL « l'une de ces colonnes contient le terme ».
    *
    * Aucune fonction n'est posée sur la colonne : c'est ce qui distingue une
    * recherche de quelques secondes d'une recherche de plusieurs minutes.
    *
    * @param string[] $columns
    * @param string[] $needles motifs `LIKE`
    * @param string   $regex   motif souple, vide si second passage inutile
    * @return string
    */
   private static function matchSql(array $columns, array $needles, string $regex = ''): string
   {
      $parts = [];
      foreach ($columns as $column) {
         foreach ($needles as $needle) {
            $parts[] = "$column LIKE '" . $needle . "'";
         }
         if ($regex !== '') {
            $parts[] = "$column REGEXP '" . $regex . "'";
         }
      }
      return '(' . implode(' OR ', $parts) . ')';
   }

   /**
    * Entités dont le nom, la désignation ou l'adresse répond au terme.
    *
    * Seule source où l'on s'autorise la comparaison normalisée dès le premier
    * passage : quelques centaines de lignes de texte court, là où les tickets
    * en comptent des centaines de milliers de plusieurs kilo-octets. Ce qui
    * était ruineux là-bas ne se mesure pas ici.
    *
    * @param string $q
    * @return array<int,array> entité indexée par id
    */
   private static function searchEntities(string $q): array
   {
      global $DB;

      $columns = ['name', 'completename', 'comment', 'address', 'town', 'postcode'];
      if ($DB->fieldExists('glpi_entities', 'registration_number')) {
         $columns[] = 'registration_number';
      }

      $needles = self::needles($q);
      $norm    = self::normalize($q);
      $parts   = [];
      foreach ($columns as $column) {
         foreach ($needles as $needle) {
            $parts[] = "$column LIKE '" . $needle . "'";
         }
         if (strlen($norm) >= 4) {
            $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column,'-',''),' ',''),'.',''),'_',''),'/','')";
            $parts[]  = $stripped . " LIKE '" . $DB->escape('%' . self::likeEscape($norm) . '%') . "'";
         }
      }

      $found = [];
      $rows  = $DB->request([
         'SELECT' => ['id', 'name', 'completename', 'address', 'town', 'postcode'],
         'FROM'   => 'glpi_entities',
         'WHERE'  => array_merge(
            [new \Glpi\DBAL\QueryExpression('(' . implode(' OR ', $parts) . ')')],
            getEntitiesRestrictCriteria('glpi_entities')
         ),
         'ORDER'  => ['completename ASC'],
         'LIMIT'  => 20,
      ]);
      foreach ($rows as $row) {
         $found[(int)$row['id']] = $row;
      }

      return $found;
   }

   /**
    * Recherche complète.
    *
    * @param string $q     terme saisi
    * @param int    $limit nombre maximum de tickets rendus
    * @param string $scope self::SCOPE_TICKET ou self::SCOPE_ENTITY
    * @return array{results:array, truncated:bool, loose:bool}
    */
   public static function run(string $q, int $limit = self::LIMIT, string $scope = self::SCOPE_TICKET): array
   {
      global $CFG_GLPI;

      $out = ['results' => [], 'truncated' => false, 'loose' => false];

      $q = trim($q);
      if (mb_strlen($q) < self::MIN_LENGTH) {
         return $out;
      }
      $limit   = max(1, min($limit, self::LIMIT));
      $rootdoc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
      $scope   = ($scope === self::SCOPE_ENTITY) ? self::SCOPE_ENTITY : self::SCOPE_TICKET;

      $entities   = [];
      $entity_ids = [];

      if ($scope === self::SCOPE_ENTITY) {
         // ---- Le client lui-même, et ses tickets -----------------------------
         $entities = self::searchEntities($q);

         /*
          * Les sous-entités suivent leur parent. Un client tenu en plusieurs
          * sites porte ses tickets sur les sites, pas sur la maison mère :
          * chercher son nom sans descendre ne ramènerait rien, et donnerait à
          * croire qu'il n'a aucun ticket.
          */
         $entity_ids = array_keys($entities);
         foreach (array_keys($entities) as $eid) {
            // Un identifiant à la fois : `getSonsOf()` met en cache par entité.
            $entity_ids = array_merge($entity_ids, getSonsOf('glpi_entities', (int)$eid));
         }
         $entity_ids = array_values(array_unique(array_map('intval', $entity_ids)));

         /*
          * Leurs tickets sont remontés quand même : `entities_id` est indexé, la
          * base y va droit sans rien parcourir. C'est tout l'intérêt de séparer
          * les deux portées — le client ET ses tickets, sans le prix d'une
          * fouille de texte.
          */
         $reasons = self::collect([], '', $q, $entity_ids);

         return self::render($out, $entities, $reasons, $limit, $rootdoc);
      }

      // ---- Premier passage : comparaison littérale, la plus rapide ----------
      $reasons = self::collect(self::needles($q), '', $q, []);

      /*
       * Second passage, et seulement s'il reste quelque chose à trouver : la
       * comparaison souple retrouve un numéro écrit avec des séparateurs
       * différents. Elle coûte cher — d'où ce garde-fou —, et une base
       * ancienne peut refuser l'expression régulière : l'échec est alors sans
       * conséquence, on rend simplement ce que le premier passage a donné.
       */
      if (empty($reasons)) {
         $regex = self::looseRegex($q);
         if ($regex !== '') {
            try {
               $reasons      = self::collect([], $regex, $q, []);
               $out['loose'] = !empty($reasons);
            } catch (Throwable $e) {
               $reasons = [];
            }
         }
      }

      return self::render($out, $entities, $reasons, $limit, $rootdoc);
   }

   /**
    * Met en forme ce que les requêtes ont trouvé, du plus récent au plus ancien.
    *
    * @param array               $out     réponse en cours de construction
    * @param array<int,array>    $entities
    * @param array<int,string[]> $reasons
    * @param int                 $limit
    * @param string              $rootdoc
    * @return array
    */
   private static function render(array $out, array $entities, array $reasons, int $limit, string $rootdoc): array
   {
      krsort($reasons, SORT_NUMERIC);

      $results = [];
      foreach ($entities as $entity) {
         // Le client lui-même en tête : sa fiche évite le détour par l'onglet
         // Entités quand c'est l'adresse ou le téléphone que l'on cherchait.
         $results[] = self::entityRow($entity, $rootdoc);
         if (count($results) >= 3) {
            break;
         }
      }

      $kept = 0;
      foreach ($reasons as $ticket_id => $why) {
         $ticket = new Ticket();
         if (!$ticket->getFromDB($ticket_id) || !$ticket->canViewItem()) {
            continue;
         }
         /*
          * Le droit se vérifie AVANT de conclure à une liste tronquée : des
          * candidats invisibles pour cet utilisateur ne sont pas des résultats
          * qu'on lui cache, et annoncer « il y en a d'autres » l'enverrait
          * affiner une recherche déjà complète.
          */
         if ($kept >= $limit) {
            $out['truncated'] = true;
            break;
         }
         $results[] = self::ticketRow($ticket, $why, $rootdoc);
         $kept++;
      }

      $out['results'] = $results;
      return $out;
   }

   /**
    * Interroge toutes les sources et retient, par ticket, d'où vient la
    * correspondance.
    *
    * Chaque requête est ordonnée sur la CLÉ PRIMAIRE : la base la parcourt à
    * l'envers, séquentiellement, et s'arrête dès qu'elle a son compte. Trier
    * sur une autre colonne l'obligerait à passer par un index secondaire puis à
    * aller chercher chaque ligne à sa place sur le disque — de très loin le
    * plus lent sur des tables de cette taille.
    *
    * @param string[] $needles    motifs `LIKE` (premier passage)
    * @param string   $regex      motif souple (second passage), vide sinon
    * @param string   $q          terme d'origine, pour distinguer titre et corps
    * @param int[]    $entity_ids entités trouvées, dont les tickets comptent
    * @return array<int,string[]> raisons indexées par identifiant de ticket
    */
   private static function collect(array $needles, string $regex, string $q, array $entity_ids): array
   {
      global $DB, $CFG_GLPI;

      $reasons = [];
      $note = static function (int $ticket_id, string $why) use (&$reasons): void {
         if ($ticket_id <= 0) {
            return;
         }
         if (!isset($reasons[$ticket_id])) {
            $reasons[$ticket_id] = [];
         }
         if (!in_array($why, $reasons[$ticket_id], true)) {
            $reasons[$ticket_id][] = $why;
         }
      };

      /*
       * Tickets des entités trouvées.
       *
       * En tête parce que c'est la seule source gratuite : `entities_id` est
       * indexé, la base y va droit sans rien parcourir.
       */
      if (!empty($entity_ids)) {
         $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => array_merge([
               'is_deleted'  => 0,
               'entities_id' => $entity_ids,
            ], getEntitiesRestrictCriteria('glpi_tickets')),
            'ORDER'  => ['id DESC'],
            'LIMIT'  => self::SCAN,
         ]);
         foreach ($rows as $row) {
            $note((int)$row['id'], __('entité', 'gestion'));
         }
      }

      if (empty($needles) && $regex === '') {
         return $reasons;
      }

      // a) Titre et description
      $rows = $DB->request([
         // Pas de `content` ici : il ne sert qu'à la condition, côté SQL. Le
         // ramener, c'est charger 200 descriptions entières en mémoire pour
         // n'en lire aucune.
         'SELECT' => ['id', 'name'],
         /*
          * `array_merge` et NON l'union `+` : la restriction d'entité peut être
          * une expression brute, donc porter la même clé 0 que la mienne.
          * L'union garderait la mienne et laisserait tomber la restriction —
          * c'est-à-dire qu'un utilisateur privé de toute entité verrait au
          * contraire tout le monde.
          */
         'FROM'   => 'glpi_tickets',
         'WHERE'  => array_merge([
            'is_deleted' => 0,
            new \Glpi\DBAL\QueryExpression(
               self::matchSql(['glpi_tickets.name', 'glpi_tickets.content'], $needles, $regex)
            ),
         ], getEntitiesRestrictCriteria('glpi_tickets')),
         'ORDER'  => ['id DESC'],
         'LIMIT'  => self::SCAN,
      ]);
      foreach ($rows as $row) {
         $in_title = self::hits((string)$row['name'], $q);
         $note((int)$row['id'], $in_title ? __('titre', 'gestion') : __('description', 'gestion'));
      }

      // b) Tâches
      $task_where = [
         new \Glpi\DBAL\QueryExpression(self::matchSql(['glpi_tickettasks.content'], $needles, $regex)),
      ];
      if (!Session::haveRight('task', CommonITILTask::SEEPRIVATE)) {
         $task_where['glpi_tickettasks.is_private'] = 0;
      }
      $rows = $DB->request([
         'SELECT' => ['tickets_id'],
         'FROM'   => 'glpi_tickettasks',
         'WHERE'  => $task_where,
         'ORDER'  => ['id DESC'],
         'LIMIT'  => self::SCAN,
      ]);
      foreach ($rows as $row) {
         $note((int)$row['tickets_id'], __('tâche', 'gestion'));
      }

      // c) Suivis
      $fup_where = [
         'itemtype' => 'Ticket',
         new \Glpi\DBAL\QueryExpression(self::matchSql(['glpi_itilfollowups.content'], $needles, $regex)),
      ];
      if (!Session::haveRight('followup', ITILFollowup::SEEPRIVATE)) {
         $fup_where['glpi_itilfollowups.is_private'] = 0;
      }
      $rows = $DB->request([
         'SELECT' => ['items_id'],
         'FROM'   => 'glpi_itilfollowups',
         'WHERE'  => $fup_where,
         'ORDER'  => ['id DESC'],
         'LIMIT'  => self::SCAN,
      ]);
      foreach ($rows as $row) {
         $note((int)$row['items_id'], __('suivi', 'gestion'));
      }

      /*
       * d) Matériel de l'inventaire, et les tickets qui lui sont rattachés.
       *
       * Source NATIVE de GLPI, qui ne dépend d'aucun des deux plugins : c'est
       * l'inventaire qui porte les numéros de série et d'inventaire, et
       * `glpi_items_tickets` qui relie un matériel à ses tickets. Chercher un
       * numéro de série continue donc de fonctionner quoi qu'il advienne des
       * plugins — c'est la seule façon de tenir cette promesse, la table du
       * plugin RP disparaissant avec lui s'il est désinstallé.
       *
       * Le coût est sans commune mesure avec la fouille des tickets : quelques
       * milliers de lignes, des colonnes de quelques caractères.
       */
      $asset_hits = [];
      foreach (($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
         if (!is_string($itemtype) || !class_exists($itemtype)) {
            continue;
         }
         $table   = getTableForItemType($itemtype);
         $columns = [];
         // `otherserial` : le numéro d'inventaire, que beaucoup d'entreprises
         // collent sur la machine à côté du numéro constructeur.
         foreach (['serial', 'otherserial'] as $field) {
            if ($DB->fieldExists($table, $field)) {
               $columns[] = "$table.$field";
            }
         }
         if (empty($columns)) {
            continue;
         }

         $where = [new \Glpi\DBAL\QueryExpression(self::matchSql($columns, $needles, $regex))];
         if ($DB->fieldExists($table, 'is_deleted')) {
            $where["$table.is_deleted"] = 0;
         }
         // `auto` : un matériel partagé aux entités filles doit ressortir pour
         // elles, et GLPI sait seul si ce type se transmet ainsi.
         $where = array_merge($where, getEntitiesRestrictCriteria($table, '', '', 'auto'));

         $ids = [];
         foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 50]) as $row) {
            $ids[] = (int)$row['id'];
         }
         if (!empty($ids)) {
            $asset_hits[] = ['itemtype' => $itemtype, 'items_id' => $ids];
         }
      }
      if (!empty($asset_hits)) {
         $rows = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_items_tickets',
            'WHERE'  => ['OR' => $asset_hits],
            'ORDER'  => ['tickets_id DESC'],
            'LIMIT'  => self::SCAN,
         ]);
         foreach ($rows as $row) {
            $note((int)$row['tickets_id'], __('matériel', 'gestion'));
         }
      }

      // e) Numéro de série relevé lors d'une intervention (table du plugin RP,
      //    interrogée seulement si elle existe : les deux plugins vivent seuls)
      if ($DB->tableExists('glpi_plugin_rp_dataclient')
          && $DB->fieldExists('glpi_plugin_rp_dataclient', 'serial_number')) {
         $rows = $DB->request([
            'SELECT' => ['id_ticket'],
            'FROM'   => 'glpi_plugin_rp_dataclient',
            'WHERE'  => [
               new \Glpi\DBAL\QueryExpression(
                  self::matchSql(['glpi_plugin_rp_dataclient.serial_number'], $needles, $regex)
               ),
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => self::SCAN,
         ]);
         foreach ($rows as $row) {
            $note((int)$row['id_ticket'], __('n° de série', 'gestion'));
         }
      }

      /*
       * f) Bons de livraison (table du plugin Gestion, si elle existe)
       *
       * Le champ « Documents/Informations associé au bon » porte souvent la
       * référence que l'on cherche : numéro de commande, de facture, de série
       * du matériel livré. Le numéro du bon lui-même reste utile ici — le
       * premier onglet ne le trouve que s'il est saisi tel quel, celui-ci le
       * retrouve écrit au milieu d'autre chose.
       */
      if ($DB->tableExists('glpi_plugin_gestion_surveys')
          && Session::haveRight('plugin_gestion_survey', READ)) {
         $bl_columns = ['glpi_plugin_gestion_surveys.bl'];
         foreach (['tracker', 'relatedInvoiceToBL', 'comment'] as $field) {
            if ($DB->fieldExists('glpi_plugin_gestion_surveys', $field)) {
               $bl_columns[] = 'glpi_plugin_gestion_surveys.' . $field;
            }
         }
         $rows = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_plugin_gestion_surveys',
            'WHERE'  => [
               new \Glpi\DBAL\QueryExpression(self::matchSql($bl_columns, $needles, $regex)),
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => self::SCAN,
         ]);
         foreach ($rows as $row) {
            $note((int)$row['tickets_id'], __('BL', 'gestion'));
         }
      }

      return $reasons;
   }

   /**
    * Le terme apparaît-il dans ce texte ? (mêmes règles que la requête)
    *
    * @param string $haystack
    * @param string $needle
    * @return bool
    */
   private static function hits(string $haystack, string $needle): bool
   {
      if (mb_stripos($haystack, $needle) !== false) {
         return true;
      }
      $norm = self::normalize($needle);
      return $norm !== '' && strpos(self::normalize($haystack), $norm) !== false;
   }

   /**
    * Ligne de résultat « entité ».
    *
    * @param array  $entity
    * @param string $rootdoc
    * @return array
    */
   private static function entityRow(array $entity, string $rootdoc): array
   {
      $address = trim(implode(' ', array_filter([
         trim((string)($entity['address'] ?? '')),
         trim((string)($entity['postcode'] ?? '')),
         trim((string)($entity['town'] ?? '')),
      ])));

      return [
         'type'     => 'entity',
         'title'    => (string)($entity['completename'] ?? $entity['name'] ?? ''),
         'subtitle' => $address !== '' ? $address : __('Entité', 'gestion'),
         'badge'    => ['label' => __('Entité', 'gestion'), 'style' => 'muted'],
         'actions'  => [[
            'label'   => __("Ouvrir l'entité", 'gestion'),
            'url'     => $rootdoc . '/front/entity.form.php?id=' . (int)$entity['id'],
            'icon'    => 'ti ti-building',
            'primary' => false,
         ]],
      ];
   }

   /**
    * Ligne de résultat « ticket ».
    *
    * Volontairement légère : pas d'actions de signature ici, contrairement au
    * premier onglet. Vingt tickets à interroger chacun sur ses tâches, ses bons
    * et ses droits, c'est une attente pour un écran dont on ne retient qu'une
    * ligne. Le ticket ouvert offre tout cela.
    *
    * @param Ticket   $ticket
    * @param string[] $why
    * @param string   $rootdoc
    * @return array
    */
   private static function ticketRow(Ticket $ticket, array $why, string $rootdoc): array
   {
      $ticket_id = (int)$ticket->fields['id'];
      $status    = (int)$ticket->fields['status'];

      $subtitle = Dropdown::getDropdownName('glpi_entities', (int)$ticket->fields['entities_id']);
      $date     = (string)($ticket->fields['date'] ?? '');
      if ($date !== '') {
         $subtitle .= ' — ' . Html::convDate($date);
      }
      if (!empty($why)) {
         $subtitle .= ' — ' . sprintf(__('trouvé dans : %s', 'gestion'), implode(', ', $why));
      }

      return [
         'type'     => 'ticket',
         'title'    => '#' . sprintf('%07d', $ticket_id) . ' — ' . (string)$ticket->fields['name'],
         'subtitle' => $subtitle,
         'badge'    => [
            'label' => Ticket::getStatus($status),
            'style' => in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true) ? 'muted' : 'ok',
         ],
         'actions'  => [[
            'label'   => __('Ouvrir le ticket', 'gestion'),
            'url'     => $rootdoc . '/front/ticket.form.php?id=' . $ticket_id,
            'icon'    => 'ti ti-ticket',
            'primary' => true,
         ]],
      ];
   }
}
