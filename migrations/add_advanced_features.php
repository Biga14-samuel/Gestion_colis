<?php
/**
 * Migration: Ajout des fonctionnalités avancées
 * Gestion_Colis v2.0 - Intégration complète du cahier des charges
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

class MigrationAdvancedFeatures {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    /**
     * Exécuter toutes les migrations
     */
    public function up() {
        echo "🚀 Démarrage de la migration v2.0...\n";
        
        $this->addMfaColumns();
        $this->addPublicServiceId();
        $this->createAgentCommissionsTable();
        $this->createAgentPerformanceTable();
        $this->createLegalTimestampsTable();
        $this->createIboxAccessLogsTable();
        $this->createPickupCodesTable();
        $this->createIboxOperatorsTable();
        $this->createNotificationsPreferencesTable();
        $this->createPiecesIdentiteTable();
        $this->createArchivesTable();
        
        echo "✅ Migration v2.0 terminée avec succès !\n";
    }
    
    /**
     * Annuler les migrations
     */
    public function down() {
        echo "🚪 Annulation de la migration v2.0...\n";
        
        $tables = [
            'archives',
            'pieces_identite',
            'notifications_preferences',
            'ibox_operators',
            'pickup_codes',
            'ibox_access_logs',
            'legal_timestamps',
            'agent_performance',
            'agent_commissions'
        ];
        
        foreach ($tables as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
            echo "  - Table {$table} supprimée\n";
        }
        
        // Supprimer les colonnes ajoutées
        try {
            $this->db->exec("ALTER TABLE utilisateurs DROP COLUMN IF EXISTS mfa_secret");
            $this->db->exec("ALTER TABLE utilisateurs DROP COLUMN IF EXISTS public_service_id");
        } catch (PDOException $e) {
            echo "  - Colonnes déjà supprimées ou inexistantes\n";
        }
        
        echo "✅ Annulation terminée !\n";
    }
    
    /**
     * Ajouter les colonnes MFA
     */
    private function addMfaColumns() {
        echo "  - Ajout des colonnes MFA...\n";
        
        try {
            $this->db->exec("ALTER TABLE utilisateurs ADD COLUMN mfa_secret VARCHAR(100) AFTER mfa_active");
            $this->db->exec("ALTER TABLE utilisateurs ADD COLUMN mfa_backup_codes JSON AFTER mfa_secret");
            $this->db->exec("ALTER TABLE utilisateurs ADD COLUMN mfa_verified_at DATETIME AFTER mfa_backup_codes");
            echo "    ✅ Colonnes MFA ajoutées\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Colonnes MFA déjà existantes: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Ajouter l'identifiant service public
     */
    private function addPublicServiceId() {
        echo "  - Ajout de la colonne public_service_id...\n";
        
        try {
            $this->db->exec("ALTER TABLE utilisateurs ADD COLUMN public_service_id VARCHAR(100) AFTER mfa_verified_at");
            echo "    ✅ Colonne public_service_id ajoutée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Colonne déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des commissions agents
     */
    private function createAgentCommissionsTable() {
        echo "  - Création de la table agent_commissions...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS agent_commissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            livraison_id INT,
            colis_id INT,
            montant_base DECIMAL(10,2) NOT NULL,
            montant_km DECIMAL(10,2) DEFAULT 0.00,
            montant_bonus DECIMAL(10,2) DEFAULT 0.00,
            montant_total DECIMAL(10,2) NOT NULL,
            statut ENUM('en_attente','approuve','paye','annule') DEFAULT 'en_attente',
            date_calcul TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_paiement DATETIME,
            transaction_id VARCHAR(100),
            details JSON,
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
            INDEX idx_agent_statut (agent_id, statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table agent_commissions créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des performances agents
     */
    private function createAgentPerformanceTable() {
        echo "  - Création de la table agent_performance...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS agent_performance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            periode ENUM('jour','semaine','mois') NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            total_livraisons INT DEFAULT 0,
            livrees_reussi INT DEFAULT 0,
            retournees INT DEFAULT 0,
            note_moyenne DECIMAL(3,2) DEFAULT 0.00,
            temps_moyen_minutes INT DEFAULT 0,
            distance_totale_km DECIMAL(10,2) DEFAULT 0.00,
            revenu_total DECIMAL(10,2) DEFAULT 0.00,
            taux_satisfaction DECIMAL(5,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
            UNIQUE KEY unique_agent_periode (agent_id, periode, date_debut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table agent_performance créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des horodatages légaux
     */
    private function createLegalTimestampsTable() {
        echo "  - Création de la table legal_timestamps...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS legal_timestamps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_hash VARCHAR(255) NOT NULL,
            document_id INT DEFAULT NULL,
            document_type ENUM('signature','colis','paiement','contrat') NOT NULL,
            timestamp_token TEXT NOT NULL,
            signature_serveur TEXT NOT NULL,
            cle_publique_serveur TEXT,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_expiration DATE,
            statut ENUM('valide','expire','revoke') DEFAULT 'valide',
            autorite_emetteuse VARCHAR(100) DEFAULT 'Gestion_Colis Internal TSA',
            INDEX idx_hash (document_hash),
            INDEX idx_document (document_type, document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table legal_timestamps créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des logs d'accès iBox
     */
    private function createIboxAccessLogsTable() {
        echo "  - Création de la table ibox_access_logs...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS ibox_access_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ibox_id INT NOT NULL,
            utilisateur_id INT,
            agent_id INT,
            action ENUM('ouverture','fermeture','depot','retour_scan','acces_autorise','acces_refuse') NOT NULL,
            code_utilise VARCHAR(10),
            ip_address VARCHAR(45),
            localisation_gps POINT,
            details JSON,
            date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ibox_id) REFERENCES ibox(id) ON DELETE CASCADE,
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
            INDEX idx_ibox (ibox_id),
            INDEX idx_date (date_action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table ibox_access_logs créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des codes de retrait
     */
    private function createPickupCodesTable() {
        echo "  - Création de la table pickup_codes...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS pickup_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            colis_id INT NOT NULL,
            ibox_id INT,
            code_pin VARCHAR(10) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            type_code ENUM('pin','qr','nfc') DEFAULT 'pin',
            qr_code_data TEXT,
            nombre_utilisations_max INT DEFAULT 1,
            nombre_utilisations INT DEFAULT 0,
            expires_at DATETIME NOT NULL,
            utilise_le DATETIME,
            actif BOOLEAN DEFAULT TRUE,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (colis_id) REFERENCES colis(id) ON DELETE CASCADE,
            FOREIGN KEY (ibox_id) REFERENCES ibox(id) ON DELETE SET NULL,
            INDEX idx_colis (colis_id),
            INDEX idx_code (code_pin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table pickup_codes créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des opérateurs iBox (multi-opérateurs)
     */
    private function createIboxOperatorsTable() {
        echo "  - Création de la table ibox_operators...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS ibox_operators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ibox_id INT NOT NULL,
            operator_name VARCHAR(100) NOT NULL,
            operator_type ENUM('postal','ecommerce','courrier','transport') NOT NULL,
            operator_code VARCHAR(50) NOT NULL,
            api_key VARCHAR(255),
            api_endpoint VARCHAR(500),
            permissions JSON,
            slots_debut INT DEFAULT 1,
            slots_fin INT DEFAULT 100,
            actif BOOLEAN DEFAULT TRUE,
            date_debut DATETIME,
            date_fin DATETIME,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ibox_id) REFERENCES ibox(id) ON DELETE CASCADE,
            UNIQUE KEY unique_ibox_operator (ibox_id, operator_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table ibox_operators créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des préférences de notifications
     */
    private function createNotificationsPreferencesTable() {
        echo "  - Création de la table notifications_preferences...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS notifications_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utilisateur_id INT NOT NULL,
            notif_colis TINYINT(1) DEFAULT 1,
            notif_livraison TINYINT(1) DEFAULT 1,
            notif_paiement TINYINT(1) DEFAULT 1,
            notif_email TINYINT(1) DEFAULT 1,
            notif_sms TINYINT(1) DEFAULT 0,
            date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_notifications_user (utilisateur_id),
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table notifications_preferences créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Table des pièces d'identité
     */
    private function createPiecesIdentiteTable() {
        echo "  - Création de la table pieces_identite...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS pieces_identite (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utilisateur_id INT NOT NULL,
            type_piece VARCHAR(50) NOT NULL,
            numero_piece VARCHAR(100) NOT NULL,
            date_expiration DATE NOT NULL,
            actif TINYINT(1) DEFAULT 1,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pieces_user (utilisateur_id),
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table pieces_identite créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Table des archives
     */
    private function createArchivesTable() {
        echo "  - Création de la table archives...\n";
        
        $sql = "
        CREATE TABLE IF NOT EXISTS archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_source VARCHAR(50) NOT NULL,
            record_id INT NOT NULL,
            data_archive JSON NOT NULL,
            raison_archivage VARCHAR(100),
            date_originale DATETIME NOT NULL,
            date_archivage TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            taille_originale BIGINT,
            format_archive ENUM('json','csv','pdf') DEFAULT 'json',
            chemin_stockage VARCHAR(500),
            restoreable BOOLEAN DEFAULT TRUE,
            INDEX idx_source (table_source, record_id),
            INDEX idx_date (date_archivage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        try {
            $this->db->exec($sql);
            echo "    ✅ Table archives créée\n";
        } catch (PDOException $e) {
            echo "    ⚠️ Table déjà existante: " . $e->getMessage() . "\n";
        }
    }
}

// Exécution si appelé directement
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $migration = new MigrationAdvancedFeatures();
    
    if (isset($argv[1]) && $argv[1] === 'down') {
        $migration->down();
    } else {
        $migration->up();
    }
}
