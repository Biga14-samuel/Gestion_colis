<?php
session_start();
require_once 'config/database.php';

// Rediriger vers le dashboard si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Récupérer quelques statistiques pour la page d'accueil
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM utilisateurs");
    $stats['utilisateurs'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM colis");
    $stats['colis'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM ibox");
    $stats['ibox'] = $stmt->fetch()['total'];
} catch (Exception $e) {
    $stats = ['utilisateurs' => 0, 'colis' => 0, 'ibox' => 0];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion_Colis - Gestion Numérique du Courrier et des Colis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Orbitron:wght@400;500;600;700;800;900&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="assets/images/favicon.png">
    
    <style>
        /* ========================================
           THÈME BLANC PROFESSIONNEL - HOME PAGE
           ======================================== */
        
        :root {
            --neon-cyan: #00B4D8;
            --neon-blue: #0096C7;
            --neon-purple: #7C3AED;
            --neon-green: #10B981;
            --light-bg: #FFFFFF;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--light-bg);
            color: var(--gray-900);
            overflow-x: hidden;
        }
        
        /* Background Effects - Subtil */
        .bg-grid {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(0, 180, 216, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 180, 216, 0.02) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
        }
        
        .bg-glow {
            position: fixed;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        
        .glow-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.15;
            animation: float 20s infinite ease-in-out;
        }
        
        .glow-orb-1 {
            width: 500px;
            height: 500px;
            background: var(--neon-cyan);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }
        
        .glow-orb-2 {
            width: 600px;
            height: 600px;
            background: var(--neon-purple);
            bottom: -300px;
            right: -300px;
            animation-delay: -7s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(50px, -50px) scale(1.1); }
            50% { transform: translate(0, 50px) scale(0.95); }
            75% { transform: translate(-50px, -25px) scale(1.05); }
        }
        
        /* Navigation */
        .nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 1.25rem 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--gray-200);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .nav-logo {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .nav-logo i {
            color: var(--neon-cyan);
            font-size: 1.75rem;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
        }
        
        .nav-links a {
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
        }
        
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--neon-cyan);
            transition: width 0.3s ease;
        }
        
        .nav-links a:hover {
            color: var(--neon-cyan);
        }
        
        .nav-links a:hover::after {
            width: 100%;
        }
        
        .nav-cta {
            display: flex;
            gap: 1rem;
        }
        
        /* Hero Section - LAYOUT PAYSAGE */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 8rem 5% 4rem;
            position: relative;
            z-index: 10;
            background: var(--light-bg);
        }
        
        .hero-container {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6rem;
            align-items: center;
        }
        
        .hero-content {
            position: relative;
        }
        
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 180, 216, 0.1);
            border: 1px solid rgba(0, 180, 216, 0.3);
            border-radius: 50px;
            font-size: 0.85rem;
            color: var(--neon-cyan);
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        .hero-badge i {
            font-size: 0.75rem;
        }
        
        .hero-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            color: var(--gray-900);
        }
        
        .hero-title-gradient {
            background: linear-gradient(135deg, var(--neon-cyan) 0%, var(--neon-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hero-subtitle {
            font-size: 1.2rem;
            color: var(--gray-600);
            line-height: 1.8;
            margin-bottom: 2rem;
            max-width: 500px;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 3rem;
            flex-wrap: wrap;
        }
        
        .btn-glow {
            position: relative;
            padding: 1rem 2.5rem;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .btn-primary-glow {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: #FFFFFF;
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.3);
        }
        
        .btn-primary-glow:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0, 180, 216, 0.4);
        }
        
        .btn-secondary-glow {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
        }
        
        .btn-secondary-glow:hover {
            border-color: var(--neon-cyan);
            color: var(--neon-cyan);
            background: rgba(0, 180, 216, 0.05);
        }
        
        /* Stats */
        .hero-stats {
            display: flex;
            gap: 3rem;
        }
        
        .hero-stat {
            position: relative;
        }
        
        .hero-stat-number {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--neon-cyan);
        }
        
        .hero-stat-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        
        /* PHOTO FLOTTANTE LUMINEUSE */
        .hero-visual {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .floating-photo-container {
            position: relative;
            animation: float-photo 6s infinite ease-in-out;
        }
        
        @keyframes float-photo {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(2deg); }
            50% { transform: translateY(0px) rotate(0deg); }
            75% { transform: translateY(20px) rotate(-2deg); }
        }
        
        .photo-glow-ring {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 380px;
            height: 380px;
            border-radius: 50%;
            background: conic-gradient(from 0deg, transparent, var(--neon-cyan), var(--neon-blue), transparent);
            animation: rotate 8s linear infinite;
            opacity: 0.3;
            filter: blur(20px);
        }
        
        @keyframes rotate {
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        .photo-outer-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 360px;
            height: 360px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 180, 216, 0.15) 0%, transparent 70%);
            animation: pulse-glow 3s infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.3; }
            50% { transform: translate(-50%, -50%) scale(1.1); opacity: 0.5; }
        }
        
        .photo-frame {
            position: relative;
            width: 340px;
            height: 340px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid var(--gray-200);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.1),
                0 0 60px rgba(0, 180, 216, 0.2);
            z-index: 2;
            background: linear-gradient(135deg, var(--gray-100), #FFFFFF);
        }
        
        .photo-frame::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 40%,
                rgba(255, 255, 255, 0.3) 50%,
                transparent 60%
            );
            animation: shine 4s infinite;
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }
        
        .joseph-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .photo-corner-accent {
            position: absolute;
            width: 60px;
            height: 60px;
            border: 2px solid var(--neon-cyan);
            z-index: 3;
        }
        
        .photo-corner-accent-1 {
            top: -10px;
            left: -10px;
            border-right: none;
            border-bottom: none;
            border-radius: 20px 0 0 0;
        }
        
        .photo-corner-accent-2 {
            top: -10px;
            right: -10px;
            border-left: none;
            border-bottom: none;
            border-radius: 0 20px 0 0;
        }
        
        .photo-corner-accent-3 {
            bottom: -10px;
            left: -10px;
            border-right: none;
            border-top: none;
            border-radius: 0 0 0 20px;
        }
        
        .photo-corner-accent-4 {
            bottom: -10px;
            right: -10px;
            border-left: none;
            border-top: none;
            border-radius: 0 0 20px 0;
        }
        
        .floating-icons {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }
        
        .floating-icon {
            position: absolute;
            width: 50px;
            height: 50px;
            background: rgba(0, 180, 216, 0.1);
            border: 1px solid rgba(0, 180, 216, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--neon-cyan);
            animation: float-icon 4s infinite ease-in-out;
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.2);
        }
        
        .floating-icon-1 {
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            animation-delay: 0s;
        }
        
        .floating-icon-2 {
            bottom: 20%;
            left: 0;
            animation-delay: -1s;
        }
        
        .floating-icon-3 {
            bottom: 20%;
            right: 0;
            animation-delay: -2s;
        }
        
        .floating-icon-4 {
            top: 30%;
            right: -20px;
            animation-delay: -3s;
        }
        
        @keyframes float-icon {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-15px) scale(1.1); }
        }
        
        /* Features Section */
        .features-section {
            padding: 6rem 2rem;
            position: relative;
            z-index: 10;
            background: var(--gray-50);
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }
        
        .section-label {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.85rem;
            color: var(--neon-cyan);
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        .section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--gray-900);
        }
        
        .section-desc {
            color: var(--gray-600);
            max-width: 600px;
            margin: 0 auto;
            font-size: 1.1rem;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .feature-card {
            background: var(--light-bg);
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            padding: 2.5rem;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--neon-cyan), var(--neon-blue));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            border-color: rgba(0, 180, 216, 0.3);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1), 0 0 30px rgba(0, 180, 216, 0.1);
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, rgba(0, 180, 216, 0.1), rgba(0, 180, 216, 0.05));
            border: 1px solid rgba(0, 180, 216, 0.3);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            color: var(--neon-cyan);
            transition: all 0.4s ease;
        }
        
        .feature-card:hover .feature-icon {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-blue));
            color: #FFFFFF;
            box-shadow: 0 10px 30px rgba(0, 180, 216, 0.3);
            transform: scale(1.1);
        }
        
        .feature-card h3 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--gray-900);
            font-weight: 600;
        }
        
        .feature-card p {
            color: var(--gray-600);
            line-height: 1.7;
            font-size: 0.95rem;
        }
        
        /* Footer */
        .footer {
            padding: 4rem 2rem 2rem;
            position: relative;
            z-index: 10;
            border-top: 1px solid var(--gray-200);
            background: var(--light-bg);
        }
        
        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }
        
        .footer-brand {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.5rem;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
        }
        
        .footer-brand i {
            color: var(--neon-cyan);
        }
        
        .footer-desc {
            color: var(--gray-600);
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .footer-social {
            display: flex;
            gap: 1rem;
        }
        
        .footer-social a {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .footer-social a:hover {
            background: var(--neon-cyan);
            color: #FFFFFF;
            border-color: var(--neon-cyan);
        }
        
        .footer-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            margin-bottom: 1.5rem;
            color: var(--gray-900);
            font-weight: 600;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--gray-600);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--neon-cyan);
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        .footer-bottom a {
            color: var(--neon-cyan);
            text-decoration: none;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .hero-container {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .hero-subtitle {
                margin-left: auto;
                margin-right: auto;
            }
            
            .hero-buttons {
                justify-content: center;
            }
            
            .hero-stats {
                justify-content: center;
            }
            
            .hero-visual {
                order: -1;
            }
            
            .footer-content {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .nav {
                padding: 1rem;
            }
            
            .nav-links {
                display: none;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .hero-stats {
                gap: 2rem;
                flex-direction: column;
            }
            
            .photo-frame, .photo-glow-ring, .photo-outer-glow {
                width: 280px;
                height: 280px;
            }
            
            .photo-frame {
                width: 260px;
                height: 260px;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .footer-brand {
                justify-content: center;
            }
            
            .footer-social {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Background Effects -->
    <div class="bg-grid"></div>
    <div class="bg-glow">
        <div class="glow-orb glow-orb-1"></div>
        <div class="glow-orb glow-orb-2"></div>
    </div>
    
    <!-- Navigation -->
    <nav class="nav">
        <a href="index.php" class="nav-logo">
            <i class="fas fa-shipping-fast"></i>
            Gestion_Colis
        </a>
        <ul class="nav-links">
            <li><a href="#features">Fonctionnalités</a></li>
            <li><a href="#about">À Propos</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
        <div class="nav-cta">
            <a href="login.php" class="btn-glow btn-secondary-glow">Connexion</a>
            <a href="register.php" class="btn-glow btn-primary-glow">Inscription</a>
        </div>
    </nav>
    
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-container">
            <div class="hero-content">
                <div class="hero-badge">
                    <i class="fas fa-bolt"></i>
                    Technologie de Pointe
                </div>
                <h1 class="hero-title">
                    <span class="hero-title-gradient">L'Avenir</span> de la Logistique
                </h1>
                <p class="hero-subtitle">
                    Revolutionnez la gestion de votre courrier et colis avec notre plateforme 
                    numérique innovante. Boîtes aux lettres virtuelles, suivi en temps réel 
                    et signature électronique.
                </p>
                <div class="hero-buttons">
                    <a href="register.php" class="btn-glow btn-primary-glow">
                        <i class="fas fa-user-plus"></i>
                        S'inscrire
                    </a>
                    <a href="login.php" class="btn-glow btn-secondary-glow">
                        <i class="fas fa-sign-in-alt"></i>
                        Se connecter
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hero-stat-number"><?php echo number_format($stats['utilisateurs']); ?>+</div>
                        <div class="hero-stat-label">Utilisateurs</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-number"><?php echo number_format($stats['colis']); ?>+</div>
                        <div class="hero-stat-label">Colis Livrés</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-number"><?php echo number_format($stats['ibox']); ?>+</div>
                        <div class="hero-stat-label">iBox Actives</div>
                    </div>
                </div>
            </div>
            
            <!-- PHOTO FLOTTANTE -->
            <div class="hero-visual">
                <div class="floating-photo-container">
                    <div class="photo-glow-ring"></div>
                    <div class="photo-outer-glow"></div>
                    <div class="photo-frame">
                        <img src="assets/images/JOSEPH.png" alt="Joseph" class="joseph-photo">
                    </div>
                    <div class="photo-corner-accent photo-corner-accent-1"></div>
                    <div class="photo-corner-accent photo-corner-accent-2"></div>
                    <div class="photo-corner-accent photo-corner-accent-3"></div>
                    <div class="photo-corner-accent photo-corner-accent-4"></div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="section-header">
            <div class="section-label">Fonctionnalités</div>
            <h2 class="section-title">Explorez le Futur</h2>
            <p class="section-desc">Des outils innovants conçus pour simplifier votre quotidien et optimiser vos livraisons</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h3>iBox Virtuelles</h3>
                <p>Créez et gérez vos boîtes aux lettres numériques. Recevez vos colis où que vous soyez, à tout moment, avec un accès sécurisé par code.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-truck"></i>
                </div>
                <h3>Suivi en Temps Réel</h3>
                <p>Suivez l'état de vos livraisons minute par minute. Soyez informé à chaque étape du parcours grâce à notre système de tracking avancé.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-id-card"></i>
                </div>
                <h3>Postal ID</h3>
                <p>Votre identifiant postal numérique sécurisé. Identification rapide, traçabilité complète et simplification des procédures de réception.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-edit"></i>
                </div>
                <h3>iSignature</h3>
                <p>Signatures électroniques qualifiées avec horodatage légal. Validez vos réceptions en toute sécurité et validez juridiquement vos documents.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3>Application Mobile</h3>
                <p>Interface dédiée pour les agents de livraison. Scan QR code et mise à jour instantanée du statut pour une efficacité maximale.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3>Sécurité Renforcée</h3>
                <p>Authentification multi-facteurs, chiffrement AES-256 et conformité GDPR pour protéger vos données personnelles.</p>
            </div>
        </div>
    </section>
    
    <!-- Footer -->
    <footer class="footer" id="contact">
        <div class="footer-content">
            <div class="footer-brand-section">
                <div class="footer-brand">
                    <i class="fas fa-shipping-fast"></i>
                    Gestion_Colis
                </div>
                <p class="footer-desc">
                    La solution innovante pour la gestion numérique du courrier et des colis. 
                    Simplifions la logistique ensemble.
                </p>
                <div class="footer-social">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="footer-section">
                <h4 class="footer-title">Liens Rapides</h4>
                <ul class="footer-links">
                    <li><a href="#">Accueil</a></li>
                    <li><a href="#features">Fonctionnalités</a></li>
                    <li><a href="#">Tarifs</a></li>
                    <li><a href="#">Support</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h4 class="footer-title">Légal</h4>
                <ul class="footer-links">
                    <li><a href="#">Mentions Légales</a></li>
                    <li><a href="#">Politique de Confidentialité</a></li>
                    <li><a href="#">CGV</a></li>
                    <li><a href="#">GDPR</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h4 class="footer-title">Contact</h4>
                <ul class="footer-links">
                    <li><a href="mailto:contact@gestioncolis.com"><i class="fas fa-envelope"></i> contact@gestioncolis.com</a></li>
                    <li><a href="tel:+237679624138"><i class="fas fa-phone"></i> +237 6 79 62 41 38</a></li>
                    <li><a href="#"><i class="fas fa-map-marker-alt"></i> Yaoundé, Cameroun</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2025 Gestion_Colis. Tous droits réservés. Développé par <a href="#">Joseph Pouda</a></p>
        </div>
    </footer>

    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        console.log('%c🚀 Gestion_Colis - Page d\'Accueil', 'color: #00B4D8; font-size: 18px; font-weight: bold;');
        console.log('%cThème professionnel blanc appliqué', 'color: #10B981; font-size: 12px;');
    </script>
</body>
</html>
