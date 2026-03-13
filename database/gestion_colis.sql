-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : mar. 10 mars 2026 à 11:48
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `gestion_colis`
--

-- --------------------------------------------------------

--
-- Structure de la table `agents`
--

CREATE TABLE `agents` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `numero_agent` varchar(50) NOT NULL,
  `zone_livraison` varchar(100) NOT NULL,
  `vehicule_type` enum('moto','voiture','camion','velo') DEFAULT 'voiture',
  `commission_rate` decimal(5,2) DEFAULT 0.00,
  `total_livraisons` int(11) DEFAULT 0,
  `total_earnings` decimal(10,2) DEFAULT 0.00,
  `note_moyenne` decimal(3,2) DEFAULT 0.00,
  `actif` tinyint(1) DEFAULT 1,
  `date_certification` date NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` datetime DEFAULT NULL,
  `localisation_gps` point DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Structure de la table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_affectee` varchar(50) DEFAULT NULL,
  `entite_id` int(11) DEFAULT NULL,
  `anciennes_valeurs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`anciennes_valeurs`)),
  `nouvelles_valeurs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`nouvelles_valeurs`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `date_action` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Journal audit et tracabilite';

--
--


-- --------------------------------------------------------

--
-- Structure de la table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(255) NOT NULL DEFAULT '',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `colis`
--

CREATE TABLE `colis` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `expediteur_id` int(11) DEFAULT NULL,
  `destinataire_id` int(11) DEFAULT NULL,
  `ibox_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `reference_colis` varchar(100) NOT NULL,
  `numero_suivi` varchar(100) DEFAULT NULL,
  `nom_destinataire` varchar(200) DEFAULT NULL,
  `adresse_livraison` text DEFAULT NULL,
  `telephone_destinataire` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `poids` decimal(6,2) DEFAULT NULL,
  `dimensions` varchar(50) DEFAULT NULL,
  `valeur_declaree` decimal(10,2) DEFAULT 0.00,
  `fragile` tinyint(1) DEFAULT 0,
  `urgent` tinyint(1) DEFAULT 0,
  `statut` enum('en_attente','en_livraison','livre','retourne','annule','preparation') DEFAULT 'en_attente',
  `code_tracking` varchar(50) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_mise_a_jour` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_livraison_estimee` datetime DEFAULT NULL,
  `date_livraison` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `signature_data` text DEFAULT NULL,
  `signature_level` enum('basic','advanced','qualified') DEFAULT 'basic',
  `signature_timestamp` datetime DEFAULT NULL,
  `signature_image` varchar(255) DEFAULT NULL,
  `proof_photo_path` varchar(255) DEFAULT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','cancelled','refunded') DEFAULT 'pending',
  `payment_amount` decimal(10,2) DEFAULT 0.00,
  `payment_currency` varchar(3) DEFAULT 'XAF',
  `payment_provider` enum('orange','mtn') DEFAULT NULL,
  `payment_reference` varchar(64) DEFAULT NULL,
  `payment_phone` varchar(20) DEFAULT NULL,
  `payment_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_metadata`)),
  `payment_last_error` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `signature_base64` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Structure de la table `commissions`
--

CREATE TABLE `commissions` (
  `id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `livraison_id` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `taux_commission` decimal(5,2) NOT NULL,
  `statut` enum('en_attente','paye','annule') DEFAULT 'en_attente',
  `date_paiement` datetime DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `documents_signes`
--

