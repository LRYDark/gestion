<?php
/**
 * Minimal tablette page: waits for a pending request, then shows a recap, then signature pad.
 * Public page (no login). Validates device_id + token from the plugin's devices table.
 */

// ---- GLPI bootstrap (NOLOGIN BEFORE includes) ----
define('GLPI_ROOT', realpath(__DIR__ . '/../../..')); // absolute to avoid Twig error
define('NOLOGIN', 1);
define('NOHEADER', 1);
define('NOTOKENRENEWAL', 1);

require GLPI_ROOT . '/inc/includes.php';

$__csrf_token = Session::getNewCSRFToken();
$_SESSION['glpicsrftoken'] = $__csrf_token;
global $DB, $CFG_GLPI;

// ---- Read & validate parameters ----
$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
$token     = isset($_GET['token']) ? trim($_GET['token']) : '';

$config = PluginGestionConfig::getInstance();
$rootdoc = rtrim($CFG_GLPI['root_doc'] ?? '/glpi', '/');

// If missing, go to login
if ($device_id === '' || $token === '' || $config->fields['RemoteSignatureOn'] == 0) {
   header('Location: ' . $rootdoc . '/front/login.php');
   exit;
}

// Validate against devices table (active rows only)
$ok = false;
$device_row = null;

// Table name used by the plugin for tablets
$table = 'glpi_plugin_gestion_signaturedevices';

// Escape using GLPI DB helper
$did = $DB->escape($device_id);
$tok = $DB->escape($token);

$sql = "SELECT id, device_id, serial, device_token, is_active
        FROM `$table`
        WHERE `is_active` = 1
          AND `device_id` = '$did'
          AND `device_token` = '$tok'
        LIMIT 1";

$res = $DB->query($sql);
if ($res && $DB->numrows($res) === 1) {
   $device_row = $DB->fetchassoc($res);
   $ok = true;
}

// If not authorized, force login
if (!$ok) {
   header('Location: ' . $rootdoc . '/front/login.php');
   exit;
}

// Base path for plugin AJAX
$plugin_base = $rootdoc . '/plugins/gestion';
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <!-- iOS plein écran -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '/glpi').'/plugins/gestion/icons/icon-192.png', ENT_QUOTES, 'UTF-8') ?>">
  <title>Signature électronique</title>
  <style>
    * { box-sizing: border-box; }
    html, body { 
      margin: 0; 
      padding: 0; 
      height: 100%; 
      background: #f8f9fa; 
      color: #2c3e50; 
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
      line-height: 1.6;
      overscroll-behavior: none;      /* évite le "pull to refresh" */
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
      touch-action: manipulation;     /* réduit les gestes parasites */
    }
    
    .header {
      position: sticky;    /* reste collé au sommet quand on scrolle */
      top: 0;
      z-index: 1000;       /* au-dessus du contenu */
      /* confort iPad avec encoches */
      padding-top: calc(16px + env(safe-area-inset-top));
      background: #ffffff;
      border-bottom: 1px solid #e9ecef;
      padding: 16px 24px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
    }
    
    .header-content {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .logo-section {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    
    .logo {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 18px;
    }
    
    /* Remplacez cette section par votre logo */
    .logo img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      border-radius: 8px;
    }
    
    .company-info h1 {
      margin: 0;
      font-size: 20px;
      font-weight: 600;
      color: #2c3e50;
    }
    
    .company-info p {
      margin: 2px 0 0 0;
      font-size: 14px;
      color: #7f8c8d;
    }
    
    .status-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      background: #e8f5e8;
      border: 1px solid #c3e6c3;
      border-radius: 20px;
      font-size: 14px;
      color: #2d5a2d;
    }
    
    .status-dot {
      width: 8px;
      height: 8px;
      background: #27ae60;
      border-radius: 50%;
    }
    
    .main-container {
      max-width: 900px;
      margin: 0 auto;
      padding: 32px 24px;
      min-height: calc(100vh - 100px);
    }
    
    .card {
      background: #ffffff;
      border: 1px solid #e9ecef;
      border-radius: 12px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
      overflow: hidden;
    }
    
    .card-header {
      padding: 24px 32px;
      background: #f8f9fa;
      border-bottom: 1px solid #e9ecef;
    }
    
    .card-title {
      margin: 0;
      font-size: 24px;
      font-weight: 600;
      color: #2c3e50;
    }
    
    .card-subtitle {
      margin: 4px 0 0 0;
      font-size: 16px;
      color: #6c757d;
    }
    
    .card-content {
      padding: 32px;
    }
    
    /* Styles pour les différentes étapes */
    .step { display: none; }
    .step.show { display: block; }
    
    /* Styles d'attente */
    .waiting-content {
      text-align: center;
      padding: 48px 24px;
    }
    
    .waiting-icon {
      width: 64px;
      height: 64px;
      margin: 0 auto 24px;
      background: #3498db;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
    }
    
    .waiting-title {
      font-size: 20px;
      font-weight: 600;
      margin-bottom: 12px;
      color: #2c3e50;
    }
    
    .waiting-subtitle {
      color: #6c757d;
      margin-bottom: 24px;
    }
    
    .device-badge {
      display: inline-flex;
      align-items: center;
      padding: 8px 16px;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      font-size: 14px;
      color: #495057;
    }
    
    /* Styles récapitulatif */
    .section {
      margin-bottom: 24px;
      padding: 20px;
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 8px;
    }
    
    .section-title {
      font-size: 16px;
      font-weight: 600;
      color: #2c3e50;
      margin: 0 0 12px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .section-content {
      color: #495057;
      line-height: 1.5;
    }
    
    .pdf-viewer {
      width: 100%;
      height: 300px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      background: #ffffff;
      margin-top: 12px;
    }
    
    @media (min-width: 768px) {
      .pdf-viewer { height: 400px; }
    }
    
    @media (min-width: 1024px) {
      .pdf-viewer { height: 500px; }
    }
    
    .external-link {
      color: #3498db;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      margin-top: 8px;
    }
    
    .external-link:hover {
      text-decoration: underline;
    }
    
    /* Styles signature */
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      font-size: 14px;
      font-weight: 500;
      color: #495057;
      margin-bottom: 6px;
    }
    
    .form-input {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #ced4da;
      border-radius: 6px;
      background: #ffffff;
      color: #495057;
      font-size: 16px;
      transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #3498db;
      box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }
    
    .signature-area {
      margin: 24px 0;
    }
    
    .signature-canvas {
      width: 100%;
      height: 200px;
      border: 2px dashed #ced4da;
      border-radius: 8px;
      background: #ffffff;
      touch-action: none;
      cursor: crosshair;
    }
    
    .signature-canvas:focus {
      border-color: #3498db;
      outline: none;
    }
    
    .signature-hint {
      text-align: center;
      margin-top: 12px;
      color: #6c757d;
      font-size: 14px;
    }
    
    /* Boutons */
    .button-group {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 24px;
      flex-wrap: wrap;
    }
    
    .btn {
      appearance: none;
      border: none;
      border-radius: 6px;
      padding: 12px 24px;
      font-weight: 500;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.15s ease-in-out;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    .btn-primary {
      background: #3498db;
      color: white;
      border: 1px solid #3498db;
    }
    
    .btn-primary:hover:not(:disabled) {
      background: #2980b9;
      border-color: #2980b9;
    }
    
    .btn-secondary {
      background: #6c757d;
      color: white;
      border: 1px solid #6c757d;
    }
    
    .btn-secondary:hover:not(:disabled) {
      background: #5a6268;
      border-color: #5a6268;
    }
    
    .btn-outline {
      background: transparent;
      color: #6c757d;
      border: 1px solid #ced4da;
    }
    
    .btn-outline:hover:not(:disabled) {
      background: #f8f9fa;
      border-color: #adb5bd;
    }
    
    .btn-danger {
      background: #e74c3c;
      color: white;
      border: 1px solid #e74c3c;
    }
    
    .btn-danger:hover:not(:disabled) {
      background: #c0392b;
      border-color: #c0392b;
    }
    
    /* Messages */
    .alert {
      padding: 16px;
      border-radius: 6px;
      margin: 16px 0;
      font-size: 14px;
    }
    
    .alert-success {
      background: #d4edda;
      border: 1px solid #c3e6cb;
      color: #155724;
    }
    
    .alert-error {
      background: #f8d7da;
      border: 1px solid #f5c6cb;
      color: #721c24;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .header-content {
        flex-direction: column;
        gap: 16px;
        text-align: center;
      }
      
      .main-container {
        padding: 16px;
      }
      
      .card-content {
        padding: 20px;
      }
      
      .button-group {
        justify-content: stretch;
      }
      
      .btn {
        flex: 1;
        justify-content: center;
      }
    }
    
    /* Styles pour l'horloge */
    .time-display {
      font-family: 'Courier New', monospace;
      font-size: 14px;
      color: #6c757d;
    }

    .is-standalone .external-link { display: none !important; }
  </style>
