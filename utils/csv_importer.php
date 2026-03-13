<?php
/**
 * CSV Import Utility
 * Importe des colis en masse depuis un fichier CSV
 */

require_once '../config/database.php';

class CSVImporter {
    private $pdo;
    private $errors = [];
    private $successCount = 0;
    private $errorCount = 0;
    
    // Colonnes attendues dans le CSV
    private $requiredColumns = ['nom_destinataire', 'adresse_livraison', 'telephone_destinataire'];
    private $optionalColumns = ['description', 'poids', 'dimensions', 'instructions_livraison', 'expediteur_email'];
    
    public function __construct() {
        try {
            $this->pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception('Erreur de connexion à la base de données: ' . $e->getMessage());
        }
    }
    
    /**
     * Importe un fichier CSV
     */
    public function importFromFile($filePath, $userId, $defaultExpediteurId = null) {
        if (!file_exists($filePath)) {
            throw new Exception('Le fichier n\'existe pas.');
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new Exception('Le fichier doit être au format CSV.');
        }
        
        return $this->processCSV(file_get_contents($filePath), $userId, $defaultExpediteurId);
    }
    
    /**
     * Importe depuis une chaîne CSV
     */
    public function importFromString($csvContent, $userId, $defaultExpediteurId = null) {
        if (empty($csvContent)) {
            throw new Exception('Le contenu CSV est vide.');
        }
        
        return $this->processCSV($csvContent, $userId, $defaultExpediteurId);
    }
    
    /**
     * Traite le contenu CSV
     */
    private function processCSV($csvContent, $userId, $defaultExpediteurId) {
        // Detecter le delimitateur
        $delimiter = $this->detectDelimiter($csvContent);
        
        // Parser le CSV
        $rows = $this->parseCSV($csvContent, $delimiter);
        
        if (empty($rows)) {
            throw new Exception('Le fichier CSV est vide ou invalide.');
        }
        
        // Extraire les en-tetes
        $headers = array_map('trim', array_shift($rows));
        $headers = array_map([$this, 'normalizeHeader'], $headers);
        
        // Verifier les colonnes requises
        $this->validateHeaders($headers);
        
        // Traiter chaque ligne
        $this->processRows($rows, $headers, $userId, $defaultExpediteurId);
        
        return [
            'success' => $this->successCount,
            'errors' => $this->errorCount,
            'total' => $this->successCount + $this->errorCount,
            'error_messages' => $this->errors
        ];
    }
    
    /**
     * Detecte le delimitateur utilise dans le CSV
     */
    private function detectDelimiter($content) {
        $delimiters = [',', ';', "\t", '|'];
        $firstLine = strtok($content, "\n");
        
        $bestDelimiter = ',';
        $maxCount = 0;
        
        foreach ($delimiters as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestDelimiter = $delimiter;
            }
        }
        
