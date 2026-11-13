# Macroentrega técnica 12 — Contratos de erros e correlação segura

## Problema abordado

A API já emitia `X-Request-ID` em rotas reconhecidas, porém a geração de ID ficava no grupo de middleware `api`, executado após a resolução da rota. Requisições a endpoints inexistentes poderiam receber um 404 **sem ID de correlação**. O middleware de CORS também pode atender preflights OPTIONS antes de passar pelo grupo de rotas, sem os cabeçalhos anti-cache.

Essas falhas dificultam o diagnóstico de erros reais no APP, especialmente em redes móveis e operações administrativas.

## Mudanças na API

- `bootstrap/app.php`: `RequestCorrelationId` movido para `$middleware->prepend` global **antes do roteamento**.
- `RequestCorrelationId::handle`: retorna imediatamente para caminhos **fora de `api/*`**, preservando o escopo anterior; não cria telemetria de outros serviços.
- Em rotas API, cada requisição recebe UUID gerado no servidor, ignorando `X-Request-ID` fornecido pelo cliente.
- `ApiSecurityHeaders::secure` agora protege respostas que retornam do pipeline global, incluindo preflight OPTIONS respondido pelo CORS. As respostas de exceção continuam sendo cobertas pelo exception handler.
- `ApiErrorCorrelationTest.php`: **7 novos cenários PHPUnit escritos** para 404 em URL inexistente, 405 de método errado, 401 sem JWT, 422 de validação, IDs não controlados pelo cliente, OPTIONS com segurança/CORS PATCH e confirmação de que a rota `/up` não recebe correlação específica da API.

Não foram criadas ou alteradas migrations, permissões, seeds, contas, endpoints financeiros ou campos de negócio.

## APP pareado

O frontend introduz proteção monotônica contra respostas atrasadas de login, renovação de JWT e alteração de senha após logout, expiração ou perda de rede. O logout oculta imediatamente os dados privados e confirma a revogação remota somente após resposta. O smoke local CORS inclui GET de rota não registrada, exigindo 404, cache no-store e UUID; não cria registros.

## Evidência executada

- `php -l` no `RequestCorrelationId.php` e `ApiErrorCorrelationTest.php`: **2/2 aprovados em PHP 8.4.24**. Arquivos executados foram comparados aos blobs publicados por tamanho/hash FNV-1a.
- APP: `node --test src/lib/sessionGate.test.mjs`: **7/7 aprovados em Node 22.16.0**, com os mesmos arquivos publicados (tamanho/hash conferidos).
- Os 7 testes Laravel e 3 testes Vitest de corrida de sessão foram **escritos, mas NÃO executados** neste executor sem Composer/Laravel/Vitest instalados.

## Comandos de aceite — MacBook sem Docker

Na API, com SQLite em memória somente para PHPUnit:

```bash
composer install
QA_MODE=sqlite bash qa/run-local.sh
```

Verificar que os testes de `ApiErrorCorrelationTest` passam com o runner. Para executar esse arquivo isoladamente após Composer:

```bash
php artisan test --filter=ApiErrorCorrelationTest
```

No APP:

```bash
npm ci
npm run qa:session
npm run qa:local
```

Para validar a API em execução no MySQL operacional local, iniciar `php artisan serve --host=127.0.0.1 --port=8000` na API e, no APP, executar `QA_INTEGRATION=1 npm run qa:local`. O smoke usa GET/OPTIONS sem credenciais, salvo se o operador fornecer credenciais QA intencionalmente (login/logout ficam auditados).

Também validar manualmente no navegador celular: login pendente -> offline -> online, refresh pendente -> offline, expiração de sessão, logout lento, ausência de dados em Cache Storage e bloqueio de admin para usuários comuns.

## Pendências e percentuais

**Macro técnica 12: 17/21 = 80,95%** dos checkpoints, com quatro gates externos:

1. [ ] PHPUnit/Pint reais da API (incluindo os 7 testes novos).
2. [ ] npm ci/ESLint/Vitest/build completo do APP (incluindo os 3 testes React novos).
3. [ ] Smoke HTTP real com API Laravel/MySQL local e contratos de erro/CORS.
4. [ ] QA de navegador/mobile offline, renovação, logout lento e PWA instalado.

**Roadmap global canônico provisório: 6/12 = 50,00%** dos macromarcos integralmente concluídos. A entrega técnica 12 não substitui os marcos de negócio/financeiro que ainda dependem de especificação validada; seus percentuais não medem conclusão de um sistema de repasses financeiros.
