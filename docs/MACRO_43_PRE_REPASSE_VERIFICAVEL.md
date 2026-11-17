# Macroentrega 43 — Pré-repasse verificável, fila comercial e relatório privado

## Objetivo concluído em código
Uma **única macro** entrega API + React no Metronic original, sem duplicar módulos. Não se trata de liquidação: a intenção é reunir, por minuta e veículo, as informações já produzidas nas Macros 19, 30, 31 e 32 e indicar quais decisões faltam antes de construir o motor executável.

## Percurso do operador
1. Central Comercial 360: pesquisar por veículo/placa/minuta, aplicar filtro de preparação `all`, `reviewed`, `needs_review`, `reference_missing` ou `model_outdated`, e localizar resultados por página. Filtros sempre no SQL antes do `paginate(20)`. Contadores do conjunto de filtro aplicado.
2. Cartão mobile de minuta e tabela desktop mostram **parecer documental da revisão atual**, referência manual, preenchimento e estado do modelo. CSV da central respeita os filtros.
3. **Conferir preparo** leva diretamente a `/vehicles/{case}?tab=commercial&term={term}` e abre a minuta e sua avaliação sem criar outra tela. A consulta só acontece quando o painel está aberto; pode atualizar e exportar CSV.
4. A avaliação mostra cartões em duas colunas (referência manual, resultado de venda gerencial independente, valor hipotético distribuído e parecer), rateio com participantes e pesos, diferença matemática, bloqueios documentais e links de correção.
5. Relatório CSV privado `.../preparation/export` traz SHA, referências, rateio e pendências sem gerar débito ou instrução de pagamento.

## Contratos API
- `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation`
- `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/export`
- `GET /api/v1/commercial-portfolio?preparation=all|reviewed|needs_review|reference_missing|model_outdated`
- `GET /api/v1/commercial-portfolio/export?preparation=...`

Duas permissões `cases.manage` E `scenarios.manage`, JWT Bearer com `session.current`, throttle de leitura e `no-store, private`. Minuta/veículo fora do escopo: 404. SHA inválido ou conteúdo adulterado: 409, antes do CSV. `reviewed` só é reconhecido para `term_revision` e `term_sha256` atuais. A Central Comercial exibe triagem resumida, não finge ter validado assinaturas, evidências ou partes de todas as páginas.

### Cálculos
A simulação continua usando `DistributionCalculator` da Macro 19, com soma de 10000 basis points e distribuição determinística em centavos. `manual_reference_cents` é entrada **explícita** do operador, não contrato financeiro nem margem automaticamente. `sale_result_known_cents` é calculado somente se houve venda datada, compra e venda conhecidas:
```text
venda + receitas_extras_realizadas - compra - despesas_realizadas
```
Previstos/estornados não entram no resultado. A comparação é visual, não um evento gerador de repasse. Sem valor manual, `allocations=[]` e diferença `null` (não zero falso).

### Segurança e limites
- Nunca inserir linhas em `repasse_cases`, `repasse_commercial_terms`, `repasse_scenarios` ou qualquer suposto ledger durante a consulta; nenhuma migration ou permissão nova.
- Mesmo com todos os campos preenchidos, modelo atual e parecer `reviewed`, **sempre** `financial_policy_approved=false`, `financial_authorization=false`, `obligation_created=false`, `payment_enabled=false`, `executable=false`.
- `snapshot_sha256` confirma consistência interna de uma leitura, **não é** assinatura externa nem prova de permanência dos dados em WORM.
- CSV UTF-8 BOM, separador `;`, neutraliza injeção de fórmulas, sem dados bancários nem links públicos.
- A edição da minuta altera revisão e SHA, invalidando o parecer da versão antiga para a fila e relatório.
- Nenhuma decisão foi presumida sobre taxação, comissionamento, alçadas, PSP, conta, estorno, conciliação e multi-organização.

## Aceite funcional esperado
1. Minuta em branco: 10 campos ausentes e falta de parecer atual, relatório não executável; margem gerencial só se compra/venda datadas conhecidas.
2. Minuta com referência 10001 e rateio 60/40: valores hipotéticos 6001 e 4000 centavos, diferença zero; renda/despesa prevista não entra em resultado gerencial.
3. Revisão de todos os campos e parecer humano independente `reviewed` => `documentary_preparation_complete=true`, porém `payment_enabled=false`.
4. Alterar revisão de minuta após parecer => `review_state=not_requested` e fila `needs_review`; filtro `reviewed` não retorna o registro.
5. JWT 401, permissão parcial 403, outro veículo 404, SHA adulterado 409.
6. Exportação da central não expõe registro de outro filtro, e CSV por minuta inclui SHA do snapshot; não faz nenhum write.
7. UI a 320/375/390px: KPIs e simulação em duas colunas, cartões da central; desktop 1024/1440 com tabela completa; Metronic original, tema claro/escuro.
8. Executar PHPunit/Pint/MySQL, npm lint/build, smoke autenticado e QA visual **após codificação do MVP**, conforme decisão do usuário.

## Status
Código escrito em branches e PRs da Macro 43. Validação cruzada estática realizada, execução real das suítes ainda não realizada. Métrica estimativa de código, excluindo homologação: ver `docs/PROGRESSO_CODIGO_MVP.md`.