CREATE TABLE `documents_signes` (
  `id` int(11) NOT NULL,
  `signature_id` int(11) NOT NULL,
  `fichier_hash` varchar(255) NOT NULL,
  `nom_fichier` varchar(255) NOT NULL,
  `format` enum('pdf','docx','png','jpg') DEFAULT 'pdf',
  `taille` bigint(20) NOT NULL,
  `emplacement_s3` varchar(500) DEFAULT NULL,
  `date_archivage` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `historique_colis`
--

CREATE TABLE `historique_colis` (
  `id` int(11) NOT NULL,
  `colis_id` int(11) NOT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `ancien_statut` varchar(50) DEFAULT NULL,
  `nouveau_statut` varchar(50) NOT NULL,
  `commentaire` text DEFAULT NULL,
  `date_action` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `ibox`
--

CREATE TABLE `ibox` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `code_box` varchar(50) NOT NULL,
  `localisation` varchar(255) NOT NULL,
  `type_box` enum('small','medium','large','xlarge') DEFAULT 'medium',
  `capacite_max` int(11) DEFAULT 10,
  `statut` enum('disponible','occupee','hors_service','recu') DEFAULT 'disponible',
  `temperature` enum('ambiant','frigo','congel') DEFAULT 'ambiant',
  `code_acces` varchar(100) NOT NULL,
  `qr_code` text NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `derniere_utilisation` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Structure de la table `ibox_access_logs`
--

CREATE TABLE `ibox_access_logs` (
  `id` int(11) NOT NULL,
  `ibox_id` int(11) NOT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `code_utilise` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `date_action` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `ibox_shares`
--

CREATE TABLE `ibox_shares` (
  `id` int(11) NOT NULL,
  `ibox_id` int(11) NOT NULL,
  `shared_with_user_id` int(11) DEFAULT NULL,
  `shared_with_email` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `livraisons`
--

CREATE TABLE `livraisons` (
  `id` int(11) NOT NULL,
  `colis_id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `date_assignation` datetime DEFAULT current_timestamp(),
  `date_debut` datetime DEFAULT NULL,
  `date_fin` datetime DEFAULT NULL,
  `date_livraison` datetime DEFAULT NULL,
  `statut` enum('assignee','en_cours','livree','echec','annulee') DEFAULT 'assignee',
  `distance_km` decimal(6,2) DEFAULT NULL,
  `duree_minutes` int(11) DEFAULT NULL,
  `signature_id` int(11) DEFAULT NULL,
  `notes_agent` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `photo_preuve` varchar(255) DEFAULT NULL,
  `evaluation` enum('1','2','3','4','5') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `type` enum('colis','livraison','paiement','system','security') NOT NULL,
  `titre` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `lue` tinyint(1) DEFAULT 0,
  `priorite` enum('low','normal','high','urgent') DEFAULT 'normal',
  `date_envoi` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_lecture` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `paiements`
--

CREATE TABLE `paiements` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `colis_id` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `devise` enum('EUR','USD','GBP','XAF') DEFAULT 'XAF',
  `mode_paiement` enum('carte','paypal','mobile','especes') NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `statut` enum('en_attente','paye','echec','rembourse') DEFAULT 'en_attente',
  `date_paiement` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `pickup_codes`
--

CREATE TABLE `pickup_codes` (
  `id` int(11) NOT NULL,
  `colis_id` int(11) NOT NULL,
  `ibox_id` int(11) DEFAULT NULL,
  `code_pin` varchar(20) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `type_code` enum('pin','qr') DEFAULT 'pin',
  `qr_code_data` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `actif` tinyint(1) DEFAULT 1,
  `nombre_utilisations` int(11) DEFAULT 0,
  `nombre_utilisations_max` int(11) DEFAULT 1,
  `utilise_le` datetime DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `postal_id`
--

CREATE TABLE `postal_id` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `identifiant_postal` varchar(100) NOT NULL,
  `niveau_securite` enum('basic','verified','premium') DEFAULT 'basic',
  `type_piece` varchar(50) DEFAULT NULL,
  `numero_piece` varchar(100) DEFAULT NULL,
  `date_expiration` date NOT NULL,
  `qr_code_data` text NOT NULL,
  `actif` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_verification` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Structure de la table `signatures`
--

CREATE TABLE `signatures` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) NOT NULL,
  `type_signature` enum('simple','avancee','qualifiee') NOT NULL,
  `document_hash` varchar(255) NOT NULL,
  `signature_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`signature_data`)),
  `certificat` text DEFAULT NULL,
  `horodatage` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `valide_jusque` date DEFAULT NULL,
  `archivee` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `matricule` varchar(50) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `role` enum('utilisateur','agent','admin') DEFAULT 'utilisateur',
  `email_verifie` tinyint(1) DEFAULT 0,
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_sent_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `theme_preference` enum('light','dark') NOT NULL DEFAULT 'light',
  `mfa_active` tinyint(1) DEFAULT 0,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `zone_livraison` varchar(100) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


--
-- Index pour les tables déchargées
--

--
-- Index pour la table `agents`
--
ALTER TABLE `agents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_agent` (`numero_agent`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_login_attempt` (`ip_address`,`email`),
  ADD KEY `idx_locked_until` (`locked_until`),
  ADD KEY `idx_last_attempt_at` (`last_attempt_at`);

--
-- Index pour la table `colis`
--
ALTER TABLE `colis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_colis` (`reference_colis`),
  ADD UNIQUE KEY `numero_suivi` (`numero_suivi`),
  ADD UNIQUE KEY `code_tracking` (`code_tracking`),
  ADD KEY `utilisateur_id` (`utilisateur_id`),
  ADD KEY `ibox_id` (`ibox_id`),
  ADD KEY `agent_id` (`agent_id`);

--
-- Index pour la table `commissions`
--
ALTER TABLE `commissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `livraison_id` (`livraison_id`);

--
-- Index pour la table `documents_signes`
--
ALTER TABLE `documents_signes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `signature_id` (`signature_id`);

--
-- Index pour la table `historique_colis`
--
ALTER TABLE `historique_colis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `colis_id` (`colis_id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `ibox`
--
ALTER TABLE `ibox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code_box` (`code_box`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `ibox_access_logs`
--
ALTER TABLE `ibox_access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ibox_id` (`ibox_id`);

--
-- Index pour la table `ibox_shares`
--
ALTER TABLE `ibox_shares`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ibox_id` (`ibox_id`),
  ADD KEY `shared_with_user_id` (`shared_with_user_id`);

--
-- Index pour la table `livraisons`
--
ALTER TABLE `livraisons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `colis_id` (`colis_id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `signature_id` (`signature_id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `paiements`
--
ALTER TABLE `paiements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`),
  ADD KEY `colis_id` (`colis_id`);

--
-- Index pour la table `pickup_codes`
--
ALTER TABLE `pickup_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `colis_id` (`colis_id`),
  ADD KEY `ibox_id` (`ibox_id`);

--
-- Index pour la table `postal_id`
--
ALTER TABLE `postal_id`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `identifiant_postal` (`identifiant_postal`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `signatures`
--
ALTER TABLE `signatures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `utilisateur_id` (`utilisateur_id`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uniq_email_verification_token` (`email_verification_token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `agents`
--
ALTER TABLE `agents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT pour la table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `colis`
--
ALTER TABLE `colis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT pour la table `commissions`
--
ALTER TABLE `commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `documents_signes`
--
ALTER TABLE `documents_signes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `historique_colis`
--
ALTER TABLE `historique_colis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `ibox`
--
ALTER TABLE `ibox`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `ibox_access_logs`
--
ALTER TABLE `ibox_access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `ibox_shares`
--
ALTER TABLE `ibox_shares`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `livraisons`
--
ALTER TABLE `livraisons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `paiements`
--
ALTER TABLE `paiements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `pickup_codes`
--
ALTER TABLE `pickup_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `postal_id`
--
ALTER TABLE `postal_id`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `signatures`
--
ALTER TABLE `signatures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `agents`
--
ALTER TABLE `agents`
  ADD CONSTRAINT `agents_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `colis`
--
ALTER TABLE `colis`
  ADD CONSTRAINT `colis_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`),
  ADD CONSTRAINT `colis_ibfk_2` FOREIGN KEY (`ibox_id`) REFERENCES `ibox` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `colis_ibfk_3` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `commissions`
--
ALTER TABLE `commissions`
  ADD CONSTRAINT `commissions_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `commissions_ibfk_2` FOREIGN KEY (`livraison_id`) REFERENCES `livraisons` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `documents_signes`
--
ALTER TABLE `documents_signes`
  ADD CONSTRAINT `documents_signes_ibfk_1` FOREIGN KEY (`signature_id`) REFERENCES `signatures` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `historique_colis`
--
ALTER TABLE `historique_colis`
  ADD CONSTRAINT `historique_colis_ibfk_1` FOREIGN KEY (`colis_id`) REFERENCES `colis` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `historique_colis_ibfk_2` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `ibox`
--
ALTER TABLE `ibox`
  ADD CONSTRAINT `ibox_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `ibox_access_logs`
--
ALTER TABLE `ibox_access_logs`
  ADD CONSTRAINT `ibox_access_logs_ibfk_1` FOREIGN KEY (`ibox_id`) REFERENCES `ibox` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `ibox_shares`
--
ALTER TABLE `ibox_shares`
  ADD CONSTRAINT `ibox_shares_ibfk_1` FOREIGN KEY (`ibox_id`) REFERENCES `ibox` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ibox_shares_ibfk_2` FOREIGN KEY (`shared_with_user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `livraisons`
--
ALTER TABLE `livraisons`
  ADD CONSTRAINT `livraisons_ibfk_1` FOREIGN KEY (`colis_id`) REFERENCES `colis` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `livraisons_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `livraisons_ibfk_3` FOREIGN KEY (`signature_id`) REFERENCES `signatures` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `paiements`
--
ALTER TABLE `paiements`
  ADD CONSTRAINT `paiements_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`),
  ADD CONSTRAINT `paiements_ibfk_2` FOREIGN KEY (`colis_id`) REFERENCES `colis` (`id`);

--
-- Contraintes pour la table `pickup_codes`
--
ALTER TABLE `pickup_codes`
  ADD CONSTRAINT `pickup_codes_ibfk_1` FOREIGN KEY (`colis_id`) REFERENCES `colis` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pickup_codes_ibfk_2` FOREIGN KEY (`ibox_id`) REFERENCES `ibox` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `postal_id`
--
ALTER TABLE `postal_id`
  ADD CONSTRAINT `postal_id_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `signatures`
--
ALTER TABLE `signatures`
  ADD CONSTRAINT `signatures_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


-- Données de test déplacées vers database/seed_data.sql
