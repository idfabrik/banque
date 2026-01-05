<?php 
// ===== FONCTIONS =====
function categorize($text) {
    global $categories;
    $text = strtoupper($text);
    
    foreach ($categories['EXPENSES'] as $cat => $keywords) {
        foreach ($keywords as $keyword) {
            if (!empty($keyword) && strpos($text, $keyword) !== false) {
                return $cat;
            }
        }
    }
    foreach ($categories['REVENUE'] as $cat => $keywords) {
        foreach ($keywords as $keyword) {
            if (!empty($keyword) && strpos($text, $keyword) !== false) {
                return $cat;
            }
        }
    }
    return ''; // Vide par défaut si pas détectée
}

function detect_type($amount) {
    if ($amount > 0) return 'Entrée';
    if ($amount < 0) return 'Dépense';
    return 'Autre';
}

function load_data() {
    if (!file_exists(JSON_FILE)) return [];
    $data = json_decode(file_get_contents(JSON_FILE), true);
    
    // Initialiser les champs manquants pour compatibilité
    if (is_array($data)) {
        foreach ($data as &$tx) {
            if (!isset($tx['notes'])) $tx['notes'] = '';
            if (!isset($tx['attachments'])) $tx['attachments'] = [];
            if (!isset($tx['source'])) $tx['source'] = 'bank';
        }
    }
    
    return is_array($data) ? $data : [];
}

function save_data($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents(JSON_FILE, $json);
    chmod(JSON_FILE, 0600);
}

