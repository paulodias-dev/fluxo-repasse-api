# Evolução do código do MVP — linha-base proposta

> Indicador **apenas de implementação**. Exclui totalmente homologação, QA, build, inspeção visual, deploy e aceite. Não equivale a prontidão operacional de produção nem à execução de pagamentos. Proposta de pesos baseada nas seis etapas funcionais do roadmap, ainda sujeita a aprovação do responsável pelo produto.

## Após Macro 43 (17/11/2026)
| Etapa | Peso | Cobertura estimada de código | Contribuição |
|---|---:|---:|---:|
| 1. Acesso, usuários, permissões e cadastros | 10% | 100% | 10% |
| 2. Veículos e ficha 360 | 20% | 100% | 20% |
| 3. Financeiro gerencial 360 | 20% | 100% | 20% |
| 4. Cenários, revisões, prestação de contas | 15% | 100% | 15% |
| 5. Preparação e motor executável de repasses | 30% | **40% estimados** | 12% |
| 6. Dashboard e integração operacional | 5% | 100% | 5% |
| **Total estimado** | **100%** | — | **82%** |

A etapa 5 não foi confundida com pagamento efetivo. A Macro 43 acrescentou à base existente uma revisão unificada, atualidade do parecer, rateio auditável, avaliação de bloqueios, fila de correção e exportações. O percentual de 40% nesta etapa é uma avaliação **conservadora e ajustável**, não um número obtido de contagem de arquivos, PRs ou testes.

## Restante de código para o MVP com movimentação real
- Regras aprovadas de origem, pagador, beneficiários e titularidade; base contratual e fato gerador.
- Configuração executável de taxas, comissão, impostos, retenções, arredondamentos, prazos e alçadas, sem presumir políticas.
- Registro transacional de obrigações/ledger e fluxo de aprovação monetária com idempotência, segregação e concorrência.
- Integração PSP/bancária e processamento de liquidação/falhas, conciliação, reembolsos e estornos com auditoria.
- Decisão single-org versus multi-tenant e isolamento organizacional se exigido no MVP.

**Bloqueio de definição:** `docs/REQUISITOS_PENDENTES.md` permanece a fonte de decisões NÃO aprovadas. Nenhum código de pagamento real poderá assumir configurações padrão imaginadas.

## Diferença para o indicador antigo
O histórico `docs/PROGRESSO_GERAL.md` (6/12=50%) mede marcos técnicos com homologação; **não** deve aparecer como progresso de código MVP. Estimativa anterior baseada nestes pesos: ~80%; após Macro 43, ~82%, mantendo QA completamente fora do cálculo.
