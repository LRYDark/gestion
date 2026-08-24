<?php
// --- Chargement autonome de l’autoloader Composer --------------------------
$vendor = dirname(__DIR__) . '/vendor/autoload.php';  //  __DIR__ = .../gestion/front
if (file_exists($vendor)) {
    require_once $vendor;
} else {
    /*throw new RuntimeException(
        "[Plugin Gestion] vendor/autoload.php introuvable : exécutez `composer install` dans plugins/gestion"
    );*/
    Session::addMessageAfterRedirect(__("[Plugin Gestion] vendor/autoload.php introuvable : exécutez `composer install` dans plugins/gestion"), true, ERROR);
}
// ---------------------------------------------------------------------------

use Smalot\PdfParser\Parser;

/**
 * Message lisible pour une réponse Sage en erreur.
 *
 * « Erreur API (404): Not Found » décrivait le transport, pas la situation. Or
 * un 404 de Sage n'est pas une panne : c'est un fait métier — le document n'est
 * pas, ou n'est plus, dans Sage. Le technicien qui le lit doit savoir s'il doit
 * vérifier un numéro, prévenir un administrateur, ou simplement réessayer.
 *
 * Le corps de la réponse n'est repris que pour les codes non reconnus : sur un
 * 404 il ne dit rien de plus que « Not Found ».
 *
 * @param int    $status code HTTP renvoyé par Sage
 * @param string $docId  référence demandée (numéro de BL)
 * @param string $body   corps de la réponse, éventuellement vide
 */
function sageErrorMessage(int $status, string $docId, string $body = ''): string
{
    $ref = trim($docId) !== '' ? $docId : '?';

    if ($status === 404) {
        return sprintf(
            __("Le document %s n'existe pas (ou plus) dans Sage. Vérifiez le numéro, ou il a été supprimé.", 'gestion'),
            $ref
        );
    }
    if ($status === 401 || $status === 403) {
        return sprintf(
            __("Sage refuse l'accès au document %s : clé API invalide ou expirée.", 'gestion'),
            $ref
        );
    }
    if ($status >= 500) {
        return sprintf(
            __('Sage est momentanément indisponible (erreur %1$d). Document %2$s : réessayez dans un instant.', 'gestion'),
            $status,
            $ref
        );
    }

    $body = trim($body);
    return sprintf(
        __('Sage a répondu une erreur %1$d pour le document %2$s.', 'gestion'),
        $status,
        $ref
    ) . ($body !== '' ? ' ' . $body : '');
}

/**
 * Nettoie un nom de fichier BL pour supprimer les caractères interdits.
 *  - ' → -
 *  - Supprime < > : " / \ | ? *
 *  - Supprime le . en fin de nom
 *  - Si la partie client est vide après nettoyage, renvoie juste la base
 */
function sanitizeBLFilename(string $name): string
{
    // Remplacer les apostrophes par un tiret
    $name = str_replace("'", '-', $name);
    // Supprimer les caractères interdits dans un nom de fichier
    $name = preg_replace('/[<>:"\/\\\\|?*]/', '', $name);
    // Supprimer les points en fin de nom
    $name = rtrim($name, '.');
    // Supprimer les underscores en fin de nom (si client était vide après nettoyage)
    $name = rtrim($name, '_');
    return $name;
}

/**
 * 1) Télécharge le PDF et renvoie les infos extraites (BL, date, tracker, client).
 */
