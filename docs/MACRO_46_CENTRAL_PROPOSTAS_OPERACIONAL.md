# Macroentrega 46 — Central Comercial com propostas internas

## Objetivo
Visualizar, filtrar e exportar **propostas documentais não exigíveis de todos os veículos** sem abrir cada ficha, reutilizando a Central Comercial 360 já existente. Nenhum módulo, menu, tabela, migration, permissão, integração bancária ou fluxo de pagamento novo.

## Jornada completa
1. Em **Central Comercial 360**, escolher filtros de minuta (situação, texto, preparação documental) e **Propostas** (todas, com propostas, sem propostas, minuta alterada depois da proposta).
2. A API devolve **uma linha por minuta**, com total de propostas, ID/data/valor hipotético da última, responsável declarado e indicação de fonte.
3. O indicador "Minuta alterada após proposta" somente se aplica à alteração comprovável do timestamp da minuta, comparado com a data da última proposta. Se não houve tal alteração, indicar **Conferir origem SHA na ficha**. Não declarar que o snapshot está atual: alteração em financeiro, revisão e vínculos exige conferência integral no detalhe.
4. O React mostra KPIs compactos em duas colunas mobile, tabela no desktop e botão **Ver proposta** para abrir o veículo, minuta e proposta exatos por deep link, inclusive fora da primeira página do histórico.
5. **Exportar CSV filtrado** reutiliza os mesmos critérios da consulta e inclui colunas de contagem, última proposta e fonte, com sanitização anti-fórmulas.

## API
Continuam os endpoints existentes:
- GET /api/v1/commercial-portfolio
- GET /api/v1/commercial-portfolio/export

Filtro novo: proposals = all | with_proposals | without_proposals | term_edited. Demais filtros preservados.

Na resposta:
- summary.terms_with_proposals
- summary.terms_without_proposals
- summary.terms_edited_after_proposal
- summary.proposal_records_total
- terms.data[].proposal_count, last_proposal_id, last_proposal_created_at, last_proposal_net_cents, last_proposal_payer_name, proposal_source_state

A consulta usa uma subconsulta agrupada por term_id para evitar N+1, duplicação em JOIN ou paginação antes do filtro. Contagens refletem **o conjunto filtrado completo**, não só os 20 itens da página. Propostas anteriores não são atualizadas nem descartadas.

## Segurança
JWT Bearer, session.current, cases.manage E scenarios.manage como na Central Comercial existente. Nenhum campo é cobrável, pago ou liquidado. Não cria despesas gerenciais ou saldos, comissões, faturas, obrigações exigíveis, Pix, PSP ou transferência. CSV privado UTF-8, BOM, no-store, anti-fórmula. Não deduzir política financeira da documentação livre nem afirmar SHA válido só pelo timestamp.

## Testes e homologação
Testes feature adicionados: filtros com/sem/alterada, combinação com status/parecer, contagens antes da paginação (21 minutas), duas propostas na mesma minuta, CSV correspondente e ausência de obrigação. Verificação de layout e deep link adicionada ao script qa:mobile-layout.

Os testes foram **escritos, não executados no runtime do GitHub**. PHPUnit/Pint/MySQL, build/lint e QA visual mobile/desktop permanecem para a homologação ao final do código MVP.

## Continuação
A implementação do dinheiro real permanece bloqueada por decisões contratuais e operacionais não aprovadas. Esta macro melhora a operação documental sem inflar a estrutura nem deslocar o foco da frota. O progresso do código MVP fica aproximadamente **84%** na matriz proposta, sem inflar o percentual por funcionalidade não executável.
