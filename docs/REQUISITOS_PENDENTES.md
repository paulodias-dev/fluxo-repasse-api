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
