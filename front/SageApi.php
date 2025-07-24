<?php
include ('../../../inc/includes.php');
require_once('../vendor/autoload.php'); // Utiliser le chargement automatique de Composer

use Smalot\PdfParser\Parser;

/**
 * 1) Télécharge le PDF et renvoie les infos extraites (BL, date, tracker, client).
 */
function parseDocument(string $docId): array
{
    $config         = new PluginGestionConfig();
    $apiKey       = $config->SageToken();
    $url = "https://sageapi.jcd-groupe.fr/api/v1/document/$docId";

    // --- Download PDF (en mémoire)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
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
        throw new \RuntimeException("Erreur cURL: $err");
    }
    if ($status >= 400) {
        throw new \RuntimeException("Erreur API ($status): $pdfBytes");
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

    // Tracker (ex : "PE")
    if (preg_match('/Tracker\s*:\s*([A-Z0-9-]+)/ui', $text, $m)) {
        $out['tracker'] = trim($m[1]);
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
 */
function downloadDocument(string $docId, string $destinationFile): string
{
    $config         = new PluginGestionConfig();
    $apiKey       = $config->SageToken();
    $url = "https://sageapi.jcd-groupe.fr/api/v1/document/$docId";

    $fp = fopen($destinationFile, 'wb');
    if ($fp === false) {
        throw new RuntimeException("Impossible d'ouvrir le fichier en écriture : $destinationFile");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
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

    if ($ok === false) {
        @unlink($destinationFile);
        throw new RuntimeException("Erreur cURL: $err");
    }

    if ($status >= 400) {
        $body = file_get_contents($destinationFile);
        @unlink($destinationFile);
        throw new RuntimeException("Erreur API ($status): $body");
    }

    return $destinationFile;
}

/**
 * Affiche (stream) le PDF dans le navigateur.
 * AUCUNE sortie ne doit être envoyée avant l'appel (espaces, BOM, var_dump, etc.).
 */
function streamDocument(string $docId, string $filename = null): void
{
    $config         = new PluginGestionConfig();
    $apiKey       = $config->SageToken();
    $url = "https://sageapi.jcd-groupe.fr/api/v1/document/$docId";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
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
    $config         = new PluginGestionConfig();
    $apiKey       = $config->SageToken();
    $url = "https://sageapi.jcd-groupe.fr/api/v1/document/$docId";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,   // on doit le mettre pour récupérer les headers
        CURLOPT_NOBODY         => true,   // ne récupère pas le corps (économie de bande passante)
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
        throw new RuntimeException("Erreur cURL: $err");
    }

    return $httpStatus === 200;
}








// ---- Exemple d’utilisation ----
try {
    $status = null;
    if (documentExiste('BL196299', $status)) {
        echo "OK (HTTP $status)";
    } else {
        echo "KO (HTTP $status)";
    }
} catch (Throwable $e) {
    echo "Erreur: " . $e->getMessage();
}


// ---------------------- Exemple d’utilisation ----------------------
/*try {
 // idéalement: getenv('SAGE_API_KEY');
$docId    = 'BL196299';

    // 1) Parser les infos
    $fields = parseDocument($docId);
    print_r($fields);

    // 2) Télécharger et sauvegarder
    //$savedPath = downloadDocument($docId, __DIR__ . "/$docId.pdf");
    //echo "PDF sauvegardé dans : $savedPath\n";

    // 2) Afficher le PDF
    streamDocument('BL196299');

} catch (Throwable $e) 
    echo "Erreur : " . $e->getMessage();
}*/