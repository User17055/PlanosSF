# PlanosSF — notas para desenvolvimento

- A fonte de verdade das regras é a especificação "Planos São Francisco (PHP/VPS)"; a tabela RF → código está no README.
- Regras de negócio ficam em `app/Services`; controllers só validam e delegam. Estados em `app/Enums`.
- Nunca ativar assinatura fora de `ActivationService::tryActivate`. Nunca liberar inadimplência manualmente.
- Dinheiro sempre em centavos (`App\Support\Money`); datas civis no fuso `America/Sao_Paulo` (`BusinessTime`), instantes em UTC.
- Ordem de bloqueio nas transações: cliente → assinatura → período de benefício. Sem chamadas externas dentro de transações.
- Efeitos assíncronos: grave a intenção na outbox (`IntegrationOutbox`) na mesma transação.
- Testes: `php artisan test` (os de concorrência devem rodar também contra MySQL real).
- Valores, coberturas e textos contratuais são decisões da São Francisco: não invente valores no código.