</head>

<script>
(function(){
  // Détecte l'ouverture depuis l'icône d'accueil (sans barre d'adresse)
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                   || ('standalone' in navigator && navigator.standalone);
  if (standalone) document.documentElement.classList.add('is-standalone');

  // (optionnel) éviter le double-tap zoom
  var last = 0;
  document.addEventListener('touchend', function(e){
    var now = Date.now();
    if (now - last <= 350) e.preventDefault();
    last = now;
  }, {passive:false});
})();
</script>

<body>
  <header class="header">
    <div class="header-content">
      <div class="logo-section">
        <div class="logo">
          <img src="JCD_logo.png" alt="Logo JCD groupe">
        </div>
        <div class="company-info">
          <h1>JCD Groupe</h1>
          <p>Signature électronique</p>
        </div>
      </div>
      <div class="status-indicator">
        <div class="status-dot"></div>
        <span>Terminal actif</span>
      </div>
    </div>
  </header>

  <div class="main-container">
    <div class="card">
        <?php
          $plugin_base = $rootdoc . '/plugins/gestion';

          // >>> AJOUT : lecture des éléments à afficher pendant l'attente
          $baseitems = [];
          try {
            // Vous pouvez limiter/ordonner si besoin (ex: 'LIMIT' => 100)
            foreach ($DB->request([
                'SELECT' => ['id','description','info'],
                'FROM'   => 'glpi_plugin_gestion_baseitems',
                'ORDER'  => 'id ASC',
            ]) as $row) {
                $baseitems[] = $row;
            }
          } catch (Throwable $e) {
            // En cas d’erreur DB, on garde le comportement standard (message d’attente)
            $baseitems = [];
          }
        ?>
        <style>
          /* S'APPLIQUE UNIQUEMENT si des lignes existent (classe .has-infos ajoutée côté PHP) */

          /* Agrandir toute la card */
          .has-infos .card {
            max-width: 1200px;   /* largeur plus grande */
            margin: 0 auto;      /* centré */
            font-size: 20px;     /* taille de texte globale */
          }

          /* Header plus grand */
          .has-infos .card-header {
            padding: 20px 24px;      /* header plus grand */
            min-height: 20px;
          }
          .has-infos .card-title {
            font-size: 32px;         /* titre très lisible */
            margin: 0;
            font-weight: 700;
          }

          /* Tableau : lisible sur tablette (tarifs visibles) */
          .has-infos .table-modern {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e9ecef;
          }
          .has-infos .table-modern thead th {
            background: #f7f8fa;
            font-weight: 700;
            color: #1f2937;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            padding: 18px 20px;      /* plus grand */
            font-size: 20px;
          }
          .has-infos .table-modern td {
            text-align: left;
            padding: 8px 16px;    /* <-- réduit verticalement (avant 18px) */
            font-size: 24px;      /* un peu plus petit que 30px pour resserrer */
            color: #111827;
            border-bottom: 1px solid #f1f3f5;
            word-break: break-word;
            line-height: 1.2;     /* réduit la hauteur de ligne */
          }
          .waiting-content {padding: 0px 24px;}
          .has-infos .table-modern tbody tr:hover {
            background: #fafbfc;
          }

          /* Ajustement responsif (tablette et petits écrans paysage) */
          @media (max-width: 1280px) {
            .has-infos .card { max-width: 100%; font-size: 18px; }
            .has-infos .card-title { font-size: 32px; }
            .has-infos .table-modern thead th,
            .has-infos .table-modern td { font-size: 32px; padding: 16px 18px; }
            .card-content {padding: 10px;}
            .waiting-content {padding: 0px 24px;}
          }
          @media (max-width: 768px) {
            .has-infos .card { font-size: 17px; }
            .has-infos .card-title { font-size: 24px; }
            .has-infos .table-modern thead th,
            .has-infos .table-modern td { font-size: 24px; padding: 14px 16px; }
            .card-content {padding: 5px;}
            .waiting-content {padding: 0px 24px;}
          }
        </style>

            <!-- ÉTAPE 1: ATTENTE -->
            <div id="waiting" class="step show <?= !empty($baseitems) ? 'has-infos' : '' ?>">
              <div class="card-header">
                <?php if (!empty($baseitems)) : ?>
                  <h2 class="card-title">Informations :</h2>
                <?php else: ?>
                  <h2 class="card-title">Terminal de signature</h2>
                  <p class="card-subtitle">Appareil : <?= htmlspecialchars($device_id, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>

              <div class="card-content">
                <div class="waiting-content">
                  <?php if (!empty($baseitems)) : ?>
              <table class="table-modern no-head">
                <tbody>
                  <?php foreach ($baseitems as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string)($item['info'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <div id="waitErr" class="alert alert-error" style="display:none;"></div>
            <?php else: ?>
              <div class="waiting-icon">⏳</div>
              <div class="waiting-title">En attente d'une demande de signature</div>
              <div class="waiting-subtitle">
                Le système attend qu’un technicien déclenche une demande de signature depuis un ticket.
              </div>
              <div class="waiting-hint">Cette page se met à jour automatiquement.</div>
              <div id="waitErr" class="alert alert-error" style="display:none;"></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ÉTAPE 2: RÉCAPITULATIF -->
      <div id="recap" class="step">
        <div class="card-header">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
              <p class="card-subtitle" id="recapTicketInfo"></p>
            </div>
            <div class="time-display" id="recapClock">--:--</div>
          </div>
        </div>
        <div class="card-content">
          <div id="recapContent">
            <!-- Le contenu sera généré dynamiquement -->
          </div>
          <div class="button-group">
            <button class="btn btn-primary" id="nextToSignBtn" type="button">
              Procéder à la signature
            </button>
          </div>
        </div>
      </div>

      <!-- ÉTAPE 3: SIGNATURE -->
      <div id="pad" class="step">
        <div class="card-header">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
              <h2 class="card-title">Signature électronique</h2>
              <p class="card-subtitle" id="ticketInfo"></p>
            </div>
            <div class="time-display" id="clock">--:--</div>
          </div>
        </div>
        <div class="card-content">
          <div class="form-group">
            <label class="form-label" for="signer">Nom du signataire *</label>
            <input id="signer" type="text" class="form-input" placeholder="Nom et prénom">
          </div>

          <style>
          .signer-email-combo-container {
              position: relative;
              width: 100%;
          }

          .form-input {
              width: 100%;
              padding: 8px 30px 8px 8px;
              border: 1px solid #ddd;
              border-radius: 4px;
              background: white;
              font-size: 14px;
              box-sizing: border-box;
          }

          .signer-email-dropdown-btn {
              position: absolute;
              right: 5px;
              top: 50%;
              transform: translateY(-50%);
              background: none;
              border: none;
              cursor: pointer;
              color: #666;
              font-size: 12px;
              display: none;
              align-items: center;
              justify-content: center;
              width: 20px;
              height: 20px;
              border-radius: 3px;
              transition: transform 0.3s ease;
          }

          .signer-email-dropdown-btn.open {
              transform: translateY(-50%) rotate(180deg);
          }

          .signer-email-dropdown {
              position: absolute;
              top: 100%;
              left: 0;
              width: 100%;
              background: white;
              border: 1px solid #ddd;
              border-top: none;
              border-radius: 0 0 4px 4px;
              max-height: 200px;
              overflow-y: auto;
              z-index: 1000;
              display: none;
              box-shadow: 0 2px 5px rgba(0,0,0,0.2);
              box-sizing: border-box;
          }

          .signer-email-option {
              padding: 8px;
              cursor: pointer;
              border-bottom: 1px solid #eee;
          }

          .signer-email-option:hover {
              background: #f5f5f5;
          }

          .signer-email-option:last-child {
              border-bottom: none;
          }
          </style>
          <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
          <div class="form-group">
              <label class="form-label" for="signerEmail">Email du signataire</label>
              <div class="signer-email-combo-container">
                  <input id="signerEmail" type="email" class="form-input" placeholder="email@exemple.com (optionnel)" onclick="showSignerEmailDropdown()" onfocus="showSignerEmailDropdown()">
                  
                  <button type="button" class="signer-email-dropdown-btn" onclick="toggleSignerEmailDropdown()">
                      <i class="fa-solid fa-chevron-down"></i>
                  </button>
                  
                  <div id="signerEmail_dropdown_list" class="signer-email-dropdown">
                  </div>
              </div>
          </div>

          <script>
          let signerEmailArray = [];
          let isDropdownInitialized = false;

          function initializeSignerEmailDropdown() {
              if (window.TABLET_CLIENT_EMAIL) {
                  // Convertir la chaîne en tableau d'emails
                  signerEmailArray = window.TABLET_CLIENT_EMAIL.split(',')
                      .map(email => email.trim())
                      .filter(email => email.length > 0);
                  
                  // Supprimer les doublons
                  signerEmailArray = [...new Set(signerEmailArray)];
                  
                  // IMPORTANT: Pré-remplir avec SEULEMENT le premier email
                  if (signerEmailArray.length > 0 && document.getElementById('signerEmail')) {
                      document.getElementById('signerEmail').value = signerEmailArray[0];
                  }
                  
                  // Créer le dropdown et afficher le bouton si plusieurs emails
                  if (signerEmailArray.length > 1) {
                      createSignerEmailDropdown();
                      showDropdownButton();
                  } else {
                      hideDropdownButton();
                  }
              }
              
              isDropdownInitialized = true;
          }

          function createSignerEmailDropdown() {
              let dropdown = document.getElementById("signerEmail_dropdown_list");
              if (!dropdown) return;
              
              dropdown.innerHTML = '';
              
              signerEmailArray.forEach(email => {
                  let option = document.createElement('div');
                  option.className = 'signer-email-option';
                  option.textContent = email;
                  option.onclick = () => selectSignerEmail(email);
                  dropdown.appendChild(option);
              });
          }

          function showDropdownButton() {
              let btn = document.querySelector(".signer-email-dropdown-btn");
              if (btn) btn.style.display = "flex";
          }

          function hideDropdownButton() {
              let btn = document.querySelector(".signer-email-dropdown-btn");
              if (btn) btn.style.display = "none";
          }

          function showSignerEmailDropdown() {
              if (!isDropdownInitialized) {
                  initializeSignerEmailDropdown();
              }
              
              var dropdown = document.getElementById("signerEmail_dropdown_list");
              if (!dropdown || signerEmailArray.length <= 1) return;

              dropdown.style.display = "block";
              var btn = document.querySelector(".signer-email-dropdown-btn");
              if (btn) btn.classList.add("open");
          }

          function toggleSignerEmailDropdown() {
              if (!isDropdownInitialized) {
                  initializeSignerEmailDropdown();
              }
              
              var dropdown = document.getElementById("signerEmail_dropdown_list");
              if (!dropdown || signerEmailArray.length <= 1) return;

              var btn = document.querySelector(".signer-email-dropdown-btn");
              var isOpen = dropdown.style.display === "block";

              dropdown.style.display = isOpen ? "none" : "block";
              if (btn) btn.classList.toggle("open", !isOpen);
          }

          function selectSignerEmail(email) {
              document.getElementById("signerEmail").value = email;

              var dropdown = document.getElementById("signerEmail_dropdown_list");
              if (dropdown) dropdown.style.display = "none";

              var btn = document.querySelector(".signer-email-dropdown-btn");
              if (btn) btn.classList.remove("open");
          }

          // Fermer le dropdown si on clique ailleurs
          document.addEventListener("click", function(event) {
              var container = document.querySelector(".signer-email-combo-container");
              var dropdown = document.getElementById("signerEmail_dropdown_list");
              if (!dropdown) return;

              if (!container.contains(event.target)) {
                  dropdown.style.display = "none";
                  var btn = document.querySelector(".signer-email-dropdown-btn");
                  if (btn) btn.classList.remove("open");
              }
          });
          </script>

          <div class="signature-area">
            <label class="form-label">Signature *</label>
            <canvas id="sig" class="signature-canvas" width="1000" height="300"></canvas>
            <div class="signature-hint">Signez dans la zone ci-dessus avec votre doigt ou un stylet</div>
          </div>
          
          <div id="sendErr" class="alert alert-error" style="display:none;"></div>
          <div id="sendOk" class="alert alert-success" style="display:none;">
            Signature enregistrée avec succès.
          </div>
          
          <div class="button-group">
            <button class="btn btn-outline" id="backToRecapBtn" type="button">
              ← Retour
            </button>
            <button class="btn btn-danger" id="refuseBtn" type="button">
              Refuser
            </button>
            <button class="btn btn-secondary" id="clearBtn" type="button">
              Effacer
            </button>
            <button class="btn btn-primary" id="sendBtn" type="button">
              Valider la signature
            </button>
          </div>
        </div>
      </div>
      
    </div>
  </div>

<script>
(() => {
  const BASE = <?php echo json_encode($plugin_base, JSON_UNESCAPED_SLASHES); ?>;
  const DEVICE_ID = <?php echo json_encode($device_id, JSON_UNESCAPED_UNICODE); ?>;
  const TOKEN = <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>;
  // expose for code executed outside initial scope
  window.BASE = BASE; window.DEVICE_ID = DEVICE_ID; window.TOKEN = TOKEN;

  const waitingEl = document.getElementById('waiting');
  const waitErr = document.getElementById('waitErr');
  const recapEl = document.getElementById('recap');
  const padEl = document.getElementById('pad');
  const ticketInfoEl = document.getElementById('ticketInfo');
  const recapTicketInfoEl = document.getElementById('recapTicketInfo');
  const recapContentEl = document.getElementById('recapContent');
  const nextToSignBtn = document.getElementById('nextToSignBtn');
  const backToRecapBtn = document.getElementById('backToRecapBtn');
  const refuseBtn = document.getElementById('refuseBtn');
  const clearBtn = document.getElementById('clearBtn');
  const sendBtn = document.getElementById('sendBtn');
  const sendErr = document.getElementById('sendErr');
  const sendOk  = document.getElementById('sendOk');
  const canvas = document.getElementById('sig');
  const ctx = canvas.getContext('2d');
  
  let drawing = false;
  let requestId = null;
  let ticketId = null;
  let requestData = null;
  let polling = true;

  // NOUVEAU : Variables pour le refresh automatique (30 minutes)
  let autoRefreshTimer = null;
  const REFRESH_INTERVAL = 30 * 60 * 1000; // 30 minutes en millisecondes

  // Simple clock
  function upClock(){
    const d = new Date();
    const hh = String(d.getHours()).padStart(2,'0');
    const mm = String(d.getMinutes()).padStart(2,'0');
    document.getElementById('clock').textContent = hh+':'+mm;
    document.getElementById('recapClock').textContent = hh+':'+mm;
  }
  setInterval(upClock, 1000); upClock();

  // NOUVEAU : Gestion du refresh automatique
  function startAutoRefresh() {
    // Annuler le timer existant s'il y en a un
    if (autoRefreshTimer) {
      clearTimeout(autoRefreshTimer);
      autoRefreshTimer = null;
    }
    
    // Démarrer un nouveau timer seulement si on est en mode attente
    if (polling && waitingEl.classList.contains('show')) {
      autoRefreshTimer = setTimeout(() => {
        // Vérifier qu'on est toujours en mode attente avant de refresh
        if (polling && waitingEl.classList.contains('show')) {
          window.location.reload();
        }
      }, REFRESH_INTERVAL);
    }
  }

  function stopAutoRefresh() {
    if (autoRefreshTimer) {
      clearTimeout(autoRefreshTimer);
      autoRefreshTimer = null;
    }
  }

  // Navigation entre étapes (MODIFIÉE pour gérer le refresh)
  function showStep(stepName) {
    document.querySelectorAll('.step').forEach(step => step.classList.remove('show'));
    document.getElementById(stepName).classList.add('show');
    
    // NOUVEAU : Gestion du refresh
    if (stepName === 'waiting') {
      startAutoRefresh();
    } else {
      stopAutoRefresh();
    }
  }

  // Drawing helpers
  function pos(e){
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;    // 1000 / largeur CSS
    const scaleY = canvas.height / rect.height;  // 300 / hauteur CSS
    
    let clientX, clientY;
    
    if (e.touches && e.touches[0]) {
      clientX = e.touches[0].clientX;
      clientY = e.touches[0].clientY;
    } else {
      clientX = e.clientX;
      clientY = e.clientY;
    }
    
    return {
      x: (clientX - rect.left) * scaleX,
      y: (clientY - rect.top) * scaleY
    };
  }

  function start(e){ drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
  function move(e){
    if (!drawing) return;
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.strokeStyle = '#2c3e50';
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.stroke();
    e.preventDefault();
  }
  function end(e){ drawing = false; e.preventDefault(); }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove', move,   {passive:false});
  canvas.addEventListener('touchend', end,     {passive:false});

  clearBtn.addEventListener('click', () => {
    ctx.clearRect(0,0,canvas.width, canvas.height);
    sendErr.style.display = 'none';
    sendOk.style.display = 'none';
  });

  // Navigation
  nextToSignBtn.addEventListener('click', () => {
    showStep('pad');

    // Version simplifiée - remplace votre ancien code
    if (window.TABLET_CLIENT_EMAIL && document.getElementById('signerEmail')) {
      document.getElementById('signerEmail').value = window.TABLET_CLIENT_EMAIL.split(',')[0].trim();
      
      // AJOUTER cette ligne :
      initializeSignerEmailDropdown();
    }
  });

  backToRecapBtn.addEventListener('click', () => {
    showStep('recap');
  });

  // Event listener pour le bouton refuser
  refuseBtn.addEventListener('click', async () => {
    sendErr.style.display = 'none'; 
    sendOk.style.display = 'none';
    
    if (!requestId) { 
      sendErr.textContent = 'Aucune demande active.'; 
      sendErr.style.display = 'block'; 
      return; 
    }
    
    // Confirmation du refus
    if (!confirm('Êtes-vous sûr de vouloir refuser cette signature ?')) {
      return;
    }
    
    refuseBtn.disabled = true;
    
    try {     
      const res = await fetch(BASE + '/ajax/device_refuse.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          device_id: ((typeof DEVICE_ID!=='undefined'&&DEVICE_ID)?DEVICE_ID:(window.DEVICE_ID||'')),
          token: ((typeof TOKEN!=='undefined'&&TOKEN)?TOKEN:(window.TOKEN||'')),
          request_id: requestId
        }).toString(),
        credentials: 'same-origin'
      });
      
      const responseText = await res.text();
      
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (parseError) {
        console.error('JSON parse error:', parseError);
        throw new Error('Réponse serveur invalide (pas du JSON): ' + responseText.substring(0, 100));
      }
      
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Erreur serveur HTTP ' + res.status);
      }
      
      sendOk.textContent = 'Signature refusée. Vous pouvez fermer cette page.';
      sendOk.style.display = 'block';
      
      // Après refus, attendre 3 secondes puis retourner à l'écran d'attente
      setTimeout(resetToWaiting, 3000);
      
    } catch (e) {
      console.error('Refuse error:', e);
      sendErr.textContent = 'Refus impossible: ' + e.message;
      sendErr.style.display = 'block';
    } finally {
      refuseBtn.disabled = false;
    }
  });

  // Fonction pour générer le contenu du récapitulatif - VERSION INTELLIGENTE COMPLÈTE
  function generateRecapContent(parameters) {
    let html = '';

    // ########################################## Netoyage du HTML ########################################## \\
    // --- Helpers: décoder & sécuriser le HTML ---

    // 1) Décodage profond
    const decodeEntitiesDeep = (str, max = 5) => {
      if (str == null) return '';
      let prev = String(str);
      for (let i = 0; i < max; i++) {
        const ta = document.createElement('textarea');
        ta.innerHTML = prev;
        const next = ta.value;
        if (next === prev) break;
        prev = next;
      }
      return prev;
    };

    // 2) Normaliser les chevrons et mapper tes pseudo-tags si besoin
    const normalizeAndMapTags = (html) => {
      let s = String(html)
        .replace(/<\s+/g, '<')
        .replace(/\s+>/g, '>')
        .replace(/<\/\s+/g, '</');

      // Exemple: <hl> -> <h4> (garde si tu en as réellement)
      s = s.replace(/<hl>/gi, '<h4>').replace(/<\/hl>/gi, '</h4>');
      return s;
    };

    // 3) Aplatir vers un sous-ensemble de balises simples
    const flattenBasic = (html) => {
      const tpl = document.createElement('template');
      tpl.innerHTML = html;

      // h1—h6 => <p><strong>texte</strong></p>
      tpl.content.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(h => {
        const p = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = h.textContent;
        p.appendChild(strong);
        h.replaceWith(p);
      });

      // div => p (en conservant le contenu)
      tpl.content.querySelectorAll('div').forEach(d => {
        const p = document.createElement('p');
        p.innerHTML = d.innerHTML;
        d.replaceWith(p);
      });

      // span => désemballer
      tpl.content.querySelectorAll('span').forEach(s => {
        s.replaceWith(...s.childNodes);
      });

      return tpl.innerHTML;
    };

    // 4) Fallback si DOMPurify absent (supprime éléments/attributs dangereux)
    const sanitizeHtmlBasic = (html) => {
      const tpl = document.createElement('template');
      tpl.innerHTML = String(html);

      ['script','style','iframe','object','embed','link','meta','base','form']
        .forEach(tag => tpl.content.querySelectorAll(tag).forEach(el => el.remove()));

      tpl.content.querySelectorAll('*').forEach(el => {
        [...el.attributes].forEach(attr => {
          const name = attr.name.toLowerCase();
          const val  = attr.value || '';
          if (name.startsWith('on')) el.removeAttribute(attr.name);
          if (['src','href','xlink:href','formaction'].includes(name) && /^\s*javascript:/i.test(val)) {
            el.removeAttribute(attr.name);
          }
        });
      });

      return tpl.innerHTML;
    };

    // 5) Ajouter des paragraphes si aucun tag
    const ensureParagraphs = (html) => {
      const hasTags = /<\w[\s\S]*>/.test(html);
      if (hasTags) return html;
      const parts = html.trim().split(/\n{2,}/).map(p => `<p>${p.replace(/\n/g,'<br>')}</p>`);
      return parts.join('') || '';
    };

    // 6) Pipeline complet
    const renderSafeHtml = (raw) => {
      const decoded = decodeEntitiesDeep(raw);
      const fixed   = normalizeAndMapTags(decoded);

      // Aplatir AVANT la sanitization (pour avoir un DOM simple)
      const flattened = flattenBasic(fixed);

      // Restreindre le HTML final à un set minimal
      const sanitized = (window.DOMPurify
        ? window.DOMPurify.sanitize(flattened, {
            ALLOWED_TAGS: ['p','br','strong','b','em','i','u','ul','ol','li','a'],
            ALLOWED_ATTR: ['href','title','target','rel']
          })
        : sanitizeHtmlBasic(flattened)
      );

      return ensureParagraphs(sanitized);
    };
    // ########################################## Netoyage du HTML ########################################## \\
    
    if (!parameters || Object.keys(parameters).length === 0) {
      return '<div class="section"><div class="section-content">Aucune information supplémentaire disponible.</div></div>';
    }

    // Entité/Client
    if (parameters.entity_name) {
      html += `<div class="section">`;
      html += `  <div class="section-title">🏢 Client</div>`;
      html += `  <div class="section-content">`;
      
      if (parameters.entity_name) {
        html += escapeHtml(parameters.entity_name);
      }
      
      html += `  </div>`;
      html += `</div>`;
    }

    // Document avec debug pour identifier le problème
    if (parameters.document_name) {   
      html += `<div class="section">`;
      html += `  <div class="section-title">📄 Document</div>`;
      html += `  <div class="section-content">`;
      
      //html += `    <div style="font-weight: 600; margin-bottom: 8px;">${escapeHtml(parameters.document_name)}</div>`;
      
      if (parameters.document_url) {
        // Fonction robuste de décodage HTML
        function decodeHtmlEntities(text) {
          const textArea = document.createElement('textarea');
          textArea.innerHTML = text;
          return textArea.value;
        }
        
        // Décoder l'URL
        let decodedUrl = decodeHtmlEntities(parameters.document_url);
        
        // Si le décodage n'a pas fonctionné, forcer le remplacement manuel
        if (decodedUrl.includes('&#38;')) {
          decodedUrl = decodedUrl.replace(/&#38;/g, '&');
        }
        
        // ✅ Déclaration en amont
        let googleViewerUrl = '';
        // Vérifier si l'URL contient document.send.php
        if (decodedUrl.includes('document.send.php')) {
          // Affichage direct via iframe
          googleViewerUrl = decodedUrl;
        } else {
          // Sinon, passer par Google Docs Viewer
          googleViewerUrl = `https://docs.google.com/viewerng/viewer?url=${encodeURIComponent(decodedUrl)}&embedded=true`;
        }

        html += `    <iframe src="${escapeHtml(googleViewerUrl)}" class="pdf-viewer" frameborder="0" title="Visualisation PDF"></iframe>`;
        
        html += `    <div style="margin-top: 8px; text-align: center;">`;
        html += `      <a href="${escapeHtml(decodedUrl)}" target="_blank" class="external-link">`;
        html += `        🔗 Ouvrir en plein écran`;
        html += `      </a>`;
        html += `    </div>`;
      } else {
        html += `    <div style="opacity: 0.7; font-style: italic;">Document disponible sans aperçu</div>`;
      }
      
      html += `  </div>`;
      html += `</div>`;
    }

    // Ticket (VERSION FUTURE)
    if (parameters.ticket_title) {
      html += `<div class="section">`;
      html += `  <div class="section-title">📋 Description</div>`;
      html += `  <div class="section-content">`;
      html += `    <div style="font-weight: 600; margin-bottom: 4px;">${escapeHtml(parameters.ticket_title)}</div>`;

      if (parameters.ticket_description) {
        const descHTML = renderSafeHtml(parameters.ticket_description);
        html += `    <div style="opacity: 0.9; font-size: 13px; margin-bottom: 8px; line-height: 1.6;">${descHTML}</div>`;
      }

      html += `  </div>`;
      html += `</div>`;
    }

    // Tâches du ticket (VERSION FUTURE)
    if (parameters.ticket_tasks && Array.isArray(parameters.ticket_tasks) && parameters.ticket_tasks.length > 0) {
      html += `<div class="section">`;
      html += `  <div class="section-title">📝 Tâches</div>`;
      html += `  <div class="section-content">`;
      
      parameters.ticket_tasks.slice().reverse().forEach((task) => {
        if (task.content) {
          const contentHTML = renderSafeHtml(task.content);

          html += `<div style="margin-bottom: 12px; padding: 12px; background: rgba(255,255,255,0.7); border-radius: 6px; border-left: 3px solid #3498db; line-height: 1.5;">`;
          html += `  <div style="font-size: 12px; color: #6c757d; margin-bottom: 4px;"></div>`;
          html += `  <div style="font-size: 13px;">${contentHTML}</div>`;
          html += `</div>`;
        }
      });
      
      html += `  </div>`;
      html += `</div>`;
    }

    // Description personnalisée (si ajoutée plus tard)
    if (parameters.description && !parameters.ticket_description) {
      const infoHTML = renderSafeHtml(parameters.description); // decode -> sanitize -> format
      html += `<div class="section">`;
      html += `  <div class="section-title">ℹ️ Informations</div>`;
      html += `  <div class="section-content" style="line-height:1.6;">${infoHTML}</div>`;
      html += `</div>`;
    }

    // URL séparée (si pas de document_name mais url fournie)
    if (parameters.url && !parameters.document_url) {
      html += `<div class="section">`;
      html += `  <div class="section-title">🔗 Lien</div>`;
      html += `  <div class="section-content">`;
      html += `    <a href="${escapeHtml(parameters.url)}" target="_blank" class="external-link">`;
      html += `    ${escapeHtml(parameters.url)}`;
      html += `    </a>`;
      html += `  </div>`;
      html += `</div>`;
    }

    // NOUVEAU : Affichage du temps total (texte simple => on garde l'échappement)
    if (parameters.total_time) {
      html += `    <div style="background: rgba(52,152,219,0.1); padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-top: 8px;">`;
      html += `      <strong>⏱️ Temps total :</strong> ${escapeHtml(parameters.total_time)}`;
      html += `    </div>`;
    }

    // Autres champs dynamiques (pour le futur)
    const displayedKeys = ['document_name', 'document_url', 'entity_name', 'client_name', 'ticket_title', 'ticket_description', 'ticket_tasks', 'total_seconds', 'description', 'url', 'total_time','client_email'];
    Object.keys(parameters).forEach(key => {
      if (!displayedKeys.includes(key) && parameters[key] && typeof parameters[key] === 'string') {
        html += `<div class="section">`;
        html += `  <div class="section-title">${escapeHtml(key)}</div>`;
        html += `  <div class="section-content">${escapeHtml(parameters[key])}</div>`;
        html += `</div>`;
      }
    });

    return html || '<div class="section"><div class="section-content">Aucune information supplémentaire disponible.</div></div>';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatDate(dateString) {
    if (!dateString) return '';
    try {
      const date = new Date(dateString);
      return date.toLocaleDateString('fr-FR', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch (e) {
      return dateString;
    }
  }

  // Fonction pour réinitialiser l'interface après envoi (MODIFIÉE pour le refresh)
  function resetToWaiting() {
    document.getElementById('signer').value = '';
    document.getElementById('signerEmail').value = '';
    ctx.clearRect(0,0,canvas.width, canvas.height);
    
    sendErr.style.display = 'none';
    sendOk.style.display = 'none';
    
    requestId = null;
    ticketId = null;
    requestData = null;
    
    showStep('waiting');
    
    polling = true;
    setTimeout(poll, 2000);
    
    // NOUVEAU : Redémarrer le refresh après reset
    startAutoRefresh();
  }

  async function post(url, data){
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new URLSearchParams(data).toString(),
      credentials: 'same-origin'
    });
    
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    
    const json = await res.json();
    
    if (!json.ok) {
      throw new Error(json.error || 'Erreur serveur');
    }
    
    return json;
  }

  async function poll(){
    if (!polling) return;
    try {
      const params = new URLSearchParams({ 
        device_id: ((typeof DEVICE_ID!=='undefined'&&DEVICE_ID)?DEVICE_ID:(window.DEVICE_ID||'')), 
        token: TOKEN 
      });
      
      const res = await fetch(BASE + '/ajax/device_poll.php?' + params.toString(), {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      });
      
      const data = await res.json();
      
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Erreur de polling');
      }
      
      if (data.pending === true && data.request_id) {
        requestId = data.request_id;
        ticketId = data.ticket_id;
        requestData = data;
        
        // NOUVEAU : Arrêter le refresh car une demande est active
        stopAutoRefresh();
        
        // Mettre à jour les infos ticket
        const ticketText = ticketId ? ('Ticket #' + ticketId) : '';
        ticketInfoEl.textContent = ticketText;
        recapTicketInfoEl.textContent = ticketText;
        
        // Générer le contenu du récapitulatif
        recapContentEl.innerHTML = generateRecapContent(data.parameters);

        // NOUVEAU : Pré-remplir l'email si disponible dans les paramètres
        if (data.parameters && data.parameters.client_email) {
          window.TABLET_CLIENT_EMAIL = data.parameters.client_email;
        }
        
        // Aller à l'étape récapitulatif
        showStep('recap');
        polling = false;
        return;
      }
      
      waitErr.style.display = 'none';
      
    } catch (e) {
      console.error('Poll error:', e);
      waitErr.textContent = 'Erreur de veille: ' + e.message;
      waitErr.style.display = 'block';
    } finally {
      if (polling) {
        setTimeout(poll, 2000);
      }
    }
  }

  setTimeout(poll, 600);
  
  // NOUVEAU : Démarrer le refresh initial
  startAutoRefresh();
 
  // --- Quick Sign (BL) ---
  let quickMode = false;
  let quickDoc = null;      // { save, filename, folder, signed }
  let quickSurveyId = null; // id_document dans glpi_plugin_gestion_surveys
  let quickPreviewUrl = null;

  const quickModal    = document.getElementById('quickBlModal');
  const quickInput    = document.getElementById('modalQuickBlInput');
  const quickResults  = document.getElementById('modalQuickBlResults');
  const quickErr      = document.getElementById('modalQuickBlErr');
  const quickCloseBtn = document.getElementById('closeQuickBlBtn');

  // Hook existing "Signature BL" button if present; otherwise inject it into waiting view
  (function addQuickButton(){
    try {
      const existing = document.getElementById('openQuickBlBtn');
      if (existing) {
        existing.addEventListener('click', openQuickModal);
        return;
      }
      const waiting = document.querySelector('#waiting .waiting-content');
      if (!waiting) return;
      const btn = document.createElement('button');
      btn.id = 'openQuickBlBtn';
      btn.className = 'btn';
      btn.textContent = 'Signature BL';
      btn.style.marginTop = '10px';
      waiting.appendChild(btn);
      btn.addEventListener('click', openQuickModal);
    } catch(e) { console.warn('Quick BL button failed', e); }
  })();

  function openQuickModal(){
    const modal    = document.getElementById('quickBlModal');
    const inputEl  = document.getElementById('modalQuickBlInput');
    const resEl    = document.getElementById('modalQuickBlResults');
    const errEl    = document.getElementById('modalQuickBlErr');
    const closeEl  = document.getElementById('closeQuickBlBtn');
    if (!modal) return;
    modal.style.display = 'block';
    if (inputEl) {
      inputEl.value = inputEl.value || '';
      setTimeout(()=>inputEl.focus(), 50);
    }
    if (resEl) resEl.innerHTML = '';
    if (errEl) errEl.style.display = 'none';
    if (closeEl) closeEl.onclick = closeQuickModal;
    modal.onclick = (e)=>{ if (e.target === modal) closeQuickModal(); };
  }
  function closeQuickModal(){
    const modal   = document.getElementById('quickBlModal');
    const resEl   = document.getElementById('modalQuickBlResults');
    const errEl   = document.getElementById('modalQuickBlErr');
    if (modal) modal.style.display = 'none';
    if (errEl) errEl.style.display = 'none';
    if (resEl) resEl.innerHTML = '';
  }

  // Recherche en temps r?el dans le modal
  if (quickInput) {
    let _qTimer;
    quickInput.addEventListener('input', () => {
      clearTimeout(_qTimer);
      _qTimer = setTimeout(() => {
        const q = (quickInput.value || '').trim();
        searchQuickBL(q);
      }, 300);
    });
  }

  function renderQuickResults(items){
    const resultsEl = document.getElementById('modalQuickBlResults'); if (!resultsEl) return;
    resultsEl.innerHTML = '';
    if (!items || items.length === 0) {
      resultsEl.style.display = 'none';
      return;
    }
    resultsEl.style.display = 'block';
    items.forEach(it => {
      const row = document.createElement('div');
      row.style.padding = '6px 8px';
      row.style.cursor = 'pointer';
      row.style.borderBottom = '1px solid #f0f0f0';
      row.innerHTML = it.html || it.text || (it.filename || '');
      row.addEventListener('click', () => window.selectQuickResult2 && window.selectQuickResult2(it));
      resultsEl.appendChild(row);
    });
  }

  async function searchQuickBL(q){
    const errEl = document.getElementById('modalQuickBlErr');
    if (errEl) errEl.style.display = 'none';
    quickSurveyId = null;
    quickPreviewUrl = null;
    quickDoc = null;

    if (!q || q.length < 2) {
      if (errEl) { errEl.textContent = 'Veuillez saisir au moins 2 caract?res.';
      errEl.style.display = 'block'; }
      return;
    }
    try {
      const url = BASE + '/ajax/ajax_search_pdf.php?q=' + encodeURIComponent(q);
      const res = await fetch(url, { method: 'GET', credentials:'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'} });
      const data = await res.json();
      renderQuickResults(Array.isArray(data) ? data : []);
    } catch(e){
      if (errEl) { errEl.textContent = 'Erreur de recherche: ' + e.message;
      errEl.style.display = 'block'; }
    }
  }

  // Quick select handler (used by modal results)
  window.selectQuickResult2 = async function(item){
    const errEl = document.getElementById('modalQuickBlErr');
    const resultsEl = document.getElementById('modalQuickBlResults');
    if (errEl) errEl.style.display = 'none';
    if (resultsEl) resultsEl.style.display = 'none';
    const devId = ((typeof DEVICE_ID!=='undefined'&&DEVICE_ID)?DEVICE_ID:(window.DEVICE_ID||''));
    const tok   = ((typeof TOKEN!=='undefined'&&TOKEN)?TOKEN:(window.TOKEN||''));
    if (!devId || !tok) { if (errEl) { errEl.textContent = 'Paramètres tablette manquants (device_id/token)'; errEl.style.display = 'block'; } return; }

    quickDoc = {
      save: (item.save || '').trim(),
      filename: (item.filename || item.text || '').trim(),
      folder: (item.folder || '').trim(),
      signed: parseInt(item.signed || 0, 10)
    };
    if (!quickDoc.save || !quickDoc.filename || !quickDoc.folder) {
      if (errEl) { errEl.textContent = 'Résultat incomplet.'; errEl.style.display = 'block'; }
      return;
    }
    try {
      const body = new URLSearchParams({
        device_id: devId,
        token: tok,
        save: quickDoc.save,
        filename: quickDoc.filename,
        folder: quickDoc.folder,
        signed: String(quickDoc.signed)
      }).toString();
      const res = await fetch(BASE + '/ajax/quick_add_survey.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
        body,
        credentials: 'same-origin'
      });
      const txt = await res.text();
      let j;
      try { j = JSON.parse(txt); } catch(parseErr) { throw new Error('Serveur: ' + txt.substring(0,200)); }
      if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP '+res.status));
      quickSurveyId = j.id;
      quickPreviewUrl = j.preview_url || null;
      quickMode = true;
      // Prefer BL returned by server (may include client suffix)
      if (j.bl) { quickDoc.bl = String(j.bl).trim(); }
      var docDisplay2 = quickDoc.bl ? (/(\.pdf)$/i.test(quickDoc.bl) ? quickDoc.bl : (quickDoc.bl + '.pdf')) : (quickDoc.filename || '');
      recapTicketInfoEl.textContent = 'Document: ' + docDisplay2;
      const params2 = { document_name: docDisplay2 };
      if (quickPreviewUrl) params2.document_url = quickPreviewUrl;
      recapContentEl.innerHTML = generateRecapContent(params2);
      if (typeof closeQuickModal === 'function') closeQuickModal();
      showStep('recap');
    } catch(e){
      if (errEl) { errEl.textContent = 'Création impossible: ' + e.message; errEl.style.display = 'block'; }
      quickMode = false;
    }
  };

  async function selectQuickResult(item){
        const errEl = document.getElementById('modalQuickBlErr');
        const resultsEl = document.getElementById('modalQuickBlResults');
    if (errEl) errEl.style.display = 'none';
    if (resultsEl) resultsEl.style.display = 'none';
    quickDoc = {
      save: (item.save || '').trim(),
      filename: (item.filename || item.text || '').trim(),
      folder: (item.folder || '').trim(),
      signed: parseInt(item.signed || 0, 10)
    };
    if (!quickDoc.save || !quickDoc.filename || !quickDoc.folder) {
      if (errEl) { errEl.textContent = 'Résultat incomplet.'; errEl.style.display = 'block'; }
      return;
    }

    try {
      const body = new URLSearchParams({
        device_id: ((typeof DEVICE_ID!=='undefined'&&DEVICE_ID)?DEVICE_ID:(window.DEVICE_ID||'')),
        token: ((typeof TOKEN!=='undefined'&&TOKEN)?TOKEN:(window.TOKEN||'')),
        save: quickDoc.save,
        filename: quickDoc.filename,
        folder: quickDoc.folder,
        signed: String(quickDoc.signed)
      }).toString();
      const res = await fetch(BASE + '/ajax/quick_add_survey.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
        body,
        credentials: 'same-origin'
      });
      const txt = await res.text();
      let j;
      try { j = JSON.parse(txt); } catch(parseErr) { throw new Error('Serveur: ' + txt.substring(0,200)); }
      if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP '+res.status));
      quickSurveyId = j.id;
      quickPreviewUrl = j.preview_url || null;

      // Etape signature en mode rapide
      quickMode = true;
      // Prefer BL returned by server (may include client suffix)
      if (j.bl) { quickDoc.bl = String(j.bl).trim(); }
      const docName = quickDoc.bl ? (/(\.pdf)$/i.test(quickDoc.bl) ? quickDoc.bl : (quickDoc.bl + '.pdf')) : (quickDoc.filename || '');
      ticketInfoEl.textContent = 'Document: ' + docName;
      recapTicketInfoEl.textContent = 'Document: ' + docName;

      try {
        if (quickPreviewUrl) {
          const previewHolderId = 'quickPreviewHolder';
          let holder = document.getElementById(previewHolderId);
          if (!holder) {
            holder = document.createElement('div');
            holder.id = previewHolderId;
            holder.style.margin = '10px 0 20px 0';
            const cardContent = document.querySelector('#pad .card-content');
            cardContent.insertBefore(holder, cardContent.firstChild);
          }
          holder.innerHTML =
            '<div class=\"form-card\">'+
              '<div class=\"form-label\">Aperçu du document</div>'+
              '<div class=\"form-content\">'+
                '<object data=\"' + quickPreviewUrl.replace(/\"/g,'&quot;') + '#view=FitH\" type=\"application/pdf\" style=\"width:100%;height:420px;border:1px solid #e9ecef;border-radius:6px;\">'+
                  'Prévisualisation indisponible'+
                '</object>'+
              '</div>'+
            '</div>';
        }
      } catch(_e) {}

      // Quick mode: show recap step with preview instead of going directly to pad
      recapTicketInfoEl.textContent = 'Document: ' + docName; 
      const params = { document_name: docName };
      if (quickPreviewUrl) params.document_url = quickPreviewUrl;
      recapContentEl.innerHTML = generateRecapContent(params);
      if (typeof closeQuickModal === 'function') closeQuickModal();
      showStep('recap');
    } catch(e){
      if (quickErr) { if (errEl) { errEl.textContent = 'Création impossible: ' + e.message; errEl.style.display = 'block'; } }
      quickMode = false;
    }
  }

  // Fallback: delegate click on any visible button labeled "Signature BL"
  document.addEventListener('click', function(ev){
    try {
      const tgt = ev.target;
      if (!tgt) return;
      const el = tgt.closest ? (tgt.closest('button, a, [data-action="openQuickBl"]')) : null;
      if (!el) return;
      const label = (el.textContent || el.innerText || '').replace(/\s+/g,' ').trim().toLowerCase();
      if (el.id === 'openQuickBlBtn' || el.getAttribute('data-action') === 'openQuickBl' || label === 'signature bl') {
        ev.preventDefault();
        // Open modal even if variables were null at load
        const modal    = document.getElementById('quickBlModal');
        const inputEl  = document.getElementById('modalQuickBlInput');
        const resEl    = document.getElementById('modalQuickBlResults');
        const errEl    = document.getElementById('modalQuickBlErr');
        if (modal) {
          modal.style.display = 'block';
          if (inputEl) { inputEl.value = inputEl.value || ''; setTimeout(()=>inputEl.focus(), 50); }
          if (resEl) resEl.innerHTML = '';
          if (errEl) errEl.style.display = 'none';
          // Attach search handler once
          if (inputEl && !window.__quickInputHandlerAttached) {
            let _qTimer;
            inputEl.addEventListener('input', () => {
              clearTimeout(_qTimer);
              _qTimer = setTimeout(() => searchQuickBL((inputEl.value || '').trim()), 300);
            });
            window.__quickInputHandlerAttached = true;
          }
        }
      }
    } catch(_e) {}
  });

  // Capture en phase de capture pour intercepter le click quand quickMode est actif
  // et éviter l'ancien flux (demande distante). Cela permet de ne pas modifier l'écouteur existant.
  if (typeof sendBtn !== 'undefined' && sendBtn && sendBtn.addEventListener) {
    sendBtn.addEventListener('click', async function(ev){
      if (!quickMode) return; // laisser l'autre handler gérer

      ev.preventDefault();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
      if (ev.stopPropagation) ev.stopPropagation();

      // Validations de base
      const blank = document.createElement('canvas');
      blank.width = canvas.width; blank.height = canvas.height;
      if (canvas.toDataURL() === blank.toDataURL()){
        sendErr.textContent = 'Veuillez signer dans la zone.';
        sendErr.style.display = 'block';
        return;
      }
      const signerInput = document.getElementById('signer');
      if (!signerInput.value.trim()) {
        sendErr.textContent = 'Veuillez saisir votre nom et prénom.';
        sendErr.style.display = 'block';
        return;
      }

      try {
        const png = canvas.toDataURL('image/png');
        const signer = document.getElementById('signer').value || '';
        const signerEmail = document.getElementById('signerEmail').value || '';
        if (!quickSurveyId || !quickDoc) {
          throw new Error('Document non initialisé.');
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = BASE + '/front/traitement.php';
        const addField = (name, val) => { const i = document.createElement('input'); i.type='hidden'; i.name=name; i.value=val; form.appendChild(i); };
        addField('REPORT_ID', '0');
        // For quick-sign, use BL from server (may include client suffix like survey.form.php)
        var __doc_for_save = (quickDoc && quickDoc.bl) ? quickDoc.bl : (quickDoc && quickDoc.filename ? quickDoc.filename.replace(/\.pdf$/i,'') : '');
        addField('DOC', __doc_for_save);
        addField('id_document', String(quickSurveyId));
        addField('url', png);
        addField('name', signer);
        addField('email', signerEmail);
        const techVal = (document.getElementById("modalTechnician") ? document.getElementById("modalTechnician").value : (document.getElementById("technician") ? document.getElementById("technician").value : ""));
        addField("technician", techVal);
        addField('mailtoclient', '1');
        if (window.GLPI_CSRF_TOKEN) addField('_glpi_csrf_token', window.GLPI_CSRF_TOKEN);
        document.body.appendChild(form);
        form.submit();
      } catch(e) {
        console.error('Quick send error:', e);
        sendErr.textContent = 'Envoi impossible (rapide): ' + e.message;
        sendErr.style.display = 'block';
      }
    }, true); // phase de capture
  }

  sendBtn.addEventListener('click', async () => {
    sendErr.style.display = 'none'; 
    sendOk.style.display = 'none';
    
    if (!requestId) { 
      sendErr.textContent = 'Aucune demande active.'; 
      sendErr.style.display = 'block'; 
      return; 
    }
    
    // Check empty signature (no pixels)
    const blank = document.createElement('canvas');
    blank.width = canvas.width; 
    blank.height = canvas.height;
    if (canvas.toDataURL() === blank.toDataURL()){
      sendErr.textContent = 'Veuillez signer dans la zone.';
      sendErr.style.display = 'block';
      return;
    }

    const signerInput = document.getElementById('signer');
    // Vérifier si le champ est vide
    if (!signerInput.value.trim()) {
      sendErr.textContent = 'Veuillez saisir votre nom et prénom.';
      sendErr.style.display = 'block';
      return;
    }

    sendBtn.disabled = true;
    
    try {
      const png = canvas.toDataURL('image/png');
      const signer = document.getElementById('signer').value || '';
      const signerEmail = document.getElementById('signerEmail').value || '';
            
      const res = await fetch(BASE + '/ajax/device_submit.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          device_id: ((typeof DEVICE_ID!=='undefined'&&DEVICE_ID)?DEVICE_ID:(window.DEVICE_ID||'')),
          token: ((typeof TOKEN!=='undefined'&&TOKEN)?TOKEN:(window.TOKEN||'')),
          request_id: requestId,
          signer_name: signer,
          signer_email: signerEmail,
          signature: png
        }).toString(),
        credentials: 'same-origin'
      });
      
      const responseText = await res.text();
      
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (parseError) {
        console.error('JSON parse error:', parseError);
        throw new Error('Réponse serveur invalide (pas du JSON): ' + responseText.substring(0, 100));
      }
      
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Erreur serveur HTTP ' + res.status);
      }
      
      sendOk.style.display = 'block';
      
      // Après succès, attendre 3 secondes puis retourner à l'écran d'attente
      setTimeout(resetToWaiting, 3000);
      
    } catch (e) {
      console.error('Send error:', e);
      sendErr.textContent = 'Envoi impossible: ' + e.message;
      sendErr.style.display = 'block';
    } finally {
      sendBtn.disabled = false;
    }
  });
})();
</script>
  <!-- Quick BL Modal -->
  <div id="quickBlModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
    <div style="max-width:700px; margin:60px auto; background:#fff; border-radius:8px; padding:16px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <div style="font-weight:600; font-size:16px;">Signature BL rapide</div>
        <button type="button" id="closeQuickBlBtn" class="btn">Fermer</button>
      </div>
      <div class="form-card">
        <div class="form-label">Technicien</div>
        <div class="form-content" style="margin-bottom:10px;">
          <input id="modalTechnician" type="text" class="form-input" placeholder="Nom du technicien">
        </div>
        <div class="form-label">Recherche BL (ex: BL199550)</div>
        <div class="form-content">
          <input id="modalQuickBlInput" type="text" class="form-input" placeholder="Ex: BL199550" style="width:100%;">
          <div id="modalQuickBlErr" class="alert alert-error" style="display:none; margin-top:8px;"></div>
          <div id="modalQuickBlResults" style="border:1px solid #e9ecef;border-radius:8px;padding:8px;max-height:280px;overflow:auto; margin-top:10px;"></div>
          
        </div>
      </div>
    </div>
  </div>
