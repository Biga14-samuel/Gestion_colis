<?php
/**
 * Politique mot de passe centralisee.
 * Retourne une liste d'erreurs, vide si le mot de passe est conforme.
 */
class PasswordPolicy
{
    public static function validate(string $password): array
    {
        $errors = [];
        $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

        if ($length < 8) {
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
}

// Backward-compatible wrapper
function validatePasswordPolicy(string $password): array
{
    return PasswordPolicy::validate($password);
}
