<?php

namespace Tests\Unit;

use App\Support\BenefitText;
use PHPUnit\Framework\TestCase;

class BenefitTextTest extends TestCase
{
    public function test_quantidade_concorda_com_a_unidade(): void
    {
        $this->assertSame('1 consulta', BenefitText::quantity(1, 'consulta'));
        $this->assertSame('2 consultas', BenefitText::quantity(2, 'consulta'));
        $this->assertSame('3 avaliações', BenefitText::quantity(3, 'avaliação'));
        $this->assertSame('2 sessões', BenefitText::quantity(2, 'sessão'));
        // Unidade já cadastrada no plural não ganha outro "s".
        $this->assertSame('2 consultas', BenefitText::quantity(2, 'consultas'));
    }

    public function test_percentual_sem_zeros_e_com_virgula(): void
    {
        $this->assertSame('20%', BenefitText::percent('20.00'));
        $this->assertSame('12,5%', BenefitText::percent('12.50'));
        $this->assertSame('7,25%', BenefitText::percent('7.25'));
        $this->assertSame('100%', BenefitText::percent('100.00'));
    }
}
