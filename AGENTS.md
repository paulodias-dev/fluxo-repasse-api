# AGENTS — Fluxo Repasse API (Macroentrega 03)

## Fundamentos
Laravel 12, PHP >=8.3, MySQL 8+, API REST `/api/v1`. Frontend React/Vite/PWA no repositório `fluxo-repasse-app`.
O histórico funcional Gemini não foi recuperado: não inventar fluxos financeiros, comissões, repasses, multitenancy ou perfis.

## Requisitos inegociáveis
1. pt-BR/UTF-8, validação FormRequest, Swagger/OpenAPI atualizado por endpoint e teste feature por mudança.
2. Usuário autenticado não possui direitos financeiros por padrão; rotas de dinheiro inexistem até RBAC e escopo aprovados.
3. Token Bearer JWT exclusivamente no header. Config `JWT_SECRET` somente em ambiente. Verificar `auth_version` e `is_active` a cada requisição privada.
4. Em troca de senha e status de conta, incrementar `auth_version` em transação, revogando todos os JWTs existentes.
5. Auditoria mínima de identidade, sem senhas, JWT, dados financeiros ou IP bruto; retenção LGPD pendente de aprovação.
6. Jamais adicionar senha fixa/usuário exemplo em seeder de produção; contas reais por `repasse:user:create`.
7. Qualquer escrita futura de dinheiro exige transações, locks, idempotência, testes de concorrência, rastreabilidade e valores monetários sem `float`.
8. Não executar `migrate:fresh` em ambiente persistente; manter migrations aditivas, backups e estratégia de rollback.
9. Sem segredos em arquivos versionados; `composer.lock` obrigatório para homologar, nunca forjar versão ou hash.
10. Executar `composer test`, `composer lint`, testes MySQL 8 reais e revisar `docs/QA.md` antes do merge.
11. PRs não draft; entregar documentação e relatório de resultados reais. Não alegar execução de testes não executados.
