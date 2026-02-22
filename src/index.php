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
        'Dienstleistungen' => ['AUSZAHLUNG', 'UEBERWEISUNG', 'TRANSFER', 'ZAHLUNG', 'ENTGELT', 'HONORAR'],
        'Versand/Provision' => ['PAYPAL', 'STRIPE', 'AMAZON', 'EBAY'],
    ],
    'EXPENSES' => [
        'Bezogene Fremdleistungen' => ['FREELANCER', 'SUBUNTERNEHMER', 'AGENTUR', 'DESIGNER'],
        'Telekommunikation' => ['VODAFONE', 'TELEKOM', 'O2', 'TELEFONICA', 'INTERNET'],
        'Reisekosten' => ['LUFTHANSA', 'RYANAIR', 'BOOKING', 'HOTEL', 'BAHNTICKET', 'BAHN'],
        'Fortbildungskosten' => ['UDEMY', 'COURSERA', 'SKILLSHARE', 'SEMINAIRE', 'TRAINING', 'WORKSHOP'],
        'Steuerberatung' => ['STEUERBERATER', 'BUCHHAELTER', 'STEUERKANZLEI', 'TAX'],
        'Laufende EDV-Kosten' => ['ADOBE', 'MICROSOFT', 'APPLE', 'GOOGLE', 'AWS', 'CLOUD', 'GITHUB', 'FIGMA', 'SLACK', 'HOSTING'],
        'Arbeitsmittel' => ['EDEKA', 'REWE', 'ALDI', 'LIDL', 'MODULOR', 'STAPLES', 'OFFICE'],
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

$categories_list = ["Privat", "Dienstleistungen", "Telekommunikation", "Reisekosten", "Werbekosten", "Büromaterial", "Hardware", "Software/SaaS", "Versicherungen", "Miete/Pacht", "Bankgebühren", "Sonstiges"];
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
            echo json_encode(['success' => false, 'error' => 'Format non reconnu ou fichier vide (EXTF, Datev, DKB supportés)']);
            exit;
        }

        // Charger données existantes et dédupliquer par hash id
        $existing = json_decode(file_get_contents($json_file), true) ?: [];
        $existing_hashes = array_column($existing, 'id');

        $added = 0;
        foreach ($new_transactions as $tx) {
            if (!in_array($tx['id'], $existing_hashes)) {
                // Adapter le format pour la v19 (mwst au lieu de tva)
                $tx['mwst'] = $tx['tva'] ?? 19;
                $tx['isPrivate'] = ($tx['category'] === 'Privat');
                $existing[] = $tx;
                $added++;
            }
        }

        file_put_contents($json_file, json_encode($existing));
        echo json_encode(['success' => true, 'added' => $added, 'total' => count($existing), 'skipped' => count($new_transactions) - $added]);
        exit;
    }

    // Sauvegarde JSON standard
    $body = file_get_contents('php://input');
    if ($body && json_decode($body) !== null) {
        file_put_contents($json_file, $body);
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

                <button onclick="saveData()" id="saveBtn" class="bg-black text-white px-8 py-2 rounded-lg font-bold text-xs uppercase">Enregistrer</button>
            </div>
        </header>

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
                <div><label class="block text-[10px] font-bold text-gray-400">Catégorie</label><select id="m_category" class="w-full border rounded p-2 text-sm"><?php foreach($categories_list as $cat): ?><option value="<?= $cat ?>"><?= $cat ?></option><?php endforeach; ?></select></div>
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
        <div class="modal-content" style="max-width:520px;">
            <h2 class="text-xl font-black mb-1 border-b pb-2">⬆ Import CSV bancaire</h2>
            <p class="text-xs text-gray-400 mb-4">Formats supportés : DATEV EXTF · DATEV · DKB<br>Les doublons sont automatiquement ignorés (déduplication par hash).</p>

            <div id="importDropZone"
                 class="border-2 border-dashed border-gray-300 rounded-xl p-10 text-center cursor-pointer transition-all hover:border-[#00c47f] hover:bg-green-50"
                 onclick="document.getElementById('csvFilePicker').click()"
                 ondragover="event.preventDefault(); this.classList.add('border-[#00c47f]','bg-green-50');"
                 ondragleave="this.classList.remove('border-[#00c47f]','bg-green-50');"
                 ondrop="event.preventDefault(); this.classList.remove('border-[#00c47f]','bg-green-50'); handleCSVDrop(event.dataTransfer.files[0]);">
                <p class="text-3xl mb-2">📂</p>
                <p class="font-bold text-gray-600">Glisser le fichier CSV ici</p>
                <p class="text-xs text-gray-400 mt-1">ou cliquer pour choisir</p>
                <input type="file" id="csvFilePicker" accept=".csv" class="hidden" onchange="handleCSVInput(this.files[0])">
            </div>

            <div id="importStatus" class="hidden mt-4 p-3 rounded-lg text-sm font-bold"></div>

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
        transactions.push(newTx); closeModal(); render();
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
                        <option value="">Choisir</option>${categories.map(c => `<option value="${c}" ${t.category === c ? 'selected' : ''}>${c}</option>`).join('')}
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
        if (tx) { tx.isPrivate = chk; tx.category = chk ? 'Privat' : ''; render(); }
    }
    function changeCat(id, val) {
        const tx = transactions.find(t => String(t.id) === String(id));
        if (tx) { tx.category = val; tx.isPrivate = (val === 'Privat'); render(); }
    }
    function updateTx(id, f, v) { const tx = transactions.find(t => String(t.id) === String(id)); if (tx) { tx[f] = v; } }
    function deleteTx(id) { if (confirm("Supprimer ?")) { transactions = transactions.filter(t => String(t.id) !== String(id)); render(); } }
    function deletePJ(txId, filename) {
        if (!confirm("Supprimer ce fichier ?")) return;
        const tx = transactions.find(t => String(t.id) === String(txId));
        if (tx && tx.attachments) { tx.attachments = tx.attachments.filter(f => f !== filename); render(); }
    }
    async function uploadFile(file) {
        if (!file || !currentUploadTxId) return;
        const formData = new FormData(); formData.append('file', file); formData.append('tx_id', currentUploadTxId);
        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            const tx = transactions.find(t => String(t.id) === String(currentUploadTxId));
            if (!tx.attachments) tx.attachments = [];
            tx.attachments.push(data.filename); render();
        }
    }
    async function saveData() {
        const btn = document.getElementById('saveBtn'); btn.innerText = "...";
        await fetch('index.php', { method: 'POST', body: JSON.stringify(transactions) });
        btn.innerText = "SAUVEGARDÉ ✅"; setTimeout(() => btn.innerText = "Enregistrer", 2000);
    }
    // ===== IMPORT CSV =====
    function openImportModal() {
        document.getElementById('importStatus').classList.add('hidden');
        document.getElementById('importModal').style.display = 'flex';
    }
    function closeImportModal() {
        document.getElementById('importModal').style.display = 'none';
        document.getElementById('csvFilePicker').value = '';
    }
    function handleCSVDrop(file) { if (file) importCSV(file); }
    function handleCSVInput(file) { if (file) importCSV(file); }

    function importCSV(file) {
        const status = document.getElementById('importStatus');
        status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-gray-100 text-gray-600';
        status.classList.remove('hidden');
        status.innerText = '⏳ Import en cours…';

        const form = new FormData();
        form.append('csv', file);

        fetch('index.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    status.className = 'mt-4 p-3 rounded-lg text-sm font-bold bg-green-100 text-green-800';
                    status.innerText = `✅ ${data.added} transaction(s) importée(s)${data.skipped > 0 ? ' · ' + data.skipped + ' doublon(s) ignoré(s)' : ''} · Total: ${data.total}`;
                    // Recharger les données sans reloader la page
                    fetch('index.php').then(r => r.text()).then(() => {
                        fetch('index.php', { headers: {'Accept':'application/json'} });
                    });
                    // Simple reload après 2s pour afficher les nouvelles transactions
                    setTimeout(() => { closeImportModal(); location.reload(); }, 2200);
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

    render();
</script>
</body>
</html>