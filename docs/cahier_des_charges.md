# Cahier des Charges - Gestion_Colis

## 1. Introduction et Contexte du Projet

### 1.1 Présentation Générale

Le projet **Gestion_Colis** consiste en le développement d'une application web complète dédiée à la gestion numérique du courrier et des colis. Cette solution innovantes vise à moderniser et optimiser les processus logistiques liés à la réception, au stockage et à la livraison de colis pour les particuliers et les professionnels. L'application intègre des fonctionnalités avancées telles que les boîtes aux lettres virtuelles (iBox), le suivi en temps réel des livraisons, les identifiants postaux numériques (Postal ID) et les signatures électroniques (iSignature).

L'objectif principal de cette application est de simplifier l'expérience des utilisateurs dans la gestion de leurs envois et réceptions de colis, tout en offrant aux administrateurs et aux agents de livraison des outils performants pour coordonner et suivre l'ensemble des opérations logistiques. La plateforme se positionne comme une solution tout-en-un qui centralise la gestion des colis, la communication entre les différentes parties prenantes et le suivi des livraisons.

Le contexte de développement s'inscrit dans une démarche de transformation numérique du secteur de la logistique au Cameroun, avec une volonté de proposer des solutions adaptées aux réalités locales tout en intégrant les meilleures pratiques internationales en matière de gestion de colis et de services postaux modernes.

### 1.2 Objectifs du Projet

L'application Gestion_Colis poursuit plusieurs objectifs stratégiques qui guident l'ensemble des décisions de conception et de développement. Le premier objectif est de dématérialiser les processus traditionnels de gestion du courrier et des colis, en替代ant les formulaires papier et les registres manuels par une interface numérique intuitive et sécurisée. Cette dématérialisation permet non seulement de réduire les erreurs humaines mais également de faciliter l'archivage et la recherche d'informations.

Le deuxième objectif majeur concerne l'amélioration de l'expérience utilisateur pour l'ensemble des acteurs du processus de livraison. Les utilisateurs finaux (expéditeurs et destinataires) doivent pouvoir créer, suivre et gérer leurs colis de manière autonome, sans avoir besoin de contacter le service client pour chaque opération. Les agents de livraison disposent d'outils optimisés pour gérer efficacement leurs tournées et mettre à jour le statut des livraisons en temps réel. Les administrateurs bénéficient d'une vue d'ensemble sur les opérations et peuvent prendre des décisions éclairées grâce aux statistiques et rapports générés automatiquement.

Le troisième objectif est d'assurer la traçabilité complète de chaque colis, depuis sa création jusqu'à sa livraison finale. Chaque opération sur un colis est horodatée et associée à l'utilisateur ou à l'agent qui l'a effectuée. Cette traçabilité garantit la transparence des opérations et permet de résoudre rapidement les éventuels litiges concernant la livraison des colis.

### 1.3 Périmètre du Projet

Le périmètre initial du projet inclut le développement d'une application web monopage (SPA) avec les fonctionnalités essentielles à la gestion de colis. L'application gère les utilisateurs avec différents rôles (utilisateur standard, agent de livraison, administrateur), les colis avec leur cycle de vie complet, les boîtes aux lettres virtuelles (iBox), les identifiants postaux numériques (Postal ID) et lesLivraisons avec attribution aux agents.

Les fonctionnalités futures envisagées mais non incluses dans ce premier périmètre comprennent l'application mobile native, l'intégration avec des transporteurs tiers, le système de paiement en ligne pour les frais de livraison, et l'API publique pour les intégrations tierces.

## 2. Analyse des Besoins

### 2.1 Besoins Fonctionnels

Les besoins fonctionnels de l'application ont été identifiés à travers une analyse approfondie des utilisateurs cibles et de leurs parcours typiques. Cette analyse a permis de définir les fonctionnalités essentielles que l'application doit offrir pour répondre aux attentes des utilisateurs.

**Gestion des Utilisateurs :** L'application doit permettre aux utilisateurs de créer un compte, de se connecter et de gérer leur profil. Le système d'authentification utilise des mots de passe hachés avec l'algorithme bcrypt et stocke les informations de session de manière sécurisée. Les utilisateurs peuvent modifier leurs informations personnelles (nom, prénom, email, téléphone, adresse) et choisir leur niveau de sécurité pour l'authentification.

