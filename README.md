# Gestion signature PDF (Gestion BL)

Plugin GLPI de gestion et signature de BL PDF (bons de livraison / documents assimilés), avec plusieurs sources de recherche (`Local`, `SharePoint`, `Sage`), flux de signature web/API, signature déportée (kiosque/appareil), intégrations mobiles et possibilité de flux combiné BL + RP.

Le plugin est un socle métier: il permet de retrouver un document, le lier au ticket, le prévisualiser, le signer, l'archiver/envoyer, et exposer ce flux via APIs pour applications externes.

## Ce que fait le plugin (lecture rapide)

- Recherche des BL/PDF depuis plusieurs sources (`Local`, `SharePoint`, `Sage`).
- Prévisualisation et signature de PDF depuis l'interface GLPI.
- Gestion de la position de la signature, du signataire, de la date et du technicien sur le PDF.
- Signature déportée (poste kiosque / appareil) via endpoints API `device_*`.
- Flux API pour apps mobiles / web (`bl_prepare`, `bl_sign`, `ticket_bls`, etc.).
- Flux combiné BL + RP (si le plugin `rp` est installé).
- Fonctions complémentaires: extraction tracker, mail tracker, facturation comptoir, signature à l'ajout de tâche.

## Fonctionnement (parcours type)

### Parcours standard (technicien depuis GLPI)

1. L'administrateur active les modes de recherche souhaités (`Local`, `SharePoint`, `Sage`).
2. L'administrateur configure les sources (dossiers, SharePoint, API Sage) et le positionnement de signature sur PDF.
3. Le technicien ouvre le menu du plugin (`survey.php`) ou un ticket selon votre workflow.
4. Le plugin recherche les BL disponibles.
5. Le technicien sélectionne un BL, le prévisualise, puis signe.
6. Le plugin finalise via `traitement.php` (ou `traitement_combined.php` si flux BL+RP) et applique les actions configurées (affichage PDF, mail, archivage, etc.).

### Parcours API / mobile / kiosque

1. Une application authentifiée appelle `bl_prepare` pour récupérer les BL disponibles et le contexte.
2. L'application affiche le document à l'utilisateur / technicien / client.
3. L'application envoie la signature via `bl_sign` (ou endpoints `device_*` selon le mode).
4. Le plugin enregistre, associe le résultat au ticket et renvoie le statut attendu par l'application.

### Parcours automatisé / complémentaire

- `GestionPdf` (cron) peut traiter des tâches liées au mode SharePoint / synchronisation selon votre configuration.
- L'extraction tracker peut analyser un chemin/nom et déclencher des actions (ou mail tracker) selon les règles configurées.
- Le plugin peut déclencher une signature BL lors de l'ajout d'une tâche si cette fonctionnalité est activée.

## Modes de fonctionnement (important)

### Mode `Local`

Le plugin recherche les documents dans les dossiers GLPI / partages configurés.

À utiliser si:
- vos PDF sont déjà présents localement sur le serveur GLPI
- vous voulez éviter une dépendance Graph/Sage pour la recherche
- vous avez un workflow simple et rapide de consultation/signature

### Mode `SharePoint`

Le plugin interroge SharePoint via Microsoft Graph pour rechercher les documents.

À utiliser si:
- vos BL sont publiés dans SharePoint
- vous avez plusieurs sites/bibliothèques à parcourir
- vous voulez centraliser la source documentaire côté Microsoft 365

### Mode `Sage`

Le plugin interroge une API Sage locale/externe (selon votre architecture) pour retrouver les documents/références.

À utiliser si:
- la vérité métier est côté Sage
- vous voulez récupérer des BL en temps réel sans dépôt local manuel

## Configuration plugin (lecture rapide par zone)

La configuration est riche. Le but du README est de donner la logique générale; le `Documentation_Fonctionnement.docx` détaille les options champ par champ.

### 1. Carte `Gestion` (comportement global après signature / envoi)

Exemples d'options utilisées dans cette zone:
- `DisplayPdfEnd`: affiche le PDF final après signature.
  Utilité: contrôle visuel immédiat du rendu final.
- `MailTo`: active / pilote l'envoi du PDF par mail (selon votre réglage de gabarit et flux).
  Utilité: éviter un envoi manuel après signature.
