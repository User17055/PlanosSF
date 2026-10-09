<?php

namespace App\Services\Catalog;

use App\Enums\Species;
use App\Models\Pet;
use App\Models\PlanVersion;

/**
 * Elegibilidade do pet segundo as regras efetivamente publicadas (espécie e idade).
 */
class PetEligibility
{
    /** @return array<int, string> motivos de inelegibilidade */
    public function reasons(PlanVersion $version, Pet $pet): array
    {
        $reasons = [];
        $species = $version->eligible_species ?? [];
        $petSpecies = $pet->species instanceof Species ? $pet->species->value : $pet->species;

        if ($species !== [] && ! in_array($petSpecies, $species, true)) {
            $reasons[] = 'Espécie não atendida por este plano.';
        }

        $age = $pet->ageInMonths();
        if (($version->min_age_months !== null || $version->max_age_months !== null) && $age === null) {
            $reasons[] = 'Informe data de nascimento ou idade estimada do pet.';
        } elseif ($age !== null) {
            if ($version->min_age_months !== null && $age < $version->min_age_months) {
                $reasons[] = "Idade mínima para o plano: {$version->min_age_months} meses.";
            }
            if ($version->max_age_months !== null && $age > $version->max_age_months) {
                $reasons[] = "Idade máxima para o plano: {$version->max_age_months} meses.";
            }
        }

        return $reasons;
    }

    public function isEligible(PlanVersion $version, Pet $pet): bool
    {
        return $this->reasons($version, $pet) === [];
    }
}
