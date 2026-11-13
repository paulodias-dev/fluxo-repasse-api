# Entrega técnica 11 — Integração local com React/Vite e correção do PHPUnit

## Resultado real do MacBook

O log de homologação fornecido pelo operador confirmou `git pull» e `composer install» aprovados, `vendor/bin/pint --test» **67 arquivos aprovados**, 49 checks preventivos aprovados (QA 10, backup 13, preflight 13, segurança MySQL 13) e **59 testes PHPUnit / 329 asserções** com SQLite em memória.

O único aviso restante foi `Option --configuration cannot be used more than once». Ele ocorria porque `qa/run-local.sh» passava `--configuration phpunit.xml» para `php artisan test», que já seleciona configuração.

## Correção do runner

- Modo padrão SQLite: `php artisan test» **sem --configuration**, com `APP_ENV=testing», `DB_CONNECTION=sqlite», `DB_DATABASE=:memory:» e `DB_URL» vazio no processo.
- Modo opcional MySQL: `vendor/bin/phpunit --configuration phpunit.local-mysql.xml» diretamente, evitando flags duplicadas. Mantém os guardas de schema `*_qa», host loopback e usuário próprio (não root/admin), sem tocar o MySQL operacional por padrão.
- A sequência permanece: 49 autotestes sem bancos reais -> Pint -> PHPUnit; falha interrompe a execução imediatamente.

**A correção foi versionada, mas a nova execução completa no Mac ainda precisa ser confirmada.** O log anterior comprova o funcionamento antes desse ajuste, não uma execução pós-PR.

## Homologação integrada com APP

No terminal da API, com MySQL de desenvolvimento/QA configurado no `.env» (sem alterar dados):

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Em outro terminal do APP:

```bash
npm ci
npm run qa:local
QA_INTEGRATION=1 npm run qa:local
```

O APP adicionou uma suíte de testes nativos com **21/21 casos novos executados** no executor de desenvolvimento: 9 de pipeline/fail-closed, 8 de CORS simulados e 4 de gate de build local. Também foram escritos dois casos de regressão do release HTTPS que precisam de execução com o restante da suíte.

O modo integrado verifica na API real: `health» 200, `ready» 200 com conexão MySQL e migrations, `auth/me» 401 sem JWT, `Cache-Control: no-store», `X-Request-ID» UUID exposto e CORS preflight PATCH. Os acessos públicos são somente GET/OPTIONS; autenticação e logout reais só acontecem caso contas de QA sejam explicitamente fornecidas ao smoke existente. Não cria, altera ou desativa usuários.

**Não executar `migrate:fresh» nem apontar os testes PHPUnit para o banco MySQL operacional.** A suíte PHPUnit permanece em SQLite em memória, independente do MySQL usado pelo servidor web da aplicação.

## Pendências de aceite

- Reexecutar runner API após esta correção e confirmar ausência do aviso.
- Instalar as dependências APP e executar lint/Vitest/build real.
- Testar a integração HTTP/CORS com API Laravel/MySQL em execução.
- Verificar PWA em navegador e smartphone.

Métrica da **entrega técnica 11: 16/20 = 80,00%**, com quatro gates de homologação real pendentes. **Roadmap canônico: 6/12 = 50,00%** de marcos integralmente concluídos. O marco canônico 11 (escopo financeiro aprovado) continua sem fonte validada; esta entrega não o substitui.
