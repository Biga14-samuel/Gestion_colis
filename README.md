# Gestion_Colis

Plateforme de gestion et de suivi de colis (clients, agents, admin).

Prérequis

1. PHP 8.0+
2. MySQL/MariaDB
3. Extensions PHP: pdo, openssl, curl, json

Installation

1. Configurez les variables d’environnement (voir .env.example).
2. Importez la base initiale via database/gestion_colis.sql ou exécutez php migrations/migrate.php.
3. Vérifiez les permissions d’écriture sur uploads, logs et keys.
4. Données de test (optionnel): importer database/seed_data.sql.

Configuration

1. La connexion DB est lue depuis DB_HOST, DB_NAME, DB_USER, DB_PASS.
2. Les options commissions, notifications et codes de retrait sont centralisées dans config/config.php.
3. Les paramètres Mobile Money sont dans config/mobile_money.php et se lisent via variables d’environnement.

Paiements

1. Mobile Money (Orange / MTN) utilise les variables OM_* et MOMO_*. Désactivez MOBILE_MONEY_SIMULATE en production.
2. Stripe est optionnel et nécessite STRIPE_ENABLED=1, STRIPE_SECRET_KEY et STRIPE_WEBHOOK_SECRET.
3. Si Stripe est activé, exécutez la migration migrations/add_stripe_payment_provider.php.

Cron

1. Nettoyage des codes expirés: php cron/cleanup_codes.php

Sécurité

1. Les répertoires sensibles sont protégés par .htaccess à la racine.
2. Les uploads sont verrouillés via .htaccess dans uploads/.
