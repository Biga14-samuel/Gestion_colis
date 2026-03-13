<?php
/**
 * Service de Commission des Agents
 * Gestion_Colis v2.0
 */

require_once __DIR__ . '/../config/database.php';

class CommissionService {
    private $db;
    
    // Taux de commission configurables
    private $config = [
        'base_rate' => 2.50,          // € par colis de base
        'km_rate' => 0.45,            // € par kilomètre
        'urgent_bonus' => 1.50,       // Bonus pour colis urgent
        'fragile_bonus' => 0.75,      // Bonus pour colis fragile
        'weight_threshold' => 5.0, // Seuil de poids pour bonus
        'weight_bonus' => 0.50,       // Bonus par kg au-delà du seuil
        'min_commission' => 1.50,     // Commission minimum
        'max_commission' => 15.00     // Commission maximum
    ];
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Charger la configuration
        $this->loadConfig();
    }
    
    /**
     * Charger la configuration
     */
    private function loadConfig() {
        $configFile = __DIR__ . '/../config/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['commissions'])) {
                $this->config = array_merge($this->config, $config['commissions']);
            }
        }
    }
    
    /**
     * Calculer et enregistrer la commission pour une livraison
     */
    public function calculateCommission($deliveryId) {
        // Récupérer les détails de la livraison
        $delivery = $this->getDeliveryDetails($deliveryId);
        if (!$delivery) {
            return ['success' => false, 'error' => 'Livraison non trouvée'];
        }
        
        // Vérifier si une commission existe déjà
        $existing = $this->getCommissionByDelivery($deliveryId);
        if ($existing) {
            return ['success' => false, 'error' => 'Commission déjà calculée'];
        }
        
        // Calculer les différents composants de la commission
        $commission = $this->computeCommission($delivery);
        
        // Enregistrer la commission
        try {
            $stmt = $this->db->prepare("
                INSERT INTO agent_commissions (
                    agent_id, livraison_id, colis_id, montant_base, montant_km,
                    montant_bonus, montant_total, statut, date_calcul, details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente', NOW(), ?)
            ");
            
            $stmt->execute([
                $delivery['agent_id'],
                $deliveryId,
                $delivery['colis_id'],
                $commission['base'],
                $commission['km'],
                $commission['bonus'],
                $commission['total'],
                json_encode($commission['details'])
            ]);
            
            $commissionId = $this->db->lastInsertId();
            
            // Mettre à jour les statistiques de l'agent
            $this->updateAgentStats($delivery['agent_id']);
            
            return [
                'success' => true,
                'commission_id' => $commissionId,
                'amount' => $commission['total'],
                'breakdown' => $commission
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => user_error_message($e, 'commission.calculate', 'Erreur de base de données.')];
        }
    }
    
    /**
     * Calculer le montant de la commission
     */
    private function computeCommission($delivery) {
        $base = $this->config['base_rate'];
        $km = 0;
        $bonus = 0;
        $details = [];
        
        // Bonus pour distance (si disponible)
        if (!empty($delivery['distance_km'])) {
            $km = $delivery['distance_km'] * $this->config['km_rate'];
            $details['distance_km'] = $delivery['distance_km'];
            $details['km_rate'] = $this->config['km_rate'];
        }
        
        // Bonus pour colis urgent
        if ($delivery['urgent']) {
            $bonus += $this->config['urgent_bonus'];
            $details['urgent_bonus'] = $this->config['urgent_bonus'];
        }
        
        // Bonus pour colis fragile
        if ($delivery['fragile']) {
            $bonus += $this->config['fragile_bonus'];
            $details['fragile_bonus'] = $this->config['fragile_bonus'];
        }
        
        // Bonus pour poids important
        if ($delivery['poids'] > $this->config['weight_threshold']) {
            $weightBonus = ($delivery['poids'] - $this->config['weight_threshold']) * $this->config['weight_bonus'];
            $bonus += $weightBonus;
            $details['weight_bonus'] = $weightBonus;
        }
        
        // Appliquer le taux personnalisé de l'agent (s'il existe)
        $agentRate = $this->getAgentRate($delivery['agent_id']);
        if ($agentRate > 0) {
            $totalBeforeRate = $base + $km + $bonus;
            $totalWithRate = $totalBeforeRate * (1 + ($agentRate / 100));
            $bonus += ($totalWithRate - $totalBeforeRate);
            $details['agent_rate'] = $agentRate . '%';
        }
        
        $total = $base + $km + $bonus;
        
        // Appliquer les limites
        $total = max($total, $this->config['min_commission']);
        $total = min($total, $this->config['max_commission']);
        
        return [
            'base' => $base,
            'km' => round($km, 2),
            'bonus' => round($bonus, 2),
            'total' => round($total, 2),
            'details' => $details
        ];
    }
    
    /**
     * Récupérer le taux de commission personnalisé de l'agent
     */
    private function getAgentRate($agentId) {
        $stmt = $this->db->prepare("SELECT commission_rate FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        return (float) ($stmt->fetch()['commission_rate'] ?? 0);
    }
    
    /**
     * Récupérer les détails d'une livraison
     */
    private function getDeliveryDetails($deliveryId) {
        $stmt = $this->db->prepare("
            SELECT l.*, c.poids, c.fragile, c.urgent, c.description
            FROM livraisons l
            JOIN colis c ON l.colis_id = c.id
            WHERE l.id = ?
        ");
        $stmt->execute([$deliveryId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer la commission d'une livraison
     */
    private function getCommissionByDelivery($deliveryId) {
        $stmt = $this->db->prepare("SELECT * FROM agent_commissions WHERE livraison_id = ?");
        $stmt->execute([$deliveryId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupérer les commissions d'un agent
     */
    public function getAgentCommissions($agentId, $status = null, $limit = 50) {
        $sql = "SELECT * FROM agent_commissions WHERE agent_id = ?";
        $params = [$agentId];
        
        if ($status) {
            $sql .= " AND statut = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY date_calcul DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Récapitulatif des commissions d'un agent
     */
    public function getAgentCommissionSummary($agentId, $period = 'month') {
        $dateFilter = match($period) {
            'week' => "DATE_SUB(NOW(), INTERVAL 1 WEEK)",
            'month' => "DATE_SUB(NOW(), INTERVAL 1 MONTH)",
            'year' => "DATE_SUB(NOW(), INTERVAL 1 YEAR)",
            default => "DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        };
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN statut = 'en_attente' THEN montant_total ELSE 0 END) as pending_amount,
                SUM(CASE WHEN statut = 'paye' THEN montant_total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN statut IN ('en_attente', 'paye') THEN montant_total ELSE 0 END) as total_amount,
                AVG(montant_total) as avg_commission
            FROM agent_commissions
            WHERE agent_id = ? AND date_calcul >= {$dateFilter}
        ");
        $stmt->execute([$agentId]);
        return $stmt->fetch();
    }
    
    /**
     * Marquer une commission comme payée
     */
    public function markAsPaid($commissionId, $transactionId = null) {
        $stmt = $this->db->prepare("
            UPDATE agent_commissions 
            SET statut = 'paye', date_paiement = NOW(), transaction_id = ?
            WHERE id = ?
        ");
        return $stmt->execute([$transactionId, $commissionId]);
    }
    
    /**
     * Marquer plusieurs commissions comme payées
     */
    public function markMultipleAsPaid($commissionIds, $transactionId = null) {
        if (empty($commissionIds)) return false;
        
        $placeholders = str_repeat('?,', count($commissionIds) - 1) . '?';
        
        try {
            $stmt = $this->db->prepare("
                UPDATE agent_commissions 
                SET statut = 'paye', date_paiement = NOW(), transaction_id = ?
                WHERE id IN ({$placeholders})
            ");
            
            $params = array_merge([$transactionId], $commissionIds);
            $stmt->execute($params);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Mettre à jour les statistiques de l'agent
     */
    private function updateAgentStats($agentId) {
        // Calculer le total des livraisons
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM livraisons 
            WHERE agent_id = ? AND statut = 'terminee'
        ");
        $stmt->execute([$agentId]);
        $totalDeliveries = $stmt->fetch()['count'];
        
        // Calculer la note moyenne
        $stmt = $this->db->prepare("
            SELECT AVG(evaluation) as avg_rating 
            FROM livraisons 
            WHERE agent_id = ? AND evaluation IS NOT NULL
        ");
        $stmt->execute([$agentId]);
        $avgRating = $stmt->fetch()['avg_rating'] ?? 0;
        
        // Mettre à jour la table agents
        $stmt = $this->db->prepare("
            UPDATE agents 
            SET total_livraisons = ?, note_moyenne = ?
            WHERE id = ?
        ");
        $stmt->execute([$totalDeliveries, round($avgRating, 2), $agentId]);
    }
    
    /**
     * Générer le rapport de performance d'un agent
     */
    public function generatePerformanceReport($agentId, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                l.statut,
                COUNT(*) as count,
                SUM(l.distance_km) as total_distance,
                AVG(l.duree_minutes) as avg_duration,
                AVG(l.evaluation) as avg_rating,
                SUM(ac.montant_total) as total_commissions
            FROM livraisons l
            LEFT JOIN agent_commissions ac ON l.id = ac.livraison_id
            WHERE l.agent_id = ? 
            AND l.date_assignation BETWEEN ? AND ?
            GROUP BY l.statut
        ");
        $stmt->execute([$agentId, $startDate, $endDate]);
        $byStatus = $stmt->fetchAll();
        
        // Statistiques globales
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN l.statut = 'terminee' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN l.statut = 'annulee' THEN 1 ELSE 0 END) as cancelled,
                SUM(l.distance_km) as total_distance,
                AVG(l.evaluation) as overall_rating
            FROM livraisons l
            WHERE l.agent_id = ? 
            AND l.date_assignation BETWEEN ? AND ?
        ");
        $stmt->execute([$agentId, $startDate, $endDate]);
        $summary = $stmt->fetch();
        
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'summary' => $summary,
            'by_status' => $byStatus,
            'success_rate' => $summary['total'] > 0 
                ? round(($summary['delivered'] / $summary['total']) * 100, 2) 
                : 0
        ];
    }
    
    /**
     * Récupérer toutes les commissions en attente de paiement (pour admin)
     */
    public function getPendingCommissions($agentId = null) {
        $sql = "
            SELECT ac.*, a.numero_agent, u.nom, u.prenom, u.email,
                   c.code_tracking, l.date_fin
            FROM agent_commissions ac
            JOIN agents a ON ac.agent_id = a.id
            JOIN utilisateurs u ON a.utilisateur_id = u.id
            LEFT JOIN colis c ON ac.colis_id = c.id
            LEFT JOIN livraisons l ON ac.livraison_id = l.id
            WHERE ac.statut = 'en_attente'
        ";
        
        $params = [];
        if ($agentId) {
            $sql .= " AND ac.agent_id = ?";
            $params[] = $agentId;
        }
        
        $sql .= " ORDER BY ac.date_calcul ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Exporter les commissions au format JSON
     */
    public function exportCommissions($agentId, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT ac.*, c.code_tracking, l.date_fin
            FROM agent_commissions ac
            LEFT JOIN colis c ON ac.colis_id = c.id
            LEFT JOIN livraisons l ON ac.livraison_id = l.id
            WHERE ac.agent_id = ? 
            AND ac.date_calcul BETWEEN ? AND ?
            ORDER BY ac.date_calcul DESC
        ");
        $stmt->execute([$agentId, $startDate, $endDate]);
        
        $commissions = $stmt->fetchAll();
        
        // Ajouter les métadonnées
        $export = [
            'export_date' => date('Y-m-d H:i:s'),
            'agent_id' => $agentId,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'total_count' => count($commissions),
            'total_amount' => array_sum(array_column($commissions, 'montant_total')),
            'commissions' => $commissions
        ];
        
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