**Rôles et Permissions :** Le système de rôles permet de différencier les accès et les fonctionnalités disponibles selon le profil de l'utilisateur. Les utilisateurs standard ont accès aux fonctionnalités de création et de suivi de leurs propres colis. Les agents de livraison peuvent consulter les colis qui leur sont attribués, mettre à jour le statut des livraisons et consulter leurs statistiques personnelles. Les administrateurs ont un accès complet à toutes les données et peuvent gérer les utilisateurs, les agents et les paramètres du système.

**Gestion des Colis :** La création de colis est une fonctionnalité centrale qui permet aux utilisateurs d'enregistrer un nouveau colis dans le système. Le formulaire de création collecte les informations essentielles : référence du colis, description du contenu, poids, dimensions, valeur déclarée, options especiales (fragile, urgent) et instructions de livraison. Chaque colis reçoit automatiquement un code de tracking unique qui permet de le suivre tout au long de son parcours.

La modification des colis est autorisée uniquement pour les colis en statut « en attente », avant qu'ils ne soient pris en charge par un agent de livraison. Cette restriction garantit l'intégrité des données et évite les modifications après le début du processus de livraison.

Le suivi des colis (tracking) permet à tout utilisateur de consulter l'état d'avancement d'un colis à partir de son code de tracking ou de sa référence. L'interface de suivi affiche le statut actuel du colis, les différentes étapes de son parcours avec les dates et heures correspondantes, ainsi que les informations sur l'agent en charge de la livraison.

**Gestion des iBox :** Les boîtes aux lettres virtuelles (iBox) permettent aux utilisateurs de disposer d'une adresse de réception sécurisée pour leurs colis. Chaque iBox possède un code d'accès unique, une localisation, un type (taille) et des options de température (ambiant, réfrigéré, congelé). Les utilisateurs peuvent créer plusieurs iBox et les partager avec des tiers pour la réception de colis en leur nom.

**Gestion des Postal ID :** L'identifiant postal numérique (Postal ID) est un élément d'identification unique associé à chaque utilisateur. Il facilite les procédures de réception de colis en fournissant un identifiant standardisé. Le Postal ID inclut un code QR pour une lecture rapide et peut être renouvelé à expiration.

**Interface Agent :** Les agents de livraison disposent d'une interface dédiée qui leur permet de visualiser leurs livraisons assignées, de prendre en charge des colis, de confirmer les livraisons avec le code secret du destinataire, et de signaler des incidents. L'interface inclut un planning des livraisons et des statistiques de performance.

**Interface Administrateur :** Le panneau d'administration offre une vue d'ensemble sur l'ensemble des opérations du système. Les administrateurs peuvent gérer les utilisateurs (création, modification, suppression), gérer les agents (attribution des zones de livraison, véhicules, certifications), consulter les statistiques globales (nombre de colis par période, par statut, par agent), et configurer les paramètres du système.

### 2.2 Besoins Non Fonctionnels

Les besoins non fonctionnels définissent les caractéristiques de qualité que l'application doit respecter pour offrir une expérience utilisateur optimale et une infrastructure technique robuste.

**Performance :** L'application doit être réactive et offrir des temps de réponse acceptables pour toutes les opérations courantes. Le temps de chargement initial ne doit pas excéder 3 secondes sur une connexion internet standard. Les requêtes AJAX pour le rechargement partiel de contenu doivent s'exécuter en moins d'une seconde. L'application doit pouvoir supporter une utilisation concurrente de plusieurs dizaines d'utilisateurs sans dégradation perceptible des performances.

**Sécurité :** La sécurité est une préoccupation majeure compte tenu de la nature sensible des données manipulées (informations personnelles, adresses, données de livraison). Toutes les communications entre le client et le serveur utilisent le protocole HTTPS. Les mots de passe sont stockés après hachage bcrypt avec un coût de 10. Les injections SQL sont prévenues par l'utilisation systématique de requêtes préparées avec PDO. Les attaques XSS sont mitigées par l'échappement systématique des sorties avec htmlspecialchars. Les sessions sont gérées de manière sécurisée avec des identifiants régénérés à la connexion.

**Fiabilité :** L'application doit être disponible et fonctionner correctement dans des conditions d'utilisation normales. Les erreurs système sont gérées avec des messages conviviaux pour l'utilisateur et des logs détaillés pour le débogage. Le système de base de données maintient l'intégrité des données même en cas d'interruption brutale des opérations.

