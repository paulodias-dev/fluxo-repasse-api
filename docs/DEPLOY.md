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

## Monitoramento de prontidão — Macroentrega 06

Sondas de infraestrutura devem distinguir `GET /api/v1/health` (liveness, não verifica MySQL) e `GET /api/v1/ready` (readiness, verifica DB + tabelas). Utilize **/ready** para decidir se a instância pode receber tráfego autenticado; em falha retorna HTTP 503 e JSON mínimo sem detalhes do ambiente.

A readiness não substitui monitoramento de cache compartilhado, backup ou auditoria; a confirmação de MySQL real depende da infraestrutura de produção. Recomenda-se monitorar também revogação JWT, latência e disponibilidade externa, após escopo operacional aprovado.

## Macro 10 — Gate operacional e investigação de incidentes

Antes de qualquer deploy, fornecer variáveis de produção via mecanismo de segredos e executar **manualmente** `bash ops/preflight-production.sh`. Ele recusa debug, APP_URL não HTTPS, CORS aberto, cache não compartilhado da blacklist JWT e conexão direcionada a banco QA. Não altera nada. Os testes em `bash ops/preflight-selftest.sh` cobrem 13 cenários simulados.

A API devolve `X-Request-ID` único por requisição. Ativar `LOG_API_REQUEST_SUMMARY=true` somente depois de definir retenção e acesso: os registros resumidos não incluem IP, URL, query, JWT, senhas, CPF ou corpo. Esse controle **não** substitui APM/monitoramento com alertas.

Para QA, executar `bash ops/selftest.sh` (13 verificações) e consultar `ops/README.md` para backup checksum e ensaio de restauração em schema `*_restore_qa` separado. A ferramenta não apaga banco, mas o dump **não é criptografado** e deve permanecer fora do repositório e webroot. Não utilizar esses scripts em produção; aprovar solução criptografada/retida e RPO/RTO antes do go-live.

No APP, build produção deve passar por `npm run qa:release` após `npm run build` com URL de API HTTPS real. Nunca incluir chaves privadas em variáveis `VITE_*`. Validar cache e service worker no navegador.

**Ainda não homologado:** Composer/PHPUnit, MySQL, Vitest, release real, TLS e restore. Os testes executados nesta fase utilizam fixtures e mocks.