function parseDocument(string $docId): array
{
    $config     = new PluginGestionConfig();
    $apiKey     = $config->SageToken();
    $apiUrl     = $config->SageUrlApi();
    $url        = $apiUrl.$docId;

    // --- Download PDF (en mémoire)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Accept: application/pdf'
        ]
    ]);

    $pdfBytes = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($pdfBytes === false) {
        throw new \RuntimeException(
            sprintf(__('Sage est injoignable : %s', 'gestion'), $err !== '' ? $err : __('pas de réponse', 'gestion'))
        );
    }
    if ($status >= 400) {
        throw new \RuntimeException(sageErrorMessage($status, $docId, (string)$pdfBytes));
    }

    // --- Parse PDF
    $parser   = new Parser();
    $document = $parser->parseContent($pdfBytes);
    $rawText  = $document->getText();

    // --- Extraction des champs "classiques"
    $text = preg_replace('/[ \t]+/', ' ', str_replace("\r", '', $rawText));

    $out = [
        'doc_id'    => $docId,
        'bl'        => null,
        'date_raw'  => null,
        'date_iso'  => null,
        'tracker'   => null,
        'client'    => null,
        'relatedInvoiceToBL' => null,
        // 'raw_text' => $rawText, // décommente si tu veux le texte entier
    ];

    // N° du BL
    if (preg_match('/N[°º]?\s*du\s*BL\s*:\s*([A-Z0-9-]+)/ui', $text, $m)) {
        $out['bl'] = trim($m[1]);
    }

    // Date (ex: 24/07/2025)
    if (preg_match('/Date\s*:\s*([0-9]{2}\/[0-9]{2}\/[0-9]{4})/ui', $text, $m)) {
        $out['date_raw'] = $m[1];
        $dt = \DateTime::createFromFormat('d/m/Y', $m[1]);
        if ($dt instanceof \DateTime) {
            $out['date_iso'] = $dt->format('Y-m-d');
        }
    }

    // Tracker (ex : "PI + EC")
    if (preg_match('/^Tracker[ \t]*:[ \t]*([^\r\n]*)/mi', $text, $m)) {
        $out['tracker'] = trim($m[1]);
    }

    // Numéro de devis éventuel
    if (preg_match('/N[°oº]?(?:\s*de)?\s*Devis\s*:\s*([A-Z0-9-]+)/ui', $text, $m)) {
        $out['relatedInvoiceToBL'] = strtoupper(trim($m[1]));
    }

    // --- Client : on prend la 1re ligne après "BON DE LIVRAISON" qui ne contient pas de chiffre
    $lines = preg_split("/\R+/", $rawText);
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        if (preg_match('/BON\s+DE\s+LIVRAISON/i', $lines[$i])) {
            for ($j = $i + 1; $j < min($i + 8, $n); $j++) { // on regarde les ~7 lignes suivantes
                $candidate = trim($lines[$j]);
                if ($candidate === '') {
                    continue;
                }
                if (preg_match('/\d/', $candidate)) { // si la ligne contient un chiffre, c'est sûrement l'adresse
                    continue;
                }
                // optionnel : si tu veux t'assurer que c'est bien de l'uppercase :
                // if (mb_strtoupper($candidate, 'UTF-8') !== $candidate) continue;

                $out['client'] = $candidate;
                break 2;
            }
        }
    }

    // Fallback : si jamais la détection via "BON DE LIVRAISON" ne marche pas,
    // on peut tenter via "Lieu de livraison"
    if (!$out['client']) {
        if (preg_match('/Lieu\s+de\s+livraison\s*:\s*([^\R\d]+)\R/ui', $rawText, $m)) {
            $out['client'] = trim($m[1]);
        }
    }

    return $out;
}

/**
 * 2) Télécharge et sauvegarde le PDF localement, renvoie le chemin du fichier.
 *
 * ---- Une seconde tentative avant de conclure ----
 *
 * Un téléchargement qui échoue ne prouve pas que le document est absent : une
 * coupure réseau, un délai dépassé ou un Sage momentanément saturé produisent
 * le même résultat qu'une suppression. Le plugin en concluait « PDF source
 * introuvable » et refusait la signature, alors qu'un simple nouvel essai
 * aurait suffi.
 *
 * On réessaie donc, brièvement, AVANT de déclarer l'échec — sauf sur un **404**,
 * qui est une réponse claire de Sage : le document n'est pas là, insister ne
 * ferait que doubler la charge et l'attente.
 *
 * Le message et le journal ne sont émis qu'après la DERNIÈRE tentative : une
 * reprise réussie ne doit pas laisser derrière elle une erreur qui n'en est pas
 * une.
 *
 * @param string $docId           référence Sage du document
 * @param string $destinationFile fichier de destination
 * @param int    $attempts        nombre total de tentatives (1 = pas de reprise)
 * @return string le chemin de destination ; l'appelant vérifie qu'il existe
 */