**Maintenabilité :** Le code source est organisé selon une structure claire et documentée pour faciliter les évolutions futures. Les conventions de nommage sont respectées de manière cohérente. Les fonctionnalités sont modularisées pour permettre des modifications ciblées sans impact sur le reste du système.

**Accessibilité :** L'interface est conçue pour être utilisable par le plus grand nombre d'utilisateurs, y compris ceux ayant des limitations visuelles ou motrices. Les contrastes de couleurs respectent les normes WCAG 2.1 niveau AA. La navigation au clavier est pleinement fonctionnelle. Le code HTML est structuré de manière sémantique pour les technologies d'assistance.

### 2.3 Contraintes Techniques

Les contraintes techniques définissent les limites et les exigences techniques que le développement doit respecter.

**Environnement d'Hébergement :** L'application est conçue pour être hébergée sur un serveur web classique avec support PHP 8.0+ et MySQL/MariaDB. Elle ne nécessite pas de framework PHP spécifique et peut fonctionner sur un hébergement mutualisé standard. La configuration requise inclut PHP avec les extensions PDO et GD, MySQL 5.7+ ou MariaDB 10.2+, et un serveur web Apache ou Nginx.

**Compatibilité Navigateurs :** L'application est compatible avec les versions récentes des principaux navigateurs web : Chrome (dernières 2 versions), Firefox (dernières 2 versions), Safari (dernières 2 versions), et Edge (dernières 2 versions). Les fonctionnalités modernes de JavaScript (fetch, async/await, CSS custom properties) sont utilisées sans polyfill pour les navigateurs obsolètes.

**Base de Données :** La base de données utilise l'encodage utf8mb4 pour supporter les caractères spéciaux et les emojis. Les tables utilisent le moteur InnoDB pour garantir les propriétés ACID. Les clés étrangères assurent l'intégrité référentielle des données.

## 3. Spécifications Fonctionnelles Détaillées

### 3.1 Module Authentification

Le module d'authentification gère l'accès des utilisateurs à l'application et assure la sécurité des sessions.

**Inscription :** Le processus d'inscription collecte les informations personnelles de l'utilisateur (nom, prénom, email, téléphone, mot de passe, adresse). Les validations effectuées incluent la vérification de l'unicité de l'email, la force minimale du mot de passe (6 caractères), et la confirmation du mot de passe. L'utilisateur reçoit un compte avec le rôle « utilisateur » par défaut. Le mot de passe est haché avec bcrypt avant stockage.

**Connexion :** L'authentification vérifie les identifiants saisis contre la base de données. En cas de succès, une session est créée avec les informations de l'utilisateur (identifiant, rôle, nom, prénom). La session est sécurisée contre les détournements. En cas d'échec, un message d'erreur générique est affiché pour des raisons de sécurité.

**Déconnexion :** La destruction de la session met fin à l'accès de l'utilisateur. Toutes les données de session sont effacées et l'utilisateur est redirigé vers la page d'accueil.

**Gestion de Session :** Les sessions ont une durée de vie limitée et sont régénérées à chaque connexion. Les pages protégées vérifient la présence d'une session valide et redirigent vers la page de connexion si nécessaire.

### 3.2 Module Gestion des Colis

Le module de gestion des colis est le cœur de l'application et gère l'ensemble du cycle de vie des colis.

