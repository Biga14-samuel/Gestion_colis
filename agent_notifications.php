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
    <title>Mes Notifications - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
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
            max-width: 900px;
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
        
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .notification-item {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 2px solid rgba(0, 180, 216, 0.2);
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            border-color: var(--neon-cyan);
            transform: translateX(5px);
        }
        
        .notification-item.unread {
            border-left: 4px solid var(--neon-cyan);
        }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .notification-icon.info {
            background: rgba(0, 180, 216, 0.15);
            color: var(--neon-cyan);
        }
        
        .notification-icon.success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }
        
        .notification-icon.warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }
        
        .notification-icon.truck {
            background: rgba(0, 150, 199, 0.15);
            color: var(--neon-blue);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .notification-message {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .notification-time {
            color: var(--text-secondary);
            font-size: 0.8rem;
            opacity: 0.7;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--neon-cyan);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-bell"></i>
                Mes Notifications
            </h1>
            <p class="page-subtitle">Restez informé de vos livraisons et activités</p>
        </div>
        
        <div class="notifications-list">
            <div class="notification-item unread">
                <div class="notification-icon truck">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">Bienvenue, Agent !</div>
                    <div class="notification-message">Votre compte agent est actif. Vous pouvez maintenant consulter vos livraisons assignées et commencer à travailler.</div>
                    <div class="notification-time">À l'instant</div>
                </div>
            </div>
            
            <div class="notification-item">
                <div class="notification-icon info">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">Prêt pour les livraisons</div>
                    <div class="notification-message">Consultez votre planning et vos livraisons assignées dans la section correspondante.</div>
                    <div class="notification-time">Aujourd'hui</div>
                </div>
            </div>
            
            <div class="notification-item">
                <div class="notification-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">Compte vérifié</div>
                    <div class="notification-message">Votre compte agent a été vérifié avec succès. Vous avez accès à toutes les fonctionnalités.</div>
                    <div class="notification-time">Aujourd'hui</div>
                </div>
            </div>
            
            <div class="notification-item">
                <div class="notification-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">Nouvelles livraisons à venir</div>
                    <div class="notification-message">De nouvelles livraisons seront bientôt assignées. Restez connecté pour ne rien manquer.</div>
                    <div class="notification-time">Hier</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
