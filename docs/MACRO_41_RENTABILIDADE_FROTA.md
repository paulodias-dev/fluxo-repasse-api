# Macroentrega 41 — Rentabilidade da frota / filtro global por margem de venda

## Entrega completa em dois repositórios
- **API:** `paulodias-dev/fluxo-repasse-api`.
- **APP:** `paulodias-dev/fluxo-repasse-app`.
- Uma única macro de negócio; o backend processa filtros/totais e React mostra comparativo **na tela já existente Meus veículos**, sem criar tela ou módulo novo.
- Layout aprovado na main do Metronic mantém cartões mobile e tabela desktop. A APP formaliza o contrato mobile-first em `AGENTS.md`.

## Contrato de cálculo
A API usa exclusivamente registros financeiros com `voided_at IS NULL` e as agregações por veículo existentes:
```text
margem_venda = sale_amount_cents
               + income_realized_cents
               - purchase_amount_cents
               - expense_realized_cents
```
Só existe margem **apurável** quando há `sold_on`, compra e valor da venda. Margem positiva, negativa e zero são categorias distintas. Compra `NULL` nunca é tratada como 0; compra 0 explicitamente registrada é válida. Venda datada NÃO confirma recebimento bancário; margem gerencial NÃO equivale a lucro contábil ou tributo devido. Projeções com valores previstos continuam separadas e NÃO alteram os filtros de venda apurada.

## Contrato de filtros
GET `/api/v1/operations/vehicles?performance=...`:
- `all`: todos.
- `sold_profit`: venda datada com margem positiva.
- `sold_loss`: venda datada com margem negativa.
- `sold_break_even`: venda datada com margem igual a zero.
- `sold_unknown`: venda datada sem compra/valor de venda conhecido para cálculo.
- `not_sold`: sem data de venda; pode ter estimativa de preço.
- `purchase_missing`: compra desconhecida, independentemente de ter sido vendido.

Combina com `status`, `attention`, `search` e `page`. Não altera seus significados. As contagens de summary são do conjunto autorizado inteiro, inclusive arquivados, NÃO da página filtrada. Filtragem está no SQL, não no `array.filter` do navegador.

Ordenação:
- `sort=sale_result_desc`: maior resultado de venda real primeiro.
- `sort=sale_result_asc`: menor resultado (maior prejuízo) primeiro.
- Em ambos, valores conhecidos precedem NULL, empate ordenado por id DESC, tudo no SQL antes do `paginate(15)`.

## Indicadores de summary
`vehicles_sold`, `vehicles_without_sale`, `vehicles_missing_purchase`, `vehicles_sold_profit`, `vehicles_sold_loss`, `vehicles_sold_break_even`, `vehicles_sold_without_result`, `sale_profit_total_cents` e `sale_loss_total_cents`. Os dois totais são int64 assinados (prejuízos negativos). `sale_result_known_total_cents` anterior permanece o saldo líquido das vendas datadas com margem conhecida. Os demais campos antigos não mudaram.

## Proteções
Somente `cases.manage` com JWT Bearer e sessão atual, `Cache-Control: no-store, private`, FormRequest com enum validado, 422 para valores inválidos. Sem alterações de autorização, schemas de banco, mutações, gateway, novos endpoints ou tabelas. Somatórios continuam pré-agregados por `repasse_case_id` para não multiplicar centavos ao combinar tarefas/anexos.

## Critérios de aceite (API + navegador)
1. Criar vendas positiva, negativa, zero, desconhecida, não vendida e compra não informada; conferir filtros e summary.
2. Receitas/despesas realizadas alteram margem; previstas ou estornadas NÃO alteram venda já datada.
3. Compra 0 informada não é NULL. Sem valor de compra, resultado fica a apurar.
4. Arquivado com resultado permanece nos totais comerciais e responde a `performance`; mas não reaparece como tarefa operacional ativa.
5. 19 veículos: ordem por margem desc/asc antes da paginação, estabilidade dos ids e NULL por último.
6. Filtros de performance + busca + status + attention preservados e composicionáveis; retornar página anterior mantém URL.
7. 401 anônimo/JWT inválido, 403 sem `cases.manage`, 422 enums desconhecidos.
8. Interface mobile 320/375/390px com duas colunas de KPIs e cartões; desktop 1024/1440px preserva tabela completa e tema claro/escuro.
9. Total positivo e prejuízo negativo exibidos separados para não ocultar resultado desfavorável na compensação.
10. Execuções obrigatórias antes do aceite: `composer test`, `composer lint`, QA MySQL 8, `npm run qa:mobile-layout`, `npm run build`, `npm run lint` e smoke autenticado React/API.

## Situação de testes
Feature tests **escritos**, mas PHPUnit/Pint/MySQL/build/browser NÃO executados nesta sessão. O PR não equivale a produção homologada. Não divulgar % global arbitrário.
