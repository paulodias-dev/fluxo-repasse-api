# QA — API Fluxo Repasse

## 1. Prerequisitos
- PHP >= 8.2, Composer, extensões PHP exigidas e MySQL 8+.
- Configurar banco local com usuário de privilégios restritos.
- Executar `composer install` e `php artisan key:generate`.
- Criar e versionar `composer.lock` antes da homologação; `composer install` sem lock resolve pacotes dinamicamente.

## 2. Verificações automatizadas
```bash
composer validate --strict
composer test
composer lint
php artisan route:list --path=api
php artisan migrate:status
```
O `phpunit.xml` padrão utiliza SQLite em memória para testes rápidos; isso **não substitui** uma rodada de integração no MySQL 8.

## 3. Testes com MySQL
1. Criar banco exclusivo de testes, nunca apontar suite para dados de produção.
2. Aplicar migrations e verificar charset/collation UTF8MB4.
3. Verificar conexões, constraints, índices, timezone e rollback.
4. Executar `GET /api/v1/health` e validar JSON/HTTP 200, Cache-Control no-store.
5. Confirmar que `POST /api/v1/repasses` retorna 404 (não existe função financeira neste bootstrap).
6. Com frontend em `http://localhost:5173`, verificar CORS e bloquear origens não autorizadas.
7. Verificar que respostas de erro não expõem dados sensíveis (em produção, `APP_DEBUG=false`).

## 4. Gatilhos de qualidade para o futuro
- Testes de autenticação, expiração, revogação e autorização por papel.
- Provas de isolamento organizacional se o sistema for multi-tenant.
- Testes concorrentes, transacionais, precisão decimal, idempotência e rollback de erros.
- Segurança de webhooks, replay, logs de auditoria, reconciliação e recuperação de desastres.
- OpenAPI/L5-Swagger e testes de contrato antes de novos consumidores.

**Status:** cenários descritos; nenhuma execução de Composer, migrations ou PHPUnit foi comprovada nesta entrega.
