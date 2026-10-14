<?php

namespace App\Tenancy;

use App\Models\Company;
use LogicException;

/**
 * Société "courante" de la requête.
 *
 * Enregistré en binding "scoped" : une instance par requête (ou par job),
 * remise à zéro entre deux requêtes (compatible Octane).
 *
 * Fail-closed : tant qu'aucune société n'est définie, les modèles
 * BelongsToCompany ne renvoient AUCUNE ligne.
 */
class TenantContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        if ($this->company !== null && $this->company->id !== $company->id) {
            throw new LogicException('TenantContext déjà initialisé avec une autre société.');
        }

        $this->company = $company;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    /**
     * Exécute $callback dans le contexte d'une société puis restaure l'état
     * précédent (utile pour les seeders, commandes et jobs).
     */
    public function runAs(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