**Création de Colis :** La création d'un nouveau colis requiert les informations obligatoires suivantes : référence unique (générée automatiquement ou saisie par l'utilisateur), description détaillée du contenu, poids en kilogrammes. Les informations optionnelles incluent les dimensions, la valeur déclarée en euros, les options, urgent), spéciales (fragile et les instructions de livraison.

Lors de la création, un code de tracking unique est généré automatiquement au format « TRK + date + numéro aléatoire ». Le colis est créé avec le statut initial « en_attente » et associé à l'utilisateur créateur.

**Modification de Colis :** La modification est autorisée uniquement pour les colis appartenant à l'utilisateur et ayant le statut « en_attente ». Les champs modifiables sont les mêmes que lors de la création (sauf la référence). Toute modification est horodatée et peut être tracée dans l'historique du colis.

**Suppression de Colis :** La suppression physique n'est pas autorisée pour maintenir la traçabilité. Les colis annulés sont marqués avec le statut « annule » et conservés dans la base de données à des fins d'historique et de statistiques.

**Consultation des Colis :** Les utilisateurs peuvent consulter la liste de leurs colis avec possibilité de filtrage par statut, tri par date, et recherche par référence ou code de tracking. Chaque colis affiche un résumé des informations principales et un lien vers le détail complet.

**Suivi de Colis (Tracking) :** Le suivi est accessible à tout utilisateur disposant du code de tracking ou de la référence du colis. L'interface de suivi affiche le statut actuel, l'historique complet des changements de statut avec dates et heures, les informations sur l'agent en charge (si attribué), et les éventuelles notes ou instructions.

### 3.3 Module Gestion des Livraisons

Le module de gestion des livraisons concerne spécifiquement les agents de livraison et le processus de livraison physique des colis.

**Attribution des Livraisons :** Seuls les administrateurs peuvent attribuer un colis à un agent de livraison. L'attribution crée une entrée dans la table des livraisons associant le colis à l'agent. Le statut du colis passe automatiquement à « en_livraison » lors de l'attribution.

**Prise en Charge :** L'agent peut marquer un colis comme « pris en charge » lorsqu'il commence effectivement la livraison. Cette action met à jour le statut de la livraison à « en_cours » et enregistre la date et l'heure de début.

**Confirmation de Livraison :** La livraison est confirmée uniquement après vérification du code secret fourni par le destinataire. Cette sécurité garantit que le colis est remis à la bonne personne. La confirmation enregistre la date et l'heure de fin de livraison, met à jour le statut du colis à « livré » et de la livraison à « terminée ».

**Signalement d'Incident :** En cas de problème lors de la livraison (destinataire absent, adresse incorrecte, colis endommagé), l'agent peut signaler un incident. L'incident est enregistré avec les notes de l'agent et les statuts sont mis à jour en conséquence (colis retourné ou annulé).

### 3.4 Module Gestion des iBox

Le module iBox permet la gestion des boîtes aux lettres virtuelles pour la réception sécurisée de colis.

**Création d'iBox :** Les utilisateurs peuvent créer des boîtes virtuelles en précisant la localisation (adresse ou description), le type de boîte (petite, moyenne, grande, très grande), les options de température (ambiant, réfrigéré, congelé). Un code d'accès unique est généré automatiquement pour chaque boîte.

**Gestion des iBox :** Les utilisateurs peuvent consulter la liste de leurs iBox avec les informations de disponibilité, générer le code QR d'accès, et modifier les paramètres de leurs boîtes. Les iBox peuvent être désactivées temporairement ou définitivement.

**Utilisation des iBox :** Les colis peuvent être attribués à une iBox spécifique lors de la création. Le code d'accès de l'iBox permet au livreur de déposer le colis. Le système enregistre automatiquement le dépôt et notifie le destinataire.

### 3.5 Module Gestion des Postal ID

Le module Postal ID gère les identifiants postaux numériques des utilisateurs.

**Création de Postal ID :** Chaque utilisateur peut générer un Postal ID unique. L'identifiant est généré au format « PID + code alphanumérique » et associé à un code QR pour une lecture rapide. Le Postal ID a une validité d'un an et peut être renouvelé.

**Utilisation du Postal ID :** Le Postal ID facilite l'identification lors de la réception de colis. Il peut être présenté sous forme de code QR ou d'identifiant texte. Le niveau de sécurité (basic, verified, premium) détermine les droits et les fonctionnalités associées.

### 3.6 Module Administration

Le module d'administration offre les fonctionnalités de gestion du système aux administrateurs.

**Gestion des Utilisateurs :** Les administrateurs peuvent visualiser la liste complète des utilisateurs avec leurs informations, créer de nouveaux comptes utilisateurs, modifier les informations et les rôles des utilisateurs existants, et désactiver ou supprimer des comptes.

**Gestion des Agents :** L'ajout d'un agent consiste à créer un compte utilisateur puis à l'associer à un profil agent avec les informations spécifiques (numéro d'agent, zone de livraison, type de véhicule, taux de commission). Les administrateurs peuvent modifier ces informations et suivre les statistiques de chaque agent (nombre de livraisons, note moyenne, état actif/inactif).

**Tableau de Bord Administrateur :** Le tableau de bord affiche les statistiques globales du système : nombre total d'utilisateurs, de colis, d'iBox et d'agents. Des graphiques illustrent l'évolution mensuelle des créations de colis et la répartition par statut. Une liste des activités récentes permet de suivre les opérations du système.

