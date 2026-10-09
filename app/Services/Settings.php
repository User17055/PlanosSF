<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Configurações de negócio. Itens marcados como "decisão pendente" ficam desabilitados
 * até a aprovação formal (seção 19 — Registro formal das decisões).
 */
class Settings
{
    /** @var array<string, mixed> */
    protected array $cache = [];

    public const DEFAULTS = [
        'in_person_payment.enabled' => [
            'value' => false,
            'pending' => true,
            'description' => 'Recebimento presencial (caixa/PIX/maquininha) e reflexo da primeira parcela na Vindi. Enquanto desabilitado, contratações presenciais permanecem aguardando confirmação financeira.',
        ],
        'site.origin_franchise_rule' => [
            'value' => 'central',
            'pending' => true,
            'description' => 'Definição da franquia de origem no site institucional. Até a decisão, a proposta fica vinculada ao canal central.',
        ],
        'benefits.waiting_period_runs_during_suspension' => [
            'value' => true,
            'pending' => true,
            'description' => 'Carência corre durante suspensão? (seção 19).',
        ],
        'billing.account_structure' => [
            'value' => 'central',
            'pending' => true,
            'description' => 'Conta Vindi central ou por franquia e eventual repasse (seção 27.8). Definir antes de criar assinaturas em produção.',
        ],
        'tudoprapet.planista_discount' => [
            'value' => false,
            'pending' => true,
            'description' => 'Condição que concede benefício de planista no e-commerce e como é retirado.',
        ],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $row = AppSetting::find($key);
            $this->cache[$key] = $row ? ($row->value['v'] ?? null) : (self::DEFAULTS[$key]['value'] ?? $default);
        }

        return $this->cache[$key];
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function isPendingDecision(string $key): bool
    {
        $row = AppSetting::find($key);

        return $row ? $row->pending_decision : (self::DEFAULTS[$key]['pending'] ?? false);
    }

    public function set(string $key, mixed $value, bool $pendingDecision, ?int $userId = null): void
    {
        AppSetting::updateOrCreate(['key' => $key], [
            'value' => ['v' => $value],
            'pending_decision' => $pendingDecision,
            'description' => self::DEFAULTS[$key]['description'] ?? null,
            'updated_by' => $userId,
        ]);
        unset($this->cache[$key]);
    }
}