function get_next_id() {
    static $max_id = 0;
    static $initialized = false;
    
    // Initialiser une fois au début
    if (!$initialized) {
        $data = load_data();
        foreach ($data as $tx) {
            if (isset($tx['numeric_id']) && is_numeric($tx['numeric_id'])) {
                $max_id = max($max_id, intval($tx['numeric_id']));
            }
        }
        $initialized = true;
    }
    
    $max_id++;
    return $max_id;
}
/*
function parse_csv($content) {
    $lines = explode("\n", trim($content));
    $transactions = [];
    
    // ===== DÉTECTION FORMAT =====
    $header_idx = null;
    $is_dkb = false;
    $is_datev = false;
    $is_datev_extf = false;
    $year_from_file = date('Y');
    
    // Chercher le format
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        if (strpos($lines[$i], 'EXTF') !== false) {
            $is_datev_extf = true;
            // Extraire l'année du header EXTF (position 14)
            $header_cols = str_getcsv($lines[0], ';');
            if (count($header_cols) > 13 && is_numeric(trim($header_cols[13], '" '))) {
                $date_val = trim($header_cols[13], '" ');
                if (strlen($date_val) == 8) {
                    $year_from_file = substr($date_val, 0, 4);
                }
            }
            $header_idx = 1; // Header est toujours ligne 1 pour EXTF
            break;
        } elseif (strpos($lines[$i], 'Datev') !== false && strpos($lines[$i], '284') !== false) {
            $is_datev = true;
            $header_idx = $i + 1;
            break;
        } elseif (strpos($lines[$i], 'Buchungsdatum') !== false) {
            $header_idx = $i;
            $is_dkb = true;
            break;
        }
    }
    
    if ($header_idx === null) return [];
    
    // ===== PARSING PAR FORMAT =====
    
    if ($is_datev_extf) {
        // Parser DATEV EXTF (smarta Fibu format)
        // Récupérer le header (ligne 1)
        $header_line = trim($lines[1]);
        $headers = str_getcsv($header_line, ';');
        
        // Chercher les indices des colonnes importantes
        $col_umsatz = 0; // Umsatz
        $col_haben = 1; // Soll-/Haben-Kennzeichen
        $col_belegdatum = 9; // Belegdatum
        $col_buchungstext = 13; // Buchungstext (col 14 en 1-based)
        
        // Parser les transactions (à partir de ligne 2)
        for ($i = 2; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            // Convertir depuis ISO-8859-1 si nécessaire
            if (function_exists('mb_detect_encoding')) {
                $encoding = mb_detect_encoding($line, 'UTF-8,ISO-8859-1', true);
                if ($encoding === 'ISO-8859-1') {
                    $line = iconv('ISO-8859-1', 'UTF-8', $line);
                }
            }
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 15) continue;
            
            // Extraire les colonnes
            $montant_str = trim($cols[$col_umsatz] ?? '', '" ');
            $haben = trim($cols[$col_haben] ?? 'H', '" ');
            $belegdatum = trim($cols[$col_belegdatum] ?? '', '" ');
            $buchungstext = trim($cols[$col_buchungstext] ?? '', '" ');
            
            if (empty($montant_str) || empty($buchungstext)) continue;
            
            // Convertir montant (format allemand: 13,27)
            $montant_str = str_replace('.', '', $montant_str);
            $montant_str = str_replace(',', '.', $montant_str);
            $montant = floatval($montant_str);
            
            if ($montant == 0) continue;
            
            // Déterminer direction (H = Haben/crédit = moins, S = Soll/débit = plus)
            if ($haben === 'H') {
                // Haben = sortie (dépense)
                $montant = -abs($montant);
            } else {
                // Soll = entrée
                $montant = abs($montant);
            }
            
            // CORRECTION: Formater la date correctement
            // Format DATEV EXTF: belegdatum = DDMM (jour + mois)
            // Exemples: "0101" = 01.01, "1112" = 12.11
            $date_str = '01.01.' . $year_from_file; // Par défaut
            
            if (strlen($belegdatum) == 4 && is_numeric($belegdatum)) {
                $jour = substr($belegdatum, 0, 2);      // Premiers 2 caractères = jour
                $mois = substr($belegdatum, 2, 2);      // Derniers 2 caractères = mois
                
                $jour_int = intval($jour);
                $mois_int = intval($mois);
                
                // Vérifier si c'est bien jour.mois
                if ($jour_int >= 1 && $jour_int <= 31 && $mois_int >= 1 && $mois_int <= 12) {
                    $date_str = sprintf("%02d.%02d.%d", $jour_int, $mois_int, $year_from_file);
                }
            }
            
            $type = detect_type($montant);
            $amount = abs($montant);
            
            // Chercher les pièces jointes et capturer l'UUID
            // IMPORTANT: BEDI est dans Beleglink (colonne 20, index 19)
            $attachments = [];
            $uuid = null;
            
            // Beleglink est à l'index 19 (0-indexed)
            if (isset($cols[19])) {
                $beleglink = trim($cols[19], '" ');
                
                // Extraire l'UUID depuis Beleglink: BEDI "UUID"
                if (strpos($beleglink, 'BEDI') !== false) {
                    // UUID format: 8-4-4-4-12 hex digits
                    if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $beleglink, $matches)) {
                        $uuid = $matches[1];
                        
                        // Chercher les fichiers avec cet UUID dans uploads
                        $uploads_dir = UPLOADS_DIR;
                        if (is_dir($uploads_dir)) {
                            // Utiliser scandir pour lister tous les fichiers
                            $all_files = @scandir($uploads_dir);
                            if ($all_files !== false) {
                                foreach ($all_files as $file) {
                                    // Chercher les fichiers qui contiennent l'UUID dans leur nom
                                    // Format: UUID-N.ext (ex: 0a4edbb1-4822-42ff-8e08-731a051d0906-0.jpg)
                                    if (stripos($file, $uuid) !== false) {
                                        // Vérifier que c'est un fichier image/pdf
                                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                                            $attachments[] = $file;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // CORRECTION: Créer un ID unique basé sur la transaction
            // Inclure toutes les données pour une vraie unicité
            $unique_id = md5($date_str . $buchungstext . $amount . $haben);
            
            // Déterminer la TVA par défaut selon le type
            $default_tva = ($type === 'Entrée') ? 0 : 19; // Entrée = 0%, Dépense = 19%
            
            $transactions[] = [
                'id' => $unique_id,
                'numeric_id' => get_next_id(),
                'date' => $date_str,
                'type' => $type,
                'beneficiary' => $buchungstext,
                'purpose' => '',
                'amount' => $amount,
                'category' => categorize($buchungstext), // sera vide si pas trouvée
                'tva' => $default_tva,
                'attachments' => $attachments,
                'notes' => '', // Laisser vide, pas d'UUID
                'source' => 'bank' // Défaut: banque (DATEV)
            ];
        }
    } elseif ($is_datev) {
        // Parser DATEV classique (non-EXTF)
        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 10) continue;
            
            $buchungstag = trim($cols[0], '" ');
            $beschreibung = count($cols) > 9 ? trim($cols[9], '" ') : '';
            $betrag = count($cols) > 10 ? trim($cols[10], '" ') : '';
            $buchungstext = trim($cols[8], '" ');
            
            if (empty($betrag) || empty($buchungstag)) continue;
            
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
            $betrag = floatval($betrag);
            
            if ($betrag == 0) continue;
            
            $type = detect_type($betrag);
            $amount = abs($betrag);
            
            $unique_id = md5($buchungstag . $buchungstext . $beschreibung . $amount);
            
            $transactions[] = [
                'id' => $unique_id,
                'numeric_id' => get_next_id(),
                'date' => $buchungstag,
                'type' => $type,
                'beneficiary' => $buchungstext,
                'purpose' => $beschreibung,
                'amount' => $amount,
                'category' => categorize($buchungstext . ' ' . $beschreibung),
                'tva' => 19,
                'attachments' => [],
                'notes' => '',
                'source' => 'bank'
            ];
        }
    } else {
        // Parser DKB ou Generic
        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 3) continue;
            
            if ($is_dkb && count($cols) >= 9) {
                $date = trim($cols[0], '" ');
                $status = trim($cols[2], '" ');
                $beneficiary = trim($cols[4], '" ');
                $purpose = trim($cols[5], '" ');
                $amount = trim($cols[8], '" ');
                
                if ($status !== 'Gebucht') continue;
            } else {
                $date = trim($cols[0], '" ');
                $beneficiary = trim($cols[1] ?? '', '" ');
                $purpose = trim($cols[2] ?? '', '" ');
                $amount = trim($cols[3] ?? '', '" ');
            }
            
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
            $amount = floatval($amount);
            
            if ($amount == 0) continue;
            
            $type = detect_type($amount);
            $amount = abs($amount);
            
            $unique_id = md5($date . $beneficiary . $purpose . $amount);
            
            $transactions[] = [
                'id' => $unique_id,
                'numeric_id' => get_next_id(),
                'date' => $date,
                'type' => $type,
                'beneficiary' => $beneficiary,
                'purpose' => $purpose,
                'amount' => $amount,
                'category' => categorize($beneficiary . ' ' . $purpose),
                'tva' => 19,
                'attachments' => [],
                'notes' => '',
                'source' => 'bank'
            ];
        }
    }
    
    return $transactions;
}
*/