**Journal d'Audit :** Toutes les actions sensibles sont journalisées avec les informations suivantes : utilisateur auteur de l'action, table affectée, anciennes et nouvelles valeurs, adresse IP, horodatage. Ce journal permet de tracer l'historique des modifications et de détecter les comportements suspects.

## 4. Modèle de Données

### 4.1 Schéma de la Base de Données

La base de données est structurée autour de plusieurs tables interconnectées qui stockent l'ensemble des informations du système.

**Table utilisateurs :** Cette table centrale stocke les informations de base de chaque utilisateur du système. Elle contient les colonnes suivantes : identifiant unique (id), nom et prénom, adresse email unique, mot de passe haché, numéro de téléphone, adresse complète, rôle (utilisateur, agent, admin), indicateurs de vérification email et MFA, dates de création et de modification.

**Table agents :** Cette table stocke les informations spécifiques aux agents de livraison. Elle contient : identifiant unique, lien vers l'utilisateur associé, numéro d'agent unique, zone de livraison, type de véhicule, taux de commission, compteur de livraisons, note moyenne, statut actif/inactif, date de certification, localisation GPS optionnelle.

**Table colis :** Cette table centrale stocke les informations sur chaque colis. Elle contient : identifiant unique, lien vers l'utilisateur propriétaire, lien optionnel vers une iBox, référence unique, description détaillée, poids, dimensions, valeur déclarée, indicateurs fragile et urgent, statut (en_attente, en_livraison, livre, retourne, annule), code de tracking unique, dates de création et de livraison estimée, instructions de livraison.

**Table livraisons :** Cette table gère le lien entre les colis et les agents de livraison. Elle contient : identifiant unique, lien vers le colis, lien vers l'agent, dates d'assignation, de début et de fin de livraison, statut (assignee, en_cours, terminee, annulee), distance en kilomètres, durée en minutes, lien vers la signature, notes de l'agent, photo de preuve, évaluation.

**Table ibox :** Cette table gère les boîtes aux lettres virtuelles. Elle contient : identifiant unique, lien vers le propriétaire, code de boîte unique, localisation, type de boîte, capacité maximale, statut (disponible, occupee, hors_service), température, code d'accès, données QR code, dates de création et dernière utilisation.

**Table postal_id :** Cette table gère les identifiants postaux. Elle contient : identifiant unique, lien vers l'utilisateur, identifiant postal unique, niveau de sécurité, date d'expiration, données QR code, statut actif/inactif, dates de création et vérification.

**Table signatures :** Cette table stocke les signatures électroniques. Elle contient : identifiant unique, lien vers l'utilisateur, type de signature, hash du document, données de signature, certificat optionnel, horodatage, adresse IP, user agent, date de validité, indicateur d'archivage.

**Table paiements :** Cette table gère les paiements liés aux colis. Elle contient : identifiant unique, lien vers l'utilisateur et le colis, montant, devise, mode de paiement, identifiant de transaction, statut, dates de paiement et de création, détails JSON.

**Table notifications :** Cette table stocke les notifications des utilisateurs. Elle contient : identifiant unique, lien vers l'utilisateur, type de notification, titre, message, indicateur de lecture, priorité, dates d'envoi et de lecture.

**Table audit_log :** Cette table journalise toutes les actions sensibles. Elle contient : identifiant unique, lien optionnel vers l'utilisateur, action effectuée, table affectée, identifiant de l'entité, anciennes valeurs (JSON), nouvelles valeurs (JSON), adresse IP, user agent, date de l'action.

### 4.2 Relations et Contraintes

Les relations entre les tables garantissent l'intégrité des données et définissent le comportement en cas de suppression ou de modification.

La table agents est liée à utilisateurs par une clé étrangère avec suppression en cascade : si un utilisateur est supprimé, son profil agent est également supprimé.

La table colis est liée à utilisateurs (créateur) et optionnellement à ibox. La suppression d'un utilisateur n'affecte pas ses colis pour maintenir la traçabilité, mais la suppression d'une iBox met la référence à NULL.

La table livraisons est liée à colis et agents avec suppression en cascade : si un colis ou un agent est supprimé, les livraisons associées sont supprimées.

Les autres tables suivent des patterns similaires de relations et de contraintes pour assurer la cohérence des données.

