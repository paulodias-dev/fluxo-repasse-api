# Macroentrega 61 — BI global / API

## Endpoints implementados
GET /api/v1/platform/analytics/overview?days=30&environment=production
GET /api/v1/platform/analytics/overview?from=2026-11-01&to=2026-11-23
GET /api/v1/platform/companies/{company}/360?days=30

Todos: bearer.only + auth:api + session.current + PlatformOperator (papel global platform_admin).
Não aceitam tenant_id vindo de usuário. Nada de chat, anexos, documentos, conteúdo financeiro operacional ou PII de usuários é retornado.

## Definições financeiras e limitações
- revenue_cents: soma apenas de saas_charges.status=approved, pelo created_at da gravação verificada na API; production padrão. Sandbox isolado por seleção explícita.
- mrr_cents: uma assinatura ativa/renovável por tenant, em environment selecionado, status authorized/active, paid_until futuro, exclui tenant inativo e assinatura cancelada. Projeção de receita recorrente, não pagamento ocorrido.
- users_active: contas ativas cujo último last_activity caiu no período. Não é DAU/MAU histórico; não comparar com período anterior pois faltam eventos diários.
- plans: agrupamento por plano-base do tenant; não representa obrigatoriamente o plano pago.
- série temporal: contagens de cadastros e cobranças confirmadas no período; em >=91 dias agrupada por mês; datas de zero incluídas.
- comparação de novos cadastros e cobrança usa janela imediatamente anterior de duração idêntica; receitas e MRR não se misturam.
- Empresa 360: métricas agregadas da empresa, sem acesso irrestrito aos seus documentos.
- atenção: até 5 empresas sem atividade há 15 dias e 5 assinaturas elegíveis próximas do vencimento, sem disparar ações. Configuração administrativa de limiares, alertas persistidos e eventos de uso diário ficam para fase posterior.
- Os valores de demonstração presentes na proposta comercial não aparecem como valores reais em resposta nenhuma.

## Gates
php artisan test --filter=PlatformAnalyticsTest
vendor/bin/pint --test
php artisan test
QA com SQLite e MySQL, incluindo duas empresas e produção/sandbox. A documentação não prova que os comandos foram executados.