// Remplacer la vieille parse_csv (70+ lignes)

// À remplacer dans inc_functions.php - parse_csv() ULTRA SIMPLE ET DIRECT

function parse_csv($content) {
    global $categories;
    
    $lines = explode("\n", trim($content));
    $transactions = [];
    
    // Déterminer le format
    $header_idx = null;
    $is_dkb = false;
    $is_datev = false;
    $is_datev_extf = false;
    $year_from_file = intval(date('Y'));
    
    // Chercher le header
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        if (strpos($lines[$i], 'EXTF') !== false) {
            $is_datev_extf = true;
            $header_cols = str_getcsv($lines[0], ';');
            if (count($header_cols) > 13) {
                $date_str = trim($header_cols[13], '" ');
                $extracted = extract_year($date_str);
                if ($extracted) {
                    $year_from_file = $extracted;
                }
            }
            $header_idx = 1;
            break;
        } elseif (strpos($lines[$i], 'Datev') !== false && strpos($lines[$i], '284') !== false) {
            $is_datev = true;
            $header_idx = $i + 1;
            break;
        } elseif (strpos($lines[$i], 'Buchungsdatum') !== false) {
            $header_idx = $i;
            $is_dkb = true;
            break;
        }
    }
    
    if ($header_idx === null) return [];
    
    // === PARSER DATEV EXTF ===
    if ($is_datev_extf) {
        for ($i = 2; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            if (function_exists('mb_detect_encoding')) {
                $encoding = mb_detect_encoding($line, 'UTF-8,ISO-8859-1', true);
                if ($encoding === 'ISO-8859-1') {
                    $line = iconv('ISO-8859-1', 'UTF-8', $line);
                }
            }
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 15) continue;
            
            $montant_str = trim($cols[0] ?? '', '" ');
            $haben = trim($cols[1] ?? 'H', '" ');
            $belegdatum = trim($cols[9] ?? '', '" ');
            $buchungstext = trim($cols[13] ?? '', '" ');
            
            if (empty($montant_str) || empty($buchungstext)) continue;
            
            $montant_str = str_replace('.', '', $montant_str);
            $montant_str = str_replace(',', '.', $montant_str);
            $montant = floatval($montant_str);
            
            if ($montant == 0) continue;
            
            if ($haben === 'H') {
                $montant = -abs($montant);
            } else {
                $montant = abs($montant);
            }
            
            $type = detect_type($montant);
            $amount = abs($montant);
            
            // ✅ FORMATTER LA DATE - FORCE 4 CHIFFRES
            $date_formatted = ensure_4digit_year($belegdatum, $year_from_file);
            
            $tx = [
                'id' => '',
                'numeric_id' => get_next_id(),
                'date' => $date_formatted,
                'type' => $type,
                'beneficiary' => $buchungstext,
                'purpose' => '',
                'amount' => $amount,
                'category' => categorize($buchungstext),
                'tva' => ($type === 'Entrée') ? 0 : 19,
                'attachments' => [],
                'notes' => '',
                'source' => 'bank'
            ];
            
            $tx['id'] = hash_tx($tx);
            $transactions[] = $tx;
        }
    }
    // === PARSER DATEV STANDARD ===
    elseif ($is_datev) {
        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 10) continue;
            
            $buchungstag = trim($cols[0], '" ');
            $beschreibung = trim($cols[9] ?? '', '" ');
            $betrag = trim($cols[10] ?? '', '" ');
            $buchungstext = trim($cols[8], '" ');
            
            if (empty($betrag) || empty($buchungstag)) continue;
            
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
            $betrag = floatval($betrag);
            
            if ($betrag == 0) continue;
            
            $type = detect_type($betrag);
            $amount = abs($betrag);
            
            // ✅ FORMATTER LA DATE - FORCE 4 CHIFFRES
            $date_formatted = ensure_4digit_year($buchungstag, $year_from_file);
            
            $tx = [
                'id' => '',
                'numeric_id' => get_next_id(),
                'date' => $date_formatted,
                'type' => $type,
                'beneficiary' => $buchungstext,
                'purpose' => $beschreibung,
                'amount' => $amount,
                'category' => categorize($buchungstext . ' ' . $beschreibung),
                'tva' => 19,
                'attachments' => [],
                'notes' => '',
                'source' => 'bank'
            ];
            
            $tx['id'] = hash_tx($tx);
            $transactions[] = $tx;
        }
    }
    // === PARSER DKB ===
    else {
        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 3) continue;
            
            if ($is_dkb && count($cols) >= 9) {
                $date = trim($cols[0], '" ');
                $status = trim($cols[2], '" ');
                $beneficiary = trim($cols[4], '" ');
                $purpose = trim($cols[5], '" ');
                $amount = trim($cols[8], '" ');
                
                if ($status !== 'Gebucht') continue;
            } else {
                $date = trim($cols[0], '" ');
                $beneficiary = trim($cols[1] ?? '', '" ');
                $purpose = trim($cols[2] ?? '', '" ');
                $amount = trim($cols[3] ?? '', '" ');
            }
            
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
            $amount = floatval($amount);
            
            if ($amount == 0) continue;
            
            $type = detect_type($amount);
            $amount = abs($amount);
            
            // ✅ FORMATTER LA DATE - FORCE 4 CHIFFRES
            $date_formatted = ensure_4digit_year($date, $year_from_file);
            
            $tx = [
                'id' => '',
                'numeric_id' => get_next_id(),
                'date' => $date_formatted,
                'type' => $type,
                'beneficiary' => $beneficiary,
                'purpose' => $purpose,
                'amount' => $amount,
                'category' => categorize($beneficiary . ' ' . $purpose),
                'tva' => 19,
                'attachments' => [],
                'notes' => '',
                'source' => 'bank'
            ];
            
            $tx['id'] = hash_tx($tx);
            $transactions[] = $tx;
        }
    }
    
    // ✅ TRIER PAR DATE DÉCROISSANTE (dernières en haut)
    usort($transactions, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $transactions;
}

