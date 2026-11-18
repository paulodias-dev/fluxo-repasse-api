# Requisitos pendentes — fonte de verdade do negócio

## Atualização formal do MVP em 18/11/2026 (prevalece sobre perguntas históricas)
As perguntas mais antigas abaixo foram redigidas quando o escopo não estava aprovado.
As decisões posteriores das Macros 49, 51, 52 e 53 **substituem** essas indefinições:
- MVP SaaS de banco único, tenant_id obrigatório, usuário vinculado a uma empresa por sessão JWT, perfis Administrador e Usuário.
- Veículo 360 registra compra, venda, despesas, receitas e participantes.
- Repasses são **manuais**: venda datada obrigatória, base declarada pelo operador,
  valor fixo ou percentual explícito, comissão expressa, taxa/imposto manual.
- Distribuição rascunho e aprovação com concessão independente (autor pode aprovar se
  autorizado), parcelas externas com comprovante opcional privado, UUID idempotente,
  estorno/cancelamento justificado e trilha auditável.
- Relatos externos anteriores são **fontes diferentes**: sem baixas cruzadas automáticas.
- Não integrar PSP, PIX, remessa, conciliação bancária nem deduzir tributo automaticamente
  no MVP aprovado. Estas funções exigiriam um novo escopo explícito.
- Encerramento exige ciência de repasses pendentes, sem quitação automática.

**Pendente antes de considerar 100% do código:** análise final de invariantes de
pagamentos/estornos e alçadas, contratos e UX de integração; realizar depois
homologação separada em MySQL e dispositivos. O histórico a seguir NÃO reabre
as decisões já aprovadas.

O conteúdo do Gemini não foi acessível. Estes pontos são **perguntas**, não decisões:
- Objetivo e jornada de cada persona; hierarquia de usuários, papéis e aprovação.
- Origem/destino dos valores, eventos geradores, comissão, retenção, impostos, cálculo, arredondamento, moeda e limite.
- Fluxos de criação, validação, aprovação, liquidação, conciliação, cancelamento, estorno, retentativa e disputa.
- Integrações com bancos/gateways, webhooks, saques e condições jurídicas.
- Single-org ou multi-tenant; privacidade, LGPD, documentos, auditoria e retenção.
- Autenticação (JWT ou outra estratégia), refresh/revogação, MFA, recuperação de acesso.
- Relatórios, exportações, notificações, integrações e escopo do MVP.
Nenhuma regra deve ser implementada ou colocada em produção sem aprovação rastreável em documento.

## Atualização após Macro 13 — base de cadastros já implementada

O módulo `Pessoas e Empresas` foi criado **sem atribuir papéis financeiros** aos registros: pessoa/empresa não equivale necessariamente a favorecido, pagador, intermediador, unidade ou cliente. Armazena apenas tipo, nome, contato, observações internas, status e histórico com operador.

Para avançar nos próximos módulos sem retrabalho, faltam as regras originais sobre: **quem paga, quem recebe, vínculo entre empresas/pessoas, fato gerador do repasse, taxas/comissões, aprovações, prazos, estornos e conciliação**, além da decisão single-org/multiempresa.

Essas definições não foram encontradas no link original do Gemini. Não inventar essas regras nem criar saques, saldos e transações até validação do escopo.

Prioridade formal: **módulos de produto antes de novos ciclos amplos de QA**. Testes devem acompanhar o módulo entregue, não constituir uma macro isolada.


## Macro 18 — avanço financeiro sem regras inventadas

Foi criado exclusivamente um **planejador de cenários hipotéticos**, não um motor de repasse executável. O valor total e todas as alocações são informados manualmente pelo operador em centavos, com destino/origem previstos e vinculados ao dossiê. Estado balanced significa só diferença aritmética zero.

Ainda faltam decisões formais: quem é de fato pagador e beneficiário; evento que gera a obrigação; critérios de cálculo, comissão, retenções, tributos, moeda e arredondamento; processo de aprovação; quando uma obrigação vira pagamento; banco/gateway; conciliação; estorno e disputa; política multi-organização, limites, segregação de funções e conformidade. Nunca afirmar que a macro 18 implementou essas regras.

A permissão técnica scenarios.manage exige cases.manage e não cria direitos de pagamento.


