<?php
/**
 * PluginGestionMail — envoi des mails du plugin (PDF signés, ZenDoc, facture scannée, renvoi d'un bon).
 *
 * MailSend() vivait dans l'ancienne classe SharePoint, abandonnée : elle est reprise ici telle quelle, sans
 * autre changement que son adresse. Sans pièce jointe, le mail passe par la file d'attente de GLPI
 * (PluginGestionMailqueue) ; avec pièce jointe, il part en direct.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginGestionMail
{
    public function MailSend($EMAIL, $gabarit_id, $outputPath = NULL, $message = NULL, $id_survey = NULL, $tracker = NULL, $url = NULL, $fileName = NULL, $SubjectMail = NULL, $BodyMail = NULL) {
        global $DB, $CFG_GLPI;

    // --- Parsing + validation des emails en base --- #GLPI11#
    if (!function_exists('parse_emails')) {
        function parse_emails($emails): array {
            // $emails peut être une chaîne "a@x, b@y; c@z" ou un array
            $items = is_array($emails)
                ? $emails
                : preg_split('/[,\s;]+/u', (string)$emails, -1, PREG_SPLIT_NO_EMPTY);

            $valid = [];
            foreach ($items as $e) {
                $e = trim((string)$e);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $valid[strtolower($e)] = $e; // dédoublonnage case-insensitive
                }
            }
            return array_values($valid);
        }
    }

    // On nettoie et valide la liste
    $EMAILS = parse_emails($EMAIL /* <- ta colonne BDD telle quelle */);
    if (!$EMAILS) {
        if (class_exists('PluginGestionLogger')) {
            $raw = is_array($EMAIL) ? implode(',', $EMAIL) : (string)$EMAIL;
            PluginGestionLogger::warning('mail', 'Aucune adresse email valide dans "' . $raw . '"');
        }
        throw new InvalidArgumentException("Aucune adresse email valide trouvée.");
    }

    // Premier mail = TO, les suivants = CC
    $to = array_shift($EMAILS); // retire le premier et le retourne
    $cc = $EMAILS;              // reste du tableau (possiblement vide)

        // --- Balises ---
        $Balises = [
            ['Balise' => '##gestion.id##',      'Value' => (string)$id_survey],
            ['Balise' => '##gestion.tracker##', 'Value' => (string)$tracker],
            ['Balise' => '##gestion.url##',     'Value' => $url && $fileName ? "<a href=\"{$url}\">{$fileName}</a>" : ''],
        ];

        if (!function_exists('balise')) {
            function balise($corps, $Balises) {
                if ($corps === null) return '';
                if (!isset($Balises) || !is_iterable($Balises)) return (string)$corps;
                foreach ($Balises as $b) {
                    $tag = isset($b['Balise']) ? (string)$b['Balise'] : '';
                    if ($tag === '') continue;
                    $val = array_key_exists('Value', $b) ? (string)$b['Value'] : '';
                    $corps = str_replace($tag, $val, (string)$corps);
                }
                return $corps;
            }
        }

        if (!function_exists('normalize_eols')) {
            function normalize_eols(string $s): string {
                $s = str_replace("\0", '', $s);
                return preg_replace("/\r\n|\r|\n/u", "\r\n", $s);
            }
        }

        // --- Lecture gabarit notification (avec fallback de langue) ---
        $BodyHtml = $BodyText = $Subject = '';
        $curLang  = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'fr_FR');
        $langs    = array_values(array_unique([$curLang, substr($curLang, 0, 2), '']));
        $order    = new \Glpi\DBAL\QueryExpression("FIELD(language,'" . implode("','", array_map('addslashes', $langs)) . "')");

        if ((int)$gabarit_id > 0) {
            $itTpl = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html', 'language'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => [
                    'notificationtemplates_id' => (int)$gabarit_id,
                    'language'                 => $langs  // IN (...)
                ],
                'ORDER'  => [$order],
                'LIMIT'  => 1
            ])->current();

            if (!is_array($itTpl)) {
                // dernier recours sans filtre de langue
                $itTpl = $DB->request([
                    'SELECT' => ['subject', 'content_text', 'content_html', 'language'],
                    'FROM'   => 'glpi_notificationtemplatetranslations',
                    'WHERE'  => ['notificationtemplates_id' => (int)$gabarit_id],
                    'LIMIT'  => 1
                ])->current();
            }

            if (is_array($itTpl)) {
                $Subject  = (string)($itTpl['subject'] ?? '');
                $BodyText = isset($itTpl['content_text']) ? html_entity_decode((string)$itTpl['content_text'], ENT_QUOTES, 'UTF-8') : '';
                $BodyHtml = isset($itTpl['content_html']) ? html_entity_decode((string)$itTpl['content_html'], ENT_QUOTES, 'UTF-8') : '';
            }
        }elseif($SubjectMail != NULL && $BodyMail != NULL){
            $Subject  = $SubjectMail ?? '';
            $BodyText = $BodyMail ?? '';
            $BodyHtml = $BodyMail ?? '';
        }

        // --- Footer signature ---
        $footerValue = '';
        $rowCfg = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => ['name' => 'mailing_signature'],
            'LIMIT'  => 1
        ])->current();
        if (is_array($rowCfg) && !empty($rowCfg['value'])) {
            $footerValue = html_entity_decode((string)$rowCfg['value'], ENT_QUOTES, 'UTF-8');
        }

        // From (sécurisé: nom non nul)
        $fromEmail = !empty($CFG_GLPI['from_email'])
            ? (string)$CFG_GLPI['from_email']
            : (!empty($CFG_GLPI['admin_email']) ? (string)$CFG_GLPI['admin_email'] : 'no-reply@localhost');

        $fromName = $CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? null;
        $fromName = (is_string($fromName) && $fromName !== '') ? $fromName : 'GLPI';

        // Pièce jointe optionnelle (vérif + taille)
        $pdfTooLarge = false;
        $pdfSizeMB = 0;
        $attachPath = null;
        if (!empty($outputPath) && is_string($outputPath) && file_exists($outputPath)) {
            $size = filesize($outputPath);
            if ($size !== false && $size > 15 * 1024 * 1024) {
                $pdfTooLarge = true;
                $pdfSizeMB = round($size / 1024 / 1024, 1);
                $Subject = "⚠️ " . ($Subject ?: "Notification GLPI");
            } else {
                $attachPath = $outputPath;
            }
        }

        // Sujet / corps
        $Subject   = is_string($Subject)   ? $Subject   : '';
        $BodyHtml  = is_string($BodyHtml)  ? $BodyHtml  : '';
        $BodyText  = is_string($BodyText)  ? $BodyText  : '';
        $footerStr = is_string($footerValue) ? $footerValue : '';

        // Ajouter un message d'avertissement dans le corps si PDF trop volumineux
        if ($pdfTooLarge) {
            $warningMsg = "<br><br><div style='padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#856404;'>"
                . "<strong>⚠️ Le fichier PDF n'a pas pu être joint à ce mail</strong> (taille : {$pdfSizeMB} Mo, limite : 15 Mo).<br>"
                . "Veuillez le récupérer directement depuis GLPI."
                . "</div>";
            $BodyHtml .= $warningMsg;
            $BodyText .= "\r\n\r\n⚠️ Le fichier PDF n'a pas pu être joint (taille : {$pdfSizeMB} Mo, limite : 15 Mo). Veuillez le récupérer depuis GLPI.";
        }

        $mailSubject = $Subject !== '' ? balise($Subject, $Balises) : '';
        $mailHtml    = normalize_eols(balise($BodyHtml, $Balises)) . ($footerStr ? "<br>".$footerStr : "");
        $mailText    = normalize_eols(balise($BodyText, $Balises)) . ($footerStr ? "\r\n".strip_tags($footerStr) : "");

        /*
         * Sans pièce jointe : file d'attente des notifications de GLPI, envoi immédiat comme avant ; en cas d'échec le
         * mail reste en file et GLPI le renvoie (tâche « queuednotification »). Une ligne de file par destinataire :
         * les copies (cc) reçoivent le même mail, chacune en destinataire principal.
         * Avec pièce jointe (BL signé, facture de la tablette, PDF fusionné, ZenDoc) : envoi direct inchangé — la file
         * de GLPI ne sait joindre que des documents GLPI, et ces fichiers n'en sont pas.
         */
        $errorInfo = '';
        $queued    = 0;
        if ($attachPath === null) {
            $result = PluginGestionMailqueue::send([
                'itemtype'    => Entity::class,
                'items_id'    => 0,
                'entities_id' => 0,
                'event'       => 'plugin_gestion_mail',
                'subject'     => $mailSubject,
                'text'        => $mailText,
                'html'        => $mailHtml,
                'to'          => array_map(static fn(string $address): array => [$address, ''], array_merge([$to], $cc)),
                'from'        => [$fromEmail, $fromName],
            ], true);
            // Parti pour tous les destinataires (file ou repli direct) ; $queued = mails restés en file, que GLPI renverra.
            $queued    = count($result['pending']);
            $ok        = $result['sent'] === count(array_merge([$to], $cc));
            $errorInfo = implode(' ; ', array_unique($result['errors']));
        } else {
            $mmail = new GLPIMailer();
            $mmail->addCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
            $emailObj = $mmail->getEmail();
            $emailObj->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName));
            $emailObj->to($to);
            if (!empty($cc)) {
                $emailObj->cc(...$cc);
            }
            $emailObj->attachFromPath($attachPath);
            if ($mailSubject !== '') {
                $mmail->Subject = $mailSubject;
            }
            $mmail->Body    = $mailHtml;
            $mmail->AltBody = $mailText;
            $ok        = $mmail->send();
            $errorInfo = $ok ? '' : (string) $mmail->ErrorInfo;
            // Nettoyage adresses (facultatif ici)
            $mmail->ClearAddresses();
        }

        // Messages
        if (class_exists('PluginGestionLogger')) {
            $dest = $to . (!empty($cc) ? ' (cc: ' . implode(', ', $cc) . ')' : '');
            if ($ok) {
                $pj = '';
                if ($pdfTooLarge) {
                    $pj = ' SANS PJ (PDF ' . $pdfSizeMB . ' Mo > 15 Mo)';
                } elseif ($attachPath !== null) {
                    $pj = ' avec PJ ' . basename($attachPath);
                }
                PluginGestionLogger::info('mail', 'Mail envoye a ' . $dest . $pj . ($attachPath === null ? ' (file d\'attente GLPI)' : ''));
            } elseif ($queued > 0) {
                PluginGestionLogger::warning('mail', 'Mail a ' . $dest . ' pas encore parti, reste en file d\'attente GLPI (nouvel essai automatique) : ' . $errorInfo);
            } else {
                PluginGestionLogger::error('mail', 'Echec envoi mail a ' . $dest . ' : ' . $errorInfo);
            }
        }
        if (!$ok && $queued > 0) {
            Session::addMessageAfterRedirect(__("Le mail n'est pas encore parti : il reste en file d'attente et GLPI le renverra automatiquement. ", 'gestion') . htmlescape($errorInfo), true, WARNING);
        } elseif (!$ok) {
            Session::addMessageAfterRedirect(__("Erreur lors de l'envoi du mail : ", 'gestion') . $errorInfo, true, ERROR);
        } else {
            // Message conditionnel comme avant
            $config = PluginGestionConfig::getInstance();
            if ((int)$gabarit_id === (int)($config->fields['gabarit'] ?? 0) && $message !== NULL) {
                Session::addMessageAfterRedirect(__($message, 'gestion'), true, INFO);
            }
        }

        // Retourne l'état réel d'envoi (true = remis au transport, ou envoyé depuis la file). Seuls les envois avec
        // pièce jointe (envoi direct, inchangé) en font usage : ZenDoc (traitement.php), facture de la tablette.
        return $ok;
    }
}
