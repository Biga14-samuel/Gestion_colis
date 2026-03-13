-- Seed data extracted from gestion_colis.sql
START TRANSACTION;
SET time_zone = "+00:00";

-- Déchargement des données de la table `agents`
INSERT INTO `agents` (`id`, `utilisateur_id`, `numero_agent`, `zone_livraison`, `vehicule_type`, `commission_rate`, `total_livraisons`, `total_earnings`, `note_moyenne`, `actif`, `date_certification`, `latitude`, `longitude`, `last_location_update`, `localisation_gps`) VALUES
(21, 11, 'AG-YDE-001', 'Bastos, Mvog-Mbi, Nlongkak', 'moto', 10.50, 157, 185000.50, 4.50, 1, '2026-01-19', 3.86500000, 11.51800000, '2026-03-05 18:18:22', NULL),
(22, 12, 'AG-YDE-002', 'Mendong, Simbock, Olézoa', 'voiture', 12.00, 98, 147500.75, 4.80, 1, '2026-01-04', 3.84000000, 11.48500000, '2026-03-05 17:48:22', NULL),
(23, 13, 'AG-DLA-001', 'Bonanjo, Akwa, Bonapriso', 'voiture', 11.00, 210, 320000.00, 4.90, 1, '2025-12-05', 4.05000000, 9.70000000, '2026-03-05 18:28:22', NULL),
(24, 14, 'AG-DLA-002', 'Makepe, Logbaba, Ndokotti', 'camion', 15.00, 75, 215000.00, 4.20, 1, '2026-02-03', 4.02000000, 9.72000000, '2026-03-05 16:33:22', NULL),
(25, 15, 'AG-BDA-001', 'Centre-Ville, Nkwen', 'moto', 9.50, 45, 45000.00, 4.00, 1, '2026-02-18', 5.96000000, 10.15000000, NULL, NULL),
(26, 16, 'AG-BFS-001', 'Ville, Quartier', 'moto', 10.00, 82, 82000.00, 4.60, 1, '2025-12-15', 5.47000000, 10.42000000, '2026-03-05 18:03:22', NULL),
(27, 17, 'AG-GOU-001', 'Centre, Roumde Adjia', 'moto', 10.00, 62, 55800.00, 4.30, 1, '2026-02-13', 9.30000000, 13.39000000, NULL, NULL),
(28, 18, 'AG-MAR-001', 'Domayo, Doualaré', 'velo', 8.00, 30, 18000.00, 4.70, 1, '2026-02-23', 10.59000000, 14.32000000, '2026-03-05 17:33:22', NULL),
(29, 19, 'AG-EBW-001', 'Ville, Ngoazik', 'moto', 9.00, 22, 15400.00, 5.00, 1, '2026-02-28', 2.90000000, 11.15000000, NULL, NULL),
(30, 20, 'AG-KRI-001', 'Plage, Ville', 'velo', 8.50, 15, 9750.00, 4.90, 1, '2026-02-26', 2.94000000, 9.91000000, '2026-03-05 18:23:22', NULL);
-- Déchargement des données de la table `audit_log`
INSERT INTO `audit_log` (`id`, `utilisateur_id`, `action`, `table_affectee`, `entite_id`, `anciennes_valeurs`, `nouvelles_valeurs`, `ip_address`, `user_agent`, `date_action`) VALUES
(1, 1, 'INSERT_MASSIVE_AGENTS', 'utilisateurs', NULL, NULL, NULL, '127.0.0.1', 'Script SQL / Admin', '2026-03-05 17:33:22'),
(2, 1, 'INSERT_MASSIVE_AGENTS', 'agents', NULL, NULL, NULL, '127.0.0.1', 'Script SQL / Admin', '2026-03-05 17:33:22');
-- Déchargement des données de la table `colis`
INSERT INTO `colis` (`id`, `utilisateur_id`, `expediteur_id`, `destinataire_id`, `ibox_id`, `agent_id`, `reference_colis`, `numero_suivi`, `nom_destinataire`, `adresse_livraison`, `telephone_destinataire`, `description`, `poids`, `dimensions`, `valeur_declaree`, `fragile`, `urgent`, `statut`, `code_tracking`, `date_creation`, `date_mise_a_jour`, `date_livraison_estimee`, `date_livraison`, `delivered_at`, `instructions`, `signature_data`, `signature_level`, `signature_timestamp`, `signature_image`, `proof_photo_path`, `recipient_name`, `delivery_notes`, `payment_status`, `payment_amount`, `payment_currency`, `payment_provider`, `payment_reference`, `payment_phone`, `payment_metadata`, `payment_last_error`, `paid_at`, `stripe_session_id`, `signature_base64`) VALUES
(5, 1, NULL, NULL, 1, NULL, 'COLIS260115GGS', NULL, NULL, NULL, NULL, 'DZDSD', 0.10, 'Non spécifié', 5000.00, 1, 0, 'en_attente', 'TRK20260115885529', '2026-01-15 10:22:36', '2026-01-15 10:22:36', NULL, NULL, NULL, 'sdsd', NULL, 'basic', NULL, 'uploads/signatures/signature_5_1768472556.png', NULL, NULL, NULL, 'pending', 0.00, 'XAF', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 1, NULL, NULL, 1, NULL, 'COLIS260115PKM', NULL, NULL, NULL, NULL, 'sdd', 0.10, 'Non spécifié', 0.02, 0, 1, 'en_attente', 'TRK20260115786721', '2026-01-15 10:28:02', '2026-01-15 10:28:02', NULL, NULL, NULL, 's', NULL, 'basic', NULL, 'uploads/signatures/signature_6_1768472882.png', NULL, NULL, NULL, 'pending', 0.00, 'XAF', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 8, NULL, NULL, NULL, NULL, 'cadeau du 14 fev', NULL, NULL, NULL, NULL, 'chaussure,vetements.', 10.00, 'Non spécifié', 14999.92, 1, 1, 'en_attente', 'TRK20260204118966', '2026-02-04 20:00:43', '2026-02-04 20:00:43', NULL, NULL, NULL, 'RAS', NULL, 'basic', NULL, 'uploads/signatures/signature_7_1770235243.png', NULL, NULL, NULL, 'pending', 0.00, 'XAF', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
-- Déchargement des données de la table `ibox`
INSERT INTO `ibox` (`id`, `utilisateur_id`, `code_box`, `localisation`, `type_box`, `capacite_max`, `statut`, `temperature`, `code_acces`, `qr_code`, `date_creation`, `derniere_utilisation`) VALUES
(1, 1, 'BOXAF3A3EA9', 'Ydé', 'medium', 10, 'recu', 'frigo', '8122', '{\"code_box\":\"BOXAF3A3EA9\",\"code_acces\":\"8122\",\"localisation\":\"Yd\\u00e9\",\"created_at\":\"2026-01-09 12:33:53\"}', '2026-01-09 11:33:53', NULL);
-- Déchargement des données de la table `postal_id`
INSERT INTO `postal_id` (`id`, `utilisateur_id`, `identifiant_postal`, `niveau_securite`, `type_piece`, `numero_piece`, `date_expiration`, `qr_code_data`, `actif`, `date_creation`, `date_verification`) VALUES
(1, 1, 'PID2026000001', 'basic', NULL, NULL, '2028-01-08', '{\"user_id\":\"1\",\"code\":\"PID2026000001\",\"created\":\"2026-01-08\"}', 1, '2026-01-08 13:49:25', NULL),
(2, 8, 'PID2026000008', 'basic', NULL, NULL, '2028-02-04', '{\"user_id\":\"8\",\"code\":\"PID2026000008\",\"created\":\"2026-02-04\"}', 1, '2026-02-04 19:58:32', NULL),
(4, 10, 'PID2026000010', 'basic', NULL, NULL, '2028-03-05', '{\"user_id\":\"10\",\"code\":\"PID2026000010\",\"created\":\"2026-03-05\"}', 1, '2026-03-05 17:14:11', NULL),
(5, 21, 'PID2026000021', 'basic', NULL, NULL, '2028-03-06', '{\"user_id\":\"21\",\"code\":\"PID2026000021\",\"created\":\"2026-03-06\"}', 1, '2026-03-06 16:54:49', NULL),
(6, 22, 'PID2026000022', 'basic', NULL, NULL, '2028-03-07', '{\"user_id\":\"22\",\"code\":\"PID2026000022\",\"created\":\"2026-03-07\"}', 1, '2026-03-07 17:13:51', NULL);
-- Déchargement des données de la table `utilisateurs`
INSERT INTO `utilisateurs` (`id`, `nom`, `prenom`, `email`, `mot_de_passe`, `telephone`, `matricule`, `adresse`, `role`, `email_verifie`, `email_verification_token`, `email_verification_sent_at`, `email_verified_at`, `mfa_active`, `date_creation`, `date_modification`, `zone_livraison`, `actif`) VALUES
(1, 'pouda', 'joseph', 'josephpouda@gmail.com', '$2y$10$Gf5.KgJ3wXqlHj76iZzy0OcXvuBONuEK47p1WC5M7eDWlBAeOFGBS', '679624138', NULL, 'yaoundé cameroun', 'admin', 1, NULL, NULL, NULL, 0, '2026-01-08 13:49:25', '2026-03-04 12:45:23', NULL, 1),
(8, 'PIPO', 'JEAN', 'pipo@gmail.com', '$2y$10$MJ.iKA3uJ1oi7h05TU1JpuslyiWU.AWAp2bwaWyIFUv5VfR72Tw/6', '677689900', NULL, 'ydé', 'utilisateur', 1, NULL, NULL, NULL, 0, '2026-02-04 19:58:32', '2026-02-04 19:58:32', NULL, 1),
(10, 'pouda', 'pouda', 'pouda@gmail.com', '$2y$10$yZmiw4rvDyHYzw14qvlYVeVM1hgQU79dtXcvDClPWFd8qWh9pEBhG', '+237679624138', NULL, 'IHTM', 'utilisateur', 1, NULL, NULL, NULL, 0, '2026-03-05 17:14:11', '2026-03-05 17:14:11', NULL, 1),
(11, 'Dongmo', 'Alice', 'alice.dongmo@email.com', '$2y$10$ExempleHashAgentAlice', '691234501', NULL, 'Bastos, Yaoundé', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(12, 'Nkoa', 'Brice', 'brice.nkoa@email.com', '$2y$10$ExempleHashAgentBrice', '692345602', NULL, 'Mvog-Mbi, Yaoundé', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(13, 'Tchoumi', 'Carine', 'carine.tchoumi@email.com', '$2y$10$ExempleHashAgentCarine', '693456703', NULL, 'Bonanjo, Douala', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(14, 'Essomba', 'David', 'david.essomba@email.com', '$2y$10$ExempleHashAgentDavid', '694567804', NULL, 'Akwa, Douala', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(15, 'Fotso', 'Estelle', 'estelle.fotso@email.com', '$2y$10$ExempleHashAgentEstelle', '695678905', NULL, 'Bamenda, Centre-Ville', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(16, 'Guedem', 'Franck', 'franck.guedem@email.com', '$2y$10$ExempleHashAgentFranck', '696789016', NULL, 'Bafoussam, Quartier', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(17, 'Hamadou', 'Grace', 'grace.hamadou@email.com', '$2y$10$ExempleHashAgentGrace', '697890127', NULL, 'Garoua, Centre', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(18, 'Ibrahima', 'Hervé', 'herve.ibrahima@email.com', '$2y$10$ExempleHashAgentHerve', '698901238', NULL, 'Maroua, Domayo', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(19, 'Jiodjo', 'Irene', 'irene.jiodjo@email.com', '$2y$10$ExempleHashAgentIrene', '699012349', NULL, 'Ebolowa, Ville', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(20, 'Kengne', 'Jean', 'jean.kengne@email.com', '$2y$10$ExempleHashAgentJean', '690123450', NULL, 'Kribi, Plage', 'agent', 1, NULL, NULL, NULL, 0, '2026-03-05 17:33:22', '2026-03-05 17:33:22', NULL, 1),
(21, 'babayaga', 'john', 'babayaga@gmail.com', '$2y$10$30BzVwIvzeEbtg1hmrygVe.KOBdyfN7fCP/RQU0iZpF2xs0cEi8LW', '674301474', NULL, 'Russie', 'utilisateur', 1, NULL, NULL, NULL, 0, '2026-03-06 16:54:49', '2026-03-06 16:54:49', NULL, 1),
(22, 'JEAN', 'DUPONT', 'dupont@gmail.com', '$2y$10$x0RGRcXVd.aohjCyIyDVYO4iroqV953BtbfG59hPNsuh787EU.57O', '+237679624138', NULL, 'IHTM', 'utilisateur', 1, NULL, NULL, NULL, 0, '2026-03-07 17:13:51', '2026-03-07 17:13:51', NULL, 1);
COMMIT;
