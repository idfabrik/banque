<?php
/**
 * Accounting App v2.0
 * - Pour indépendants en Allemagne (Freiberufler/Unternehmer)
 * - IDs incrémentaux stables
 * - Détection Entrée/Dépense
 * - Drag-drop justificatifs
 * - Recherche et tris avancés
 */

define('PASSWORD', 'bonjour');
define('JSON_FILE', __DIR__ . '/data.json');
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('SESSION_NAME', 'accounting_auth');

session_start();

if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}

// ===== CATÉGORIES POUR INDÉPENDANTS ALLEMANDS =====

$categories = [
    'REVENUE' => [
        'Dienstleistungen' => ['AUSZAHLUNG', 'UEBERWEISUNG', 'TRANSFER', 'ZAHLUNG', 'ENTGELT', 'HONORAR'],
        'Versand/Provision' => ['PAYPAL', 'STRIPE', 'AMAZON', 'EBAY', 'AMAZON PAY'],
    ],
    'EXPENSES' => [
        'Bezogene Fremdleistungen' => ['FREELANCER', 'SUBUNTERNEHMER', 'AGENTUR', 'DESIGNER', 'PROGRAMMIERER'],
        'Aufwendungen für geringwertige Wirtschaftsgüter' => ['BUERO', 'SCHREIBTISCH', 'STUHL', 'LAMPE', 'MOEBEL', 'WERKZEUG'],
        'Telekommunikation' => ['VODAFONE', 'TELEKOM', 'O2', 'TELEFONICA', 'TELEFON', 'INTERNET', 'SIMKARTE'],
        'Reisekosten Geschäftsreisen' => ['LUFTHANSA', 'RYANAIR', 'BOOKING', 'HOTEL', 'BAHNTICKET', 'BOOKING.COM', 'BAHN'],
        'Fortbildungskosten' => ['UDEMY', 'COURSERA', 'SKILLSHARE', 'SEMINAIRE', 'TRAINING', 'WORKSHOP', 'VHS', 'KONFERENZ'],
        'Steuerberatung und Buchführung' => ['STEUERBERATER', 'BUCHHAELTER', 'REVISIONSSTELLE', 'LOHN', 'STEUERKANZLEI', 'TAX'],
        'Miete Kraftfahrzeuge' => ['SIXT', 'HERTZ', 'AVIS', 'EUROPCAR', 'AUTOVERMIETUNG'],
        'Laufende EDV-Kosten' => ['ADOBE', 'MICROSOFT', 'APPLE', 'GOOGLE', 'AWS', 'CLOUD', 'GITHUB', 'FIGMA', 'SLACK', 'NOTION', 'HOSTING', 'SERVER'],
        'Arbeitsmittel' => ['EDEKA', 'REWE', 'ALDI', 'LIDL', 'PENNY', 'NETTO', 'KAUFLAND', 'MODULOR', 'PIN', 'STAPLES', 'OFFICE', 'SCHREIBWARENHANDEL'],
        'Werbekosten' => ['GOOGLE ADS', 'FACEBOOK', 'INSTAGRAM', 'LINKEDIN', 'WERBUNG', 'PRINTEREI', 'DRUCKEREI'],
        'Geschenke' => ['GESCHENK', 'PRAESENT', 'BLUMEN', 'PRESENT'],
        'Bewirtungsaufwendungen' => ['RESTAURANT', 'CAFE', 'BAR', 'PIZZA', 'PASTA', 'SUMUP', 'BURGER', 'LUNCH'],
        'Treibstoff' => ['SHELL', 'ARAL', 'BP', 'ESSO', 'TOTAL', 'TANKSTELLE', 'DIESEL', 'BENZIN'],
        'Versicherungen' => ['VERSICHERUNG', 'INSURANCE', 'AXA', 'ALLIANZ', 'DEBEKA', 'BARMER', 'TECHNIKER', 'IKK'],
        'Bankgebühren' => ['BANKGEBUEHR', 'KONTOGEBUEHR', 'ZINSEN', 'PROVISIONEN', 'DKB'],
        'Steuern' => ['FINANZAMT', 'STEUER', 'KIRCHENSTEUER', 'GEWERBESTEUER'],
        'Sonstiges' => ['SONSTIGES', 'DIVERSES'],
        'Privat' => ['PRIVAT', 'PERSONAL', 'PRIVAT', 'PERSÖNLICH']  // À GRISER!
    ]
];

