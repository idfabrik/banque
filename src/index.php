<?php
$json_file = __DIR__ . '/data.json';
$uploads_dir = __DIR__ . '/uploads';

if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
if (!file_exists($json_file)) file_put_contents($json_file, json_encode([]));

$initial_data_raw = file_get_contents($json_file);
$transactions_array = json_decode($initial_data_raw, true) ?: [];

// Catégories (pour CSVParserService + select UI)
$categories_map = [
    'REVENUE' => [
        'Einnahme Deutschland'     => ['ZAHLUNG', 'UEBERWEISUNG', 'ENTGELT', 'HONORAR', 'AUSZAHLUNG'],
        'Einnahme EU'              => ['VIREMENT', 'SEPA', 'EUROPEAN', 'EINGANG EU'],
        'Einnahme International'   => ['WIRE', 'SWIFT', 'INTERNATIONAL', 'USD', 'TRANSFERWISE', 'WISE'],
        'Versand/Provision'        => ['PAYPAL', 'STRIPE', 'AMAZON', 'EBAY'],
    ],
    'EXPENSES' => [
        'Bezogene Fremdleistungen' => ['FREELANCER', 'SUBUNTERNEHMER', 'AGENTUR', 'DESIGNER'],
        'Telekommunikation' => ['VODAFONE', 'TELEKOM', 'O2', 'TELEFONICA', 'INTERNET'],
        'Reisekosten' => ['LUFTHANSA', 'RYANAIR', 'BOOKING', 'HOTEL', 'BAHNTICKET', 'BAHN'],
        'Fortbildungskosten' => ['UDEMY', 'COURSERA', 'SKILLSHARE', 'SEMINAIRE', 'TRAINING', 'WORKSHOP'],
        'Steuerberatung' => ['STEUERBERATER', 'BUCHHAELTER', 'STEUERKANZLEI', 'TAX'],
        'Laufende EDV-Kosten' => ['ADOBE', 'MICROSOFT', 'APPLE', 'GOOGLE', 'AWS', 'CLOUD', 'GITHUB', 'FIGMA', 'SLACK', 'HOSTING'],
        'Arbeitsmittel' => ['EDEKA', 'REWE', 'ALDI', 'LIDL', 'MODULOR', 'STAPLES', 'OFFICE'],
        'Bürokosten' => ['POST', 'PORTO', 'DHL', 'HERMES', 'DPD', 'UPS', 'BRIEFMARKE', 'PIN.AG', 'PAPETERIE'],
        'Werbekosten' => ['GOOGLE ADS', 'FACEBOOK', 'INSTAGRAM', 'LINKEDIN', 'WERBUNG', 'DRUCKEREI'],
        'Bewirtungsaufwendungen' => ['RESTAURANT', 'CAFE', 'BAR', 'PIZZA', 'SUMUP', 'BURGER'],
        'Treibstoff' => ['SHELL', 'ARAL', 'BP', 'ESSO', 'TOTAL', 'TANKSTELLE', 'DIESEL'],
        'Versicherungen' => ['VERSICHERUNG', 'INSURANCE', 'AXA', 'ALLIANZ', 'DEBEKA', 'BARMER'],
        'Bankgebühren' => ['BANKGEBUEHR', 'KONTOGEBUEHR', 'ZINSEN', 'PROVISIONEN', 'DKB'],
        'Steuern' => ['FINANZAMT', 'STEUER', 'GEWERBESTEUER'],
        'Privat' => ['PRIVAT', 'PERSONAL', 'PERSÖNLICH'],
        'Sonstiges' => ['SONSTIGES', 'DIVERSES'],
    ]
];

$categories_list = [
    // Revenus
    'Einnahme Deutschland',
    'Einnahme EU',
    'Einnahme International',
    // Dépenses
    'Bezogene Fremdleistungen',
    'Telekommunikation',
    'Reisekosten',
    'Werbekosten',
    'Bürokosten',
    'Büromaterial',
    'Hardware',
    'Software/SaaS',
    'Versicherungen',
    'Miete/Pacht',
    'Bankgebühren',
    'Steuern',
    'Bewirtungsaufwendungen',
    'Fortbildungskosten',
    // Divers
    'Privat',
    'Sonstiges',
];
foreach ($transactions_array as $tx) {
    if (!empty($tx['category']) && !in_array($tx['category'], $categories_list)) {
        $categories_list[] = $tx['category'];
    }
}
sort($categories_list);

// Helper pour get_next_id utilisé par CSVParserService
function get_next_id(): int {
    global $transactions_array;
    $max = 0;
    foreach ($transactions_array as $tx) {
        $max = max($max, intval($tx['numeric_id'] ?? 0));
    }
    return $max + 1;
}

// ===== BACKUPS DIR =====
$backups_dir = __DIR__ . '/backups';
if (!is_dir($backups_dir)) mkdir($backups_dir, 0755, true);

// Lister les backups (GET) — hors du bloc POST
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_backups') {
    $files = glob($backups_dir . '/data_*.json');
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $list = array_map(fn($f) => [
        'name'    => basename($f),
        'size'    => round(filesize($f) / 1024, 1),
        'date'    => date('d.m.Y H:i:s', filemtime($f)),
        'records' => count(json_decode(file_get_contents($f), true) ?: [])
    ], array_slice($files, 0, 20));
    header('Content-Type: application/json');
    echo json_encode($list);
    exit;
}

