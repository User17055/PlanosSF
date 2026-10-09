<?php

namespace App\Services\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\Species;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Proposal;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PetEligibility;
use App\Support\Cpf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dados informados pelo cliente no site/link. Ficam registrados na proposta e só são gravados
 * no cadastro central no aceite, depois da confirmação do contato. Assim um link público não
 * altera cadastros existentes nem revela dados de terceiros.
 */
class CustomerFormService
{
    public const CUSTOMER_FIELDS = ['name', 'document', 'email', 'phone', 'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state'];

    public const PET_FIELDS = ['name', 'species', 'breed', 'sex', 'birth_date', 'estimated_age_months'];

    public function __construct(protected AuditLogger $audit, protected PetEligibility $eligibility) {}

    /**
     * @param  array{customer: array<string, mixed>, pet: array<string, mixed>}  $data
     */
    public function save(Proposal $proposal, array $data): Proposal
    {
        return DB::transaction(function () use ($proposal, $data) {
            $proposal = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->hasBeenAccepted() || ! $proposal->isOpen()) {
                throw ValidationException::withMessages(['proposal' => 'Esta proposta não aceita mais alterações.']);
            }

            $customer = $this->onlyFilled($data['customer'] ?? [], self::CUSTOMER_FIELDS);
            $pet = $this->onlyFilled($data['pet'] ?? [], self::PET_FIELDS);
            $customer['document'] = Cpf::normalize($customer['document'] ?? null);

            // Cadastro já vinculado pelo vendedor: o documento informado precisa coincidir.
            if ($proposal->customer_id) {
                $existing = Customer::find($proposal->customer_id);
                if ($existing->document_normalized && $customer['document'] && $customer['document'] !== $existing->document_normalized) {
                    throw ValidationException::withMessages(['customer.document' => 'Os dados não conferem com o cadastro desta proposta. Procure a unidade.']);
                }
            }

            $conflict = false;
            if (! $proposal->customer_id && $customer['document']) {
                $match = Customer::where('document_normalized', $customer['document'])->first();
                // Coincidência de documento sem coincidência de e-mail exige revisão interna (seção 27.2).
                $conflict = $match !== null && strcasecmp((string) $match->email, (string) ($customer['email'] ?? '')) !== 0;
            }

            $petCheck = $this->transientPet($proposal, $pet);
            if ($reasons = $this->eligibility->reasons($proposal->planVersion, $petCheck)) {
                throw ValidationException::withMessages(['pet.species' => $reasons]);
            }

            $previous = $proposal->form_data;
            $proposal->forceFill([
                'form_data' => ['customer' => $customer, 'pet' => $pet, 'conflict' => $conflict, 'submitted_at' => now()->toIso8601String()],
                'status' => $conflict ? ProposalStatus::AwaitingFill : ProposalStatus::AwaitingAcceptance,
            ])->save();

            $this->audit->log($previous ? 'proposal.form_corrected' : 'proposal.form_filled', $proposal, [
                'campos' => array_keys(array_filter($customer + $pet)),
                'conflito_cadastral' => $conflict,
            ], null, 'customer');

            if ($conflict) {
                throw ValidationException::withMessages(['customer.document' => 'Não foi possível continuar com estes dados. Entre em contato com a unidade para concluir a contratação.']);
            }

            return $proposal;
        });
    }

    /** Dados efetivos para revisão: cadastro vinculado sobreposto pelas correções do cliente. */
    public function merged(Proposal $proposal): array
    {
        $proposal->loadMissing('customer', 'pet');
        $form = $proposal->form_data ?? [];
        $c = $proposal->customer;
        $p = $proposal->pet;

        $customer = array_merge($c ? [
            'name' => $c->name, 'document' => $c->document_normalized, 'email' => $c->email, 'phone' => $c->phone,
            'zip_code' => $c->zip_code, 'street' => $c->street, 'number' => $c->number, 'complement' => $c->complement,
            'district' => $c->district, 'city' => $c->city, 'state' => $c->state,
        ] : [], array_filter($form['customer'] ?? [], fn ($v) => $v !== null && $v !== ''));

        $pet = array_merge($p ? [
            'name' => $p->name, 'species' => $p->species?->value, 'breed' => $p->breed, 'sex' => $p->sex,
            'birth_date' => $p->birth_date?->format('Y-m-d'), 'estimated_age_months' => $p->estimated_age_months,
        ] : [], array_filter($form['pet'] ?? [], fn ($v) => $v !== null && $v !== ''));

        return ['customer' => $customer, 'pet' => $pet];
    }

    /** @return array<int, string> campos obrigatórios ainda ausentes */
    public function missing(Proposal $proposal): array
    {
        $m = $this->merged($proposal);
        $labels = [
            'customer.name' => 'nome do tutor', 'customer.document' => 'CPF', 'customer.email' => 'e-mail',
            'customer.phone' => 'telefone', 'customer.zip_code' => 'CEP', 'customer.street' => 'logradouro',
            'customer.number' => 'número', 'customer.city' => 'cidade', 'customer.state' => 'UF',
            'pet.name' => 'nome do pet', 'pet.species' => 'espécie',
        ];
        $missing = [];
        foreach ($labels as $path => $label) {
            [$group, $field] = explode('.', $path);
            if (blank($m[$group][$field] ?? null)) {
                $missing[] = $label;
            }
        }
        if (blank($m['pet']['birth_date'] ?? null) && blank($m['pet']['estimated_age_months'] ?? null)) {
            $missing[] = 'nascimento ou idade estimada do pet';
        }

        return $missing;
    }

    /**
     * Grava tutor e pet no cadastro central no momento do aceite (dentro da transação do aceite).
     *
     * @return array{0: Customer, 1: Pet}
     */
    public function materialize(Proposal $proposal): array
    {
        $m = $this->merged($proposal);
        $c = $m['customer'];

        $customer = $proposal->customer_id ? Customer::lockForUpdate()->find($proposal->customer_id) : null;
        if (! $customer && ! empty($c['document'])) {
            $customer = Customer::where('document_normalized', $c['document'])->lockForUpdate()->first();
            if ($customer && strcasecmp((string) $customer->email, (string) ($c['email'] ?? '')) !== 0) {
                throw ValidationException::withMessages(['customer.document' => 'Cadastro exige revisão interna antes do aceite.']);
            }
        }

        $attributes = [
            'name' => $c['name'] ?? null, 'document_normalized' => $c['document'] ?? null, 'email' => $c['email'] ?? null,
            'phone' => $c['phone'] ?? null, 'zip_code' => $c['zip_code'] ?? null, 'street' => $c['street'] ?? null,
            'number' => $c['number'] ?? null, 'complement' => $c['complement'] ?? null, 'district' => $c['district'] ?? null,
            'city' => $c['city'] ?? null, 'state' => isset($c['state']) ? strtoupper($c['state']) : null,
        ];

        if ($customer) {
            $customer->fill(array_filter($attributes, fn ($v) => $v !== null));
            if ($customer->isDirty()) {
                $this->audit->log('customer.updated_by_customer', $customer, $this->audit->diff($customer), 'Correção informada pelo titular na proposta '.$proposal->number, 'customer');
                $customer->save();
            }
        } else {
            $customer = Customer::create($attributes + ['created_franchise_id' => $proposal->originating_franchise_id]);
            $this->audit->log('customer.created_from_proposal', $customer, null, 'Proposta '.$proposal->number, 'customer');
        }

        $p = $m['pet'];
        $pet = $proposal->pet_id ? Pet::lockForUpdate()->find($proposal->pet_id) : null;
        $petAttributes = array_filter([
            'name' => $p['name'] ?? null, 'species' => $p['species'] ?? null, 'breed' => $p['breed'] ?? null,
            'sex' => $p['sex'] ?? null, 'birth_date' => $p['birth_date'] ?? null,
            'estimated_age_months' => isset($p['estimated_age_months']) && $p['estimated_age_months'] !== '' ? (int) $p['estimated_age_months'] : null,
        ], fn ($v) => $v !== null);

        if ($pet) {
            $pet->fill($petAttributes);
            if ($pet->isDirty()) {
                $this->audit->log('pet.updated_by_customer', $pet, $this->audit->diff($pet), 'Proposta '.$proposal->number, 'customer');
                $pet->save();
            }
        } else {
            $pet = $customer->pets()->create($petAttributes + [
                'estimated_age_reference_date' => isset($petAttributes['estimated_age_months']) ? now(config('planos.business_timezone'))->toDateString() : null,
            ]);
            $this->audit->log('pet.created_from_proposal', $pet, null, 'Proposta '.$proposal->number, 'customer');
        }

        return [$customer, $pet];
    }

    protected function transientPet(Proposal $proposal, array $pet): Pet
    {
        $base = $proposal->pet ? $proposal->pet->replicate() : new Pet;
        $model = $base->fill(array_filter([
            'species' => $pet['species'] ?? null,
            'birth_date' => $pet['birth_date'] ?? null,
            'estimated_age_months' => isset($pet['estimated_age_months']) && $pet['estimated_age_months'] !== '' ? (int) $pet['estimated_age_months'] : null,
        ], fn ($v) => $v !== null));
        if ($model->estimated_age_months !== null && ! $model->estimated_age_reference_date) {
            $model->estimated_age_reference_date = now(config('planos.business_timezone'))->toDateString();
        }
        if (! $model->species) {
            $model->species = Species::Other;
        }

        return $model;
    }

    /** @param array<int, string> $fields */
    protected function onlyFilled(array $values, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $values[$f] ?? null;
            $out[$f] = is_string($v) ? trim($v) : $v;
        }

        return $out;
    }
}
