# Gestion_Colis v2.0 - Intégration Complète des Fonctionnalités

## 📋 Vue d'Ensemble

Ce document décrit les nouvelles fonctionnalités intégrées à Gestion_Colis v2.0 conformément au cahier des charges.

---

## 🆕 Nouvelles Tables de Base de Données

### Fichier : `migrations/add_advanced_features.php`

| Table | Description |
|-------|-------------|
| `agent_commissions` | Gestion des commissions des agents |
| `agent_performance` | Suivi des performances des agents |
| `legal_timestamps` | Horodatage légal des documents |
| `ibox_access_logs` | Journal des accès aux iBox |
| `pickup_codes` | Codes de retrait PIN/QR |
| `ibox_operators` | Support multi-opérateurs |
| `notifications_preferences` | Préférences de notification |
| `archives` | Archivage des données |

### Colonnes ajoutées à `utilisateurs` :
- `mfa_secret` - Secret pour l'authentification multi-facteurs
- `mfa_backup_codes` - Codes de secours (JSON)
- `mfa_verified_at` - Date de vérification MFA
- `public_service_id` - Identification services publics

---

## 🔐 Module MFA (Authentification Multi-Facteurs)

### Fichier : `utils/mfa_service.php`

**Fonctionnalités :**
- Génération de secrets TOTP compatibles Google Authenticator
- Codes QR pour configuration rapide
- Codes de secours (10 codes à usage unique)
- Vérification TOTP avec fenêtre de temps
- Activation/désactivation sécurisée

**Utilisation :**
```php
require_once 'utils/mfa_service.php';

$mfaService = new MFAService();

// Générer la configuration
$setup = $mfaService->generateSecret($userId);
// Affiche: secret, qr_code_url, backup_codes, setup_string

// Vérifier un code
$result = $mfaService->verifyCode($userId, $code);
// Retourne: 'totp', 'backup', ou false

// Activer MFA
$mfaService->enableMFA($userId, $code);
```

**Nouvelle page :** `security_mfa.php`

---

## 📱 Module Notifications (SMS/Email/Push)

### Fichier : `utils/notification_service.php`

**Fonctionnalités :**
- Envoi d'emails avec templates HTML
- Support SMS (Twilio, Nexmo)
- Notifications in-app
- Templates pour :
  - Colis prêt pour retrait
  - Livraison complétée
  - Codes MFA
  - Commissions agents

**Utilisation :**
```php
require_once 'utils/notification_service.php';

$notificationService = new NotificationService();

// Envoyer notification de colis prêt
$notificationService->notifyParcelReady($parcelId, $pickupCode);

// Envoyer SMS
$notificationService->sendSMS($phone, $message);

// Créer notification in-app
$notificationService->createInAppNotification(
    $userId, 'colis', 'Titre', 'Message', 'high'
);
```

---

## 💰 Module Commissions Agents

### Fichier : `utils/commission_service.php`

**Fonctionnalités :**
- Calcul automatique des commissions
- Composants de commission :
  - Frais de base : 2,50€/colis
  - Par KM : 0,45€/km
  - Bonus urgent : 1,50€
  - Bonus fragile : 0,75€
  - Bonus poids : 0,50€/kg au-delà de 5kg
- Suivi des performances
- Export CSV des commissions
- Statuts : en_attente → approuvé → payé

**Utilisation :**
```php
require_once 'utils/commission_service.php';

$commissionService = new CommissionService();

// Calculer et enregistrer une commission
$result = $commissionService->calculateCommission($deliveryId);

// Récapitulatif des commissions
$summary = $commissionService->getAgentCommissionSummary($agentId, 'month');

// Marquer comme payé
$commissionService->markAsPaid($commissionId, $transactionId);
```

**Nouvelle page :** `agent_dashboard.php`

**API d'export :** `api/export_commissions.php`

---

## ⏰ Module Horodatage Légal

### Fichier : `utils/timestamp_service.php`

**Fonctionnalités :**
- Génération de clés RSA pour l'horodatage
- Signature SHA-256 avec clé privée serveur
- Vérification d'intégrité des documents
- Certificats de vérification
- Durée de validité : 5 ans

