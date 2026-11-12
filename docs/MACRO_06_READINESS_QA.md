# Macroentrega 06 — Readiness Laravel, QA e status consolidado

## Contratos operacionais

- `GET /api/v1/health` é **liveness**: verifica que o processo HTTP responde, mas não que banco/migrations funcionam.
- `GET /api/v1/ready` é **readiness**: executa `SELECT 1` e confirma `users`, `roles`, `permissions`, `role_user`, `permission_role`, `auth_security_events`, `migrations`. Não publica host, nome de tabelas, SQL, exceções ou credenciais.
- Disponível → JSON `{ "status": "ready", "service": "fluxo-repasse-api", "version": "v1" }` HTTP 200.
- Indisponível → JSON com `status: unavailable` e a mesma metadata pública, HTTP 503.
- `Cache-Control: no-store`; limite de consultas públicas por IP usando throttle.

## QA executado

O controlador e o teste feature tiveram sintaxe validada em PHP 8.4.24, por meio de cópias locais byte-a-byte equivalentes aos arquivos GitHub (mesmos comprimentos e hash de codepoints); nenhum erro de sintaxe.

Foram **escritos quatro testes Laravel**, mas não executados, pois não há Composer, extensões PDO MySQL/SQLite nem dependências Laravel no executor. Não afirmar que readiness foi testada contra um MySQL real.

Do lado React, os oito testes nativos do smoke `node:test` foram executados em Node.js 22.16.0 e passaram. Esse teste usa API simulada em memória e não comprova conectividade com o banco.

## Verificação de instalação em homologação

```bash
composer install
bash scripts/qa-preflight.sh
php artisan migrate
composer test
composer lint
php artisan route:list --path=api
curl --fail http://127.0.0.1:8000/api/v1/ready
```

Apenas gerar `composer.lock` em ambiente confiável e versioná-lo antes de homologar; usar banco MySQL de QA, jamais rodar migrations em produção sem backup/restauração testados. Ver `docs/PROGRESSO_GERAL.md`.