- `CombinedMailMode`: comportement d'envoi quand vous utilisez le flux combiné BL + RP.
  Utilité: choisir comment répartir ou fusionner les envois.
- `gabarit` (`NotificationTemplate`): modèle de notification utilisé pour les mails.
- `ZenDocMail`: envoi/archivage vers une adresse ou circuit documentaire (selon votre implémentation ZenDoc).
- `InvoiceMail` (« Envoi facture par mail »): destinataire(s) du document scanné envoyé depuis la tablette via `device_send_invoice`. Plusieurs adresses possibles (séparées par `,` `;` ou espace) ; la 1ʳᵉ est en `To`, les suivantes en `Cc`.
- `ConfigModes`, `SageOn`, `SharePointOn`, `LocalSearch`: activent les modes disponibles et la source principale de recherche.

En résumé, cette zone décide "que fait le plugin une fois la signature faite" et "quelles sources sont autorisées".

### 2. Positionnement sur PDF (signature, signataire, date, technicien)

Options typiques:
- `SignatureX`, `SignatureY`, `SignatureSize`
- `SignataireX`, `SignataireY`
- `DateX`, `DateY`
- `TechX`, `TechY`

Ces valeurs servent à placer visuellement les éléments sur le PDF.

Pourquoi c'est important:
- chaque modèle de BL a une mise en page différente
- un mauvais réglage peut superposer la signature au texte
- vous pouvez adapter le rendu sans modifier le code

Bon réflexe:
- tester avec un BL réel de chaque modèle utilisé avant déploiement global.

### 3. Source SharePoint (connexion et recherche)

Options typiques:
- `TenantID`, `ClientID`, `ClientSecret`
- `Hostname`, `SitePath`
- `SharePointSearch`
- `Global`

Ce que ça active:
- authentification Microsoft Graph
- ciblage du site SharePoint
- mode de recherche des documents dans SharePoint
- comportements de recherche globale ou ciblée (selon la logique de votre instance)

Quand l'utiliser:
- si les BL sont centralisés dans SharePoint et doivent rester la source documentaire officielle

### 4. Source Sage (API)

Options typiques:
- `SageUrlApi`
- `SageToken`
- `SageSearch`

Ce que ça active:
- connexion à l'API Sage
- authentification applicative via token
- recherche côté Sage sur la référence demandée

Quand l'utiliser:
- si le BL doit être retrouvé via l'ERP plutôt que dans un dossier local

### 5. Entités et Tracker (extraction / routage)

C'est la zone qui répond exactement à l'exemple "Entités et Tracker : Extraction d'un tracker oui/non".

