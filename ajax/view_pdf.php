<?php
include('../../../inc/includes.php');

// view_pdf.php
if (!isset($_GET['id'])) {
    http_response_code(400);
    exit("Missing document ID");
}

$id = $_GET['id'];

// Inclure ton script ou autoloader ici
require_once PLUGIN_GESTION_DIR.'/front/SageApi.php';

// streamDocument() doit simplement afficher le PDF avec les bons headers
streamDocument($id);
