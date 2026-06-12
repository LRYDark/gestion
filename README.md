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
