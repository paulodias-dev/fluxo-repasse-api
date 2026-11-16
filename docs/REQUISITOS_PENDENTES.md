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
