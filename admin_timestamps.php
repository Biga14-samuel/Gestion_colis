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
    <title>Horodatages Légaux - Gestion_Colis</title>
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
        }
        
        .info-box {
            background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(124, 58, 237, 0.1));
            border: 2px solid var(--neon-cyan);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        
        .info-box i {
            font-size: 1.5rem;
            color: var(--neon-cyan);
            flex-shrink: 0;
        }
        
        .info-box-content h3 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        
        .info-box-content p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.6;
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
        
        .stat-icon.purple {
            background: linear-gradient(135deg, var(--neon-purple), #6D28D9);
        }
        
        .stat-icon.cyan {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
        }
        
        .stat-icon.green {
            background: linear-gradient(135deg, var(--success), #059669);
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
        }
        
        .card-header {
            margin-bottom: 1.5rem;
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
        
        .timestamp-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .timestamp-table th,
        .timestamp-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .timestamp-table th {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(0, 180, 216, 0.05);
        }
        
        .timestamp-table tr:hover {
            background: rgba(0, 180, 216, 0.03);
        }
        
        .hash-code {
            font-family: monospace;
            font-size: 0.85rem;
            color: var(--neon-cyan);
            background: rgba(0, 180, 216, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-clock"></i>
                Horodatages Légaux
            </h1>
            <p class="page-subtitle">Supervisez les signatures électroniques et horodatages</p>
        </div>
        
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div class="info-box-content">
                <h3>Service d'Horodatage Légal</h3>
                <p>Ce module gère les signatures électroniques qualifiées avec horodatage légal conforme aux réglementations en vigueur. Chaque horodatage garantit l'intégrité et la date exacte de vos documents.</p>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-file-signature"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Signatures Totales</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon cyan">
                    <i class="fas fatimestamp"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Horodatages aujourd'hui</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">0</div>
                <div class="stat-label">Validés</div>
            </div>
        </div>
        
        <div class="data-card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-history"></i>
                    Historique des Horodatages
                </h2>
            </div>
            
            <table class="timestamp-table">
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Document</th>
                        <th>Utilisateur</th>
                        <th>Hash</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            Aucun horodatage enregistré pour le moment
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
