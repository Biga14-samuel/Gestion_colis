<?php
/**
 * Centralized notification helper to avoid duplicate definitions.
 */

function createNotification(PDO $db, int $userId, string $type, string $title, string $message): bool {
    $allowedTypes = ['colis', 'livraison', 'paiement', 'system', 'security', 'ibox', 'signature', 'postal_id', 'promotion'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'system';
    }

    $stmt = $db->prepare("
        INSERT INTO notifications (utilisateur_id, type, titre, message, priorite, date_envoi)
        VALUES (?, ?, ?, ?, 'normal', NOW())
    ");
    return $stmt->execute([$userId, $type, $title, $message]);
}
