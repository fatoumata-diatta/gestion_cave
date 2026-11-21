<?php
declare(strict_types=1);

/**
 * Une règle de gestion n'est pas respectée (stock insuffisant, quantité nulle, doublon...).
 *
 * Ce n'est pas un bug : c'est un refus normal, avec des messages destinés à l'utilisateur.
 * Quand elle est levée, la transaction en cours est annulée : la base reste inchangée.
 */
class RegleGestionException extends RuntimeException
{
    /** @var string[] */
    private array $erreurs;

    /** @param string[] $erreurs */
    public function __construct(array $erreurs)
    {
        $this->erreurs = array_values($erreurs);
        parent::__construct(implode(' ', $this->erreurs));
    }

    /** @return string[] */
    public function erreurs(): array
    {
        return $this->erreurs;
    }
}

/** Impossible de joindre la base de données (serveur éteint, mauvais mot de passe, base absente). */
class ConnexionBaseException extends RuntimeException
{
}
