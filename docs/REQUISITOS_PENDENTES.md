# Requisitos pendentes — fonte de verdade do negócio

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