function downloadDocument(string $docId, string $destinationFile, int $attempts = 2): string
{
    $config = new PluginGestionConfig();
    $apiKey = $config->SageToken();
    $apiUrl = $config->SageUrlApi();
    $url    = $apiUrl . $docId;

    $attempts = max(1, $attempts);
    $status   = 0;
    $err      = '';
    $body     = '';

    for ($try = 1; $try <= $attempts; $try++) {
        $fp = fopen($destinationFile, 'wb');
        if ($fp === false) {
            $msg = sprintf(
                __("Impossible d'ouvrir le fichier en écriture : %s", 'gestion'),
                $destinationFile
            );
            Session::addMessageAfterRedirect($msg, true, ERROR);
            if (class_exists('PluginGestionLogger')) {
                PluginGestionLogger::error('sage', $msg);
            }
            return $destinationFile;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'Accept: application/pdf'
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => false, // on gère nous-même les statuts HTTP
        ]);

        $ok     = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        // Succès : le fichier est en place, on s'arrête là.
        if ($ok !== false && $status < 400) {
            return $destinationFile;
        }

        // Le corps est lu AVANT d'effacer : il sert au message des codes non
        // reconnus, et le fichier partiel n'a plus de valeur.
        $body = (string)@file_get_contents($destinationFile);
        @unlink($destinationFile);

        // Réponse nette de Sage : le document n'existe pas. Inutile d'insister.
        if ($status === 404) {
            break;
        }

        // Incident probablement passager : on laisse respirer avant de reprendre.
        if ($try < $attempts) {
            usleep(700000);
        }
    }

    /*
     * Échec définitif. `WARNING` sur un 404 : le document absent de Sage n'est
     * pas une défaillance du plugin, c'est un état du référentiel. Le distinguer
     * évite qu'un journal d'erreurs se remplisse de BL supprimés, et que ces
     * lignes noient les vraies pannes (401, 500, réseau).
     */
    if ($status === 0 || $status >= 500 || $err !== '') {
        $msg = sprintf(
            __('Sage est injoignable après %1$d tentative(s) pour le document %2$s : %3$s', 'gestion'),
            $attempts,
            $docId,
            $err !== '' ? $err : sprintf(__('erreur %d', 'gestion'), $status)
        );
        $level = ERROR;
    } else {
        $msg   = sageErrorMessage($status, $docId, $body);
        $level = ($status === 404) ? WARNING : ERROR;
    }

    Session::addMessageAfterRedirect($msg, true, $level);
    if (class_exists('PluginGestionLogger')) {
        if ($level === WARNING) {
            PluginGestionLogger::warning('sage', $msg);
        } else {
            PluginGestionLogger::error('sage', $msg);
        }
    }

    return $destinationFile;
}

/**
 * Affiche (stream) le PDF dans le navigateur.
 * AUCUNE sortie ne doit être envoyée avant l'appel (espaces, BOM, var_dump, etc.).
 */
function streamDocument(string $docId, string $filename = null): void
{
    $config     = new PluginGestionConfig();
    $apiKey     = $config->SageToken();
    $apiUrl     = $config->SageUrlApi();
    $url        = $apiUrl.$docId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Accept: application/pdf'
        ],
    ]);

    $pdfBytes = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($pdfBytes === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur cURL : $err";
        exit;
    }

    if ($status >= 400) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $pdfBytes; // le corps d’erreur retourné par l’API
        exit;
    }

    if ($filename === null) {
        $filename = $docId . '.pdf';
    }

    // Nettoie tout buffer éventuel pour ne pas corrompre le PDF.
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));

    echo $pdfBytes;
    exit;
}

function documentExiste(string $docId, ?int &$httpStatus = null): bool
{
    $config     = new PluginGestionConfig();
    $apiKey     = $config->SageToken();
    $apiUrl     = $config->SageUrlApi();
    $url        = $apiUrl.$docId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,   // on doit le mettre pour récupérer les headers
        CURLOPT_NOBODY         => true,   // ne récupère pas le corps (économie de bande passante)
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Accept: application/pdf'
        ],
    ]);

    $res        = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err        = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        Session::addMessageAfterRedirect(
            sprintf(__('Sage est injoignable : %s', 'gestion'), $err !== '' ? $err : __('pas de réponse', 'gestion')),
            true,
            ERROR
        );
    }

    return $httpStatus === 200;
}

function Montant($doc)
{
    $montant = [
        // Valeurs de test avec virgule (laisser en chaîne pour éviter la troncature PHP)
        'TTC' => 'Fonctionnalité en cours d’intégration',
        'HT'  => 'Fonctionnalité en cours d’intégration',
    ];

    return $montant;
}