## Após a Macro 19

Existe motor de **planejamento e simulação**, com modelos percentuais configurados manualmente, arredondamento determinístico e lotes atômicos. Isso NÃO define política contratual de comissão, imposto, retenção, cobrança, autorização, recebíveis, estorno ou pagamento. Os cenários permanecem não executáveis mesmo com soma conferida.

Antes de construir a camada financeira executável, aprovar de forma rastreável: modelo single-org versus isolamento por tenant; origem/beneficiário e titularidade; fato gerador; critérios de comissão/rateio e arredondamento contratual; impostos/retenções; níveis de autorização; ledger; liquidação e conciliação; gateway/PSP e eventos de webhook; cancelamentos e estornos. Não aplicar regra de outro projeto automaticamente.


## Macro 20 — revisão documental NÃO é alçada de pagamento

A macro 20 adiciona conferência humana segregada para snapshots de cenários. O novo status reviewed significa documentação conferida, enquanto changes_requested solicita correções. Mesmo após "reviewed", nenhuma obrigação, comissão, liquidação, saldo, ordem bancária, pagamento ou conciliação é criada.

Não inferir que operadores com scenario_reviews.manage possuem alçada monetária. Regras de liberação financeira, papéis de aprovadores, dupla autorização, beneficiários, contratos, pagamentos, gateway/PSP, impostos, retenções, estornos e isolamento por organização continuam por aprovar, sem material original recuperado. Não ligar reviewed ao motor bancário futuro automaticamente.


## Macro 21 — pacotes de prestação de contas documental

O produto agora possui um fluxo de fotografias históricas de dossiê com manifesto SHA-256 e ZIP privado, integrando notas, tarefas, checklist, documentos, cenários e pareceres existentes. Isso não transforma os documentos em comprovante financeiro: não existe autorização monetária, retenção bancária, pagamento, taxa contratual ou liquidação.

Políticas de compartilhamento externo, retenção LGPD e isolamento entre organizações seguem pendentes. Nesta macro, pacotes são **privados a operadores internos** com permissões explícitas, e não há convites públicos ou links assinados.

O ZIP é gerado somente com evidências ainda íntegras no armazenamento local privado. Assinatura digital externa, timestamp de terceiro, guarda WORM e certificação jurídica não foram implementados nem presumidos.

## Macro 30 — Condições comerciais declaradas, sem operação financeira
O sistema agora permite **registrar propostas de condições por veículo** sem inventar sua validade ou taxas: dez campos de decisão manual (fato gerador, base, comissões, retenções, elegibilidade, alçadas, liquidação, cancelamentos, integração e isolamento) podem ficar em branco. Cada minuta referencia revisão SHA de um modelo existente e pode ser copiada, revisada, arquivada e consultada historicamente.

**Dados inseridos em campos de texto não viram regra computável ou aprovada.** Mesmo que a matriz mostre 'documentary_complete=true', isso representa somente preenchimento e vínculos ativos, não assinatura, alçada, exigibilidade, dívida, direito de recebimento ou autorização de pagamento.

Para construir a **etapa executável**, o operador ainda precisa definir e aprovar explicitamente: quem compra/vende, titularidade/pagador/beneficiário, valor-base contratual, impostos, comissão, momento e forma de liquidação, segregação entre organizações, alçada de aprovação, PSP/banco, estorno, reembolso e conciliação.


## Após Macro 32 — Parecer comercial exclusivamente documental
Um operador pode solicitar conferência de revisão histórica de minuta e outro, com permissão, registrar reviewed (conferência de completude documental) ou changes_requested. Não equivale a aceite jurídico, assinatura, aprovação de condição comercial ou liberação financeira. Atualizar minuta, modelo ou vínculos bloqueia decisões antigas. Continuam sem definição aprovada: titularidade, pagador/favorecido legal, evento financeiro, base/cálculos, comissão, tributos, alçadas monetárias, liquidação, PSP/contas, estornos, conciliação e isolamento entre organizações.


