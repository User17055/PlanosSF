<?php

namespace App\Services\Contracts;

use App\Models\Customer;
use App\Models\Pet;
use App\Models\Proposal;

/**
 * Texto integral para leitura antes do aceite, com os mesmos dados que serão congelados no contrato.
 */
class ContractPreview
{
    public function __construct(protected ContractRenderer $renderer) {}

    /** @param array{customer: array<string, mixed>, pet: array<string, mixed>} $merged */
    public function render(Proposal $proposal, array $merged): string
    {
        $c = $merged['customer'];
        $customer = new Customer([
            'name' => $c['name'] ?? null, 'document_normalized' => $c['document'] ?? null, 'email' => $c['email'] ?? null,
            'phone' => $c['phone'] ?? null, 'zip_code' => $c['zip_code'] ?? null, 'street' => $c['street'] ?? null,
            'number' => $c['number'] ?? null, 'complement' => $c['complement'] ?? null, 'district' => $c['district'] ?? null,
            'city' => $c['city'] ?? null, 'state' => $c['state'] ?? null,
        ]);
        $p = $merged['pet'];
        $pet = new Pet([
            'name' => $p['name'] ?? null, 'species' => $p['species'] ?? 'other', 'breed' => $p['breed'] ?? null,
            'sex' => $p['sex'] ?? null, 'birth_date' => $p['birth_date'] ?? null,
            'estimated_age_months' => isset($p['estimated_age_months']) && $p['estimated_age_months'] !== '' ? (int) $p['estimated_age_months'] : null,
        ]);

        $frozen = $this->renderer->freeze($proposal, $customer, $pet, '(gerado no aceite)');

        return $this->renderer->renderBody((string) ($proposal->conditions_snapshot['contract_template']['body'] ?? ''), $frozen);
    }
}
