<?php

namespace Database\Seeders;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use App\Models\ContractTemplate;
use App\Models\Franchise;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Catalog\PlanCatalogService;
use Illuminate\Database\Seeder;

/**
 * Base fictícia para homologação (seção 17). Valores, coberturas e texto NÃO representam
 * condições comerciais aprovadas — servem apenas para exercitar os fluxos.
 */
class HomologationSeeder extends Seeder
{
    public function run(): void
    {
        Franchise::firstOrCreate(['code' => 'UN-CENTRO'], ['name' => 'Unidade Centro (teste)', 'status' => 'active', 'city' => 'São Paulo', 'state' => 'SP']);
        Franchise::firstOrCreate(['code' => 'UN-NORTE'], ['name' => 'Unidade Norte (teste)', 'status' => 'active', 'city' => 'São Paulo', 'state' => 'SP']);

        $template = ContractTemplate::firstOrCreate(['name' => 'Modelo de homologação', 'version' => 1], [
            'status' => 'draft',
            'body' => <<<'TXT'
MODELO DE HOMOLOGAÇÃO — TEXTO SEM VALIDADE JURÍDICA. Substitua pelo contrato aprovado pela São Francisco.

1. OBJETO. Este contrato nº {{ contrato.numero }}, referente à proposta {{ proposta.numero }}, formaliza a adesão de {{ tutor.nome }}, CPF {{ tutor.cpf }}, ao plano {{ plano.nome }} (código {{ plano.codigo }}, versão {{ plano.versao }}), cobrindo exclusivamente o pet {{ pet.nome }} ({{ pet.especie }}).

2. VALORES. Mensalidade de {{ plano.mensalidade }}, cobrança {{ plano.periodicidade }}, e adesão de {{ plano.adesao }}.

3. VIGÊNCIA. A vigência inicia quando o contrato final estiver armazenado e o primeiro pagamento integral for confirmado, ou na data futura contratada, se posterior. Duração: {{ plano.duracao }}. Renovação: {{ plano.renovacao }}

4. CARÊNCIAS E LIMITES. Cada benefício possui carência, limite e período de renovação próprios, conforme a tabela deste documento. A mensalidade de cobrança, a vigência do contrato e o período de renovação dos benefícios são conceitos distintos.

5. INADIMPLÊNCIA. A existência de qualquer parcela vencida e não quitada de planos do titular bloqueia a utilização de serviços e benefícios de todos os seus planos, em toda a rede, até a regularização.

6. CANCELAMENTO. O titular pode solicitar o cancelamento a qualquer tempo, nos termos da política aprovada.

Unidade de origem: {{ franquia.nome }}. Emitido em {{ data }}.
TXT,
        ]);

        if (Plan::where('code', 'TESTE-ESSENCIAL')->exists()) {
            return;
        }

        $plan = Plan::create(['code' => 'TESTE-ESSENCIAL', 'name' => 'Plano Teste Essencial', 'status' => 'active']);
        $version = $plan->versions()->create([
            'version' => 1,
            'display_name' => 'Plano Teste Essencial (exemplo)',
            'description' => 'Plano fictício para homologação. Valores e coberturas não aprovados.',
            'status' => 'draft',
            'price_cents' => 9990,
            'enrollment_fee_cents' => 0,
            'billing_interval_months' => 1,
            'contract_duration_months' => 12,
            'renewal_policy' => 'Exemplo: renovação automática por igual período, salvo manifestação contrária.',
            'eligible_species' => ['dog', 'cat'],
            'min_age_months' => 2,
            'contract_template_id' => $template->id,
        ]);

        $version->benefitRules()->createMany([
            [
                'service_code' => 'CONSULTA', 'description' => 'Consulta clínica', 'coverage_type' => CoverageType::Included,
                'quantity_limit' => 2, 'unit' => 'consulta', 'period_type' => PeriodType::ContractYear, 'waiting_days' => 30,
                'requires_authorization' => true, 'coverage_group' => 'CONSULTAS', 'group_limit' => 3,
            ],
            [
                'service_code' => 'AVALIACAO', 'description' => 'Avaliação de rotina', 'coverage_type' => CoverageType::Included,
                'quantity_limit' => 1, 'unit' => 'avaliação', 'period_type' => PeriodType::MonthlyCycle, 'waiting_days' => 10,
                'requires_authorization' => true, 'coverage_group' => 'CONSULTAS',
            ],
            [
                'service_code' => 'VACINA-V10', 'description' => 'Vacina anual', 'coverage_type' => CoverageType::Included,
                'quantity_limit' => 1, 'unit' => 'dose', 'period_type' => PeriodType::ContractYear, 'waiting_days' => 0,
                'requires_authorization' => true, 'min_interval_days' => 300,
            ],
            [
                'service_code' => 'EXAMES', 'description' => 'Desconto em exames laboratoriais', 'coverage_type' => CoverageType::Discount,
                'discount_percent' => 20, 'quantity_limit' => null, 'unit' => 'exame', 'period_type' => null, 'waiting_days' => 0,
                'requires_authorization' => true,
            ],
        ]);

        app(PlanCatalogService::class)->publish(PlanVersion::find($version->id), new User(['name' => 'seed']));
    }
}
