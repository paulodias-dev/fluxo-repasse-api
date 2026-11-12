# Deploy e operação — Fluxo Repasse API

## Pré-requisitos
- Dependências reproduzíveis a partir de `composer.lock` revisado.
- PHP >=8.3 e extensões necessárias, MySQL 8+, HTTPS.
- Segredos protegidos em gerenciador seguro, configuração de cache blacklist compartilhado e fila supervisionada apenas quando necessária.
- Backup testado com capacidade de restauração; fazer ensaio em staging.

## Implantação incremental Macro 02 → Macro 03
1. Registrar o commit SHA, backup da base e verificação do rollback.
2. Instalar dependências via `composer install --no-dev --prefer-dist --optimize-autoloader`.
3. Preservar `APP_KEY` e `JWT_SECRET` — não redefinir em atualização.
4. Executar `php artisan migrate --force` na janela de manutenção.
5. Executar `php artisan config:cache`, `php artisan route:cache` e limpar caches necessários conforme política de deploy.
6. Verificar `GET /api/v1/health`, login de teste real e rota `/auth/me`.
7. Confirmar que JWT de antes da macro 03 exige novo login (não tem claim `av`).
8. Testar password change, invalidar JWT de outro dispositivo, e desativar/reativar em ambiente de testes.
9. Monitorar `auth_security_events`, erros 401/422/429/500 e crescimento do banco, sem log de credenciais.
10. Conferir todos os pontos de `docs/QA.md` antes de liberar produção.

## Configurações críticas
`APP_ENV=production`, `APP_DEBUG=false`, TLS, CORS explícito, cache blacklist compartilhado e privilégio mínimo DB. O hashing do IP de auditoria usa APP_KEY; cuidar da rotação de chaves. Política de retenção dos eventos exige validação LGPD.

## Rollback
Migrations aditivas evitam destruir a tabela `users`. Voltar ao código macro 02 sem planejar a compatibilidade dos tokens pode reabrir tokens já revogados por versão; **não fazer rollback apenas do código** sem plano de revogação, contingência e revisão de segurança. Nunca usar `migrate:fresh` em banco persistente.
