# Journal des changements

## 1.8.4 — 2026-11-02

**À faire après la mise à jour : cliquer « Mettre à jour » dans Configuration → Plugins**, puis vider le cache
de GLPI (`php bin/console cache:clear`, en tant qu'utilisateur du serveur web).

- **Correctif : fiches ticket et utilisateur cassées** (« syntax error, unexpected token "public" » dans un
  template Twig compilé). Le dossier `vendor/` embarquait l'outillage de développement (`glpi-project/tools`,
  donc Twig 3.23, Symfony, psr/container), chargé AVANT celui de GLPI : quand le cache des templates se
  reconstruisait pendant une requête du plugin, GLPI compilait avec un Twig mélangé et gardait le résultat
  cassé pour tout le monde. `vendor/` ne contient plus que ce que le plugin utilise (setasign/fpdf, fpdi,
  fpdi_pdf-parser, smalot/pdfparser), et son autoload passe désormais APRÈS celui de GLPI
  (`prepend-autoloader: false`).
- **Dépendances à jour** : FPDF 1.9.0, FPDI 2.6.9 (corrige CVE-2026-45802, déni de service sur un PDF piégé),
  pdfparser 2.13.1. `fpdi_pdf-parser` reste en 2.1.4, dernière version couverte par la licence setasign.
- **SharePoint abandonné** : recherche des bons dans SharePoint (Microsoft Graph), dépôt des PDF signés,
  tâche cron « GestionPdf », mail « tracker » et extraction d'entité qui en dépendaient. Restent Sage et le
  dossier local, inchangés. La migration :
  - bascule un mode de recherche SharePoint vers Sage (ou Local si Sage est inactif) ;
  - supprime les réglages Azure (TenantID, ClientID, ClientSecret…) et ceux de la tâche cron ;
  - renomme `SharePointLinkDisplay` en `PdfPreview` (« Prévisualisation du PDF avant signature », valeur
    conservée) ;
  - supprime l'action automatique « GestionPdf » et le gabarit « Gestion Mail PDF (Tracker) » s'il ne sert
    à aucune notification.
  Les bons déjà enregistrés depuis SharePoint gardent leur lien ; les signer passe par la page de
  remplacement, comme pour toute source introuvable.
- L'envoi des mails (`MailSend`) passe dans sa propre classe, `PluginGestionMail` : il vivait dans la classe
  SharePoint. Fonctionnement inchangé.
- Mails texte sans pièce jointe : plus d'avertissement « Undefined array key
  "attach_documents_to_notifications_for_anonymous" » quand ce réglage manque dans la base de GLPI.
- Supprimé : `inc/entity.class.php`, vieille copie de la classe `PluginRtEntity` du plugin RT. Incluse à chaque
  installation ou mise à jour, elle pouvait provoquer « Cannot declare class PluginRtEntity » ou remplacer la
  vraie classe de RT.
- Mise à jour lancée en console (`bin/console plugin:install`) : la version était lue dans la session, absente
  en console — 14 avertissements PHP et anciennes migrations sautées. Lue désormais dans la constante du plugin.
- Anciennes migrations rendues sûres : elles ajoutaient leurs colonnes en bloc dès qu'une seule manquait, et
  auraient échoué sur « Duplicate column » une fois les colonnes SharePoint supprimées.
- Dépôt : `auth.json` (jeton de licence setasign), `.vs/` et un `composer.phar` ne sont plus suivis par git.
- CI GitHub : syntaxe sur PHP 8.2 à 8.5, installation réelle dans GLPI 11.0.12 (neuve et mise à jour depuis
  la release précédente), chargement de chaque classe, parcours des pages et onglets, audit des dépendances.
- **Jeton Sage qui « sautait » après une mise à jour** : il était chiffré avec une clé propre au plugin, lue en dur
  dans `<GLPI>/config/` — pas forcément le dossier de configuration de GLPI — et régénérée sans prévenir quand elle
  manquait ; une clé changée par GLPI le rendait aussi illisible, et enregistrer la configuration l'effaçait.
  Il est désormais chiffré avec la clé de GLPI (`GLPIKey`), là où GLPI la range, et déclaré à GLPI
  (`secured_fields`), qui le rechiffre lui-même s'il change de clé. La mise à jour réécrit l'ancienne valeur au
  nouveau format ; une valeur illisible est signalée sous le champ, et un champ laissé vide ne l'efface plus.
- **Affichage** : le message « Élément modifié » après l'enregistrement de la configuration affichait du code HTML
  (`<span class="d-flex …">`) ; et l'indicateur de chargement apparaissait sur le bouton « Bannir » du premier
  appareil au lieu d'« Enregistrer ». Les actions des appareils ne sont plus des boutons d'envoi du formulaire :
  Entrée dans un champ ne peut plus bannir un appareil.

## 1.8.3 — 2026-10-06

- **Garanties d'envoi** : si la file d'attente est indisponible (écriture refusée, erreur de base), le mail part en direct comme avant — la file n'empêche jamais un envoi. Un mail envoyé aussitôt est mis en file avec une heure d'envoi décalée de 5 minutes, pour que la tâche « queuednotification » ne l'envoie pas une seconde fois pendant l'envoi immédiat.
- **Mails sans pièce jointe envoyés par la file d'attente des notifications de GLPI**, aussitôt, comme avant :
  avis au tracker de la tâche planifiée, et mails dont le PDF dépasse 15 Mo (déjà envoyés sans le PDF, avec
  l'avertissement). En cas d'échec, le mail reste en file et GLPI le renvoie automatiquement (avertissement à
  l'écran, journal du plugin). Une ligne de file par destinataire : les copies (cc) reçoivent le même mail, chacune
  en destinataire principal. Visibles dans Administration → File d'attente des notifications.
- **Mails avec pièce jointe inchangés, en envoi direct** : BL signé, facture scannée par la tablette, PDF fusionné,
  ZenDoc. La file de GLPI ne sait joindre que des documents GLPI, et ces fichiers n'en sont pas (SharePoint,
  fichiers temporaires, aucun stockage GLPI pour la tablette).
- Aucune migration de base.
