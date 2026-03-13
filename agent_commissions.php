<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Commissions - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
            --neon-purple: #7C3AED;
            --success: #10B981;
            --warning: #F59E0B;
            --bg-primary: #E8EEF2;
            --bg-card: #FFFFFF;
            --text-primary: #1A2332;
            --text-secondary: #2D3A4D;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }
        
        .page-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.75rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 2px solid rgba(0, 180, 216, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card.success {
            border-color: var(--success);
        }
        
        .stat-card.warning {
            border-color: var(--warning);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stat-icon.cyan {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
        }
        
        .stat-icon.green {
            background: linear-gradient(135deg, var(--success), #059669);
        }
        
        .stat-icon.orange {
            background: linear-gradient(135deg, var(--warning), #D97706);
        }
        
        .stat-value {
            font-family: 'Orbitron', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .section-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 2px solid rgba(0, 180, 216, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }
        
        .section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.25rem;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-title i {
            color: var(--neon-cyan);
        }
        
        .commission-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .commission-table th,
        .commission-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .commission-table th {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .commission-table tr:hover {
            background: rgba(0, 180, 216, 0.05);
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }
        
        .badge-pending {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-money-bill-wave"></i>
                Mes Commissions
            </h1>
            <p class="page-subtitle">Suivez vos gains et vos paiements</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon cyan">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-value">0 XAF</div>
                <div class="stat-label">Total des Commissions</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">0 XAF</div>
                <div class="stat-label">Commissions Payées</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon orange">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value">0 XAF</div>
                <div class="stat-label">En Attente</div>
            </div>
        </div>
        
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Historique des Commissions
            </h2>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                Vos commissions s'afficheront ici une fois que vous aurez effectué des livraisons.
            </p>
            <table class="commission-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Livraison</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            Aucune commission pour le moment
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
