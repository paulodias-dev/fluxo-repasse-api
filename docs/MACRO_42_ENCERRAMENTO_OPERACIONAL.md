# Macroentrega 42 — Encerramento operacional seguro e reversível

## Escopo: uma macro completa API + React, sem tela ou módulo extra
O veículo já tem status `draft` (em acompanhamento) e `archived` (arquivado), ambos reutilizados. A API fornece uma **revisão de decisão** e o React exibe os dados na ficha original do Metronic Demo 1, antes de confirmar. O produto não ganha status de venda, nova tabela, pagamento ou fluxo bancário.

## GET /api/v1/repasse-cases/{case}/closeout-review
- Exige JWT Bearer válido, `session.current`, `cases.manage`, throttle de leitura; resposta `no-store, private`, sem escrever registros.
- Retorna `data` e `as_of`. `data`: id, title, status, updated_at, purchase_amount_cents, sale_amount_cents, sold_on, current_cost_cents, sale_result_cents, expense_realized_cents, income_realized_cents, expense_planned_cents, income_planned_cents, expense_planned_count, income_planned_count, open_tasks_count, pending_checklist_count, documents_count.
- Tarefas abertas: `pending` + `doing`, sem paginação; checklist somente `checked_at IS NULL`; documentos somente `archived_at IS NULL`.
- Lançamentos financeiros: somente `voided_at IS NULL`; valores realizados para custo atual/margem, valores previstos para alertar o operador. Os valores nunca são multiplicados por joins entre anexos/tarefas/finanças.
- Margem de venda gerencial datada: `venda + receitas_extras_realizadas - compra - despesas_realizadas`. `sold_on` ausente, compra `NULL` ou venda `NULL` produzem resultado `NULL` (A apurar). O resultado pode ser negativo.
- Read-only: nenhuma data de venda inventada, quitação, recebimento, pagamento ou repasse é inferido.

## Fluxo completo no React
1. O operador acessa a ficha do veículo e toca **Concluir veículo**. A revisão busca dados atuais sob demanda, sem recarregar outras abas.
2. Vê quatro cards compactos (Compra, Venda, Despesas realizadas e Resultado), e contagens de tarefas abertas, checklist, despesas/receitas previstas.
3. Se há pendências, pode tocar **Conferir pendências** (aba Tarefas) ou **Conferir financeiro** (aba Financeiro filtrada para previstos). Compra, venda e anexos ausentes podem ser consultados pelas ações já existentes.
4. Ausência de compra, ausência de venda ou ausência de anexos são INFORMAÇÕES opcionais, não impedimentos legais/operacionais inventados.
5. Se há tarefas/checklist/lançamentos previstos ainda em aberto, exige escolha explícita da confirmação de ciência antes de habilitar **Concluir veículo**. Não estorna, conclui ou quita estes registros automaticamente.
6. Antes de chamar PATCH de status, o React repete o GET e compara o snapshot de dados, exceto `as_of`. Se mudou, mostra os números atualizados e pede nova revisão. Uma consulta falha bloqueia o arquivamento; há opção de tentar novamente.
7. O PATCH `/api/v1/repasse-cases/{case}` com `status: archived` continua a operação oficial, com lock e auditoria já existentes. Se o servidor não confirmar `archived` do ID esperado, o React apresenta erro.
8. No estado arquivado, a ficha segue consultável. **Reabrir veículo** usa PATCH de `status: draft` com confirmação do operador, preservando histórico, anexos e lançamentos.

## Segurança e limites de concorrência
- Nenhuma condição de status impede arquivar sem venda. O sistema nunca altera custos previstos, notas ou tarefas por conta própria.
- Revisão é orientativa e ajuda a evitar encerramentos inadvertidos; não é uma transação que bloqueia concorrência de outros clientes. A API continua a verificar status e autorizar cada escrita dentro da transação.
- Dados numéricos são centavos inteiros; zero real e desconhecido (`NULL`) são diferentes; interface não declara recebimentos ou quitações.
- Sem nova permissão, tabela, migration, serviço externo, CSS, sidebar ou tela.

## Critérios de aceite
- API 401 anônimo/JWT inválido, 403 sem permissão, 404 ID desconhecido.
- 17+ tarefas abertas no mesmo veículo devem aparecer por contagem integral, sem limitação de paginação.
- Checklist não conferido, arquivo ativo versus arquivado, despesas/receitas previstas e estorno refletidos corretamente.
- Custo + resultado iguais à `finance/summary`, inclusive prejuízo, `null` e valores zero.
- Veículo sem venda pode ser arquivado (sem data/valor fictícios); depois reabrir, mantendo histórico e operações.
- UI: carga somente ao abrir, loading/falha/retry, botões de revisão, ciência de pendências, proteção contra duplo submit, bloqueio se snapshot mudar; status correto após PATCH.
- Mobile 320/375/390 px, duas colunas de cards e botões pequenos; desktop 1024/1440 e tablet 768 mantêm Metronic original e não introduzem outra tela. Verificar temas claro/escuro.
- Executar `composer test`, `composer lint`, MySQL 8, `npm run qa:mobile-layout`, `npm run lint`, `npm run build` e smoke autenticado antes da homologação.

## QA real
Os testes estão **escritos**, mas não houve nesta sessão execução de PHPUnit/Pint/MySQL, build/lint React ou inspeção visual real. A macro codificada não representa produção homologada; não inventar porcentagem de conclusão global.
