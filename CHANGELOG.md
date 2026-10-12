# Journal des changements

## 1.9.0 — 2026-10-12

Première version pour GLPI 12 (12.0.x). La série 1.8.x reste celle de GLPI 11.

- **Compatible GLPI 12 uniquement.** GLPI 12 déclare le type des propriétés de ses classes (`$rightname`,
  `$dohistory`…) ; une classe du plugin qui les redéfinit doit porter le même type, ce que GLPI 11 refuse. Les deux
  séries ne sont donc pas interchangeables.
- API retirées par GLPI 12 remplacées : `Plugin::getWebDir()`, `Html::entities_deep()` / `cleanInputText()`,
  `Html::display*Error()`, `Toolbox::addslashes_deep()`, `$DB->doQueryOrDie()`, `$DB->affectedRows()`.
- **File d'attente des signatures hors-ligne sans jeton** (`gestion_outbox.js`, jumeau de celui de RP) : plus de
  jeton à joindre, à renouveler ni à voir expirer. `ajax/csrf.php` est supprimé, ainsi que le diagnostic CSRF
  temporaire de `setup.php` (il aurait journalisé chaque signature comme une anomalie).
- **API du plugin et API v3 de GLPI** : les écrans et la documentation pointent vers `/api.php/v3.0/token` (GLPI 12 ne
  sert plus la v2.2). Les endpoints de lecture (`device_poll_v2`, `device_checkin`, `bl_prepare`, `bl_tracker`,
  `ticket_bls`) ouvrent une session temporaire, comme l'API de GLPI : GLPI 12 enregistre sinon une connexion par
  appel dans l'historique de l'utilisateur. Les endpoints de signature gardent une session enregistrée, nécessaire à
  la page de traitement qu'ils rejouent.
- Adresse du client de l'API lue par `IPUtilities::getClientIP()` (proxys de confiance de GLPI pris en compte).
- Documents créés par le plugin : la taille du fichier est renseignée (nouvelle colonne de GLPI 12).
- Corrections : `front/profile.form.php` contrôlait un droit inexistant ; `view_pdf.php` renvoyait toujours vers
  `/glpi` pour la connexion, même quand GLPI est installé ailleurs.
- **Plus de jeton CSRF.** GLPI 12 contrôle l'origine de la requête (en-têtes `Sec-Fetch-Site` / `Origin`) : les
  champs `_glpi_csrf_token`, les en-têtes `X-Glpi-Csrf-Token` et les appels à `Session::checkCSRF()` /
  `getNewCSRFToken()` sont retirés.
- Inclusion de `inc/includes.php` retirée (obsolète).
- **Corrigé : onglet de configuration et formulaire de signature en erreur 500.** Le dossier `vendor/` du plugin
  embarquait Twig 3.23 et `symfony/console`, tirés par l'outillage de développement (`glpi-project/tools`) : chargés
  avant ceux de GLPI 12 (Twig 3.30), ils faisaient échouer les gabarits de GLPI lui-même. L'outillage passe en
  `require-dev` ; `vendor/` ne garde que les bibliothèques PDF réellement utilisées (FPDF, FPDI, pdfparser).
- Aucune migration de base.

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
