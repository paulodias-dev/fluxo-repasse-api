# Macro 47 — Venda e conclusão operacional integradas

## Objetivo
Uma etapa completa: registrar ou revisar a venda e opcionalmente concluir o veículo na mesma operação. Concluir um veículo sem venda continua possível. Tudo na ficha Metronic original, sem criar tabelas, páginas, menus ou permissões.

## Contrato da API
- GET /api/v1/repasse-cases/{case}/closeout-review: devolve contagens gerenciais e SHA-256 de conferência para transição de estado.
- POST /api/v1/repasse-cases/{case}/sale/complete: preço positivo em centavos, data explícita não anterior à compra, nome do comprador opcional, expected_closeout_sha256 e acknowledge_pending.
- POST /api/v1/repasse-cases/{case}/complete: somente conclui o acompanhamento, com ou sem venda.
- JWT, session.current, cases.manage e throttle de escrita existentes. Estritamente sem autorização bancária, saldo, obrigação, receita extra automática ou liquidação.

## Concorrência e integridade
As duas escritas usam transação e lock na linha do veículo, que os módulos de finanças, tarefas, checklist e anexos já utilizam via CaseOperations::editable. Recalculam os custos e pendências sob lock. Se SHA divergir, 409 sem escrita; se há pendências sem ciência, 422. A venda é gravada junto com status archived e um único evento case_sale_completed; a conclusão sem venda registra case_archived. Sem alterar receitas/despesas ou comprovantes existentes.

O SHA é digest gerencial, não assinatura jurídica, e confere a fotografia exibida: campos fora do resumo não estão necessariamente cobertos. O PATCH legado de status archived permanece disponível por compatibilidade, mas o React oficial usa os endpoints atômicos. Não afirmar a mesma proteção para clientes legados.

## Testes e qualidade
Feature tests acrescentados para: venda + arquivamento, margem calculada sem duplicidade, pendências, reconhecimento expresso, revisão alterada, rollback de venda inválida, conclusão sem venda, 401/403/404/409/422, campos extras e prevenção de eventos duplicados. OpenAPI 1.23 atualizado.

Testes escritos, porém não executados nesta sessão. PHPUnit/Pint/MySQL, build/lint e QA visual permanecem para a homologação após o código do MVP. A estimativa de implementação fica em ~84%, pois esta etapa não cria motor bancário nem políticas financeiras não aprovadas.
