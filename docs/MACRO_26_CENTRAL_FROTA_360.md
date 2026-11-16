# Macroentrega 26 — Central operacional da frota 360 (API)

## Objetivo
O operador identifica quais veículos exigem atenção sem abrir todas as fichas 360, preservando as tabelas existentes, o controle cases.manage e a arquitetura single-org atual.

## Endpoint novo
GET /api/v1/operations/vehicles (Bearer JWT + sessão válida + cases.manage + throttle).

Filtros validados:
- search (100 caracteres): título, placa, chassi, marca, modelo, vendedor e origem.
- status: all, draft, archived.
- attention: all, overdue, open_tasks, missing_documents, incomplete_profile, planned_expenses.
- sort: updated, oldest, purchase_desc, overdue.
- page: inteiro positivo; 15 registros por página, ordenação estável.

Resposta: data, current_page, last_page, per_page, total, summary e as_of.
Summary: totais da **frota inteira autorizada**, sem filtros; total: tamanho da consulta filtrada atual.

## SQL e indicadores
- Tarefas, checklist, anexos e lançamentos NÃO estornados agregados por repasse_case_id antes de unir com veículo. Não duplica contagens nem centavos por multiplicação de joins.
- Retorna dados de identificação, origem, datas, tarefas abertas/vencidas, checklist pendente, documentos ativos, despesas e receitas acessórias atuais/previstas e custos/resultados projetados.
- Sem valor de compra: custo atual/projetado nulo. Sem compra ou venda: resultado projetado nulo; valor informado igual a zero mantém zero.
- Arquivados consultáveis e fora dos filtros e contagens de atenção acionável.
- Busca com curingas escapados e filtros/sort fixos (sem SQL dinâmico arbitrário).
- Não há movimentação bancária, saldo, pagamento, comissão, conciliação, transferência, nem isolamento por tenant, que não foi definido.

## QA criado
tests/Feature/VehiclePortfolioTest.php — 5 cenários: RBAC 401/403; frota vazia; múltiplos itens sem duplicar valores; arquivados/filtros/nulos/zero; paginação 15 e 422 para filtros inválidos.

Pendências de execução em ambiente homologado:
php artisan test --filter=VehiclePortfolioTest
php artisan test --filter=Vehicle360Test
php artisan test --filter=CaseOperationsTest
vendor/bin/pint --test
php artisan route:list --path=operations

Sem migrations novas ou comandos destrutivos. Sem execução real de MySQL/PHPUnit nesta sessão; não confundir código entregue com homologação.
