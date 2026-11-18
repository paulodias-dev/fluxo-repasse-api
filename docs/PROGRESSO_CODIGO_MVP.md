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

## Após Macroentrega 44 — atualização proposta do CÓDIGO (sem homologação)
| Etapa | Peso | Cobertura estimada | Contribuição |
|---|---:|---:|---:|
| 1. Acesso/cadastros | 10% | 100% | 10% |
| 2. Veículos 360 | 20% | 100% | 20% |
| 3. Financeiro gerencial | 20% | 100% | 20% |
| 4. Cenários/prestação documental | 15% | 100% | 15% |
| 5. Preparação e motor executável | 30% | **43% (estimativa)** | 12,9% |
| 6. Dashboard/integração | 5% | 100% | 5% |
| **Total de código estimado** | **100%** | — | **82,9% ≈ 83%** |

A macro 44 acrescentou uma nova capacidade concreta, o registro interno versionado e imutável de apurações **hipotéticas**, reutilizando minutas e rateio. Não a contar como obrigação, pagamento, aprovação monetária ou motor bancário. O indicador continua uma ponderação provisória, não auditoria funcional automatizada. O valor da `main` antes da integração da macro 44 é 82%; com os PRs API/APP integrados fica estimado em **83%**, sem QA na fórmula.

**Prioridades de código restantes:** aprovar fonte de regras financeiras, titularidade e fatos geradores, política executável de taxas/tributos, alçadas e aprovações, eventuais obrigações/ledger, integração PSP, conciliação, reembolsos e isolamento organizacional se exigido. Uma decisão explícita por MVP sem dinheiro real mudaria o denominador e exigiria recalcular a métrica.

## Após Macroentrega 45 — indicador de implementação (provisório)
| Etapa | Peso | Cobertura estimada de código | Contribuição |
| --- | ---: | ---: | ---: |
| 1. Acesso e cadastros | 10% | 100% | 10% |
| 2. Veículos 360 | 20% | 100% | 20% |
| 3. Financeiro gerencial 360 | 20% | 100% | 20% |
| 4. Cenários e prestação documental | 15% | 100% | 15% |
| 5. Preparação + motor monetário executável | 30% | **46% estimados** | 13,8% |
| 6. Dashboard e operação | 5% | 100% | 5% |
| **Total código MVP** | **100%** | — | **83,8% ≈ 84%** |

Macro 45 entrega documento interno de proposta derivada da apuração manual: pagador declarado pelo operador, rateio congelado em centavos, história imutável, CSV, fonte stale e verificação SHA. É uma preparação de pré-repasse e **não constitui obrigação real nem motor executável**. Avanço da etapa 5 é estimativa editorial de +3 p.p. sobre a cobertura de código anterior (43% -> 46%). Homologação, QA, deploy e aceitação excluídos da matriz, por orientação do responsável. Regras financeiras/PSP, alçadas, conciliação e isolamento organizacional continuam sem aprovação; não inflar o índice pela contagem de PRs.

## Após Macro 48 — registro de transferências externas informadas (estimativa de código)
| Etapa | Peso | Cobertura estimada de código | Contribuição |
|---|---:|---:|---:|
| 1. Acesso, usuários, permissões e cadastros | 10% | 100% | 10% |
| 2. Veículos e ficha 360 | 20% | 100% | 20% |
| 3. Financeiro gerencial | 20% | 100% | 20% |
| 4. Cenários e prestação documental | 15% | 100% | 15% |
| 5. Preparação e execução de repasses | 30% | **50% estimados** | **15%** |
| 6. Dashboard e integração | 5% | 100% | 5% |
| **Total de implementação estimado** | **100%** | — | **85%** |

Comparado ao baseline de ~84%, a Macro 48 adiciona **registro, anexação de prova, histórico, estorno e exportação de transferências EXTERNAS reportadas por operador**. Este recurso NÃO executa pagamento e NÃO verifica liquidação bancária. Não foram aprovadas regras de comissões, tributos, obrigação exigível, alçadas, PSP, reconciliação, disputas ou organização/tenant. A evolução de ~1 p.p. é editorial e não é homologação. Nunca apresentar 85% como prontidão para uso bancário, nem declarar testes executados sem logs efetivos.

## Revisão após Macro 49 — execução do código sem homologação
- A etapa de preparação e controle financeiro passa de aproximadamente 50% para 53,3% de cobertura estimada, mantendo peso de 30 pontos da matriz.
- Os demais blocos mantêm 65 pontos estimados de contribuição, totalizando **~86% do código MVP**.
- Diferença +1 p.p. é avaliação editorial de usabilidade: registro de repasse externo sem minuta, com o mesmo histórico/prova/estorno.
- NÃO implementar nem contar como entregue: motor de pagamento bancário, aprovação de obrigação exigível, impostos/comissões/retencões, PSP, conciliação, liquidação e regras multi-organização não aprovadas. Homologação não entra na porcentagem.
- Testes adicionados, execução e QA não atestados.

## Após Macro 51 — revisão de escopo SaaS aprovado
Arquitetura SaaS multiempresa com tenant_id e seeders faz parte do novo escopo obrigatório e não estava contabilizada na matriz antiga. **O percentual anterior (~86%) deixa de ser uma medição suficiente** do MVP revisado. Não elevar o indicador sem matriz atualizada, validação do isolamento e conclusão do motor financeiro real conforme decisões.