$tva_rates = [0, 7, 19];

require_once 'CSVParserService.php';

include("inc_functions.php");

// ===== PAGE HTML =====
$data = $is_auth ? load_data() : [];
$tva_summary = $is_auth ? calculate_tva_summary($data) : [];


?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting App - Tawan</title>
    <style type="text/css" src=""></style>
    <link rel="stylesheet" type="text/css" href="style.css" manuel="oui" />

</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💼 Accounting - Freiberufler</h1>
            <p>Verwaltung von Transaktionen, MwSt. und Belegen</p>
        </div>
        
        <?php if (!$is_auth): ?>
            <div class="login-form">
                <form method="POST">
                    <input type="password" name="password" placeholder="Passwort" autofocus>
                    <button type="submit">Anmelden</button>
                </form>
            </div>
        <?php else: ?>
            <div class="tabs">
                <button class="tab active" data-tab="transactions">📊 Transaktionen</button>
                <button class="tab" data-tab="upload">📤 CSV importieren</button>
                <button class="tab" data-tab="manual">✏️ Manuell hinzufügen</button>
                <button class="tab" data-tab="tva">🧾 MwSt.-Zusammenfassung</button>
                <button class="tab" data-tab="logout">🚪 Abmelden</button>
            </div>
            
            <div class="content">
                <!-- Transaktionen -->
                <div class="tab-panel active" id="transactions">
                    <div class="section">
                        <h2>📋 Alle Transaktionen</h2>
                        
                        <div class="controls">
                            <div class="search-box">
                                <input type="text" id="searchBox" placeholder="🔍 Nach Wort suchen (Name, Betrag, Kategorie...)">
                            </div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                <select id="monthSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <option value="">Alle Monate</option>
                                    <option value="current">Mois en cours</option>
                                    <?php 
                                    $months = [];
                                    foreach ($data as $tx) {
                                        $month = substr($tx['date'], 3); // MM.YYYY
                                        if (!in_array($month, $months)) $months[] = $month;
                                    }
                                    rsort($months);
                                    foreach ($months as $month): ?>
                                    <option value="<?php echo $month; ?>"><?php echo $month; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <select id="filterSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <option value="">Alle anzeigen</option>
                                    <option value="hide-privat">Privat masqué</option>
                                    <option value="income-only">Nur Einnahmen</option>
                                    <option value="no-documents">Fehlende Belege</option>
                                    <option value="no-category">Fehlende Kategorie</option>
                                </select>
                                
                                <select id="sortSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <option value="date_desc">Datum (neueste zuerst)</option>
                                    <option value="date_asc">Datum (älteste zuerst)</option>
                                    <option value="amount_desc">Betrag (höchste zuerst)</option>
                                    <option value="amount_asc">Betrag (niedrigste zuerst)</option>
                                </select>
                            </div>
                        </div>
                        
                        <?php if (empty($data)): ?>
                            <div class="empty-state">
                                <p>Keine Transaktionen. Importieren Sie eine CSV oder fügen Sie manuell hinzu.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                            <table class="transactions-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Datum</th>
                                        <th>S.</th>
                                        <th>Beschreibung</th>
                                        <th>Betrag</th>
                                        <th>Kategorie</th>
                                        <th>MwSt.</th>
                                        <th>Notes</th>
                                        <th>Justificatif</th>
                                    </tr>
                                </thead>
                                
                                <!-- Résumé mensuel -->
                                <?php
                                // Grouper les transactions par mois
                                $by_month = [];
                                foreach ($data as $tx) {
                                    $month = substr($tx['date'], 3); // MM.YYYY
                                    if (!isset($by_month[$month])) {
                                        $by_month[$month] = [];
                                    }
                                    $by_month[$month][] = $tx;
                                }
                                krsort($by_month); // Du plus récent au plus ancien
                                ?>
                                
                                <tbody id="txTable">
                                    <?php foreach ($by_month as $month => $txs): ?>
                                        <!-- Transactions du mois -->
                                        <?php foreach ($txs as $tx): ?>
                                            <tr class="tx-row <?php echo ($tx['category'] === 'Privat') ? 'privat' : ''; ?> <?php echo ($tx['type'] === 'Entrée') ? 'revenue' : ''; ?>" data-id="<?php echo $tx['id']; ?>" data-search="<?php echo strtolower($tx['beneficiary'] . ' ' . $tx['purpose'] . ' ' . $tx['category'] . ' ' . $tx['amount']); ?>">
                                                <td style="font-size: 11px; color: #999;"><?php echo $tx['numeric_id']; ?></td>
                                                <td style="<?php echo ($tx['category'] === 'Privat') ? 'font-size: 11px; color: #999;' : ''; ?>"><?php echo $tx['date']; ?></td>
                                                <td title="<?php echo ($tx['source'] === 'bank') ? 'Banktransaction' : 'Bargeld'; ?>" style="text-align: center; font-size: 12px;">
                                                    <?php echo ($tx['source'] === 'bank') ? 'B.' : 'C.'; ?>
                                                </td>
                                                <td style="<?php echo ($tx['category'] === 'Privat') ? 'font-size: 11px; color: #999;' : ''; ?>">
                                                    <div style="font-size: 12px; color: #666; line-height: 1.4;">
                                                        <?php echo htmlspecialchars($tx['beneficiary']); ?><br>
                                                        <span style="color: #999;"><?php echo htmlspecialchars($tx['purpose']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="amount" style="<?php echo ($tx['category'] === 'Privat') ? 'font-size: 11px; color: #999;' : ''; ?>">
                                                    <?php 
                                                    if ($tx['type'] === 'Entrée') {
                                                        echo '<span style="color: green; font-weight: bold;">+</span>' . number_format($tx['amount'], 2, ',', '&nbsp;') . '€';
                                                    } else {
                                                        echo '<span style="color: #000;">−</span>' . number_format($tx['amount'], 2, ',', '&nbsp;') . '€';
                                                    }
                                                    ?>
                                                </td>
                                                
                                                <td>
                                                    <select class="inline-select" onchange="saveTx('<?php echo $tx['id']; ?>', 'category', this.value)">
                                                        <option value="" <?php echo (empty($tx['category']) ? 'selected' : ''); ?>>—</option>
                                                        <?php 
                                                        foreach ($categories['EXPENSES'] as $cat => $kw) {
                                                            echo '<option value="' . htmlspecialchars($cat) . '"' . ($tx['category'] === $cat ? ' selected' : '') . '>' . htmlspecialchars($cat) . '</option>';
                                                        }
                                                        foreach ($categories['REVENUE'] as $cat => $kw) {
                                                            echo '<option value="' . htmlspecialchars($cat) . '"' . ($tx['category'] === $cat ? ' selected' : '') . '>' . htmlspecialchars($cat) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                    <label class="checkbox-label" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
                                                        <input type="checkbox" class="privat-checkbox" <?php echo ($tx['category'] === 'Privat') ? 'checked' : ''; ?> onchange="togglePrivat('<?php echo $tx['id']; ?>', this.checked)">
                                                        <span>privat</span>
                                                    </label>
                                                </td>
                                                
                                                <td>
                                                    <select class="inline-select" onchange="saveTx('<?php echo $tx['id']; ?>', 'tva', this.value)">
                                                        <?php foreach ($tva_rates as $rate): ?>
                                                            <option value="<?php echo $rate; ?>" <?php echo ($tx['tva'] == $rate) ? 'selected' : ''; ?>>
                                                                <?php echo $rate; ?>%
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                
                                                <td>
                                                    <input type="text" class="notes-input" value="<?php echo htmlspecialchars($tx['notes'] ?? ''); ?>" placeholder="Notes..." onchange="saveTx('<?php echo $tx['id']; ?>', 'notes', this.value)">
                                                </td>
                                                
                                                <td class="tx-attachments">
                                                    <?php if (!empty($tx['attachments'])): ?>
                                                        <div class="attachment-list">
                                                            <?php foreach ($tx['attachments'] as $att): ?>
                                                                <div class="attachment">
                                                                    <a href="?action=get-file&file=<?php echo urlencode($att); ?>" target="_blank" title="Öffnen">📄</a>
                                                                    <button class="delete-attachment" onclick="deleteAttachment('<?php echo $tx['id']; ?>', '<?php echo urlencode($att); ?>'); return false;" title="Löschen">✕</button>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
<div class="drop-zone" style="<?php echo ($tx['category'] === 'Privat') ? 'display: none;' : ''; ?>" ondrop="handleDrop(event, '<?php echo $tx['id']; ?>'); return false;" ondragover="event.preventDefault(); event.stopPropagation(); this.classList.add('dragover'); return false;" ondragleave="this.classList.remove('dragover')" onclick="this.querySelector('input[type=file]').click()">
                                                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" onchange="uploadAttachment('<?php echo $tx['id']; ?>', this)">
                                                            <p style="font-size: 12px; margin: 0; color: #666;">Beleg upload</p>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Récap du mois -->
                                        <?php
                                        $revenue_amount = 0;      // Total recettes (montant HT)
                                        $revenue_vat = 0;         // TVA collectée (sur recettes)
                                        $expense_amount = 0;      // Total dépenses (montant HT)
                                        $expense_vat = 0;         // TVA payée (sur dépenses)
                                        
                                        foreach ($txs as $tx) {
                                            // Exclure Privat et sans catégorie
                                            if ($tx['category'] === 'Privat' || empty($tx['category'])) continue;
                                            
                                            if ($tx['type'] === 'Entrée') {
                                                // Recette: calculer TVA collectée
                                                $revenue_amount += $tx['amount'];
                                                $revenue_vat += ($tx['amount'] * $tx['tva']) / 100;
                                            } else {
                                                // Dépense: calculer TVA payée
                                                $expense_amount += $tx['amount'];
                                                $expense_vat += ($tx['amount'] * $tx['tva']) / 100;
                                            }
                                        }
                                        
                                        $revenue_net = $revenue_amount - $revenue_vat;  // Recette nette (HT)
                                        $profit = $revenue_net - $expense_amount;        // Bénéfice
                                        $margin = $revenue_net > 0 ? ($profit / $revenue_net) * 100 : 0;  // Marge %
                                        ?>
                                        <tr class="summary-row" style="background: #f0f4ff; font-size: 13px; color: #333; border-top: 3px solid #667eea; padding: 16px 8px;">
                                            <td colspan="3" style="padding: 12px 8px; font-weight: 700; font-size: 14px;">📅 <?php echo $month; ?></td>
                                            <td colspan="6" style="padding: 12px 8px; text-align: right; font-size: 13px; line-height: 1.6;">
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                                    <div>
                                                        <div style="color: #667eea; font-weight: 600; margin-bottom: 8px;">💰 EINNAHMEN</div>
                                                        <div style="margin-bottom: 4px;">Brutto: <span style="font-weight: 600;"><?php echo number_format($revenue_amount, 2, ',', '&nbsp;'); ?>€</span></div>
                                                        <div style="margin-bottom: 4px; font-size: 12px; color: #666;">davon MwSt (einkassiert): <span style="font-weight: 600;"><?php echo number_format($revenue_vat, 2, ',', '&nbsp;'); ?>€</span></div>
                                                        <div style="margin-bottom: 8px; background: #e8f0ff; padding: 6px; border-radius: 4px;">Netto: <span style="font-weight: 700; color: #667eea;"><?php echo number_format($revenue_net, 2, ',', '&nbsp;'); ?>€</span></div>
                                                    </div>
                                                    <div>
                                                        <div style="color: #d32f2f; font-weight: 600; margin-bottom: 8px;">💸 AUSGABEN</div>
                                                        <div style="margin-bottom: 4px;">Brutto: <span style="font-weight: 600;"><?php echo number_format($expense_amount, 2, ',', '&nbsp;'); ?>€</span></div>
                                                        <div style="margin-bottom: 8px; font-size: 12px; color: #666;">davon MwSt (bezahlt): <span style="font-weight: 600;"><?php echo number_format($expense_vat, 2, ',', '&nbsp;'); ?>€</span></div>
                                                        <div style="color: #f57c00; font-weight: 600;">MwSt-Saldo: <span style="<?php echo ($revenue_vat - $expense_vat) >= 0 ? 'color: #d32f2f;' : 'color: #388e3c;'; ?>"><?php echo ($revenue_vat - $expense_vat) >= 0 ? '+' : ''; ?><?php echo number_format($revenue_vat - $expense_vat, 2, ',', '&nbsp;'); ?>€</span></div>
                                                    </div>
                                                </div>
                                                <div style="margin-top: 12px; border-top: 2px solid #ddd; padding-top: 12px;">
                                                    <div style="margin-bottom: 6px;">Gewinn: <span style="font-weight: 700; color: <?php echo $profit >= 0 ? '#388e3c' : '#d32f2f'; ?>; font-size: 15px;"><?php echo $profit >= 0 ? '+' : ''; ?><?php echo number_format($profit, 2, ',', '&nbsp;'); ?>€</span></div>
                                                    <div style="font-size: 12px; color: #666;">Marge: <span style="font-weight: 600;"><?php echo number_format($margin, 1, ',', '&nbsp;'); ?>%</span></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- CSV Import -->
                <div class="tab-panel" id="upload">
                    <div class="section">
                        <h2>📤 CSV importieren</h2>
                        
                        <div class="upload-zone" id="uploadZone">
                            <p>📁 Datei hier ablegen</p>
                            <p style="font-size: 14px;">oder klicken zum Auswählen</p>
                            <input type="file" id="csvFile" accept=".csv">
                        </div>
                        
                        <div id="uploadSuccess" class="success"></div>
                        <div id="uploadError" class="error"></div>
                    </div>
                </div>
                
                <!-- Manuelles Hinzufügen -->
                <div class="tab-panel" id="manual">
                    <div class="section">
                        <h2>✏️ Transaktion hinzufügen</h2>
                        <form id="manualForm">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Datum</label>
                                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Typ</label>
                                    <select name="type" required>
                                        <option value="Dépense">Ausgabe</option>
                                        <option value="Entrée">Einnahme</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Betrag (€)</label>
                                    <input type="number" name="amount" step="0.01" min="0" placeholder="0.00" required>
                                </div>
                                <div class="form-group">
                                    <label>MwSt. (%)</label>
                                    <select name="tva" required>
                                        <?php foreach ($tva_rates as $rate): ?>
                                            <option value="<?php echo $rate; ?>" <?php echo $rate == 19 ? 'selected' : ''; ?>>
                                                <?php echo $rate; ?>%
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Empfänger</label>
                                    <input type="text" name="beneficiary" placeholder="Name oder Unternehmen" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Beschreibung</label>
                                    <textarea name="purpose" placeholder="Beschreibung der Transaktion"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Kategorie</label>
                                    <select name="category">
                                        <option value="">—</option>
                                        <?php 
                                        foreach ($categories['EXPENSES'] as $cat => $kw) {
                                            echo '<option value="' . htmlspecialchars($cat) . '">' . htmlspecialchars($cat) . '</option>';
                                        }
                                        foreach ($categories['REVENUE'] as $cat => $kw) {
                                            echo '<option value="' . htmlspecialchars($cat) . '">' . htmlspecialchars($cat) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit">➕ Transaktion hinzufügen</button>
                        </form>
                        <div id="manualSuccess" class="success"></div>
                    </div>
                </div>
                
                <!-- MwSt. Zusammenfassung -->
                <div class="tab-panel" id="tva">
                    <div class="section">
                        <h2>🧾 MwSt.-Zusammenfassung (Finanzamt)</h2>
                        
                        <?php if (empty($tva_summary)): ?>
                            <div class="empty-state">
                                <p>Keine Transaktionen zur Anzeige.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($tva_summary as $month => $summary): ?>
                                <div class="tva-card">
                                    <h3>📅 <?php echo $month; ?></h3>
                                    
                                    <div class="tva-grid">
                                        <div class="tva-item">
                                            <label>MwSt. 0%</label>
                                            <div class="value"><?php echo number_format($summary['tva_0'], 2, ',', ' '); ?>€</div>
                                        </div>
                                        <div class="tva-item">
                                            <label>MwSt. 7%</label>
                                            <div class="value"><?php echo number_format($summary['tva_7'], 2, ',', ' '); ?>€</div>
                                        </div>
                                        <div class="tva-item">
                                            <label>MwSt. 19%</label>
                                            <div class="value"><?php echo number_format($summary['tva_19'], 2, ',', ' '); ?>€</div>
                                        </div>
                                        <div class="tva-item">
                                            <label>Summe netto</label>
                                            <div class="value"><?php echo number_format($summary['total_ht'], 2, ',', ' '); ?>€</div>
                                        </div>
                                        <div class="tva-item">
                                            <label>Summe brutto</label>
                                            <div class="value"><?php echo number_format($summary['total_ttc'], 2, ',', ' '); ?>€</div>
                                        </div>
                                        <div class="tva-item">
                                            <label>Transaktionen</label>
                                            <div class="value"><?php echo $summary['transactions']; ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        const allTransactions = <?php echo json_encode($data); ?>;
        
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;
                
                if (tabName === 'logout') {
                    location.href = '?logout=1';
                    return;
                }
                
                document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                
                document.getElementById(tabName).classList.add('active');
                tab.classList.add('active');
            });
        });
        
        // Upload CSV
        const uploadZone = document.getElementById('uploadZone');
        const csvFile = document.getElementById('csvFile');
        
        uploadZone.addEventListener('click', () => csvFile.click());
        uploadZone.addEventListener('dragover', e => {
            e.preventDefault();
            uploadZone.style.background = '#f0f4ff';
        });
        uploadZone.addEventListener('dragleave', () => {
            uploadZone.style.background = '#f9fafb';
        });
        uploadZone.addEventListener('drop', e => {
            e.preventDefault();
            if (e.dataTransfer.files.length > 0) {
                uploadFile(e.dataTransfer.files[0]);
            }
        });
        
        csvFile.addEventListener('change', e => {
            if (e.target.files.length > 0) {
                uploadFile(e.target.files[0]);
            }
        });
        
        function uploadFile(file) {
            const form = new FormData();
            form.append('csv', file);
            
            fetch(location.href, { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('uploadSuccess').textContent = `✅ ${data.added} Transaktion(en) importiert. Gesamt: ${data.total}`;
                        document.getElementById('uploadSuccess').style.display = 'block';
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(e => {
                    document.getElementById('uploadError').textContent = '❌ Fehler: ' + e;
                    document.getElementById('uploadError').style.display = 'block';
                });
        }
        
        // Manual form
        document.getElementById('manualForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = new FormData(this);
            form.append('add_manual', '1');
            
            fetch(location.href, { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('manualSuccess').textContent = '✅ Transaktion hinzugefügt';
                        document.getElementById('manualSuccess').style.display = 'block';
                        document.getElementById('manualForm').reset();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        document.getElementById('manualSuccess').textContent = '❌ Fehler beim Hinzufügen';
                        document.getElementById('manualSuccess').style.display = 'block';
                        console.error('Erreur:', data);
                    }
                })
                .catch(e => {
                    console.error('Fehler:', e);
                    alert('Fehler beim Senden: ' + e.message);
                });
        });
        
        // Drag-drop global pour éviter les comportements par défaut du navigateur
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // Toggle Privat - mettre à jour aussi le select de catégorie
        function togglePrivat(txId, isPrivat) {
            const category = isPrivat ? 'Privat' : '';
            saveTx(txId, 'category', category);
            
            // Mettre à jour aussi le select visuellement
            const row = document.querySelector(`[data-id="${txId}"]`);
            if (row) {
                const select = row.querySelector('select');
                if (select) {
                    select.value = category;
                }
                const checkbox = row.querySelector('.privat-checkbox');
                if (checkbox) {
                    checkbox.checked = isPrivat;
                }
            }
        }
        
        // Sauvegarder catégorie ou TVA
        function saveTx(txId, field, value) {
            const form = new FormData();
            form.append('update_tx', '1');
            form.append('tx_id', txId);
            form.append(field, value);
            
            fetch(location.href, { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const row = document.querySelector(`[data-id="${txId}"]`);
                        if (row) {
                            // Si c'est la catégorie qui change
                            if (field === 'category') {
                                // Appliquer le grisage si c'est Privat
                                if (value === 'Privat') {
                                    row.classList.add('privat');
                                } else {
                                    row.classList.remove('privat');
                                }
                                
                                // Synchroniser le checkbox avec le select
                                const checkbox = row.querySelector('.privat-checkbox');
                                if (checkbox) {
                                    checkbox.checked = (value === 'Privat');
                                }
                            }
                            
                            // Feedback visuel
                            row.style.background = '#e8f5e9';
                            setTimeout(() => row.style.background = '', 1000);
                        }
                    }
                })
                .catch(e => console.error('Erreur:', e));
        }
        
        // Drag and drop
        function handleDrop(e, txId) {
            e.preventDefault();
            e.stopPropagation();
            
            const zone = e.currentTarget;
            zone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files && files.length > 0) {
                // Passer les fichiers directement
                uploadAttachment(txId, { files: files });
            }
            
            return false;
        }
        
        // Upload attachement
        function uploadAttachment(txId, input) {
            // Gérer les deux cas: drag-drop (objet avec files) et click (input element)
            let files = null;
            
            if (input && input.files && input.files instanceof FileList) {
                files = input.files;
            } else if (input && input.files && Array.isArray(input.files)) {
                // Cas du drag-drop où files est passé directement
                files = input.files;
            }
            
            if (!files || files.length === 0) {
                console.error('Pas de fichier sélectionné');
                return;
            }
            
            const file = files[0];
            
            // Vérifier le type (moins strict pour les JPG)
            const ext = file.name.split('.').pop().toLowerCase();
            const allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!allowedExt.includes(ext)) {
                alert('Format nicht erlaubt. Verwenden Sie: PDF, JPG, PNG');
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                alert('Datei zu groß (max 5MB)');
                return;
            }
            
            const form = new FormData();
            form.append('file', file);
            form.append('tx_id', txId);
            
            fetch(location.href, { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setTimeout(() => location.reload(), 500);
                    } else if (data.error) {
                        alert('Fehler: ' + data.error);
                    }
                })
                .catch(e => alert('Upload-Fehler: ' + e));
        }
        
        // Supprimer un fichier joint
        function deleteAttachment(txId, filename) {
            if (!confirm('Fichier löschen?')) return;
            
            const form = new FormData();
            form.append('delete_attachment', '1');
            form.append('tx_id', txId);
            form.append('filename', filename);
            
            fetch(location.href, { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setTimeout(() => location.reload(), 300);
                    }
                })
                .catch(e => console.error('Fehler:', e));
        }
        
        // Recherche
        // Fonction maître pour appliquer tous les filtres
        function applyFilters() {
            const searchQuery = document.getElementById('searchBox').value.toLowerCase();
            const monthFilter = document.getElementById('monthSelect').value;
            const typeFilter = document.getElementById('filterSelect').value;
            const hasFilters = searchQuery || monthFilter || typeFilter;
            
            let visibleRows = 0;
            
            document.querySelectorAll('.tx-row').forEach(row => {
                const text = row.dataset.search;
                const date = row.cells[1].textContent;
                const month = date.substring(3); // MM.YYYY
                
                // Filtre recherche
                let matches = !searchQuery || text.includes(searchQuery);
                
                // Filtre mois
                if (monthFilter) {
                    if (monthFilter === 'current') {
                        const today = new Date();
                        const currentMonth = String(today.getMonth() + 1).padStart(2, '0') + '.' + today.getFullYear();
                        matches = matches && month === currentMonth;
                    } else {
                        matches = matches && month === monthFilter;
                    }
                }
                
                // Filtre type
                if (typeFilter === 'hide-privat') {
                    matches = matches && !row.classList.contains('privat');
                } else if (typeFilter === 'income-only') {
                    matches = matches && row.classList.contains('revenue');
                } else if (typeFilter === 'no-documents') {
                    const attachments = row.querySelector('.attachment-list');
                    matches = matches && !attachments;
                } else if (typeFilter === 'no-category') {
                    const categorySelect = row.querySelector('select');
                    matches = matches && categorySelect.value === '';
                }
                
                row.style.display = matches ? '' : 'none';
                if (matches) visibleRows++;
            });
            
            // Cacher les lignes résumé si filtre actif
            document.querySelectorAll('.summary-row').forEach(summary => {
                summary.style.display = hasFilters ? 'none' : '';
            });
        }
        
        // Événements de filtre
        document.getElementById('searchBox').addEventListener('keyup', applyFilters);
        document.getElementById('monthSelect').addEventListener('change', applyFilters);
        document.getElementById('filterSelect').addEventListener('change', applyFilters);
        
        // Tri
        document.getElementById('sortSelect').addEventListener('change', e => {
    // On récupère les lignes visibles
    const rows = Array.from(document.querySelectorAll('.tx-row:not([style*="display: none"])')).filter(row => row.style.display !== 'none');
    const sortType = e.target.value;
    
    rows.sort((a, b) => {
        let aVal, bVal;
        
        // Fonction utilitaire pour extraire proprement le nombre (enlève €, les espaces et gère la virgule)
        const parseAmount = (cell) => {
            let text = cell.textContent.replace(',', '.').replace('−', '-'); // Remplace virgule et signe moins spécial
            return parseFloat(text.replace(/[^\d.-]/g, '')); // Garde uniquement chiffres, points et signes moins
        };

        switch(sortType) {
            case 'date_desc':
                return new Date(b.cells[1].textContent.split('.').reverse().join('-')) - new Date(a.cells[1].textContent.split('.').reverse().join('-'));
            case 'date_asc':
                return new Date(a.cells[1].textContent.split('.').reverse().join('-')) - new Date(b.cells[1].textContent.split('.').reverse().join('-'));
            case 'amount_desc':
                return parseAmount(b.cells[4]) - parseAmount(a.cells[4]); // Index 4 = Montant
            case 'amount_asc':
                return parseAmount(a.cells[4]) - parseAmount(b.cells[4]); // Index 4 = Montant
            case 'beneficiary':
                return a.cells[3].textContent.localeCompare(b.cells[3].textContent);
            case 'category':
                return a.cells[5].textContent.localeCompare(b.cells[5].textContent); // Index 5 = Catégorie
            default:
                return 0;
        }
    });
    
    const tbody = document.getElementById('txTable');
    rows.forEach(row => tbody.appendChild(row));
});
    </script>
</body>
</html>