**Utilisation :**
```php
require_once 'utils/timestamp_service.php';

$timestampService = new LegalTimestampService();

// Créer un horodatage
$result = $timestampService->createTimestamp($documentData, $documentId, 'signature');

// Vérifier un horodatage
$verification = $timestampService->verifyTimestamp($documentHash, $token);

// Générer un certificat de vérification
$certificate = $timestampService->generateVerificationCertificate($hash, $token);
```

---

## 🔑 Module Codes de Retrait (iBox)

### Fichier : `utils/pickup_code_service.php`

**Fonctionnalités :**
- Génération de codes PIN à 6 chiffres
- Codes QR pour retrait rapide
- Expiration configurable (72h par défaut)
- Limitation du nombre d'utilisations
- Journalisation des accès
- Notifications automatiques (Email/SMS)

**Utilisation :**
```php
require_once 'utils/pickup_code_service.php';

$pickupService = new PickupCodeService();

// Générer un code
$result = $pickupService->generateCode($parcelId, 'pin', true);

// Vérifier et utiliser un code
$result = $pickupService->verifyAndUseCode($parcelId, $code);

// Régénérer un code
$result = $pickupService->regenerateCode($parcelId, $userId);

// Obtenir l'URL du QR Code
$qrUrl = $pickupService->getQRCodeUrl($qrData);
```

---

## 📁 Structure des Fichiers

```
gestion_colis/
├── migrations/
│   └── add_advanced_features.php    # Migration BDD v2.0
├── utils/
│   ├── mfa_service.php              # Service MFA
│   ├── notification_service.php     # Service Notifications
│   ├── commission_service.php       # Service Commissions
│   ├── timestamp_service.php        # Service Horodatage
│   └── pickup_code_service.php      # Service Codes Retrait
├── api/
│   └── export_commissions.php       # Export CSV commissions
├── security_mfa.php                 # Page gestion MFA
└── agent_dashboard.php              # Dashboard agents
```

---

## 🚀 Installation

### 1. Exécuter la migration

```bash
php migrations/add_advanced_features.php
```

### 2. Générer les clés d'horodatage

```php
require_once 'utils/timestamp_service.php';
$service = new LegalTimestampService();
$service->generateKeys('Gestion_Colis TSA');
```

### 3. Configurer les API SMS (optionnel)

Dans `config/config.php` :

```php
return [
    'notifications' => [
        'twilio' => [
            'sid' => 'VOTRE_SID',
            'token' => 'VOTRE_TOKEN',
            'from' => '+33123456789'
        ],
        'nexmo' => [
            'key' => 'VOTRE_KEY',
            'secret' => 'VOTRE_SECRET'
        ]
    ],
    'commissions' => [
        'base_rate' => 2.50,
        'km_rate' => 0.45,
        'urgent_bonus' => 1.50
    ]
];
```

---

## 📊 Tableau Récapitulatif des Fonctionnalités

| Module | Status | Niveau d'Implémentation |
|--------|--------|------------------------|
| Postal ID | ✅ | Complet |
| MFA | ✅ | Complet |
| iBox | ✅ | Complet |
| Multi-opérateurs | ✅ | Complet |
| Notifications SMS/Email | ✅ | Complet |
| Agents - Commissions | ✅ | Complet |
| Agents - Performance | ✅ | Complet |
| iSignature - Horodatage | ✅ | Complet |
| iSignature - Archivage | ⚠️ | Partiel |
| Paiements Stripe | ⚠️ | Partiel |

---

## 📝 Notes de Développement

### Sécurité
- Les clés privées sont stockées dans `keys/private.key`
- Les codes de backup sont hashés avec `password_hash`
- Les codes PIN sont vérifiés avec `password_verify`

### Performance
- Les commissions sont calculées automatiquement lors de la livraison
- Les codes expirés sont nettoyés via CRON recommandé

### Conformité
- L'horodatage légal suit le format RFC 3161
- Les documents sont hashés en SHA-256

---

## 🔧 Maintenance

### Nettoyer les codes expirés (CRON daily)
```php
require_once 'utils/pickup_code_service.php';
$service = new PickupCodeService();
$count = $service->cleanupExpiredCodes();
```

### Nettoyer les horodatages expirés
```php
require_once 'utils/timestamp_service.php';
$service = new LegalTimestampService();
$count = $service->cleanupExpired();
```

---

## 📞 Support

Pour toute question sur l'intégration des fonctionnalités, consulter la documentation technique dans `docs/structure_technique.md`.
