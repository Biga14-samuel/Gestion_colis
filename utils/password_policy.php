<?php
/**
 * Politique mot de passe centralisee.
 * Retourne une liste d'erreurs, vide si le mot de passe est conforme.
 */
function validatePasswordPolicy(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = "Le mot de passe doit contenir au minimum 8 caractères";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une majuscule";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une minuscule";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un chiffre";
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un caractère spécial";
    }

    return $errors;
}