// === FONCTIONS HELPER ===

/**
 * Extraire l'année d'une string
 */
function extract_year($value) {
    $value = trim($value, '" ');
    
    if (strlen($value) === 4 && is_numeric($value)) {
        $year = intval($value);
        if ($year >= 2000 && $year <= 2099) {
            return $year;
        }
    }
    
    if (strlen($value) === 2 && is_numeric($value)) {
        $year = intval($value);
        return ($year <= 30) ? (2000 + $year) : (1900 + $year);
    }
    
    if (strlen($value) === 8 && is_numeric($value)) {
        return intval(substr($value, 0, 4));
    }
    
    return null;
}

/**
 * CRITICAL: S'assurer que TOUTES les dates ont 4 chiffres pour l'année
 * Convertit:
 * - "20.11.25" → "20.11.2025"
 * - "20.11.26" → "20.11.2026"
 * - "20.11.2025" → "20.11.2025"
 * - "20.11" → "20.11.2025" (utilise année par défaut)
 */
function ensure_4digit_year($date, $default_year) {
    $date = trim($date, '" ');
    
    // Format "DD.MM.YY" (points, 2 chiffres année)
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $date, $m)) {
        $jour = $m[1];
        $mois = $m[2];
        $yy = intval($m[3]);
        
        // Convertir YY en YYYY: 25→2025, 26→2026, 99→1999
        $yyyy = ($yy <= 30) ? (2000 + $yy) : (1900 + $yy);
        
        return "$jour.$mois.$yyyy";
    }
    
    // Format "DD.MM.YYYY" (points, 4 chiffres) - déjà bon!
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date)) {
        return $date;
    }
    
    // Format "DD.MM" (points, pas d'année) - ajouter l'année par défaut
    if (preg_match('/^(\d{2})\.(\d{2})$/', $date, $m)) {
        $jour = $m[1];
        $mois = $m[2];
        return "$jour.$mois.$default_year";
    }
    
    // Format "DDMM" (pas de points, 4 chiffres)
    if (strlen($date) === 4 && is_numeric($date)) {
        $jour = substr($date, 0, 2);
        $mois = substr($date, 2, 2);
        return "$jour.$mois.$default_year";
    }
    
    // Format "YYYYMMDD" (8 chiffres)
    if (strlen($date) === 8 && is_numeric($date)) {
        $yyyy = substr($date, 0, 4);
        $mois = substr($date, 4, 2);
        $jour = substr($date, 6, 2);
        return "$jour.$mois.$yyyy";
    }
    
    // Si pas reconnu, retourner tel quel
    return $date;
}