## 5. Architecture Technique

### 5.1 Architecture Globale

L'application suit une architecture classique de type « Three-Tier » avec une séparation claire entre la présentation (HTML/CSS/JS), la logique métier (PHP) et les données (MySQL).

Le client est de pages HTML génér constituéées par le serveur, enrich JavaScript pour les interactions dynamiques (requêtes AJAX, navigation SPA). Leies de style visuel est défini par des fichiers CSS utilisant des variables personnalisées pour la maintenabilité.

Le serveur web (Apache/Nginx) exécute les scripts PHP qui constituent le moteur de l'application. Les requêtes sont routing vers les contrôleurs appropriés qui orchestrent les opérations et transmettent les données aux vues.

Le serveur de base de données (MySQL/MariaDB) stocke l'ensemble des données persistantes et traite les requêtes SQL générées par l'application.

### 5.2 Structure des Fichiers

L'organisation des fichiers suit une structure logique qui sépare les différents types de ressources.

```
gestion_colis/
├── assets/
│   ├── css/
│   │   ├── style.css      # Styles principaux
│   │   └── variables.css  # Variables CSS du thème
│   ├── images/
│   │   └── *.png          # Images et logos
│   └── js/
│       ├── main.js        # Fonctions JavaScript principales
│       ├── router.js      # Système de routage SPA
│       └── charts-config.js # Configuration des graphiques
├── config/
│   └── database.php       # Classe de connexion PDO
├── api/
│   └── api.php            # Point d'entrée API REST
├── views/
│   ├── admin/
│   │   ├── gestion_agents.php
│   │   └── gestion_utilisateurs.php
│   ├── agent/
│   │   └── mes_livraisons.php
│   ├── client/
│   │   ├── mes_colis.php
│   │   ├── modifier_colis.php
│   │   └── mon_compte.php
│   └── tracking.php       # Page de suivi publique
├── utils/
│   ├── csv_importer.php   # Import de colis par CSV
│   └── pdf_generator.php  # Génération de documents PDF
├── database/
│   └── schema.sql         # Schéma de la base de données
├── *.php                  # Pages principales (index, login, register, etc.)
└── docs/
    ├── theme_projet.md    # Documentation du thème
    ├── cahier_des_charges.md # Ce document
    └── structure_technique.md # Documentation technique
```

### 5.3 Technologies Utilisées

**Frontend :** HTML5 pour la structure sémantique des pages, CSS3 avec variables personnalisées pour le style, JavaScript (ES6+) pour les interactions dynamiques, Chart.js pour les graphiques statistiques, Font Awesome pour les icônes, et Google Fonts (Inter, Poppins, Orbitron, Rajdhani) pour la typographie.

**Backend :** PHP 8.0+ comme langage serveur principal, PDO pour l'accès à la base de données avec requêtes préparées, et gestion native des sessions PHP.

**Base de Données :** MySQL ou MariaDB avec encodage utf8mb4, tables InnoDB pour les contraintes de clé étrangère.

**Outils de Développement :** phpMyAdmin pour l'administration de la base de données (optionnel), Git pour le versionnement du code, et éditeur de code moderne avec support PHP et HTML.

## 6. Interfaces Utilisateur

### 6.1 Page d'Accueil

La page d'accueil (index.php) présente l'application aux visiteurs non authentifiés. Elle adopte un design immersif avec des effets visuels spectaculaires : grille de fond animée, orbes lumineux flottants, et animations de elements. Le contenu inclut une présentation des fonctionnalités principales, des statistiques du système, et des appels à l'action pour l'inscription et la connexion.

### 6.2 Pages d'Authentification

Les pages de connexion (login.php) et d'inscription (register.php) utilisent un formulaire centré dans un conteneur aux effets de verre (backdrop-blur). Le style visuel est cohérent avec la page d'accueil tout en étant plus sobre pour se concentrer sur les actions d'authentification.

### 6.3 Tableau de Bord

Le tableau de bord (dashboard.php) est la page principale pour les utilisateurs authentifiés. Il utilise une interface monopage (SPA) où le contenu se charge dynamiquement sans rechargement complet. Le layout comprend une sidebar de navigation (réductible), un header avec recherche et profil utilisateur, et une zone de contenu principal.

