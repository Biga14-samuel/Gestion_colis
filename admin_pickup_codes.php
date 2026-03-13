<?php
require_once __DIR__ . '/utils/session.php';
SessionManager::start();

// Vérifier si l'utilisateur est connecté et est admin
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

if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Codes de Retrait - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 2px solid rgba(0, 180, 216, 0.2);
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
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .data-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 2px solid rgba(0, 180, 216, 0.2);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .card-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.25rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .card-title i {
            color: var(--neon-cyan);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.4);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .data-table th {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(0, 180, 216, 0.05);
        }
        
        .data-table tr:hover {
            background: rgba(0, 180, 216, 0.03);
        }
        
        .code-display {
            font-family: 'Orbitron', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--neon-cyan);
            letter-spacing: 2px;
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }
        
        .badge-info {
            background: rgba(0, 180, 216, 0.15);
            color: var(--neon-cyan);
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-qrcode"></i>
                Gestion des Codes de Retrait
            </h1>
            <p class="page-subtitle">Gérez les codes de retrait pour les iBox et livraisons</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon cyan">
                    <i class="fas fa-qrcode"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Codes Actifs</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Codes Utilisés</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Codes Expirés</div>
            </div>
        </div>
        
        <div class="data-card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-list"></i>
                    Historique des Codes
                </h2>
                <button class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Générer un Code
                </button>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Destinataire</th>
                        <th>Date Création</th>
                        <th>Expiration</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            Aucun code de retrait généré pour le moment
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