        return $bestDelimiter;
    }
    
    /**
     * Parse le contenu CSV
     */
    private function parseCSV($content, $delimiter) {
        $rows = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Gerer les guillemets pour les champs avec des virgules
            $row = $this->parseCSVLine($line, $delimiter);
            $rows[] = $row;
        }
        
        return $rows;
    }
    
    /**
     * Parse une ligne CSV en gerant les guillemets
     */
    private function parseCSVLine($line, $delimiter) {
        $fields = [];
        $currentField = '';
        $inQuotes = false;
        
        for ($i = 0; $i < strlen($line); $i++) {
            $char = $line[$i];
            
            if ($char === '"') {
                if ($inQuotes && $i + 1 < strlen($line) && $line[$i + 1] === '"') {
                    $currentField .= '"';
                    $i++;
                } else {
                    $inQuotes = !$inQuotes;
                }
            } elseif ($char === $delimiter && !$inQuotes) {
                $fields[] = $currentField;
                $currentField = '';
            } else {
                $currentField .= $char;
            }
        }
        
        $fields[] = $currentField;
        
        return $fields;
    }
    
    /**
     * Normalise un en-tete de colonne
     */
    private function normalizeHeader($header) {
        // Supprimer les accents et mettre en minuscule
        $header = strtolower(trim($header));
        $header = str_replace(['à', 'â', 'ä', 'á', 'ã', 'å'], 'a', $header);
        $header = str_replace(['è', 'ê', 'ë', 'é'], 'e', $header);
        $header = str_replace(['ì', 'î', 'ï', 'í'], 'i', $header);
        $header = str_replace(['ò', 'ô', 'ö', 'ó', 'õ'], 'o', $header);
        $header = str_replace(['ù', 'û', 'ü', 'ú'], 'u', $header);
        $header = str_replace(['ñ'], 'n', $header);
        $header = str_replace(['ç'], 'c', $header);
        
        // Remplacer les espaces et caracteres speciaux par des underscores
        $header = preg_replace('/[^a-z0-9_]/', '_', $header);
        $header = preg_replace('/_+/', '_', $header);
        $header = trim($header, '_');
        
        return $header;
    }
    
    /**
     * Valide les en-tetes du CSV
     */
    private function validateHeaders($headers) {
        $normalizedRequired = array_map([$this, 'normalizeHeader'], $this->requiredColumns);
        
        foreach ($normalizedRequired as $required) {
            if (!in_array($required, $headers)) {
                // Essayer des variantes
                $found = false;
                foreach ($headers as $header) {
                    if (strpos($header, $required) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new Exception('Colonne requise manquante: ' . $required);
                }
            }
        }
    }
    
    /**
     * Traite chaque ligne du CSV
     */
    private function processRows($rows, $headers, $userId, $defaultExpediteurId) {
        $rowNumber = 1; // Commence a 1 car la premiere ligne est les en-tetes
        
        foreach ($rows as $row) {
            $rowNumber++;
            
            // Ignorer les lignes vides
            if (count($row) === 1 && empty($row[0])) {
                continue;
            }
            
            // Associer les colonnes aux valeurs
            $data = [];
            foreach ($headers as $index => $header) {
                if (isset($row[$index])) {
                    $data[$header] = trim($row[$index]);
                } else {
                    $data[$header] = '';
                }
            }
            
            // Traiter la ligne
            try {
                $this->processRow($data, $userId, $defaultExpediteurId);
                $this->successCount++;
            } catch (Exception $e) {
                $this->errorCount++;
                $this->errors[] = "Ligne $rowNumber: " . $e->getMessage();
            }
        }
    }
    
    /**
     * Traite une seule ligne de donnees
     */
    private function processRow($data, $userId, $defaultExpediteurId) {
        // Valider les donnees obligatoires
        $nomDestinataire = $this->getField($data, ['nom_destinataire', 'destinataire', 'nom', 'name', 'recipient']);
        $adresseLivraison = $this->getField($data, ['adresse_livraison', 'adresse', 'address', 'livraison']);
        $telephoneDestinataire = $this->getField($data, ['telephone_destinataire', 'telephone', 'phone', 'tel']);
        
        if (empty($nomDestinataire)) {
            throw new Exception('Nom du destinataire manquant.');
        }
        
        if (empty($adresseLivraison)) {
            throw new Exception('Adresse de livraison manquante.');
        }
        
        // Generer le numero de suivi
        $numeroSuivi = $this->generateTrackingNumber();
        
        // Trouver l'expediteur
        $expediteurEmail = $this->getField($data, ['expediteur_email', 'expediteur', 'sender_email', 'sender']);
        $expediteurId = $defaultExpediteurId;
        
        if ($expediteurEmail) {
            $stmt = $this->pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
            $stmt->execute([$expediteurEmail]);
            $expediteur = $stmt->fetch();
            if ($expediteur) {
                $expediteurId = $expediteur['id'];
            }
        }
        
        if (!$expediteurId) {
            $expediteurId = $userId;
        }
        
        // Donnees optionnelles
        $description = $this->getField($data, ['description', 'desc', 'contenu', 'content']) ?: 'Colis sans description';
        $poids = $this->getField($data, ['poids', 'weight', 'kg']) ?: null;
        $dimensions = $this->getField($data, ['dimensions', 'size', 'taille']) ?: null;
        $instructions = $this->getField($data, ['instructions_livraison', 'instructions', 'note', 'commentaire']) ?: '';
        
        // Inserer dans la base de donnees
        $stmt = $this->pdo->prepare("
            INSERT INTO colis (
                numero_suivi, expediteur_id, destinataire_id,
                nom_destinataire, adresse_livraison, telephone_destinataire,
                description, poids, dimensions, instructions_livraison,
                statut, date_creation, date_mise_a_jour
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'preparation', NOW(), NOW())
        ");
        
        $stmt->execute([
            $numeroSuivi,
            $expediteurId,
            $userId, // Le client est aussi le destinataire par defaut
            $nomDestinataire,
            $adresseLivraison,
            $telephoneDestinataire ?: null,
            $description,
            $poids ? (float)$poids : null,
            $dimensions ?: null,
            $instructions
        ]);
        
        // Ajouter l'historique
        $colisId = $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare("
            INSERT INTO historique_colis (colis_id, ancien_statut, nouveau_statut, commentaire, utilisateur_id, date_action)
            VALUES (?, NULL, 'preparation', 'Colis créé via import CSV', ?, NOW())
        ");
        $stmt->execute([$colisId, $userId]);
    }
    
    /**
     * Récupère un champ en essayant plusieurs noms possibles
     */
    private function getField($data, $possibleNames) {
        foreach ($possibleNames as $name) {
            if (isset($data[$name]) && !empty($data[$name])) {
                return $data[$name];
            }
        }
        return '';
    }
    
    /**
     * Genere un numero de suivi unique
     */
    private function generateTrackingNumber() {
        do {
            $prefix = 'COL';
            $timestamp = date('Ymd');
            $random = strtoupper(substr(md5(uniqid()), 0, 6));
            $numeroSuivi = $prefix . '-' . $timestamp . '-' . $random;
            
            $stmt = $this->pdo->prepare("SELECT id FROM colis WHERE numero_suivi = ?");
            $stmt->execute([$numeroSuivi]);
        } while ($stmt->fetch());
        
        return $numeroSuivi;
    }
    
    /**
     * Genere un modele CSV
     */
    public function generateTemplate() {
        $headers = [
            'nom_destinataire (requis)',
            'adresse_livraison (requis)',
            'telephone_destinataire',
            'description',
            'poids',
            'dimensions',
            'instructions_livraison',
            'expediteur_email'
        ];
        
        $sampleData = [
            ['Jean Dupont', '123 Rue de la Paix, 75001 Paris', '0612345678', 'Documents importants', '0.5', 'A4', 'Sonner à l\'interphone', ''],
            ['Marie Martin', '45 Avenue des Champs-Élysées, 75008 Paris', '0698765432', 'Vêtements', '2.0', '40x30x10', 'Laisser chez le gardien', 'client@email.com']
        ];
        
        $csv = implode(',', $headers) . "\n";
        
        foreach ($sampleData as $row) {
            $escapedRow = array_map(function($field) {
                if (strpos($field, ',') !== false || strpos($field, '"') !== false) {
                    return '"' . str_replace('"', '""', $field) . '"';
                }
                return $field;
            }, $row);
            $csv .= implode(',', $escapedRow) . "\n";
        }
        
        return $csv;
    }
    
    /**
     * Retourne les erreurs
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Retourne le nombre de succes
     */
    public function getSuccessCount() {
        return $this->successCount;
    }
    
    /**
     * Retourne le nombre d'erreurs
     */
    public function getErrorCount() {
        return $this->errorCount;
    }
}
