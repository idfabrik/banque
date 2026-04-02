<?php
/**
 * CSVParserService v3 - AMÉLIORE
 * - Année toujours formatée en 4 chiffres (2025, pas 25)
 * - Meilleure détection des années
 * - Logging pour déboguer
 */

class CSVParserService {
    private array $categories;
    private ?int $yearOverride = null;
    private int $nextNumericId = 0;
    private array $logs = [];
    
    public function __construct(array $categories) {
        $this->categories = $categories;
        $this->initializeNextId();
    }
    
    private function initializeNextId(): void {
        if (function_exists('get_next_id')) {
            $this->nextNumericId = get_next_id() - 1;
        } else {
            $this->nextNumericId = 0;
        }
    }
    
    private function getNextNumericId(): int {
        return ++$this->nextNumericId;
    }
    
    public function setYearOverride(int $year): self {
        $this->yearOverride = $year;
        return $this;
    }
    
    public function parse(string $content): array {
        $lines = explode("\n", trim($content));
        $format = $this->detectFormat($lines);
        
        $bank_label = match($format) {
            'datev_extf' => 'DATEV',
            'datev' => 'DATEV',
            'dkb' => 'DKB',
            'ing' => 'ING',
            default => ''
        };

        $transactions = match($format) {
            'datev_extf' => $this->parseDatevExtf($lines),
            'datev' => $this->parseDatev($lines),
            'dkb' => $this->parseDkb($lines),
            'ing' => $this->parseIng($lines),
            default => []
        };

        // Injecter la banque source dans les notes
        if ($bank_label) {
            foreach ($transactions as &$tx) {
                $tx['notes'] = $bank_label;
            }
            unset($tx);
        }

        error_log("CSVParser: Détecté " . count($transactions) . " transactions au format: $format");

        return $transactions;
    }
    
    private function detectFormat(array $lines): string {
        for ($i = 0; $i < min(15, count($lines)); $i++) {
            if (strpos($lines[$i], 'EXTF') !== false) {
                return 'datev_extf';
            } elseif (strpos($lines[$i], 'Datev') !== false && strpos($lines[$i], '284') !== false) {
                return 'datev';
            } elseif (strpos($lines[$i], 'Buchungsdatum') !== false) {
                return 'dkb';
            } elseif (strpos($lines[$i], 'Bank;ING') !== false || strpos($lines[$i], 'Buchung;Wertstellungsdatum') !== false) {
                return 'ing';
            }
        }
        return 'unknown';
    }
    
    private function parseDatevExtf(array $lines): array {
        $transactions = [];
        $year = $this->yearOverride ?? date('Y');
        
        // Chercher l'année dans le header EXTF
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            if (strpos($lines[$i], 'EXTF') !== false) {
                $header_cols = str_getcsv($lines[$i], ';');
                if (count($header_cols) > 13) {
                    $date_val = trim($header_cols[13], '" ');
                    $extracted_year = $this->extractYear($date_val);
                    if ($extracted_year) {
                        $year = $extracted_year;
                        error_log("CSVParser EXTF: Année détectée = $year (depuis: $date_val)");
                    }
                }
                break;
            }
        }
        
        error_log("CSVParser EXTF: Année finale = $year");
        
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
            
            $date_str = $this->formatDate($belegdatum, $year);
            
            $tx = $this->createTransaction(
                date: $date_str,
                beneficiary: $buchungstext,
                purpose: '',
                montant: $montant,
                attachments: []
            );
            
