<?php

namespace App\Services\Access;

use App\Models\Franchise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unidade de atuação do usuário na requisição atual. A escolha vem da sessão,
 * mas é revalidada contra os vínculos ativos em toda requisição (seção 27.1):
 * alterar um franchise_id no navegador não concede acesso a outra unidade.
 */
class FranchiseContext
{
    public const SESSION_KEY = 'active_franchise_id';

    protected ?Franchise $franchise = null;

    protected ?User $user = null;

    public function set(User $user, Franchise $franchise): void
    {
        if (! $user->membershipFor($franchise->id)) {
            throw new AuthorizationException('Usuário sem vínculo ativo com a unidade.');
        }
        $this->user = $user;
        $this->franchise = $franchise;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->franchise = null;
    }

    public function has(): bool
    {
        return $this->franchise !== null;
    }

    public function franchise(): Franchise
    {
        if (! $this->franchise) {
            throw new AuthorizationException('Nenhuma unidade de atuação selecionada.');
        }

        return $this->franchise;
    }

    public function id(): int
    {
        return $this->franchise()->id;
    }

    public function isCentral(): bool
    {
        return $this->franchise?->is_central === true;
    }

    public function allows(string $permission): bool
    {
        if (! $this->user || ! $this->franchise) {
            return false;
        }
        if (in_array($permission, PermissionCatalog::centralOnly(), true) && ! $this->isCentral()) {
            return false;
        }

        return in_array($permission, $this->user->permissionsFor($this->franchise->id), true);
    }

    /**
     * Restringe consultas a registros da unidade (visão restrita) ou libera toda a rede (visão central) — RF-15.
     */
    public function scope(Builder $query, string $column = 'originating_franchise_id'): Builder
    {
        return $this->isCentral() ? $query : $query->where($column, $this->id());
    }

    public function canSeeFranchiseRecord(?int $franchiseId): bool
    {
        return $this->isCentral() || ($franchiseId !== null && $franchiseId === $this->franchise?->id);
    }
}