/**
 * Hash robuste
 */
function hash_tx($tx) {
    // Date normalisée
    $date_parts = explode('.', $tx['date']);
    if (count($date_parts) === 3) {
        $date_iso = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
    } else {
        $date_iso = $tx['date'];
    }
    
    $signature = implode('|', [
        $date_iso,
        number_format($tx['amount'], 2, '.', ''),
        strtoupper(trim($tx['beneficiary'])),
        strtoupper(trim($tx['purpose'])),
        $tx['type']
    ]);
    
    return hash('sha256', $signature);
}

// === FONCTIONS HELPER ===

function extract_year_from_string($value) {
    $value = trim($value, '" ');
    
    // "2025" (4 chiffres)
    if (strlen($value) === 4 && is_numeric($value)) {
        $year = intval($value);
        if ($year >= 2000 && $year <= 2099) {
            return $year;
        }
    }
    
    // "25" (2 chiffres) → 2025, "26" → 2026
    if (strlen($value) === 2 && is_numeric($value)) {
        $year = intval($value);
        // 00-30 = 2000-2030, 31-99 = 1931-1999
        return ($year <= 30) ? (2000 + $year) : (1900 + $year);
    }
    
    // "20250101" (8 chiffres)
    if (strlen($value) === 8 && is_numeric($value)) {
        return intval(substr($value, 0, 4));
    }
    
    // Par défaut: année actuelle
    return intval(date('Y'));
}