</body>
</html>

<script>
(function(){
  window.GLPI_CSRF_TOKEN = <?php echo json_encode($__csrf_token ?? null); ?>;

  function ensureHeaders(init) {
    init = init || {};
    if (!init.credentials) init.credentials = 'same-origin';
    init.headers = init.headers || {};
    if (init.headers.append) {
      init.headers.append('X-Requested-With', 'XMLHttpRequest');
      if (window.GLPI_CSRF_TOKEN) init.headers.append('X-GLPI-CSRF-Token', window.GLPI_CSRF_TOKEN);
    } else {
      init.headers['X-Requested-With'] = 'XMLHttpRequest';
      if (window.GLPI_CSRF_TOKEN) init.headers['X-GLPI-CSRF-Token'] = window.GLPI_CSRF_TOKEN;
    }
    return init;
  }

  var _fetch = window.fetch;
  window.fetch = function(input, init) {
    init = ensureHeaders(init);
    var ct = '';
    if (init.headers) {
      if (typeof init.headers.get === 'function') ct = (init.headers.get('Content-Type') || '').toLowerCase();
      else ct = (init.headers['Content-Type'] || '').toLowerCase();
    }

    if (init.body && (typeof FormData !== 'undefined') && init.body instanceof FormData) {
      if (window.GLPI_CSRF_TOKEN && !init.body.has('_glpi_csrf_token')) {
        init.body.append('_glpi_csrf_token', window.GLPI_CSRF_TOKEN);
      }
    } else if (init.body && typeof init.body === 'string' && /application\/json/.test(ct)) {
      try {
        var o = JSON.parse(init.body);
        if (o && window.GLPI_CSRF_TOKEN && !o._glpi_csrf_token) {
          o._glpi_csrf_token = window.GLPI_CSRF_TOKEN;
          init.body = JSON.stringify(o);
        }
      } catch(e){}
    } else if (init.body && typeof init.body === 'string') {
      if (/application\/x-www-form-urlencoded/.test(ct) || /^[^{}[\]]+=/.test(init.body)) {
        if (window.GLPI_CSRF_TOKEN && !/[?&]_glpi_csrf_token=/.test('&'+init.body)) {
          init.body += (init.body ? '&' : '') + '_glpi_csrf_token=' + encodeURIComponent(window.GLPI_CSRF_TOKEN);
          if (!ct) {
            if (init.headers.append) init.headers.append('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
            else init.headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
          }
        }
      }
    }
    return _fetch(input, init);
  };

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form').forEach(function(f){
      if (!f.querySelector('input[name="_glpi_csrf_token"]')) {
        var h = document.createElement('input');
        h.type='hidden'; h.name='_glpi_csrf_token'; h.value=window.GLPI_CSRF_TOKEN || '';
        f.appendChild(h);
      }
    });
  });
})();
</script>