// API LOGIQUE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Upload PJ (pièce jointe)
    if (isset($_FILES['file'])) {
        $tx_id = $_POST['tx_id'];
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $filename = 'pj_' . $tx_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $uploads_dir . '/' . $filename)) {
            echo json_encode(['status' => 'success', 'filename' => $filename]);
        }
        exit;
    }

    // Restaurer un backup
    if (isset($_POST['action']) && $_POST['action'] === 'restore_backup') {
        $name = basename($_POST['backup'] ?? '');
        $src  = $backups_dir . '/' . $name;
        if ($name && file_exists($src) && str_starts_with($name, 'data_') && str_ends_with($name, '.json')) {
            // Sauvegarder l'état actuel avant de restaurer
            $stamp = date('Y-m-d_H-i-s');
            copy($json_file, $backups_dir . "/data_{$stamp}_before_restore.json");
            copy($src, $json_file);
            echo json_encode(['success' => true, 'restored' => $name]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
        }
        exit;
    }

    // Nettoyage des doublons
    if (isset($_POST['action']) && $_POST['action'] === 'deduplicate') {
        $existing = json_decode(file_get_contents($json_file), true) ?: [];

        // Backup avant nettoyage
        $stamp = date('Y-m-d_H-i-s');
        copy($json_file, $backups_dir . "/data_{$stamp}_before_dedup.json");

        // Regrouper par empreinte (date + montant + purpose normalisé)
        // Le purpose est stable entre versions du parser, contrairement au bénéficiaire
        $groups = [];
        foreach ($existing as $idx => $tx) {
            $purp_norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $tx['purpose'] ?? ''));
            $key = $tx['date'] . '|' . round(floatval($tx['amount']), 2) . '|' . $purp_norm;
            $groups[$key][] = $idx;
        }

        $cleaned = [];
        $removed = [];
        $dominated = []; // indices à exclure

        foreach ($groups as $key => $indices) {
            if (count($indices) <= 1) continue; // pas de doublon

            // Vrais doublons : même date+montant+purpose → garder le meilleur
            $best_idx = $indices[0];
            $best_score = 0;
            foreach ($indices as $idx) {
                $tx = $existing[$idx];
                $score = (count($tx['attachments'] ?? []) * 10)
                    + (strlen($tx['notes'] ?? '') > 0 ? 5 : 0)
                    + (strlen($tx['category'] ?? '') > 0 ? 2 : 0)
                    + (strlen($tx['beneficiary'] ?? '') * 0.1); // préférer le bénéficiaire le plus complet
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_idx = $idx;
                }
            }
            // Marquer les autres comme doublons
            foreach ($indices as $idx) {
                if ($idx !== $best_idx) {
                    $dominated[$idx] = true;
                    $tx = $existing[$idx];
                    $removed[] = ['id' => $tx['numeric_id'] ?? '', 'date' => $tx['date'], 'beneficiary' => $tx['beneficiary'], 'amount' => $tx['amount']];
                }
            }
        }

        foreach ($existing as $idx => $tx) {
            if (!isset($dominated[$idx])) {
                $cleaned[] = $tx;
            }
        }

        $json_output = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json_output !== false) {
            file_put_contents($json_file, $json_output);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'before'  => count($existing),
            'after'   => count($cleaned),
            'removed' => count($removed),
            'details' => array_slice($removed, 0, 50)
        ]);
        exit;
    }

    // Import CSV
    if (isset($_FILES['csv'])) {
        require_once __DIR__ . '/CSVParserService.php';
        $content = file_get_contents($_FILES['csv']['tmp_name']);

        // Détection encoding
        $enc = mb_detect_encoding($content, 'UTF-8,ISO-8859-1,Windows-1252', true);
        if ($enc && $enc !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $enc);
        }

        $parser = new CSVParserService($categories_map);
        $new_transactions = $parser->parse($content);

        if (empty($new_transactions)) {
            echo json_encode(['success' => false, 'error' => 'Format non reconnu ou fichier vide (EXTF, Datev, DKB, ING supportés)']);
            exit;
        }

        // ✅ BACKUP avant toute modification
        $stamp = date('Y-m-d_H-i-s');
        $backup_file = $backups_dir . "/data_{$stamp}.json";
        copy($json_file, $backup_file);

        // Charger données existantes
        $existing = json_decode(file_get_contents($json_file), true) ?: [];

        // Index de déduplication (stocke l'index dans $existing pour pouvoir mettre à jour)
        // 1. Hash exact (SHA-256 sur date+montant+bénéficiaire+objet+type)
        $existing_hashes = [];
        foreach ($existing as $idx => $tx) {
            $existing_hashes[$tx['id']] = $idx;
        }

        // 2. Empreinte semi-stricte (date + montant + purpose normalisé)
        //    Sans le bénéficiaire → résiste aux changements de logique d'extraction
        //    Le purpose distingue les achats distincts (ex: 2x Steinecke = 2 numéros VISA différents)
        $strict_fingerprints = [];
        foreach ($existing as $idx => $tx) {
            $purp_norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $tx['purpose'] ?? ''));
            $key = $tx['date'] . '|' . round(floatval($tx['amount']), 2) . '|' . $purp_norm;
            $strict_fingerprints[$key] = $idx;
        }

        // 3. Empreinte souple (date + montant arrondi) → importer mais signaler en jaune
        $soft_fingerprints = [];
        foreach ($existing as $tx) {
            $key = $tx['date'] . '|' . round(floatval($tx['amount']), 2);
            $soft_fingerprints[$key] = true;
        }

        $added      = 0;
        $skipped    = 0;
        $updated    = 0;
        $soft_dupes = 0;
        $soft_dupe_list = [];

        // Met à jour une transaction existante avec les infos du réimport,
        // sans écraser les infos personnalisées (catégorie, PJ, notes custom)
        $updateExisting = function(int $idx, array $new_tx) use (&$existing, &$updated) {
            $old = &$existing[$idx];
            $changed = false;

            // Ajouter la banque source dans les notes si pas déjà présente
            $new_notes = trim($new_tx['notes'] ?? '');
            $old_notes = trim($old['notes'] ?? '');
            if ($new_notes && strpos($old_notes, $new_notes) === false) {
                $old['notes'] = $old_notes ? $old_notes . ' | ' . $new_notes : $new_notes;
                $changed = true;
            }

            // Mettre à jour le bénéficiaire si le nouveau est plus complet
            $old_ben = trim($old['beneficiary'] ?? '');
            $new_ben = trim($new_tx['beneficiary'] ?? '');
            if (!empty($new_ben) && strlen($new_ben) > strlen($old_ben)) {
                $old['beneficiary'] = $new_ben;
                $changed = true;
            }

            // Mettre à jour la catégorie si le réimport force "Privat" et que l'existante ne l'est pas encore
            if (($new_tx['category'] ?? '') === 'Privat' && ($old['category'] ?? '') !== 'Privat') {
                $old['category'] = 'Privat';
                $old['isPrivate'] = true;
                $changed = true;
            }

            if ($changed) $updated++;
        };

        foreach ($new_transactions as $tx) {
            $tx['mwst']      = $tx['tva'] ?? 19;
            $tx['isPrivate'] = ($tx['category'] === 'Privat');

            // Doublon exact (hash) → mettre à jour notes, pas d'ajout
            if (isset($existing_hashes[$tx['id']])) {
                $updateExisting($existing_hashes[$tx['id']], $tx);
                $skipped++;
                continue;
            }

            // Doublon semi-strict (date + montant + purpose) → mettre à jour, pas d'ajout
            $purp_norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $tx['purpose'] ?? ''));
            $strict_key = $tx['date'] . '|' . round(floatval($tx['amount']), 2) . '|' . $purp_norm;
            if (isset($strict_fingerprints[$strict_key])) {
                $updateExisting($strict_fingerprints[$strict_key], $tx);
                $skipped++;
                continue;
            }

            // Doublon souple (date + montant, bénéficiaire différent) → importer mais marquer
            $soft_key = $tx['date'] . '|' . round(floatval($tx['amount']), 2);
            if (isset($soft_fingerprints[$soft_key])) {
                $tx['_soft_dupe'] = true;
                $soft_dupes++;
                $soft_dupe_list[] = [
                    'date'        => $tx['date'],
                    'amount'      => $tx['amount'],
                    'beneficiary' => $tx['beneficiary']
                ];
            }

            $existing[] = $tx;
            $existing_hashes[$tx['id']] = count($existing) - 1;
            $strict_fingerprints[$strict_key] = count($existing) - 1;
            $soft_fingerprints[$soft_key] = true;
            $added++;
        }

        // Encoder en JSON — protéger contre les erreurs d'encodage qui videraient data.json
        $json_output = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json_output === false) {
            // Restaurer depuis le backup si json_encode échoue
            copy($backup_file, $json_file);
            echo json_encode(['success' => false, 'error' => 'Erreur encodage JSON: ' . json_last_error_msg() . ' — backup conservé.']);
            exit;
        }
        file_put_contents($json_file, $json_output);
        echo json_encode([
            'success'     => true,
            'added'       => $added,
            'skipped'     => $skipped,
            'updated'     => $updated,
            'soft_dupes'  => $soft_dupes,
            'soft_list'   => $soft_dupe_list,
            'total'       => count($existing),
            'backup'      => basename($backup_file)
        ]);
        exit;
    }

    // Sauvegarde JSON standard
    $body = file_get_contents('php://input');
    $decoded = $body ? json_decode($body, true) : null;
    if ($decoded !== null && is_array($decoded)) {
        // Re-encoder proprement pour garantir un JSON valide et bien encodé
        $safe = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($safe !== false) {
            file_put_contents($json_file, $safe);
            echo json_encode(['status' => 'ok']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'msg' => 'Encodage JSON échoué: ' . json_last_error_msg()]);
        }
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Compta Ledger Pro v19</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #f3f4f6; color: #1a1a1a; font-family: ui-sans-serif, system-ui, sans-serif; text-transform: none; }
        .row-private { opacity: 0.25; background-color: #f9fafb !important; color: #9ca3af !important; font-size: 10px !important; }
        .row-private td { padding-top: 2px !important; padding-bottom: 2px !important; }
        .row-duplicate { background-color: #fef9c3 !important; border-left: 4px solid #facc15; }
        .amt-recette { color: #00c47f; font-weight: 800; } 
        .amt-depense { color: #1a1a1a; font-weight: 600; }
        .drag-active { background-color: #dbeafe !important; border: 2px dashed #00c47f !important; }
        .month-card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 3rem; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .month-header { background: #1a1a1a; color: #fff; padding: 12px 20px; font-weight: 700; font-size: 16px; }
        .summary-footer { background: #f9fafb; border-top: 2px solid #1a1a1a; padding: 15px 20px; display: grid; grid-template-cols: 1fr 1fr; gap: 40px; }
        th { background: #f9fafb; color: #6b7280; font-size: 11px; font-weight: 600; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; }
        td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
        .pj-badge { background: #1a1a1a; color: #fff; padding: 1px 4px; border-radius: 3px; font-size: 9px; display: flex; align-items: center; gap: 4px; }
        .pj-del { color: #ff4d4d; cursor: pointer; font-weight: bold; border-left: 1px solid #333; padding-left: 4px; }
        .id-badge { background: #f3f4f6; color: #111; padding: 1px 4px; border-radius: 3px; font-size: 10px; font-family: monospace; font-weight: bold; border: 1px solid #ddd; }
        input.note-input { border-bottom: 1px solid transparent; background: transparent; width: 100%; font-size: 11px; outline: none; }
        input.note-input:focus { border-color: #00c47f; background: #fff; }
        .modal { display: none; position: fixed; z-index: 50; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 2rem; border-radius: 12px; width: 100%; max-width: 550px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        button, input, select, label { text-transform: none !important; }
    </style>
</head>
<body class="p-4 md:p-8">

    <div class="max-w-full mx-auto">
        <header class="flex flex-wrap justify-between items-center mb-10 bg-white p-6 rounded-lg border border-gray-200 shadow-sm gap-4">
            <div>
                <h1 class="text-3xl font-black tracking-tighter">Comptabilité</h1>
                <p class="text-xs text-gray-400 font-mono tracking-widest">Résumé masqué en recherche | Option de tri rétablie</p>
            </div>
            <div class="flex gap-4 items-center flex-wrap">
                <button onclick="openModal()" class="bg-[#00c47f] text-white px-5 py-2 rounded-lg font-bold flex items-center gap-2 hover:opacity-90">
                    <span>+</span> Ajouter
                </button>
                <button onclick="openImportModal()" class="bg-gray-800 text-white px-5 py-2 rounded-lg font-bold flex items-center gap-2 hover:opacity-90">
                    ⬆ Import CSV
                </button>
                <label class="flex items-center gap-2 text-xs font-bold cursor-pointer bg-gray-50 px-3 py-2 rounded border border-gray-200">
                    <input type="checkbox" id="hidePrivateToggle" onchange="render()" class="w-4 h-4 accent-[#00c47f]"> Masquer privé
                </label>
                <label class="flex items-center gap-2 text-xs font-bold cursor-pointer bg-gray-50 px-3 py-2 rounded border border-gray-200">
                    <input type="checkbox" id="onlyRevenueToggle" onchange="render()" class="w-4 h-4 accent-[#00c47f]"> Recettes uniquement
                </label>
                <input type="text" id="searchInput" oninput="resetView(); render();" placeholder="Chercher..." class="border rounded px-4 py-2 text-sm outline-none w-48 focus:ring-1 focus:ring-[#00c47f]">
                
                <select id="sortSelect" onchange="render()" class="border rounded px-2 py-2 text-sm bg-white cursor-pointer outline-none focus:ring-1 focus:ring-black">
                    <option value="date_desc">Date récente</option>
                    <option value="date_asc">Date ancienne</option>
                    <option value="amount_desc">Montant ↓</option>
                    <option value="amount_asc">Montant ↑</option>
                </select>

                <button onclick="deduplicateData()" class="bg-red-50 text-red-600 border border-red-200 px-4 py-2 rounded-lg font-bold text-xs hover:bg-red-100">Nettoyer doublons</button>
                <button onclick="saveData()" id="saveBtn" class="bg-black text-white px-8 py-2 rounded-lg font-bold text-xs uppercase">Enregistrer</button>
            </div>
        </header>

        <div id="smartAlerts" class="space-y-3 mb-6"></div>
        <div id="mainContainer"></div>

        <div id="loadMoreContainer" class="flex justify-center gap-4 py-12 hidden">
            <button onclick="loadMore()" class="border-2 border-black px-8 py-3 font-bold text-xs hover:bg-black hover:text-white transition-all rounded-lg">+ Voir plus</button>
            <button onclick="showAllMonths()" class="bg-gray-200 text-gray-700 px-8 py-3 font-bold text-xs hover:bg-gray-300 transition-all rounded-lg">Voir tous</button>
        </div>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 class="text-xl font-black mb-4 border-b pb-2">Nouvelle transaction</h2>
            <div class="mb-6 p-3 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                <label class="block text-[10px] font-black text-gray-500 uppercase mb-1 italic">Copier à partir de l'ID</label>
                <select id="m_copy_id" onchange="applyCopy(this.value)" class="w-full border rounded p-2 text-xs bg-white focus:outline-none">
                    <option value="">-- Ne pas copier --</option>
                </select>
            </div>
            <form id="addForm" onsubmit="handleManualSubmit(event)" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-[10px] font-bold text-gray-400">Date</label><input type="date" id="m_date" required class="w-full border rounded p-2 text-sm"></div>
                    <div><label class="block text-[10px] font-bold text-gray-400">Type</label><select id="m_type" class="w-full border rounded p-2 text-sm"><option value="Dépense">Dépense</option><option value="Entrée">Recette (Entrée)</option></select></div>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-400">Bénéficiaire</label><input type="text" id="m_beneficiary" required class="w-full border rounded p-2 text-sm"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-[10px] font-bold text-gray-400">Montant Brut (€)</label><input type="number" step="0.01" id="m_amount" required class="w-full border rounded p-2 text-sm"></div>
                    <div><label class="block text-[10px] font-bold text-gray-400">TVA / MwSt</label><select id="m_mwst" class="w-full border rounded p-2 text-sm"><option value="19">19%</option><option value="7">7%</option><option value="0">0%</option></select></div>
                </div>
                <div><label class="block text-[10px] font-bold text-gray-400">Catégorie</label><select id="m_category" class="w-full border rounded p-2 text-sm">
  <optgroup label="── Revenus ──">
    <option value="Einnahme Deutschland">Einnahme Deutschland</option>
    <option value="Einnahme EU">Einnahme EU</option>
    <option value="Einnahme International">Einnahme International</option>
  </optgroup>
  <optgroup label="── Dépenses ──">
    <?php foreach($categories_list as $cat): if (in_array($cat, ['Einnahme Deutschland','Einnahme EU','Einnahme International','Privat','Sonstiges'])) continue; ?>
    <option value="<?= $cat ?>"><?= $cat ?></option>
    <?php endforeach; ?>
  </optgroup>
  <optgroup label="── Divers ──">
    <option value="Privat">Privat</option>
    <option value="Sonstiges">Sonstiges</option>
  </optgroup>
</select></div>
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeModal()" class="flex-1 border py-2 rounded-lg font-bold text-gray-400">Annuler</button>
                    <button type="submit" class="flex-1 bg-black text-white py-2 rounded-lg font-bold">Ajouter</button>
                </div>
            </form>
        </div>
    </div>

    <input type="file" id="globalFilePicker" class="hidden" onchange="uploadFile(this.files[0])">

    <!-- MODAL IMPORT CSV -->
    <div id="importModal" class="modal">
        <div class="modal-content" style="max-width:560px;">
            <h2 class="text-xl font-black mb-1 border-b pb-2">⬆ Import CSV bancaire</h2>
            <p class="text-xs text-gray-400 mb-4">Formats supportés : DATEV EXTF · DATEV · DKB · ING &nbsp;·&nbsp; Un backup automatique est créé avant chaque import.</p>

            <!-- Drop zone -->
            <div id="importDropZone"
                 class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer transition-all hover:border-[#00c47f] hover:bg-green-50"
                 onclick="document.getElementById('csvFilePicker').click()"
                 ondragover="event.preventDefault(); this.classList.add('border-[#00c47f]','bg-green-50');"
                 ondragleave="this.classList.remove('border-[#00c47f]','bg-green-50');"
                 ondrop="event.preventDefault(); this.classList.remove('border-[#00c47f]','bg-green-50'); handleCSVDrop(event.dataTransfer.files[0]);">
                <p class="text-3xl mb-2">📂</p>
                <p class="font-bold text-gray-600">Glisser le fichier CSV ici</p>
                <p class="text-xs text-gray-400 mt-1">ou cliquer pour choisir</p>
                <input type="file" id="csvFilePicker" accept=".csv" class="hidden" onchange="handleCSVInput(this.files[0])">
            </div>

            <!-- Status -->
            <div id="importStatus" class="hidden mt-4 p-3 rounded-lg text-sm font-bold"></div>

            <!-- Soft dupes warning -->
            <div id="softDupesBox" class="hidden mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-xs text-yellow-800">
                <p class="font-black mb-1">⚠️ Transactions avec date+montant identiques déjà présentes (importées quand même — vérifier)&nbsp;:</p>
                <ul id="softDupesList" class="list-disc pl-4 space-y-0.5 mt-1 font-mono"></ul>
            </div>

            <!-- Backup panel -->
            <div class="mt-5 border-t pt-4">
                <div class="flex justify-between items-center mb-2">
                    <p class="text-xs font-black text-gray-500 uppercase tracking-wide">🗂 Backups disponibles</p>
                    <button onclick="loadBackups()" class="text-xs text-[#00c47f] font-bold hover:underline bg-transparent border-0 p-0">↺ Rafraîchir</button>
                </div>
                <div id="backupList" class="text-xs text-gray-400 italic">Cliquer sur "Rafraîchir" pour charger.</div>
            </div>

            <div class="flex gap-3 mt-5">
                <button type="button" onclick="closeImportModal()" class="flex-1 border py-2 rounded-lg font-bold text-gray-400">Fermer</button>
            </div>
        </div>
    </div>

<script>
    let transactions = <?php echo $initial_data_raw ?: '[]'; ?>;
    const categories = <?php echo json_encode($categories_list); ?>;
    let currentUploadTxId = null;
    let monthsShown = 2;

    function safeParseDate(str) {
        if (!str) return new Date();
        if (typeof str === 'string' && str.includes('.')) {
            const p = str.split('.');
            let d = parseInt(p[0]), m = parseInt(p[1]), y = parseInt(p[2]);
            if (y < 100) y += 2000;
            return new Date(y, m - 1, d);
        }
        let dObj = new Date(str);
        if (dObj.getFullYear() < 2000) dObj.setFullYear(dObj.getFullYear() + 100);
        return dObj;
    }

    function resetView() { monthsShown = 2; }
    function loadMore() { monthsShown += 3; render(); }
    function showAllMonths() { monthsShown = 999; render(); }

    function openModal() {
        const copySelect = document.getElementById('m_copy_id');
        copySelect.innerHTML = '<option value="">-- Ne pas copier --</option>';
        [...transactions].sort((a,b) => parseInt(b.numeric_id) - parseInt(a.numeric_id)).slice(0, 50).forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.innerText = `ID ${t.numeric_id} | ${t.beneficiary} (${t.amount}€)`;
            copySelect.appendChild(opt);
        });
        document.getElementById('addModal').style.display = 'flex';
    }
    function closeModal() { document.getElementById('addModal').style.display = 'none'; document.getElementById('addForm').reset(); }
    function applyCopy(id) {
        if (!id) return;
        const tx = transactions.find(t => String(t.id) === String(id));
        if (tx) {
            if (tx.date.includes('.')) {
                const [d, m, y] = tx.date.split('.');
                document.getElementById('m_date').value = `${y}-${m.padStart(2,'0')}-${d.padStart(2,'0')}`;
            }
            document.getElementById('m_type').value = tx.type;
            document.getElementById('m_beneficiary').value = tx.beneficiary;
            document.getElementById('m_amount').value = tx.amount;
            document.getElementById('m_mwst').value = tx.mwst || tx.tva || 0;
            document.getElementById('m_category').value = tx.category;
        }
    }
    function handleManualSubmit(e) {
        e.preventDefault();
        const maxNumericId = transactions.reduce((max, t) => Math.max(max, parseInt(t.numeric_id || 0)), 0);
        const newTx = {
            id: 'man_' + Date.now(),
            numeric_id: maxNumericId + 1,
            date: document.getElementById('m_date').value.split('-').reverse().join('.'),
            type: document.getElementById('m_type').value,
            beneficiary: document.getElementById('m_beneficiary').value,
            purpose: "Saisie manuelle",
            amount: parseFloat(document.getElementById('m_amount').value),
            mwst: parseInt(document.getElementById('m_mwst').value),
            category: document.getElementById('m_category').value,
            isPrivate: (document.getElementById('m_category').value === 'Privat'),
            notes: "", attachments: []
        };
        transactions.push(newTx); closeModal(); render(); autoSave();
    }

    // --- RENDU ---
    function render() {
        const container = document.getElementById('mainContainer');
        const searchInput = document.getElementById('searchInput');
        const search = searchInput.value.toLowerCase();
        const sort = document.getElementById('sortSelect').value;
        const hidePrivate = document.getElementById('hidePrivateToggle').checked;
        const onlyRevenue = document.getElementById('onlyRevenueToggle').checked;
        
        // Logique pour masquer les résumés pendant une recherche
        const isSearching = search.length > 0;

        let filtered = transactions.filter(t => {
            const isPriv = (t.category === 'Privat' || t.isPrivate);
            if (hidePrivate && isPriv) return false;
            if (onlyRevenue && t.type !== 'Entrée') return false;
            return (String(t.numeric_id || '') + ' ' + (t.beneficiary || '') + ' ' + (t.notes || '')).toLowerCase().includes(search);
        });

        const duplicateCounts = {};
        filtered.forEach(t => {
            const key = `${t.date}_${t.amount}_${t.type}`;
            duplicateCounts[key] = (duplicateCounts[key] || 0) + 1;
        });

        // Application du tri
        filtered.sort((a, b) => {
            if (sort === 'date_desc') return safeParseDate(b.date) - safeParseDate(a.date);
            if (sort === 'date_asc') return safeParseDate(a.date) - safeParseDate(b.date);
            if (sort === 'amount_desc') return parseFloat(b.amount) - parseFloat(a.amount);
            if (sort === 'amount_asc') return parseFloat(a.amount) - parseFloat(b.amount);
            return 0;
        });

        const groups = {};
        filtered.forEach(t => {
            const date = safeParseDate(t.date);
            const key = date.toLocaleString('fr-FR', { month: 'long', year: 'numeric' });
            if (!groups[key]) groups[key] = { txs: [], rec: { b:0, t:0, h:0 }, dep: { b:0, t:0, h:0 } };
            groups[key].txs.push(t);
            if (!t.isPrivate) {
                const b = Math.abs(parseFloat(t.amount || 0));
                const rate = parseFloat(t.mwst || 0) / 100;
                const h = b / (1 + rate);
                const tva = b - h;
                if (t.type === 'Entrée') { groups[key].rec.b += b; groups[key].rec.h += h; groups[key].rec.t += tva; }
                else { groups[key].dep.b += b; groups[key].dep.h += h; groups[key].dep.t += tva; }
            }
        });

        const keys = Object.keys(groups);
        container.innerHTML = '';
        keys.slice(0, monthsShown).forEach(month => {
            const data = groups[month];
            const block = document.createElement('div');
            block.className = 'month-card';
            
            // On n'affiche le footer que si on ne fait pas de recherche
            const footerHtml = isSearching ? '' : `
                <div class="summary-footer">
                    <div>
                        <h4 style="color:#00c47f; font-weight:700; border-bottom:1px solid #eee; margin-bottom:5px;">Recettes</h4>
                        <div class="flex justify-between text-[11px]">Net (HT): <b>${data.rec.h.toFixed(2)}€</b></div>
                        <div class="flex justify-between text-[11px]">TVA: <b>${data.rec.t.toFixed(2)}€</b></div>
                        <div class="flex justify-between text-lg font-black mt-1 border-t border-gray-200" style="color:#00c47f;">Total Brut: <b>${data.rec.b.toFixed(2)}€</b></div>
                    </div>
                    <div>
                        <h4 style="font-weight:700; border-bottom:1px solid #eee; margin-bottom:5px;">Dépenses</h4>
                        <div class="flex justify-between text-[11px]">Net (HT): <b>${data.dep.h.toFixed(2)}€</b></div>
                        <div class="flex justify-between text-[11px]">TVA: <b>${data.dep.t.toFixed(2)}€</b></div>
                        <div class="flex justify-between text-lg font-black mt-1 border-t border-gray-200">Total Brut: <b>${data.dep.b.toFixed(2)}€</b></div>
                    </div>
                </div>
            `;

            block.innerHTML = `
                <div class="month-header">${month}</div>
                <table class="w-full text-left">
                    <thead><tr><th width="30">Pr.</th><th width="70">ID</th><th width="85">Date</th><th width="200">Bénéficiaire</th><th width="150">Notes</th><th width="65">MwSt</th><th width="90" class="text-right">Montant</th><th width="160">Catégorie</th><th width="100">PJ</th><th width="30"></th></tr></thead>
                    <tbody id="tbody-${month.replace(/\s/g, '')}"></tbody>
                </table>
                ${footerHtml}
            `;
            container.appendChild(block);
            const tbody = document.getElementById(`tbody-${month.replace(/\s/g, '')}`);
            data.txs.forEach(t => {
                const tr = document.createElement('tr');
                const isPriv = (t.category === 'Privat' || t.isPrivate);
                const isDup = duplicateCounts[`${t.date}_${t.amount}_${t.type}`] > 1;
                tr.className = (isPriv ? 'row-private ' : '') + (isDup ? 'row-duplicate' : '');
                
                tr.addEventListener('dragover', (e) => { e.preventDefault(); tr.classList.add('drag-active'); });
                tr.addEventListener('dragleave', () => tr.classList.remove('drag-active'));
                tr.addEventListener('drop', (e) => {
                    e.preventDefault(); tr.classList.remove('drag-active');
                    currentUploadTxId = t.id;
                    if (e.dataTransfer.files.length) uploadFile(e.dataTransfer.files[0]);
                });

                tr.innerHTML = `
                    <td class="text-center"><input type="checkbox" ${isPriv ? 'checked' : ''} onchange="togglePriv('${t.id}', this.checked)" class="accent-[#00c47f]"></td>
                    <td><span class="id-badge">${t.numeric_id || '---'}</span></td>
                    <td class="text-gray-400 font-mono text-[11px]">${safeParseDate(t.date).toLocaleDateString('fr-FR')}</td>
                    <td><div class="font-bold text-[11px] leading-tight">${t.beneficiary || '---'}</div></td>
                    <td><input type="text" value="${t.notes || ''}" class="note-input" onchange="updateTx('${t.id}', 'notes', this.value)" placeholder="..."></td>
                    <td><select onchange="updateTx('${t.id}', 'mwst', this.value)" class="text-[10px] bg-transparent outline-none">
                        <option value="0" ${t.mwst == 0 ? 'selected' : ''}>0%</option><option value="7" ${t.mwst == 7 ? 'selected' : ''}>7%</option><option value="19" ${t.mwst == 19 ? 'selected' : ''}>19%</option>
                    </select></td>
                    <td class="text-right ${t.type === 'Entrée' ? 'amt-recette' : 'amt-depense'}">${parseFloat(t.amount || 0).toFixed(2)}€</td>
                    <td><select onchange="changeCat('${t.id}', this.value)" class="text-[10px] w-full font-bold outline-none bg-transparent">
                        <option value="">Choisir</option>
                        <optgroup label="Revenus">${['Einnahme Deutschland','Einnahme EU','Einnahme International'].map(c => `<option value="${c}" ${t.category === c ? 'selected' : ''}>${c}</option>`).join('')}</optgroup>
                        <optgroup label="Dépenses">${categories.filter(c => !['Einnahme Deutschland','Einnahme EU','Einnahme International','Privat','Sonstiges'].includes(c)).map(c => `<option value="${c}" ${t.category === c ? 'selected' : ''}>${c}</option>`).join('')}</optgroup>
                        <optgroup label="Divers">${['Privat','Sonstiges'].map(c => `<option value="${c}" ${t.category === c ? 'selected' : ''}>${c}</option>`).join('')}</optgroup>
                    </select></td>
                    <td><div class="flex flex-wrap gap-1">
                        ${(t.attachments || []).map(f => `<div class="pj-badge"><a href="uploads/${f}" target="_blank">Pj</a><span class="pj-del" onclick="deletePJ('${t.id}', '${f}')">✕</span></div>`).join('')}
                        <button onclick="currentUploadTxId='${t.id}'; document.getElementById('globalFilePicker').click();" class="text-gray-300">⊕</button>
                    </div></td>
                    <td class="text-right"><button onclick="deleteTx('${t.id}')" class="text-gray-200 hover:text-red-500 text-xs font-bold">✕</button></td>
                `;
                tbody.appendChild(tr);
            });
        });
        document.getElementById('loadMoreContainer').style.display = (monthsShown < Object.keys(groups).length) ? 'flex' : 'none';
    }

    // ACTIONS
    function togglePriv(id, chk) {
        const tx = transactions.find(t => String(t.id) === String(id));
        if (tx) { tx.isPrivate = chk; tx.category = chk ? 'Privat' : ''; render(); autoSave(); }
    }
    function changeCat(id, val) {
        const tx = transactions.find(t => String(t.id) === String(id));
        if (tx) { tx.category = val; tx.isPrivate = (val === 'Privat'); render(); autoSave(); }
    }
    function updateTx(id, f, v) { const tx = transactions.find(t => String(t.id) === String(id)); if (tx) { tx[f] = v; autoSave(); } }
    function deleteTx(id) { if (confirm("Supprimer ?")) { transactions = transactions.filter(t => String(t.id) !== String(id)); render(); autoSave(); } }
    function deletePJ(txId, filename) {
        if (!confirm("Supprimer ce fichier ?")) return;
        const tx = transactions.find(t => String(t.id) === String(txId));
        if (tx && tx.attachments) { tx.attachments = tx.attachments.filter(f => f !== filename); render(); autoSave(); }
    }
    async function uploadFile(file) {
        if (!file || !currentUploadTxId) return;
        const formData = new FormData(); formData.append('file', file); formData.append('tx_id', currentUploadTxId);
        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            const tx = transactions.find(t => String(t.id) === String(currentUploadTxId));
            if (!tx.attachments) tx.attachments = [];
            tx.attachments.push(data.filename); render(); autoSave();
        }
    }
    // Auto-save avec debounce (500ms)
    let _saveTimer = null;
    let _saving = false;
    function autoSave() {
        clearTimeout(_saveTimer);
        _saveTimer = setTimeout(() => _doSave(), 500);
    }
    async function _doSave() {
        if (_saving) return;
        _saving = true;
        const btn = document.getElementById('saveBtn');
        btn.innerText = '...';
        try {
            const res = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(transactions)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'error') {
                btn.innerText = 'Sauvegardé ✅';
            } else {
                btn.innerText = '❌ ERREUR';
                console.error('Save error:', data);
            }
        } catch(e) {
            btn.innerText = '❌ ERREUR';
            console.error('Save error:', e);
        }
        _saving = false;
        setTimeout(() => btn.innerText = 'Enregistrer', 2000);
    }
    // Bouton manuel = save immédiat
    function saveData() { clearTimeout(_saveTimer); _doSave(); }
    // ===== IMPORT CSV + BACKUP =====
    function openImportModal() {
        document.getElementById('importStatus').classList.add('hidden');
        document.getElementById('softDupesBox').classList.add('hidden');
        document.getElementById('importModal').style.display = 'flex';
        loadBackups();
    }
    function closeImportModal() {
        document.getElementById('importModal').style.display = 'none';
        document.getElementById('csvFilePicker').value = '';
    }
    function handleCSVDrop(file) { if (file) importCSV(file); }
    function handleCSVInput(file) { if (file) importCSV(file); }

    function importCSV(file) {
        const status    = document.getElementById('importStatus');
        const softBox   = document.getElementById('softDupesBox');
        softBox.classList.add('hidden');
        status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-gray-100 text-gray-600';
        status.classList.remove('hidden');
        status.innerText = '⏳ Import en cours…';

        const form = new FormData();
        form.append('csv', file);

        fetch('index.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let msg = `✅ ${data.added} transaction(s) importée(s)`;
                    if (data.updated > 0)     msg += ` · ${data.updated} note(s) mise(s) à jour`;
                    if (data.skipped > 0)     msg += ` · ${data.skipped} doublon(s) ignoré(s)`;
                    if (data.soft_dupes > 0)  msg += ` · ⚠️ ${data.soft_dupes} quasi-doublon(s)`;
                    msg += ` · Total: ${data.total}`;
                    if (data.backup)          msg += `\n💾 Backup : ${data.backup}`;

                    status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-green-100 text-green-800 whitespace-pre-line';
                    status.innerText = msg;

                    // Afficher quasi-doublons si présents
                    if (data.soft_dupes > 0 && data.soft_list && data.soft_list.length) {
                        const ul = document.getElementById('softDupesList');
                        ul.innerHTML = data.soft_list.map(d =>
                            `<li>${d.date} · ${parseFloat(d.amount).toFixed(2)}€ · ${d.beneficiary}</li>`
                        ).join('');
                        softBox.classList.remove('hidden');
                    }

                    loadBackups();
                    setTimeout(() => { closeImportModal(); location.reload(); }, 3000);
                } else {
                    status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-red-100 text-red-800';
                    status.innerText = '❌ ' + (data.error || 'Erreur inconnue');
                }
            })
            .catch(e => {
                status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-red-100 text-red-800';
                status.innerText = '❌ Erreur réseau : ' + e;
            });
    }

    // Charger la liste des backups
    function loadBackups() {
        const box = document.getElementById('backupList');
        box.innerHTML = '<span class="italic text-gray-300">Chargement…</span>';
        fetch('index.php?action=list_backups')
            .then(r => r.json())
            .then(list => {
                if (!list.length) { box.innerHTML = '<span class="italic">Aucun backup.</span>'; return; }
                box.innerHTML = list.map(b => `
                    <div class="flex items-center justify-between py-1 border-b border-gray-100 gap-2">
                        <span class="font-mono text-gray-500 truncate" style="max-width:300px;" title="${b.name}">${b.date}</span>
                        <span class="text-gray-400">${b.records} entrées · ${b.size} Ko</span>
                        <button onclick="restoreBackup('${b.name}')"
                                class="text-red-400 hover:text-red-700 font-black text-xs border border-red-200 rounded px-2 py-0.5 hover:bg-red-50 bg-transparent">
                            Restaurer
                        </button>
                    </div>
                `).join('');
            })
            .catch(() => { box.innerHTML = '<span class="text-red-400">Erreur de chargement.</span>'; });
    }

    // Restaurer un backup
    function restoreBackup(name) {
        if (!confirm(`⚠️ Restaurer "${name}" ?\n\nL'état actuel sera sauvegardé automatiquement avant la restauration.`)) return;
        const form = new FormData();
        form.append('action', 'restore_backup');
        form.append('backup', name);
        fetch('index.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (data.success) { alert('✅ Restauré. La page va recharger.'); location.reload(); }
                else alert('❌ Erreur : ' + data.error);
            });
    }

    function deduplicateData() {
        if (!confirm('Nettoyer les doublons ?\n\nUn backup sera créé automatiquement.\nLes doublons (même date + montant + bénéficiaire) seront supprimés.\nLa version avec le plus d\'infos (PJ, notes, catégorie) est conservée.')) return;
        const form = new FormData();
        form.append('action', 'deduplicate');
        fetch('index.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let msg = `${data.removed} doublon(s) supprimé(s) (${data.before} → ${data.after} transactions)`;
                    if (data.details && data.details.length > 0) {
                        msg += '\n\nSupprimés :';
                        data.details.forEach(d => {
                            msg += `\n  ID ${d.id} | ${d.date} | ${d.beneficiary} | ${d.amount}€`;
                        });
                    }
                    alert(msg);
                    if (data.removed > 0) location.reload();
                } else {
                    alert('Erreur : ' + (data.error || 'inconnue'));
                }
            })
            .catch(e => alert('Erreur réseau : ' + e));
    }

    // ===== SERVICE INTELLIGENT =====

    function smartAnalyze() {
        const alertsBox = document.getElementById('smartAlerts');
        alertsBox.innerHTML = '';

        // 1. Compter les catégories par bénéficiaire (normalisé)
        const benCats = {}; // { beneficiary: { cat: count } }
        transactions.forEach(tx => {
            if (!tx.beneficiary || !tx.category) return;
            const ben = tx.beneficiary.trim().toUpperCase();
            if (!benCats[ben]) benCats[ben] = {};
            benCats[ben][tx.category] = (benCats[ben][tx.category] || 0) + 1;
        });

        // 2. Auto-catégorisation : si un bénéficiaire a 3+ occurrences dans une catégorie,
        //    proposer de catégoriser les transactions sans catégorie de ce bénéficiaire
        const suggestions = []; // { beneficiary, category, count, targets: [indices] }
        transactions.forEach((tx, idx) => {
            if (tx.category) return; // déjà catégorisé
            const ben = (tx.beneficiary || '').trim().toUpperCase();
            if (!benCats[ben]) return;

            // Trouver la catégorie dominante (3+ occurrences)
            let bestCat = null, bestCount = 0;
            for (const [cat, count] of Object.entries(benCats[ben])) {
                if (count >= 3 && count > bestCount) {
                    bestCat = cat;
                    bestCount = count;
                }
            }
            if (bestCat) {
                let existing = suggestions.find(s => s.beneficiary === ben && s.category === bestCat);
                if (!existing) {
                    existing = { beneficiary: ben, category: bestCat, count: bestCount, targets: [] };
                    suggestions.push(existing);
                }
                existing.targets.push(idx);
            }
        });

        // Afficher les suggestions
        suggestions.forEach(s => {
            const displayBen = transactions[s.targets[0]].beneficiary;
            const div = document.createElement('div');
            div.className = 'bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center justify-between gap-4';
            div.innerHTML = `
                <div>
                    <span class="font-black text-blue-800 text-sm">Suggestion</span>
                    <span class="text-sm text-blue-700 ml-2">
                        <strong>${displayBen}</strong> est catégorisé <strong>${s.category}</strong> ${s.count}x
                        — ${s.targets.length} transaction(s) sans catégorie à mettre à jour
                    </span>
                </div>
                <div class="flex gap-2 shrink-0">
                    <button onclick="applySmartCategory('${displayBen.replace(/'/g, "\\'")}', '${s.category}', [${s.targets}]); this.closest('div.bg-blue-50').remove();"
                            class="bg-blue-600 text-white px-4 py-1.5 rounded font-bold text-xs hover:bg-blue-700">Appliquer</button>
                    <button onclick="this.closest('div.bg-blue-50').remove();"
                            class="border border-blue-300 text-blue-600 px-4 py-1.5 rounded font-bold text-xs hover:bg-blue-100">Ignorer</button>
                </div>
            `;
            alertsBox.appendChild(div);
        });

        // 3. Alerte conflit : bénéficiaire dans plusieurs catégories différentes
        const conflicts = [];
        for (const [ben, cats] of Object.entries(benCats)) {
            const catEntries = Object.entries(cats).filter(([c, n]) => c && c !== 'Privat' && c !== 'Sonstiges');
            if (catEntries.length >= 2) {
                const displayBen = transactions.find(t => t.beneficiary && t.beneficiary.trim().toUpperCase() === ben)?.beneficiary || ben;
                conflicts.push({
                    beneficiary: displayBen,
                    categories: catEntries.map(([c, n]) => `${c} (${n}x)`).join(', ')
                });
            }
        }

        if (conflicts.length > 0) {
            const div = document.createElement('div');
            div.className = 'bg-amber-50 border border-amber-300 rounded-lg p-4';
            div.innerHTML = `
                <p class="font-black text-amber-800 text-sm mb-2">Conflits de catégorie détectés</p>
                <ul class="text-xs text-amber-700 space-y-1">
                    ${conflicts.map(c => `<li><strong>${c.beneficiary}</strong> : ${c.categories}</li>`).join('')}
                </ul>
            `;
            alertsBox.appendChild(div);
        }
    }

    function applySmartCategory(beneficiary, category, indices) {
        indices.forEach(idx => {
            transactions[idx].category = category;
            if (category === 'Privat') transactions[idx].isPrivate = true;
        });
        render(); autoSave();
        // Ne pas rappeler smartAnalyze ici, render() le fait déjà
    }

    render();
    smartAnalyze();
</script>
</body>
</html>