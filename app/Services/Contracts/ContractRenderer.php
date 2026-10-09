<?php

namespace App\Services\Contracts;

use App\Enums\Species;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Proposal;
use App\Support\BusinessTime;
use App\Support\Money;

/**
 * Monta os dados congelados do contrato e o texto integral a partir do modelo.
 * O documento final é um registro histórico: mudanças posteriores no cadastro não o alteram.
 */
class ContractRenderer
{
    public const PLACEHOLDERS = [
        'contrato.numero' => 'Número do contrato',
        'proposta.numero' => 'Número da proposta',
        'tutor.nome' => 'Nome do tutor',
        'tutor.cpf' => 'CPF do tutor',
        'tutor.email' => 'E-mail do tutor',
        'tutor.telefone' => 'Telefone do tutor',
        'tutor.endereco' => 'Endereço completo',
        'pet.nome' => 'Nome do pet',
        'pet.especie' => 'Espécie',
        'pet.raca' => 'Raça',
        'pet.nascimento' => 'Nascimento ou idade estimada',
        'plano.nome' => 'Nome do plano',
        'plano.codigo' => 'Código do plano',
        'plano.versao' => 'Versão do plano',
        'plano.mensalidade' => 'Valor da mensalidade',
        'plano.adesao' => 'Valor de adesão',
        'plano.periodicidade' => 'Periodicidade de cobrança',
        'plano.duracao' => 'Duração contratual',
        'plano.renovacao' => 'Política de renovação',
        'franquia.nome' => 'Unidade de origem',
        'data' => 'Data de emissão',
    ];

    /** @return array<string, mixed> */
    public function freeze(Proposal $proposal, Customer $customer, Pet $pet, string $contractNumber): array
    {
        $proposal->loadMissing('originatingFranchise', 'seller');
        $s = $proposal->conditions_snapshot;
        $species = $pet->species instanceof Species ? $pet->species : Species::tryFrom((string) $pet->species);

        return [
            'contract_number' => $contractNumber,
            'proposal_number' => $proposal->number,
            'channel' => $proposal->channel->value,
            'signature_method' => $proposal->signature_method->value,
            'issued_at' => now()->toIso8601String(),
            'franchise' => ['id' => $proposal->originatingFranchise->id, 'code' => $proposal->originatingFranchise->code, 'name' => $proposal->originatingFranchise->name],
            'seller' => $proposal->seller ? ['id' => $proposal->seller->id, 'name' => $proposal->seller->name] : null,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'document' => $customer->formattedDocument(),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => trim(implode(', ', array_filter([
                    $customer->street, $customer->number, $customer->complement, $customer->district,
                    $customer->city ? $customer->city.'/'.$customer->state : null, $customer->zip_code ? 'CEP '.$customer->zip_code : null,
                ]))),
            ],
            'pet' => [
                'id' => $pet->id,
                'name' => $pet->name,
                'species' => $species?->label() ?? (string) $pet->species,
                'breed' => $pet->breed,
                'sex' => $pet->sex,
                'birth' => $pet->birth_date ? $pet->birth_date->format('d/m/Y') : ($pet->estimated_age_months !== null ? 'Idade estimada: '.$pet->estimated_age_months.' meses' : null),
            ],
            'conditions' => $s,
            'price_cents' => $proposal->price_cents,
            'enrollment_fee_cents' => $proposal->enrollment_fee_cents,
            'desired_start_date' => $proposal->desired_start_date?->format('Y-m-d'),
        ];
    }

    public function renderBody(string $template, array $frozen): string
    {
        $c = $frozen['conditions'];
        $interval = (int) ($c['billing_interval_months'] ?? 1);
        $values = [
            'contrato.numero' => $frozen['contract_number'],
            'proposta.numero' => $frozen['proposal_number'],
            'tutor.nome' => $frozen['customer']['name'],
            'tutor.cpf' => $frozen['customer']['document'],
            'tutor.email' => $frozen['customer']['email'],
            'tutor.telefone' => $frozen['customer']['phone'],
            'tutor.endereco' => $frozen['customer']['address'],
            'pet.nome' => $frozen['pet']['name'],
            'pet.especie' => $frozen['pet']['species'],
            'pet.raca' => $frozen['pet']['breed'] ?: 'não informada',
            'pet.nascimento' => $frozen['pet']['birth'] ?: 'não informado',
            'plano.nome' => $c['version']['display_name'],
            'plano.codigo' => $c['plan']['code'],
            'plano.versao' => (string) $c['version']['number'],
            'plano.mensalidade' => Money::format($frozen['price_cents']),
            'plano.adesao' => Money::format($frozen['enrollment_fee_cents']),
            'plano.periodicidade' => $interval === 1 ? 'mensal' : "a cada {$interval} meses",
            'plano.duracao' => ($c['contract_duration_months'] ?? '—').' meses',
            'plano.renovacao' => (string) ($c['renewal_policy'] ?? ''),
            'franquia.nome' => $frozen['franchise']['name'],
            'data' => BusinessTime::now()->format('d/m/Y'),
        ];

        return preg_replace_callback('/\{\{\s*([a-z]+\.[a-z]+|data)\s*\}\}/u', function ($m) use ($values) {
            return array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0];
        }, $template);
    }
}
