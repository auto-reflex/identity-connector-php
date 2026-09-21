<?php

namespace AutoGteck\IdentityConnector\Web;

/**
 * La personne connectée à une application web (Blade) : ce qu'il faut pour l'afficher et décider de ses droits.
 * Lecture seule : rien ici n'autorise de soi-même, les rôles viennent du token vérifié (AR-066).
 */
final readonly class WebIdentity
{
    /**
     * @param  list<string>  $roles  rôles d'équipe de la personne dans ce produit
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public array $roles,
        public int $expiresAt,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
