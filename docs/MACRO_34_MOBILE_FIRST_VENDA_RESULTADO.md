# Macroentrega 34 — Mobile-first de ponta a ponta

## O que o usuário consegue fazer sem atrito
1. Abrir **Meus veículos** no smartphone e localizar um veículo pelo nome/placa, sem tabela horizontal.
2. Tocar **+ Despesa** ou **+ Receita** no card, preencher tipo, valor, descrição e categoria. Escolher se é realizada/prevista e opcionalmente data; salvar.
3. Consultar o comprovante diretamente no detalhe do lançamento e fazer retificação/estorno auditável sem navegar a novos sistemas.
4. Tocar **Registrar venda**; informar **valor e data**. Nome do comprador é opcional. A mesma ficha já exibe o resultado gerencial.
5. Voltar à frota e ver os números reais. Dashboard separa apenas vendas datadas do resultado projetado.
6. Em telas grandes, mantém a tabela e as mesmas ações. Funções técnicas são secundárias sem eliminar módulos operacionais existentes.

## Contratos e semântica
- PATCH /api/v1/repasse-cases/{case} com sale_amount_cents >0, sold_on AAAA-MM-DD e buyer_name opcional. Validação: se sold_on existe, sale_amount_cents positivo e sold_on >= purchased_on (quando informado).
- GET /api/v1/repasse-cases/{case}/finance/summary acrescenta sale_result_cents: null se compra, venda ou data ausente, ou (venda + receitas extras realizadas - compra - despesas realizadas). Despesas/receitas previstas ficam exclusivamente no projected_result_cents.
- GET /api/v1/operations/vehicles acrescenta sale_result_cents por veículo, vehicles_with_sale_result e sale_result_known_total_cents sobre a frota autorizada, não sobre página filtrada. Margem pode ser negativa e não vira zero quando há campos nulos.
- Compra e venda continuam na ficha, não são lançamentos financeiros duplicados.
- O produto permanece single-org e com JWT/roles anteriores. Sem nova permissão, tabela, endpoint, tela ou gateway.

## Compatibilidade e dependências
Aplicar a API da Macro 34 primeiro; depois o React. Sem migration nova, não precisa executar migrate:fresh --seed. O usuário autorizou recriar banco de desenvolvimento se necessário, mas esta macro não precisa disso.

## QA e limites
Testes Feature foram ampliados para vender somente com data/preço, barrar venda anterior à compra, calcular margem apenas após venda datada, excluir estornos e manter previsto fora do realizado. Ainda **não executados**. Smoke navegador mobile/desktop, lint/build React e verificação de rotas/consultas continuam **pendentes de homologação**, sem declaração de produção pronta.