function format_date_robustement($date, $default_year) {
    $date = trim($date, '" ');
    
    // "DDMM" (4 chiffres, pas de points)
    if (strlen($date) === 4 && is_numeric($date)) {
        $jour = intval(substr($date, 0, 2));
        $mois = intval(substr($date, 2, 2));
        
        if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
            return sprintf("%02d.%02d.%04d", $jour, $mois, $default_year);
        }
    }
    
    // "DD.MM.YYYY" ou "DD.MM.YY" (avec points)
    if (strpos($date, '.') !== false) {
        $parts = explode('.', $date);
        
        if (count($parts) === 3) {
            $jour = intval($parts[0]);
            $mois = intval($parts[1]);
            $year = intval($parts[2]);
            
            // Si année sur 2 chiffres: "25" → 2025, "26" → 2026
            if ($year < 100) {
                $year = ($year <= 30) ? (2000 + $year) : (1900 + $year);
            }
            
            if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                return sprintf("%02d.%02d.%04d", $jour, $mois, $year);
            }
        }
        // "DD.MM" sans année
        elseif (count($parts) === 2) {
            $jour = intval($parts[0]);
            $mois = intval($parts[1]);
            
            if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                return sprintf("%02d.%02d.%04d", $jour, $mois, $default_year);
            }
        }
    }
    
    // "YYYYMMDD" (8 chiffres, pas de points)
    if (strlen($date) === 8 && is_numeric($date)) {
        $year = intval(substr($date, 0, 4));
        $mois = intval(substr($date, 4, 2));
        $jour = intval(substr($date, 6, 2));
        
        if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
            return sprintf("%02d.%02d.%04d", $jour, $mois, $year);
        }
    }
    
    // Si déjà au bon format "DD.MM.YYYY"
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
        return $date;
    }
    
    // Sinon, retourner tel quel (peut-être que le parser suivant le gèrera)
    return $date;
}

function generate_hash_robuste($tx) {
    // Créer un hash ROBUSTE basé sur:
    // - Date normalisée (YYYY-MM-DD)
    // - Montant formaté (2 décimales)
    // - Bénéficiaire (uppercase, trim)
    // - Objet (uppercase, trim)
    // - Type (Entrée/Dépense)
    
    $date_normalized = normalize_date_to_iso($tx['date']);
    
    $signature = implode('|', [
        $date_normalized,
        number_format($tx['amount'], 2, '.', ''),
        strtoupper(trim($tx['beneficiary'])),
        strtoupper(trim($tx['purpose'])),
        $tx['type']
    ]);
    
    return hash('sha256', $signature);
}

function normalize_date_to_iso($date) {
    // Convertir DD.MM.YYYY en YYYY-MM-DD pour comparaison robuste
    
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    
    // Si déjà en YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
        return $date;
    }
    
    return $date;
}


function get_month_key($date) {
    if (strpos($date, '-') !== false) {
        return substr($date, 0, 7);
    }
    $parts = explode('.', $date);
    if (count($parts) >= 3) {
        return $parts[2] . '-' . $parts[1];
    }
    return date('Y-m');
}

function calculate_tva_summary($transactions) {
    $summary = [];
    
    foreach ($transactions as $tx) {
        $month = get_month_key($tx['date']);
        
        if (!isset($summary[$month])) {
            $summary[$month] = [
                'tva_0' => 0,
                'tva_7' => 0,
                'tva_19' => 0,
                'total_ht' => 0,
                'total_ttc' => 0,
                'transactions' => 0
            ];
        }
        
        $summary[$month]['total_ht'] += $tx['amount'];
        $tva_rate = $tx['tva'] ?? 19;
        $tva_amount = $tx['amount'] * ($tva_rate / 100);
        $summary[$month]['total_ttc'] += $tx['amount'] + $tva_amount;
        
        if ($tva_rate == 0) {
            $summary[$month]['tva_0'] += $tx['amount'];
        } elseif ($tva_rate == 7) {
            $summary[$month]['tva_7'] += $tx['amount'];
        } else {
            $summary[$month]['tva_19'] += $tx['amount'];
        }
        
        $summary[$month]['transactions']++;
    }
    
    krsort($summary);
    return $summary;
}

// ===== ROUTES API =====