Le contenu du tableau de bord varie selon le rôle de l'utilisateur. Les utilisateurs standard voient leurs statistiques personnelles (nombre de colis, d'iBox, de Postal ID), les actions rapides, et l'activité récente. Les agents voient leurs livraisons assignées et leurs statistiques de performance. Les administrateurs ont accès aux statistiques globales, à la gestion des utilisateurs et agents, et aux rapports.

### 6.4 Pages de Gestion

Les pages de gestion (mes_colis.php, mes_ibox.php, etc.) présentent des tableaux ou des cartes avec les informations détaillées. Les actions disponibles sont accessibles via des boutons et des icônes clairement identifiées. Les modales sont utilisées pour les formulaires secondaires et les confirmations.

### 6.5 Page de Suivi

La page de suivi (tracking.php) est accessible publiquement sans authentification. Elle affiche les détails complets d'un colis à partir de son code de tracking, incluant le statut actuel, l'historique des événements, et les informations de contact.

## 7. Tests et Validation

### 7.1 Stratégie de Tests

Les tests de l'application visent à garantir le bon fonctionnement des fonctionnalités et la qualité du code.

**Tests Manuels :** Chaque fonctionnalité est testée manuellement selon des scénarios définis. Les scénarios couvrent les cas nominaux (fonctionnement normal) et les cas d'erreur (comportement en situation anormale).

**Validation des Formulaires :** Les validations côté client (JavaScript) et côté serveur (PHP) sont vérifiées pour chaque formulaire. Les messages d'erreur sont testés pour s'assurer qu'ils sont clairs et appropriés.

**Tests de Sécurité :** Les failles de sécurité courantes sont vérifiées : injections SQL, XSS, CSRF, gestion des sessions, et contrôle des accès.

### 7.2 Critères d'Acceptation

L'application est considérée comme fonctionnelle lorsque les critères suivants sont satisfaits :

- Tous les formulaires soumis avec des données valides sont traités correctement
- Les validations rejettent les données invalides avec des messages appropriés
- Les utilisateurs ne peuvent pas accéder aux fonctionnalités sans les droits requis
- Les pages se chargent correctement sur les navigateurs supportés
- Les temps de réponse sont acceptables pour les opérations courantes
- Les messages d'erreur système sont conviviaux et ne révèlent pas d'informations sensibles

## 8. Planning et Livrables

### 8.1 Phases de Développement

Le développement de l'application s'est déroulé en plusieurs phases distinctes.

**Phase 1 : Analyse et Conception** – Rédaction du cahier des charges, conception du modèle de données, et création de la structure du projet.

**Phase 2 : Développement du Backend** – Implémentation de la couche d'accès aux données, création des contrôleurs API, et développement des pages principales.

**Phase 3 : Développement du Frontend** – Intégration du design (thème futuriste), développement des interactions JavaScript, et optimisation responsive.

**Phase 4 : Tests et Corrections** – Vérification des fonctionnalités, correction des bugs identifiés, et optimisation des performances.

**Phase 5 : Documentation** – Rédaction de la documentation technique et utilisateur.

### 8.2 Livrables du Projet

Les livrables finaux du projet comprennent :

- Le code source complet de l'application
- Le schéma de la base de données
- La documentation technique (theme_projet.md, cahier_des_charges.md, structure_technique.md)
- Les instructions d'installation et de déploiement

## 9. Annexes

### 9.1 Glossaire

| Terme | Définition |
|-------|------------|
| iBox | Boîte aux lettres virtuelle pour la réception de colis |
| Postal ID | Identifiant postal numérique associé à un utilisateur |
| Tracking | Suivi en temps réel de l'état d'un colis |
| Code de tracking | Identifiant unique permettant de suivre un colis |
| Agent | Utilisateur chargé de la livraison des colis |
| Admin | Administrateur ayant un accès complet au système |

### 9.2 Références des Statuts

| Statut Colis | Signification |
|--------------|---------------|
| en_attente | Colis créé mais pas encore pris en charge |
| en_livraison | Colis attribué à un agent en cours de livraison |
| livre | Colis livré avec succès au destinataire |
| retourne | Colis non livré et retourné à l'expéditeur |
| annule | Colis annulé avant la livraison |

| Statut Livraison | Signification |
|------------------|---------------|
| assignee | Livraison attribuée à un agent |
| en_cours | Agent en possession du colis |
| terminee | Livraison effectuée avec succès |
| annulee | Livraison annulée ou échouée |
