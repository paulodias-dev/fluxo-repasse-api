# Macroentrega 09 — Respostas API não armazenáveis

## Problema corrigido

O PWA é instalável e possui conteúdo estático em cache. Respostas de autenticação, dados cadastrais e eventos administrativos **não devem ser armazenados** por caches HTTP compartilhados ou pelo navegador. O frontend já solicitava `cache: no-store`; a API passa a impor proteção equivalente, independentemente do cliente.

## Implementação

`app/Http/Middleware/ApiSecurityHeaders.php` aplica a todas as rotas do grupo API:

- `Cache-Control: no-store, private`
- `Pragma: no-cache`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `X-Frame-Options: DENY`
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`

O middleware é registrado em `bootstrap/app.php` via grupo API. O tratamento central de exceções utiliza a mesma política para manter cabeçalhos também em respostas de autorização negada ou falha de validação. As respostas de JSON existentes são preservadas; não foram criados endpoints ou migrations financeiras.

O contrato OpenAPI passa à versão `0.7.0`. Cinco testes novos `ApiSecurityHeadersTest.php` foram escritos para health, readiness, 401, perfil autenticado e 403. Expectativas antigas de cache em cinco arquivos de testes foram atualizadas para o novo contrato.

## Testes e evidências

- `php -l app/Http/Middleware/ApiSecurityHeaders.php` — **aprovado**.
- `php -l tests/Feature/ApiSecurityHeadersTest.php` — **aprovado**.
- Os dois arquivos PHP executados em cópia local têm tamanho e checksum FNV-1a idênticos aos do GitHub.
- **Não executados**: os cinco testes Laravel, Composer/PHPUnit/Pint e integração MySQL. Dependências não estão instaladas neste ambiente.

### Homologação exigida

No QA isolado e após `composer validate --strict`/`composer install`:

```bash
php artisan test --configuration phpunit.mysql.xml --filter=ApiSecurityHeadersTest
php artisan test --configuration phpunit.mysql.xml
vendor/bin/pint --test
```

Validar, com API real, os cabeçalhos HTTP de 200, 401, 403, 422 e 503. Verificar que responses administrativas, inclusive audit logs, não entram no Cache Storage do PWA. Inspecionar em navegador mobile e PWA instalado. Cabeçalhos HTTP reduzem riscos de cache, mas não comprovam conformidade completa com LGPD.

## Relação com o APP

O APP restringe o service worker ao shell estático público, sem runtime caching, e oculta imediatamente a área autenticada quando o navegador perde a conexão. O JWT permanece em memória e não é persistido. Quando offline, a sessão local é descartada, mas o logout **não pode garantir revogação remota do token**; a revogação depende da API estar acessível ou da expiração.

## Métricas

Macro 09 conjunta: **11/15 checkpoints = 73,33%**. Índice global de macromarcos totalmente concluídos: **6/12 = 50,00%**. Marcos 07/08/09 ainda requerem execução com runtime real. O índice não mede conclusão funcional das operações financeiras, sem escopo aprovado.