$action = $_GET['action'] ?? null;
$is_auth = !empty($_SESSION[SESSION_NAME]);

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === PASSWORD) {
        $_SESSION[SESSION_NAME] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Upload CSV
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $csv_content = file_get_contents($_FILES['csv']['tmp_name']);
    
      // Vérifier que le service existe
        if (!file_exists(__DIR__ . '/CSVParserService.php')) {
            throw new Exception('CSVParserService.php introuvable');
        }
        
        require_once __DIR__ . '/CSVParserService.php';
        $parser = new CSVParserService($categories);
        $new_txs = $parser->parse($csv_content);

    
    $data = load_data();
    $existing_ids = array_column($data, 'id');
    
    $added = 0;
    foreach ($new_txs as $tx) {
        if (!in_array($tx['id'], $existing_ids)) {
            $data[] = $tx;
            $added++;
        }
    }
    
    usort($data, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    save_data($data);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'added' => $added, 'total' => count($data)]);
    exit;
}

// Ajouter transaction manuelle
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual'])) {
    $data = load_data();
    
    $type = $_POST['type'] ?? 'Dépense';
    $amount = floatval($_POST['amount'] ?? 0);
    
    // Convertir la date du format Y-m-d au format d.m.Y
    $date_input = $_POST['date'] ?? date('Y-m-d');
    $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
    $date_formatted = $date_obj ? $date_obj->format('d.m.Y') : date('d.m.Y');
    
    $tx = [
        'id' => md5(uniqid()),
        'numeric_id' => get_next_id(),
        'date' => $date_formatted,
        'type' => $type,
        'beneficiary' => $_POST['beneficiary'] ?? '',
        'purpose' => $_POST['purpose'] ?? '',
        'amount' => abs($amount),
        'category' => $_POST['category'] ?? '', // Vide par défaut
        'tva' => intval($_POST['tva'] ?? 19),
        'attachments' => [],
        'notes' => '',
        'source' => 'cash' // Transactions manuelles = espèce
    ];
    
    if ($tx['amount'] > 0) {
        $data[] = $tx;
        usort($data, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        save_data($data);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $tx['id']]);
        exit;
    }
}

// Mettre à jour transaction
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tx'])) {
    $data = load_data();
    
    foreach ($data as &$tx) {
        if ($tx['id'] === $_POST['tx_id']) {
            if (isset($_POST['category'])) $tx['category'] = $_POST['category'];
            if (isset($_POST['tva'])) $tx['tva'] = intval($_POST['tva']);
            if (isset($_POST['type'])) $tx['type'] = $_POST['type'];
            if (isset($_POST['notes'])) $tx['notes'] = trim($_POST['notes']);
            break;
        }
    }
    
    save_data($data);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Supprimer un fichier joint
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'])) {
    $data = load_data();
    $filename = $_POST['filename'] ?? '';
    $tx_id = $_POST['tx_id'] ?? '';
    
    foreach ($data as &$tx) {
        if ($tx['id'] === $tx_id) {
            if (($key = array_search($filename, $tx['attachments'])) !== false) {
                unset($tx['attachments'][$key]);
                $tx['attachments'] = array_values($tx['attachments']); // Réindexer
                
                // Supprimer le fichier
                $filepath = UPLOADS_DIR . '/' . $filename;
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
            break;
        }
    }
    
    save_data($data);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Upload fichier
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $tx_id = $_POST['tx_id'] ?? null;
    $file = $_FILES['file'];
    
    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Format non autorisé']);
        exit;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fichier trop volumineux (max 5MB)']);
        exit;
    }
    
    $data = load_data();
    $numeric_id = null;
    foreach ($data as $tx) {
        if ($tx['id'] === $tx_id) {
            $numeric_id = $tx['numeric_id'] ?? md5($tx_id);
            break;
        }
    }
    
    $filename = $numeric_id . '_' . time() . '.' . $ext;
    $path = UPLOADS_DIR . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        foreach ($data as &$tx) {
            if ($tx['id'] === $tx_id) {
                $tx['attachments'][] = $filename;
                break;
            }
        }
        
        save_data($data);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'filename' => $filename]);
        exit;
    }
}

// API
if ($action === 'get-transactions') {
    header('Content-Type: application/json');
    $data = load_data();
    echo json_encode($data);
    exit;
}

if ($action === 'get-file') {
    $filename = $_GET['file'] ?? null;
    $filepath = UPLOADS_DIR . '/' . basename($filename);
    
    if (file_exists($filepath)) {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        
        // Déterminer le bon Content-Type
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';
        elseif (in_array($ext, ['jpg', 'jpeg'])) $mime = 'image/jpeg';
        elseif ($ext === 'png') $mime = 'image/png';
        
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        readfile($filepath);
        exit;
    }
}