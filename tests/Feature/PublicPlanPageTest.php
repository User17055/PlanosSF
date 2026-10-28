<?php

namespace Tests\Feature;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Página pública do plano: limite e desconto escritos como o cliente lê.
 */
class PublicPlanPageTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_limite_no_plural_e_desconto_sem_casas_decimais(): void
    {
        $version = $this->publishedPlan([
            ['service_code' => 'CONSULTA', 'description' => 'Consulta', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 2,
                'unit' => 'consulta', 'period_type' => PeriodType::ContractYear, 'waiting_days' => 30],
            ['service_code' => 'EXAMES', 'description' => 'Exames', 'coverage_type' => CoverageType::Discount, 'discount_percent' => 20,
                'quantity_limit' => null, 'unit' => 'exame', 'period_type' => null, 'waiting_days' => 0],
        ]);

        $this->get(route('site.plan', $version))->assertOk()
            ->assertSee('2 consultas por ano contratual')
            ->assertSee('Desconto 20%')
            ->assertDontSee('20.00');
    }
}
