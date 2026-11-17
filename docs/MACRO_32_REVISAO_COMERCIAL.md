# Macroentrega 32 — Tramitação documental de condições comerciais

## Escopo de negócio
Completar o fluxo de análise HUMANA de minutas por veículo, após macros 30 e 31, sem presumir regras contratuais, financeiras ou bancárias.

### Funcionalidades integradas
1. Criar solicitação para revisão exata e SHA-256 da minuta; chave UUID idempotente, justificativa obrigatória, revisor e prazo opcionais.
2. Impedir envio duplicado de versão pendente ou já conferida, preservando rodadas anteriores.
3. Fila global com solicitado por mim/atribuído a mim, filtros, busca, paginação de 20, indicadores.
4. Atribuir revisor ativo com permissões exigidas e segregação de funções.
5. Comentar apenas análises pendentes, com histórico de eventos e autor.
6. Retirar solicitação apenas pelo solicitante, com justificativa e revisão otimista.
7. Conferência documental reviewed ou pedido de correções changes_requested com justificativa. Reviewed exige dez campos e vínculos atuais.
8. Bloquear decisão se minuta, modelo, veículo, participantes ou anexos estiverem desatualizados/inativos.
9. Consultar snapshot histórico imutável e cadeia SHA-256 de eventos, verificável internamente.
10. Navegação pelo Metronic original: sidebar, dashboard, Central Comercial 360 e Veículo 360.
11. Migration aditiva e testes de regressão escritos; sem novo grant técnico.

### Banco
repasse_commercial_reviews: term_id, chave idempotente, revisões e digests, status, solicitante, revisor, decisor, justificativas e datas.
repasse_commercial_review_events: sequência, tipo, autor, mensagem, detalhes JSON, previous_hash e event_hash.
Sem exclusão de dados ou migração destrutiva.

### API
- GET e POST /api/v1/commercial-reviews
- GET /api/v1/commercial-reviews/{review}
- POST /api/v1/commercial-reviews/{review}/assign, /decide, /comment, /withdraw
- GET /api/v1/commercial-reviews/{review}/events, /snapshot, /integrity

JWT + session.current + cases.manage + scenarios.manage para acessar. scenario_reviews.manage adicional para atribuir ou decidir. Autor ou editor da revisão não pode decidir sobre a própria minuta.

### Guardrails
Esta implantação continua single-org: sem promessa de isolamento tenant. O parecer reviewed é documental, jamais aceitação jurídica, aprovação de contrato ou liberação de pagamento. A cadeia de hashes é evidência interna, não assinatura externa/certificação WORM. Valor de referência continua hipotético, não existe comissão padrão, saldo, Pix, PSP ou transferência. A verificação completa das partes/anexos é executada nos detalhes/decisão, nunca inferida da listagem.

### Implantação / QA
Aplicar migration aditiva no banco autorizado após backup e antes da aplicação React. Testes feature foram escritos, mas execução de PHPUnit/Pint, migrations MySQL, lint/build React e navegador permanece pendente conforme pedido do operador. Não usar migrate:fresh em banco com dados.