            $tx['id'] = $this->generateUniqueHash($tx);
            $transactions[] = $tx;
        }
        
        return $transactions;
    }
    
    private function parseDatev(array $lines): array {
        $transactions = [];
        $header_idx = null;
        
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], 'Datev') !== false && strpos($lines[$i], '284') !== false) {
                $header_idx = $i + 1;
                break;
            }
        }
        
        if ($header_idx === null) return [];
        
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
            
            $tx = $this->createTransaction(
                date: $buchungstag,
                beneficiary: $buchungstext,
                purpose: $beschreibung,
                montant: $betrag,
                attachments: []
            );
            
            $tx['id'] = $this->generateUniqueHash($tx);
            $transactions[] = $tx;
        }
        
        return $transactions;
    }
    
    private function parseDkb(array $lines): array {
        $transactions = [];
        $header_idx = null;
        
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], 'Buchungsdatum') !== false) {
                $header_idx = $i;
                break;
            }
        }
        
        if ($header_idx === null) return [];
        
        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = str_getcsv($line, ';');
            if (count($cols) < 9) continue;
            
            $date        = trim($cols[0], '" ');
            $status      = trim($cols[2], '" ');
            $counterpart = trim($cols[3], '" ');
            $col4        = trim($cols[4] ?? '', '" ');
            $purpose     = trim($cols[5] ?? '', '" ');
            $direction   = trim($cols[6] ?? '', '" '); // "Eingang" = revenu entrant

            // DKB: col[3] = Zahlungspflichtige*r (payeur), col[4] = Zahlungsempfänger*in (destinataire)
            // Pour Ausgang → le vrai bénéficiaire est col[4] (à qui on paie)
            // Pour Eingang → le vrai bénéficiaire est col[3] (qui nous paie)
            $dkb_placeholders = ['ISSUER', 'IBAN', 'BIC', 'SEPA', '', 'DKB AG'];

            if ($direction === 'Ausgang') {
                // Dépense : priorité col[4] (destinataire)
                if (!empty($col4) && !in_array(strtoupper($col4), $dkb_placeholders)) {
                    $beneficiary = $col4;
                } elseif (!empty($counterpart) && !in_array(strtoupper($counterpart), $dkb_placeholders)) {
                    $beneficiary = $counterpart;
                } else {
                    $parts = preg_split('/\s{2,}|\/|\|/', $purpose, 2);
                    $beneficiary = !empty(trim($parts[0])) ? trim($parts[0]) : $purpose;
                }
            } elseif ($direction === 'Eingang') {
                // Recette : priorité col[3] (payeur)
                if (!empty($counterpart) && !in_array(strtoupper($counterpart), $dkb_placeholders)) {
                    $beneficiary = $counterpart;
                } elseif (!empty($col4) && !in_array(strtoupper($col4), $dkb_placeholders)) {
                    $beneficiary = $col4;
                } else {
                    $parts = preg_split('/\s{2,}|\/|\|/', $purpose, 2);
                    $beneficiary = !empty(trim($parts[0])) ? trim($parts[0]) : $purpose;
                }
            } else {
                // Fallback
                $beneficiary = !empty($col4) ? $col4 : $counterpart;
            }

            $amount = trim($cols[8], '" ');
            
            if ($status !== 'Gebucht') continue;
            
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
            $amount = floatval($amount);
            
            if ($amount == 0) continue;
            
            $tx = $this->createTransaction(
                date: $date,
                beneficiary: $beneficiary,
                purpose: $purpose,
                montant: $amount,
                attachments: []
            );
            
            $tx['id'] = $this->generateUniqueHash($tx);
            $transactions[] = $tx;
        }
        
        return $transactions;
    }

    /**
     * Parser ING (format CSV export ING-DiBa)
     * Colonnes: Buchung;Wertstellungsdatum;Auftraggeber/Empfänger;Buchungstext;Verwendungszweck;Saldo;Währung;Betrag;Währung
     * Index:    0      ;1                  ;2                      ;3            ;4                ;5     ;6      ;7      ;8
     */
    private function parseIng(array $lines): array {
        $transactions = [];
        $header_idx = null;

        // Trouver la ligne d'en-tête
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], 'Buchung;Wertstellungsdatum') !== false) {
                $header_idx = $i;
                break;
            }
        }

        if ($header_idx === null) return [];

        for ($i = $header_idx + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $cols = str_getcsv($line, ';');
            if (count($cols) < 8) continue;

            $date        = trim($cols[0], '" ');
            $beneficiary = trim($cols[2], '" ');
            $buchungstext = trim($cols[3], '" ');
            $purpose     = trim($cols[4], '" ');
            $amount_str  = trim($cols[7], '" ');

            if (empty($date) || empty($amount_str)) continue;

            // Si pas de bénéficiaire, utiliser le Buchungstext (ex: "Abschluss")
            if (empty($beneficiary)) {
                $beneficiary = !empty($buchungstext) ? $buchungstext : 'Inconnu';
            }

            // Convertir montant allemand → float
            $amount_str = str_replace('.', '', $amount_str);
            $amount_str = str_replace(',', '.', $amount_str);
            $amount = floatval($amount_str);

            if ($amount == 0) continue;

            // La date ING est déjà en DD.MM.YYYY → formatDate la gère
            $date_str = $this->formatDate($date, intval(date('Y')));

            $tx = $this->createTransaction(
                date: $date_str,
                beneficiary: $beneficiary,
                purpose: $purpose,
                montant: $amount,
                attachments: []
            );

            $tx['id'] = $this->generateUniqueHash($tx);
            $transactions[] = $tx;
        }

        return $transactions;
    }

    /**
     * ✅ AMÉLIORE: Extraire l'année et toujours retourner 4 chiffres
     */
    private function extractYear(string $value): ?int {
        $value = trim($value, '" ');
        
        // YYYYMMDD (8 chiffres)
        if (strlen($value) === 8 && is_numeric($value)) {
            $year = intval(substr($value, 0, 4));
            if ($year >= 2000) return $year;
        }
        
        // YYYY (4 chiffres)
        if (strlen($value) === 4 && is_numeric($value)) {
            $year = intval($value);
            if ($year >= 2000 && $year <= 2099) {
                return $year;
            }
        }
        
        // YY (2 chiffres) → Convertir en 4 chiffres
        if (strlen($value) === 2 && is_numeric($value)) {
            $year = intval($value);
            // 00-30 = 2000-2030, 31-99 = 1931-1999
            $full_year = ($year <= 30) ? (2000 + $year) : (1900 + $year);
            return $full_year;
        }
        
        return null;
    }
    
    /**
     * ✅ AMÉLIORE: Toujours formatter en DD.MM.YYYY (4 chiffres)
     */
    private function formatDate(string $date, int $defaultYear): string {
        $date = trim($date, '" ');
        
        // DDMM (4 chiffres)
        if (strlen($date) === 4 && is_numeric($date)) {
            $jour = intval(substr($date, 0, 2));
            $mois = intval(substr($date, 2, 2));
            
            if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                // ✅ TOUJOURS formatter l'année en 4 chiffres
                $year = $this->ensureFourDigitYear($defaultYear);
                return sprintf("%02d.%02d.%d", $jour, $mois, $year);
            }
        }
        
        // DD.MM.YYYY ou DD.MM.YY
        if (strpos($date, '.') !== false) {
            $parts = explode('.', $date);
            
            if (count($parts) === 3) {
                $jour = intval($parts[0]);
                $mois = intval($parts[1]);
                $year = intval($parts[2]);
                
                // Convertir l'année si sur 2 chiffres
                if ($year < 100) {
                    $year = ($year <= 30) ? (2000 + $year) : (1900 + $year);
                }
                
                if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                    return sprintf("%02d.%02d.%d", $jour, $mois, $year);
                }
            } elseif (count($parts) === 2) {
                $jour = intval($parts[0]);
                $mois = intval($parts[1]);
                
                if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                    $year = $this->ensureFourDigitYear($defaultYear);
                    return sprintf("%02d.%02d.%d", $jour, $mois, $year);
                }
            }
        }
        
        // YYYYMMDD
        if (strlen($date) === 8 && is_numeric($date)) {
            $year = intval(substr($date, 0, 4));
            $mois = intval(substr($date, 4, 2));
            $jour = intval(substr($date, 6, 2));
            
            if ($jour >= 1 && $jour <= 31 && $mois >= 1 && $mois <= 12) {
                return sprintf("%02d.%02d.%d", $jour, $mois, $year);
            }
        }
        
        // Déjà en bon format?
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
            return $date;
        }
        
        return $date;
    }
    
    /**
     * ✅ HELPER: S'assurer que l'année a 4 chiffres
     */
    private function ensureFourDigitYear($year): int {
        $year = intval($year);
        if ($year < 100) {
            return ($year <= 30) ? (2000 + $year) : (1900 + $year);
        }
        return $year;
    }
    
    private function createTransaction(
        string $date,
        string $beneficiary,
        string $purpose,
        float $montant,
        array $attachments
    ): array {
        $type = ($montant > 0) ? 'Entrée' : 'Dépense';
        $amount = abs($montant);
        $category = $this->categorizeTransaction($beneficiary . ' ' . $purpose);

        // Règles de catégorisation forcée → Privat
        if ($this->isPrivateBeneficiary($beneficiary)) {
            $category = 'Privat';
        }

        return [
            'id' => '',
            'numeric_id' => $this->getNextNumericId(),
            'date' => $date,
            'type' => $type,
            'beneficiary' => $beneficiary,
            'purpose' => $purpose,
            'amount' => $amount,
            'category' => $category,
            'tva' => ($type === 'Entrée') ? 0 : 19,
            'attachments' => $attachments,
            'notes' => '',
            'source' => 'bank'
        ];
    }
    
    private function isPrivateBeneficiary(string $beneficiary): bool {
        $privat_beneficiaries = [
            'TAWAN ARUN',
            'ARUN,TAWAN',
            'ARUN, TAWAN',
            'ARUN',
            'ABSCHLUSS',
            'ANAIS EDELY',
            'MONOP\'',
            'AUCHAN',
            'BACKEREI ARMBRUSTER',
            'BÄCKEREI ARMBRUSTER',
            'EDEKA',
            'REWE',
            'LA MAISON',
            'PENNY',
            'BBSV BERLIN-BRANDENBURGER',
            'WEG FORSTER 55',
            'ALLIANZ VERSICHERUNGS-AKTIENGESELLSCHAFT',
            'TINO KNOBEL IMMOBILIEN',
            'BAUSPARKASSE SCHWABISCH HALL',
            'SANTANDER CONSUMER BANK',
        ];
        $ben_upper = strtoupper(trim($beneficiary));
        foreach ($privat_beneficiaries as $name) {
            if ($ben_upper === $name || strpos($ben_upper, $name) !== false) {
                return true;
            }
        }
        return false;
    }

    private function categorizeTransaction(string $text): string {
        $text = strtoupper($text);
        
        foreach ($this->categories['EXPENSES'] ?? [] as $cat => $keywords) {
            foreach ($keywords as $keyword) {
                if (!empty($keyword) && strpos($text, $keyword) !== false) {
                    return $cat;
                }
            }
        }
        
        foreach ($this->categories['REVENUE'] ?? [] as $cat => $keywords) {
            foreach ($keywords as $keyword) {
                if (!empty($keyword) && strpos($text, $keyword) !== false) {
                    return $cat;
                }
            }
        }
        
        return '';
    }
    
    private function generateUniqueHash(array $tx): string {
        $date_obj = $this->parseToDateTime($tx['date']);
        $date_normalized = $date_obj ? $date_obj->format('Y-m-d') : $tx['date'];
        
        $signature = implode('|', [
            $date_normalized,
            number_format($tx['amount'], 2, '.', ''),
            strtoupper(trim($tx['beneficiary'])),
            strtoupper(trim($tx['purpose'])),
            $tx['type']
        ]);
        
        return hash('sha256', $signature);
    }
    
    private function parseToDateTime(string $date): ?\DateTime {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
            return \DateTime::createFromFormat('d.m.Y', $date);
        }
        
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
            return \DateTime::createFromFormat('Y-m-d', $date);
        }
        
        return null;
    }
}