Options typiques:
- `ExtractYesNo` (extraction d'un tracker: oui/non)
- `extract` (séparateurs / logique d'extraction)
- `MailTrackerYesNo` (envoyer un mail si extraction OK / condition remplie)
- `MailTracker`
- `gabarit_tracker`
- `EntitiesExtract`
- `EntitiesExtractValue`

À quoi ça sert (en pratique):
- extraire une information (tracker / identifiant) depuis un chemin, nom de fichier ou référence
- décider si cette extraction est active ou non
- déclencher un envoi mail spécifique lié au tracker
- adapter l'extraction selon l'entité GLPI

Pourquoi c'est utile:
- automatiser une partie de la qualification ou de la notification
- éviter une re-saisie manuelle d'un identifiant présent dans le document

### 6. Signature BL à l'ajout de tâche (workflow terrain / technicien)

C'est la zone qui répond à l'exemple "Signature BL à l'ajout de tâche, Technicien(s) autorisé(s)".

Options typiques:
- `TaskSignatureUsers[]` (technicien(s) autorisé(s))
- `TaskSignatureTriggerStates` (déclencheur(s) sur état de tâche)
- `PlanningBLSignatureOn`

À quoi ça sert:
- limiter la fonctionnalité de signature automatique/assistée à certains techniciens
- déclencher la proposition de signature seulement sur certains états de tâche
- intégrer la signature BL dans un workflow opérationnel (fin intervention, statut particulier, etc.)

Exemple d'usage:
- vous autorisez seulement l'équipe terrain à signer les BL à la fin d'une tâche
- vous déclenchez la signature quand la tâche passe à un état de fin / réalisation

### 7. Signature déportée / kiosque / appareils

Options / zones associées:
- `RemoteSignatureOn`
- `RemoteSignatureUsers[]`
- endpoints `device_checkin`, `device_poll_v2`, `device_submit_v2`, `device_refuse_v2`, `device_direct_sign`, `device_send_invoice`

À quoi ça sert:
- faire signer le document sur un appareil dédié (tablette, borne, poste d'accueil)
- séparer le poste technicien GLPI et l'écran de signature utilisateur/client
- piloter une file d'attente de signature côté appareil

Pourquoi c'est utile:
- améliore l'expérience terrain / accueil
- sécurise le flux en évitant l'usage d'une simple page ouverte avec token (voir note > 1.7.0)

### 8. Facturation comptoir

Options de type `CounterInvoice*` (ex: mail interne, texte d'affichage, intitulés) servent à:
- paramétrer le libellé et la communication autour du règlement comptoir
- injecter une mention spécifique dans le flux document / ticket selon votre usage

Quand l'utiliser:
- si vous avez un accueil/comptoir avec validation / paiement sur place

### 9. Affichage / performance / comportements annexes

Exemples d'options:
- `SharePointLinkDisplay`
- `NumberViews`
- `formulaire`
- `LastCronTask` (état/trace côté configuration selon la version)

Ces options servent à:
- piloter certaines informations visibles dans l'interface
- ajuster le nombre de résultats/éléments affichés
- suivre l'état de certains traitements périodiques

## Menus / écrans utilisés (vue fonctionnelle)

- `survey.php`: écran principal de recherche / sélection BL.
- `survey.form.php`: création/ajout manuel ou assisté selon le workflow configuré.
- `charger_dropdown.php`: chargement de listes/document selon mode de recherche.
- `traitement.php`: finalisation du flux de signature BL.
- `traitement_combined.php`: finalisation combinée BL + RP (si `rp` est utilisé).
- `front/api_docs.php`: documentation / tests des APIs.
- Onglets Ticket / configuration / profil selon les droits.

### Rangement des PDF signés
`<dossier configuré>/<Entité>/<année>/<mois>/<fichier>` — appliqué à **tous** les points
d'écriture, local comme SharePoint :
- `front/traitement.php` (signature d'un BL seul) ;
- `front/traitement_combined.php` (BL + rapport), qui inclut `traitement.php` ;
- `front/traitement_combined_multi.php` (plusieurs BL + un rapport fusionnés).

Le mois est en toutes lettres sans accent (`janvier`, `fevrier`, … `aout`). Le plugin RP suit
la même convention depuis `pluginRpDatedFolder()`.

`survey.form.php` et `ajax/quick_add_survey_form.php` ne rangent rien : ils **référencent** le
BL source non signé là où il a été déposé (`_plugins/gestion/Documents`). Le `$destinationPath`
de `inc/ticketconfig.class.php` est une variable morte — elle ne fait que créer le dossier de
base, aucune écriture n'en dépend.

### Onglet Ticket « Gestion BL » : bandeau « Étape suivante »
Au-dessus du tableau des BL, deux sources dans cet ordre :

1. **Plugin `rp` actif** — `PluginRpCridetail::getNextStepHtml()` : même recommandation que
   l'onglet RP, le bouton flottant et le scanner (source unique `PluginRpTicketActions`).
   Évite l'aller-retour entre les deux onglets quand l'étape réelle est « BL + rapport
   ensemble » et non « BL seul ».
2. **Repli Gestion** — `PluginGestionTicket::getBlNextStepHtml()` : « Signer le bon de
   livraison », sur le plus ancien bon non signé. Il ouvre `gestion_loadCriForm` avec les
   **mêmes paramètres que les boutons du tableau**, donc les mêmes règles (choix Rapport/BL,
   `NoTaskSignMode`…) — aucune logique de signature réécrite.

Le repli ne sert pas qu'au cas « `rp` désinstallé » : RP rend une chaîne vide dès qu'aucune
de *ses* étapes ne se dégage (rapport déjà fait, droits manquants, aucune tâche), et le bon
restait alors à signer sans que rien ne le dise. Gestion fonctionne donc seul, et
`method_exists` couvre un `rp` antérieur à cette méthode.

Présentation identique dans les deux cas — bouton `btn-info` et liseré
`card-status-start bg-info`, pour trancher avec les boutons `primary` du tableau. Classes
sémantiques Tabler, jamais de couleur en dur : le bandeau suit le thème GLPI, sombre compris.

### Signature groupée des BL : « Rapport + BL » ou « BL seul »
`front/traitement_combined_multi.php` porte **deux parcours**, pilotés par le champ POST
`bl_only`, et une seule chaîne de traitement (fusion → archivage daté → mise à jour des
lignes → mails → nettoyage) :

| | Documents fusionnés | Quand |
|---|---|---|
| défaut | BL cochés **+ 1 rapport d'intervention** | rapport pas encore signé |
| `bl_only` | BL cochés **seuls** | rapport déjà signé, **ou** plugin `rp` absent |

Dans les deux cas, **tous** les bons non signés du ticket sont listés avec une case à cocher :
on en signe un, plusieurs ou tous, et on revient signer les autres plus tard.

**Tout est précoché par défaut.** Seul le paramètre `one_bl` renverse la règle, et il n'est
posé que là où l'utilisateur a cliqué sur **un bon précis** :

| Point d'entrée | Précoché |
|---|---|
| Bandeaux « Étape suivante » / « Continuer », page mobile, scanner OCR, bouton flottant, « Signature BL » de `survey.php`, « Régénérer » du rapport | **tous** les bons en attente |
| Ligne du tableau des BL (onglet ticket), colonne « Signature » de la liste, fiche d'un BL, lien du planning | **le seul bon désigné** — les autres restent visibles et cochables |

Signer plusieurs bons d'un coup est le cas courant ; désigner un bon précis est l'exception,
d'où le défaut. Tout précocher sur un clic de ligne faisait signer trois bons à qui n'en avait
demandé qu'un.

Si l'identifiant transmis ne correspond à aucun bon en attente (déjà signé entre-temps), tout
est recoché plutôt que de présenter un formulaire où rien n'est sélectionné.

**Le choix « Signature BL »** n'apparaît dans le radio « Que signe le client ? » **que si le
rapport d'intervention est déjà signé** — ailleurs il serait un piège : signer le bon sans le
rapport laisserait l'intervention sans trace. Une fois le rapport signé, l'inverse devient
vrai, et ce mode devient le **défaut**. Décision unique : `PluginGestionCri::defaultCombinedMode()`,
qui s'appuie sur `hasSignedRpReport()` → `PluginRpCridetail::getSignedTypes()`. Gestion ne
redevine jamais ce que RP considère comme signé.

**Le rapport doit aussi être encore d'actualité.** `defaultCombinedMode()` repasse à
`both` dès que le ticket a **évolué depuis** le rapport : `PluginRpCridetail::countChangesSinceReport()`
compte les **tâches et suivis** créés ou modifiés après lui. Un changement de statut, une
clôture, une réattribution ne comptent pas — ils ne changent rien à ce que raconte le rapport.
Les **trois** horodatages sont interrogés (`date_mod`, `date_creation`, `date`) : `date` est la
date métier, antidatable, les deux autres disent quand la ligne est réellement apparue.

Le filtre `use_publictask` de RP est repris tel quel — sur une installation qui exclut les
tâches privées du rapport, en ajouter une ne le périme pas. Une tolérance de **30 secondes**
évite qu'un rapport se déclare périmé à cause du suivi ou de la tâche que sa propre génération
crée quelques secondes après l'avoir horodaté.

Le modal affiche sous le choix : la **date du rapport**, son ancienneté en clair, et le cas
échéant ce qui a bougé (« Le ticket a évolué depuis : 2 tâches, 1 suivi »).

> **Aucun seuil d'ancienneté.** Un rapport n'est périmé que par un ajout de tâche ou de suivi,
> jamais par le temps qui passe : un ticket sans le moindre mouvement depuis un mois n'a rien
> de plus à raconter, et le régénérer produirait le même document.

La mention reste **en gris dans les deux cas**, seule l'icône change (`ti-file-check` /
`ti-refresh`) et l'essentiel est mis en gras. Elle constate, elle n'alerte pas : le choix étant
déjà repositionné sur « Rapport + BL », une ligne orange criait plus fort que nécessaire pour
un fait aussi banal.

**Ce défaut ne vaut que pour les écrans partant d'un BL** (liste des bons, bandeau « Signer
le bon de livraison », planning, page mobile). Un écran partant du **rapport** connaît son
intention et la déclare : le bouton « Régénérer » de la carte *Rapport d'intervention* passe
`force_combined = 1`. Sans cela, cliquer « Régénérer » sur un ticket dont le rapport était
déjà signé ouvrait un formulaire « Signature BL » — qui ne régénérait aucun rapport.
Les actions RP en `mode => 'rp'` (bouton flottant, scanner) ouvrent le formulaire de RP
directement et ne passent pas par ce choix.

**Hôte du formulaire** : le formulaire groupé n'écrit ni signature ni champ client, il se
greffe sur un formulaire existant dont il réécrit l'action et le libellé du bouton —
le formulaire **rapport de RP** en mode combiné, le formulaire de signature **de Gestion** en
mode BL seul. Les deux postent exactement les mêmes champs (`name`, `url`, `photo_base64_N`,
`pdf_base64`, `comment`, `mailtoclient`, `email`, `REPORT_ID`), donc aucune correspondance à
écrire. En mode BL seul, seule la liste à cocher est injectée : l'hôte porte déjà sa capture
photo/PDF et son commentaire, les injecter produirait des identifiants HTML en double.

**Sans le plugin `rp`**, la même liste à cocher est proposée : Gestion signe plusieurs bons
d'un coup au lieu d'un par un.

#### Un seul chemin, quelle que soit la porte
Tous ces écrans passent par `ajax/cri.php`, et **aucun** n'a de règle propre :

| Écran | `root_modal` |
|---|---|
| Onglet ticket « Gestion BL » (liste + bandeau de repli) | `ticket-form` |
| Bandeau « Étape suivante » du plugin RP | `rp-next-step` |
| Page mobile RP (QR code) | `rp-mobile-modal` |
| Planning GLPI | `planning-form` |
| Scanner OCR | `scan-form` (redirige vers le ticket) |
| Boutons flottants RP et Gestion, liste des BL, signature à l'ajout de tâche | — |

Le formulaire ne dépend **que** de deux faits : le bon appartient-il à un ticket, et le
rapport est-il déjà signé. Réserver la liste à cocher à certaines portes aurait recréé la
divergence qu'on cherche à supprimer — le même bon signé d'une façon ici, d'une autre là.

Le choix « Compléter l'intervention / Signer » proposé hors ticket suit le même arbitrage :
il propose « Signer le BL » quand le rapport est signé, « Signer Rapport + BL » sinon.

La page mobile RP ne force plus « Rapport + BL » en dur : elle interroge
`defaultCombinedMode()` comme les autres. Le bouton flottant Gestion ne liste plus une entrée
par bon non signé — elles menaient toutes au même écran ; une entrée annonce le nombre en
attente.

`ajax/task_signature_form.php` et la fonction JS `openSignatureModal()` (première occurrence,
vers la ligne 884 de `scripts_gestion.js`) sont du **code mort** : la signature à l'ajout de
tâche passe par `gestion_loadCriForm` depuis la refonte. Ils sont laissés en place mais ne
doivent pas servir de modèle — ils ne suivent pas l'arbitrage ci-dessus.

#### Archivage : dossiers créés, échecs annoncés
`<Entité>/<année>/<mois>` n'existe pas au premier document du mois : il est créé
récursivement (`mkdir(..., true)`), **et l'échec n'est pas silencieux**. Sans dossier, la
copie échoue et le Document GLPI enregistré ensuite pointe vers un fichier absent — c'est
le « Fichier introuvable sur le disque » des listes. `traitement.php` et
`traitement_combined_multi.php` s'arrêtent donc net, avec un message, **avant** de marquer
quoi que ce soit signé : le technicien recommence, plutôt que de découvrir des semaines plus
tard des bons `signed = 1` pointant vers rien.

Côté SharePoint, Microsoft Graph crée les dossiers intermédiaires à l'upload
(`PUT /root:/{chemin}/{fichier}:/content`) — rien à prévoir.

### Onglet Ticket « Gestion BL » : liste et suppression définitive
Le tableau à cinq colonnes est remplacé par une **liste**, identique à celle des cartes du
plugin RP : numéro de BL en gras **suivi du badge** **Signé** / **À faire signer**, ID /
technicien / signataire en gris dessous, action unique et **« Signé le : <date> »** à droite.
Les cases à cocher des actions massives restent en place, dans le formulaire ouvert au-dessus.

Le badge est **contre le nom** et non dans la colonne de droite : il y formait une troisième
pile sous le bouton et la date, trois éléments empilés pour une ligne qui n'en dit qu'un.

Suppression par la barre **« Actions »** de GLPI (droit `plugin_gestion_sign` en **PURGE**),
sans bouton « Supprimer » par ligne : les deux auraient offert la même chose.

Le ménage est fait par `PluginGestionTicket::cleanDBonPurge()`, donc identique quelle que soit
la voie empruntée. La ligne disparaissait jusqu'ici seule, en laissant le PDF sur le disque ;
le Document GLPI est maintenant **purgé** (`delete(..., 1)`), ce qui déclenche
`Document::cleanDBonPurge()` et retire le fichier.

> Un bon stocké dans **SharePoint** ou servi par **Sage** n'est **pas** supprimé chez son
> hébergeur : seule la trace GLPI l'est, et l'utilisateur en est averti. Supprimer à distance
> depuis un ticket serait irréversible et invisible pour les autres utilisateurs de la
> bibliothèque.

`isMobile()` a disparu avec le tableau — elle n'y masquait que des colonnes trop larges, et
étant déclarée dans le corps d'une méthode elle aurait provoqué un « Cannot redeclare » au
deuxième appel.

Libellé au singulier : ce parcours ne signe que le bon désigné. S'il en reste d'autres, leur
nombre est rappelé (« BL… — 3 bons non signés au total ») plutôt que promis. La signature
groupée de tous les bons reste le parcours combiné de RP.

## APIs / intégrations (résumé fonctionnel)

### APIs BL / signatures

- `public/api/bl_prepare.php` : prépare un ticket/BL pour signature (recherche, métadonnées, options disponibles).
- `public/api/bl_sign.php` : enregistre la signature BL et finalise le traitement.
- `public/api/ticket_bls.php` : liste / expose les BL liés à un ticket pour une application externe.

### API combinée BL + RP

- `public/api/combined_sign.php` : flux combiné quand vous utilisez `gestion` avec `rp`.

### APIs appareils / kiosque (`device_*`)

- `public/api/device_checkin.php` : enregistrement / présence d'un appareil.
- `public/api/device_poll_v2.php` : récupération des demandes de signature en attente.
- `public/api/device_submit_v2.php` : retour signature depuis l'appareil.
- `public/api/device_refuse_v2.php` : refus de signature depuis l'appareil.
- `public/api/device_direct_sign.php` : flux de signature direct selon votre implémentation.
- `public/api/device_send_invoice.php` : reçoit un document scanné depuis la tablette et le transfère **par mail** aux destinataires configurés (`InvoiceMail`). Aucun stockage GLPI. (depuis 1.7.4)
  - Auth : Token GLPI v1/v2 + header `X-Device-Serial` (même mécanisme que `device_submit_v2`).
  - Corps : `multipart/form-data` champ `file` (recommandé) **ou** JSON `{ "file_base64": "...", "filename": "..." }`.
  - Types : pdf / jpg / png (MIME détecté côté serveur), 15 Mo max.
  - Réponse : `200 { ok:true, recipients_count:N, filename:"..." }`.

## Prérequis

- GLPI 11.x (selon version du plugin installée)
- PHP compatible GLPI
- Extension `sodium` (chiffrement des secrets SharePoint/Sage)
- SharePoint / Microsoft Graph (optionnel, si mode SharePoint)
- API Sage (optionnel, si mode Sage)
- Plugin `rp` (optionnel, pour flux combinés BL + RP)
- Cron GLPI fonctionnel (si vous utilisez les traitements planifiés du plugin)

## Droits / profils

- Les menus principaux (survey / gestion BL) suivent les droits de profil du plugin (ex: `plugin_gestion_survey`).
- Les onglets Ticket / Config / Profil dépendent des droits plugin correspondants.
- Les endpoints API nécessitent une authentification GLPI valide (OAuth v2 / legacy selon votre environnement).
- Les fonctions de signature déportée peuvent être limitées à des utilisateurs autorisés (`RemoteSignatureUsers[]`).

## Tâches cron

Le plugin enregistre la tâche cron `GestionPdf`.

Rôle du cron (selon options activées):
- traitements liés à SharePoint / synchronisation
- opérations périodiques du plugin
- mise à jour de certains états exploités par l'interface

## Journalisation

Le plugin écrit ses logs dans le fichier `plugin-gestion.log` du répertoire de logs
GLPI (`GLPI_LOG_DIR`, par défaut `files/_log/`, configurable dans GLPI). Le fichier est
consultable directement dans GLPI : **Administration → Journaux → Fichier de log**
(lecture, filtre, téléchargement, purge).

Format d'une entrée : `[NIVEAU] [contexte] message`, avec niveau **`WARN` ou `ERROR`
uniquement**.

> Le niveau `INFO` est **désactivé** : `PluginGestionLogger::info()` ne écrit plus rien.
> Le fichier se remplissait de lignes de fonctionnement normal — mails envoyés, bons
> signés — qui noyaient les seules lignes qu'on vient y chercher. Les messages
> correspondants restent affichés **à l'écran** (`Session::addMessageAfterRedirect`) : c'est
> le fichier qu'on allège, pas le retour à l'utilisateur. Pour rétablir la trace complète,
> décommenter l'appel dans `inc/logger.class.php`.

Contextes utilisés :
- `signature`, `signature-combinee`, `signature-groupee` : flux de signature BL / Rapport+BL.
  Une ligne par bon en échec, avec sa **source** (SharePoint / Sage / Local), sa **référence**
  et la cause — un « signature impossible » anonyme ne permettait pas de savoir lequel des
  bons du ticket avait échoué.
- `mail` : échecs de transport.
- `resend-mail` : renvoi d'un document signé depuis l'onglet BL.
- `scanner` : recherche/vérification BL (Sage).
- `api:<endpoint>.php` : toute réponse en erreur (HTTP >= 400) des endpoints `public/api/`.
- `csrf-diag` : anomalies de token CSRF sur `front/traitement*.php` uniquement (token
  absent/vide/inconnu, avec content-length et liste des champs reçus — typiquement un
  POST dépassant `post_max_size`, vidé par PHP). Aucune ligne en fonctionnement normal.

Point d'entrée du code : `inc/logger.class.php` (`PluginGestionLogger::info/warning/error`),
qui s'appuie sur `Toolbox::logInFile()` — le réglage GLPI « Journaux dans les fichiers »
(`use_log_in_files`) est donc respecté. Ne jamais journaliser de secrets (tokens, mots de passe).

## Note importante (versions > 1.7.0)

Pour les versions **supérieures à 1.7.0**, il y a **abandon de la page web `device_sign.php`**. Elle est remplacée par les **APIs** : il n'y a donc plus de page avec token, uniquement des connexions API. Une **application de remplacement pour iOS** utilisant les APIs est disponible. Si vous avez besoin d'une page web, il faut la développer via les APIs fournies. Même principe pour une **application Android** : l'usage des APIs laisse plus de possibilités de création derrière.

## Architecture (résumé court)

- `front/` porte les workflows utilisateurs/admin (configuration, recherche BL, traitement, documentation API).
- `ajax/` gère les interactions UI dynamiques (recherche, formulaires rapides, contexte de signature, polling côté interface).
- `public/api/` expose les flux pour applications externes (BL, combiné, appareils).
- `inc/` centralise configuration, logique métier, intégration et hooks GLPI.
- Le plugin sépare la préparation du document, la collecte de signature et la finalisation (stockage/envoi/intégration).

## Vérifications rapides après mise à jour

- Tester la configuration et la sauvegarde des sources (`Local`, `SharePoint`, `Sage`).
- Tester une recherche BL sur chaque source activée.
- Tester la prévisualisation PDF puis la signature.
- Tester `traitement.php` (et `traitement_combined.php` si `rp` est utilisé).
- Tester `front/api_docs.php` et un flux API `bl_prepare -> bl_sign`.
- Tester les endpoints `device_*` si vous utilisez la signature déportée.
- Vérifier le cron `GestionPdf` et les logs GLPI/PHP/web.