## Macroentrega 43 — Pré-repasse verificável (uma entrega API + APP)
- [x] Consulta `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation` reúne minuta íntegra (SHA), modelo congelado, rateio em centavos, 10 condições, partes/evidências atuais, parecer documental da **mesma revisão e SHA**, valor de referência manual e margem gerencial da venda.
- [x] Motor de bloqueios documentais: condições ausentes, minuta/veículo arquivados, modelo desatualizado, partes/evidências inativas, valor manual ausente e falta de parecer current reviewed. Mesmo se sem pendências, `financial_policy_approved=false`, `obligation_created=false`, `payment_enabled=false` e `executable=false` são invariantes.
- [x] SHA-256 da fotografia de leitura (sem assinatura jurídica/bancária), CSV privado anti-fórmula por minuta, sem tabelas/migrations/autorizações/gateway/ledger ou transferências.
- [x] Central Comercial: filtros globais por `preparation` antes de paginar, contadores de parecer atual/pendente e referência ausente, CSV com o mesmo recorte. Parecer histórico é ignorado quando a minuta muda de revisão/SHA.
- [x] React Metronic: cartões mobile `col-6`, tabela desktop preservada, deeplink por minuta e pré-repasse aberto sob demanda dentro da ficha, com exportação, pendências e acesso aos controles existentes.
- [x] OpenAPI 1.19, testes feature adicionados aos módulos de minutas e central comercial, script de regressão do layout ampliado.
- [ ] Sem pagamentos reais: faltam decisões APROVADAS de titularidade, fato gerador, comissão/base, retenções, alçadas, PSP/conta, conciliação, estorno, isolamento organizacional. Não inferir com base nas dez declarações livres.
- [ ] PHPUnit, Pint/MySQL, npm build/lint e inspeção browser NÃO executados nesta sessão; homologação expressamente fora da métrica de código.

**Estimativa de código MVP completo:** ~82% em matriz PROPOSTA de pesos (10/20/20/15/30/5), versus ~80% da medição anterior; etapa 5 vai de ~33% para ~40% de cobertura preparatória, sem implicar motor executável. Matriz em `docs/PROGRESSO_CODIGO_MVP.md`. Não usar o histórico técnico antigo 6/12.


## Macroentrega 44 — Apuração manual versionada e imutável do pré-repasse
- [x] `POST /repasse-cases/{case}/commercial-terms/{term}/preparation/records`: congela referência manual da minuta e até 20 acréscimos/deduções em centavos; net = referência + acréscimos − deduções, positivo e <= 10^12, alocado via DistributionCalculator existente (10000 bps e maior resto). **Não cria obrigação real, saldo, lançamento gerencial ou transferência.**
- [x] `GET .../records`, `GET .../records/{record}`, `GET .../records/{record}/export`: histórico paginado e somente leitura, SHA-256 canônico cross-MySQL/SQLite, fonte `stale` quando referência, contrato, parecer ou valores mudam; CSV UTF-8 privado anti-fórmula.
- [x] Uma tabela aditiva **append-only** `repasse_preparation_records` com request_key UUID UNIQUE, payload_digest, snapshot JSON/SHA, source SHA, soma de centavos, origem de revisão, autor, timestamp; nenhuma edição/exclusão de snapshots.
- [x] Segurança JWT + session.current + cases.manage E scenarios.manage + throttles existentes. Criação só se ficha/minuta ativas, referência explícita, SHA esperado atual, fonte íntegra e percentuais explícitos do modelo já existente; reuso de request_key com payload divergente retorna 409, retry com conteúdo e ator iguais não duplica.
- [x] FormRequest para contratos, validação estrita e 4 novos testes feature escritos: centavos, replay idempotente, staleness, integridade, multi-veículo, RBAC, valores inválidos e paginação de 17 itens.
- [x] Frontend React na aba já existente da ficha comercial, formulário compacto, histórico imutável, indicadores de valores, navegação por páginas, exportação CSV e integração mobile-first.
- [x] OpenAPI 1.20, documentação/roadmap e baseline de evolução **somente código** atualizados.
- [ ] Regras de obrigação/pagamento real, comissões legais, retenções, alçadas e banco continuam **sem aprovação de escopo**; o código mantém todos os campos financeiros executáveis como false.
- [ ] PHPUnit/Pint/MySQL, npm lint/build, browser e aceite ficam na fase de homologação após MVP, conforme orientação do usuário.
