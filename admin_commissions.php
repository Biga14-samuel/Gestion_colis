<?php
ob_start();
require_once __DIR__ . '/views/admin/gestion_commissions.php';
$pageContent = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Commissions - Gestion_Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary, #F1F5F9);
            color: var(--text-color, #0f172a);
            margin: 0;
        }
        .standalone-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem 2rem;
            background: var(--bg-card, #ffffff);
            border-bottom: 1px solid var(--border-color, rgba(15, 23, 42, 0.08));
        }
        .standalone-header h1 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .standalone-header a {
            text-decoration: none;
            color: var(--text-color, #0f172a);
            font-weight: 600;
        }
        .standalone-main {
            padding: 1.5rem 2rem 2.5rem;
        }
    </style>
</head>
<body>
    <header class="standalone-header">
        <h1><i class="fas fa-coins" style="color:#00B4D8;"></i> Gestion des commissions</h1>
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Retour au dashboard</a>
    </header>
    <main class="standalone-main">
        <?php echo $pageContent; ?>
    </main>
</body>
</html>
