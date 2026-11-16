# Macroentrega 20 — Governança de propostas e revisão documental independente

## Resultado

Uma macro funcional grande, integrada ao planejamento monetário já entregue nas macros 18–19, para que a equipe consiga **submeter, distribuir, acompanhar e registrar pareceres documentais** sobre versões específicas de cenários. O objetivo é operacionalizar a validação humana e a rastreabilidade de propostas, sem considerar um parecer como liberação monetária.

## Capacidades incluídas

1. **Solicitação por revisão congelada:** envia um cenário em versão exata (scenario_revision), contexto obrigatório, chave UUID de idempotência, prazo sugerido opcional e operador revisor opcional. Recusa dossiês/cenários arquivados, revisão obsoleta e solicitação duplicada sobre mesma revisão.
2. **Fila de análises:** busca por cenário/dossiê/solicitante, status, minhas atribuições, solicitadas por mim, sem responsável, vencidas, versões desatualizadas; paginação 20.
3. **Indicadores operacionais:** total, aguardando, conferidos documentalmente, correções solicitadas, retirados, vencidos, sem responsável, atribuídos a mim e obsoletos; indicador de aprovações financeiras permanece **zero**.
4. **Gestão de responsáveis:** lista somente usuários ativos com cases.manage + scenarios.manage + scenario_reviews.manage; atribuição e troca com revisão otimista, sem permitir atribuir ao solicitante/criador.
5. **Parecer independente:** operador com terceira permissão separada registra reviewed ou changes_requested, justificativa obrigatória, autor e data; solicitante/criador não pode revisar a própria proposta, mesmo que posteriormente ganhe permissão.
6. **Obsolescência automática:** compara revision + current_digest, além de status de dossiê/cenário. Uma alteração ou arquivamento bloqueia decisão (409), embora preserve o histórico antigo.
7. **Comentários e retirada:** trilha permanente de comentários; retirada somente por solicitante enquanto pending, com justificativa; histórico preservado.
8. **Snapshots e auditoria:** leitura da versão exata do cenário pelo histórico anterior validado por SHA-256 (valores em centavos, origem e destinatários); eventos com sequência, actor_user_id, payload e encadeamento previous_hash/event_hash, verificação de consistência interna.
9. **Relatório privado:** CSV autenticado, no-store, com solicitação, valores originais, alocações, status, decisões e comentários; neutralização de fórmulas. Não inclui dados bancários ou comandos de pagamento.

## Banco de dados — migration exclusivamente aditiva

2026_11_16_000006_create_scenario_reviews.php

- repasse_scenario_reviews: cenário, UUID idempotente, digest do payload inicial, revisão/digest do cenário, estado, responsável, solicitante, prazo, parecer, revisão otimista, datas.
- repasse_scenario_review_events: sequência imutável por API, tipo de evento, autor, texto, metadados JSON, hash anterior e hash do evento.

FKS restritivas preservam históricos, índices para filas e prazos. O hash encadeado detecta alteração isolada na trilha, mas **NÃO é uma garantia absoluta de imutabilidade externa** (DBA com acesso direto pode alterar banco/hash).

## APIs — 14 operações em docs/openapi.yaml v1.3

Base: /api/v1/finance

- GET /reviews/summary
- GET /reviews/reviewers
- GET /reviews
- GET/POST /scenarios/{scenario}/reviews
- GET /reviews/{review}
- POST /reviews/{review}/comment
- POST /reviews/{review}/withdraw
- POST /reviews/{review}/assign (permissão adicional de revisor)
- POST /reviews/{review}/decide (permissão adicional de revisor)
- GET /reviews/{review}/events
- GET /reviews/{review}/snapshot
- GET /reviews/{review}/integrity
- GET /reviews/{review}/export

Todas exigem JWT, sessão vigente, cases.manage e scenarios.manage. Para decidir/atribuir, exigem também scenario_reviews.manage. AdminUserSeeder inclui a nova permissão no papel platform_admin de forma idempotente, sem criar usuário/senha padrão.

## Estados e limites

- pending — aguardando parecer.
- reviewed — documentação conferida por pessoa independente. **Não é aprovação financeira**.
- changes_requested — ajustes documentais solicitados. Não cria obrigação nem bloqueia diretamente atualização de cenário.
- withdrawn — retirada por solicitante; não há exclusão do histórico.

Alterar o cenário após solicitação **não** altera automaticamente o parecer já emitido, mas marca is_stale=true e impede novos pareceres sobre uma pendência obsoleta. Uma nova revisão de cenário pode ser submetida para outra análise. Sem SLA/prazo padrão, comissão, taxa ou alçada inventada.

## Homologação proporcional e segurança

1. Backup autorizado e php artisan migrate; **não** migrate:fresh em banco persistente.
2. php artisan db:seed --class=AdminUserSeeder para provisionar somente permissão; renovar sessão do administrador.
3. php artisan test --filter=ScenarioReviewWorkflowTest. Cobrir 401/403, criador vs revisor, idempotência, 409, histórico, CSV e snapshot original.
4. vendor/bin/pint --test nos PHP alterados; verificar OpenAPI.
5. Login APP, solicitar revisão, atribuir operador diferente, registrar comentário, emitir parecer, alterar cenário e confirmar bloqueio stale, conferir CSV e hash; testar em mobile.
6. Nenhum endpoint emite pagamentos, aprova financeiramente, debita conta ou reconhece saldos.

**PHPUnit, MySQL, Pint, Vitest e build do APP ainda não foram executados nesta sessão.** Os testes são entregues em código para execução focalizada antes de merge/homologação.

## Pendências para produto financeiro real

Continuam sem especificação aprovada: pagador/beneficiário, fato gerador, comissão/rateio contratual, retenção, tributos, segregaçāo multi-organização, alçadas de liberação financeira, ledger, estornos, conciliação e PSP. O novo status reviewed não pode ser reutilizado para "pagar" nem alimentar um gateway.
