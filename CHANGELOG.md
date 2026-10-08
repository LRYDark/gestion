# Journal des changements

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
