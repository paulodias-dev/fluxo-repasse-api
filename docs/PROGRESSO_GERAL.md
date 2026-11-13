# Fluxo Repasse — Medição global provisória (Macroentrega 10)

> Documento compartilhado pelos repositórios API e APP. Esta medição é um **índice de marcos do roadmap**, não uma estimativa comprovada de esforço, custo, valor de negócio, homologação ou conclusão das operações financeiras.

## Regra matemática estável
- Denominador: **12 macromarcos definidos** abaixo, cada um com peso idêntico (1/12).
- Integrado na `main` dos dois repositórios: **6 de 12 = 50,00%**.
- Os PRs #7, #8 e #9 estão incorporados à main, mas os respectivos marcos seguem parcialmente abertos por ausência de homologação em runtime; o índice de marcos concluídos permanece **6 de 12 = 50,00%**.
- Esta porcentagem provisória inclui os marcos 11 e 12 apenas como **placeholders**, porque o escopo funcional do Gemini não foi disponibilizado. **Não afirmar que o negócio de repasses está 50% implementado.**
- Homologação ponta a ponta: não concluída; documentar separadamente, nunca embutir esse status no percentual.

## Marcos canônicos

| Nº | Marco | Estado | Evidência |
|---:|---|---|---|
| 01 | Repositórios, bootstrap React/Vite/PWA e Laravel/MySQL | Incorporado | PR #1 em ambos |
| 02 | Autenticação JWT, perfil, login, logout e refresh | Incorporado | PR #2 em ambos |
| 03 | Conta ativa, expiração, auditoria de identidade e troca de senha | Incorporado | PR #3 em ambos |
| 04 | RBAC técnico e módulo administrativo de usuários | Incorporado | PR #4 em ambos |
| 05 | Histórico administrativo e smoke de API | Incorporado | PR #5 em ambos |
| 06 | Readiness MySQL, diferenciação health/ready, regressões e testes nativos executados | Incorporado | PR #6 em ambos |
| 07 | Lockfiles legítimos e instalação reproduzível npm/Composer | Parcial, PR #7 incorporado, QA pendente | Runner QA/Compose; ambos os lockfiles existentes e inspecionados; instalação real pendente |
| 08 | PHPUnit, Pint, migrations/testes MySQL reais, Vitest e build executados | Parcial, PR #8 incorporado | PHPUnit MySQL isolado e 35 testes de proteção executados; integração real pendente |
| 09 | PWA mobile real, acessibilidade, performance e atualização offline segura | Parcial, PR #9 incorporado | Política offline, 8 testes Node executados, a11y de base; E2E mobile pendente |
| 10 | Deploy, backup/restore, observabilidade, privacidade e segurança homologados | Parcial, PR #10 em revisão | X-Request-ID, preflight, backup e restauração em QA simulados, gate de release PWA; execução real pendente |
| 11 | Especificação **aprovada** das regras de repasse, papéis de negócio e integrações | Pendente de fonte | Não inventar processos |
| 12 | Operações reais do produto, integrações financeiras e aceite E2E do negócio | Pendente de escopo | Não criar transações sem regras |

## Percentuais

`main = 6 ÷ 12 × 100 = 50,00%`

`main + macro 10 em PR = 6 ÷ 12 × 100 = 50,00%` (marcos 07–10 ainda incompletos; não contabilizar código parcial como marco integral)

**Cuidado:** estes são números de marcos de mesmo peso e não refletem a complexidade desigual entre autenticação e operações financeiras. Assim que o escopo funcional for recuperado/aprovado, substituir este baseline por uma matriz de funcionalidades e pesos consensuais, com histórico auditável da mudança de denominador.

## Medição interna da Macro 06

Critérios implementados/com evidência:
1. [x] Endpoint `GET /api/v1/ready` com contrato de resposta genérico.
2. [x] Verificação de conectividade DB e tabelas essenciais de usuários/RBAC/auditoria.
3. [x] Falha 503 sem divulgação de SQL/segredos/host.
4. [x] Quatro testes feature Laravel escritos.
5. [x] Sintaxe PHP dos dois novos arquivos verificada com PHP 8.4.
6. [x] OpenAPI atualizado para 0.6.
7. [x] Cliente React consulta prontidão após health.
8. [x] Interface distingue health 200 de readiness 503.
9. [x] Smoke exige prontidão antes de login.
10. [x] Oito testes `node:test` executados com sucesso.
11. [x] Novos testes Vitest de readiness escritos.
12. [x] Roadmap, instruções operacionais e matriz QA documentados.

Gates não cumpridos:
13. [x] `composer.lock` agora presente na main da API; estrutura conferida (113 pacotes, dependências principais, URLs HTTPS). Ainda sem Composer validate em runtime.
14. [ ] Gerar/versionar `package-lock.json` via npm com acesso ao registry.
15. [ ] Executar PHPUnit/MySQL/migrations com banco e extensões verdadeiros.
16. [ ] Executar npm ci, Vitest, ESLint, build e E2E PWA/API em dispositivos reais.

**Progresso da macro 06: 12/16 = 75,00% (checkpoints).**

## Evidência de execução nesta entrega

- Executor: Node.js `v22.16.0`, PHP CLI `8.4.24`.
- Testes nativos: `node --test scripts/smoke-api.test.mjs` — **8 pass, 0 fail**, em cópia local exata dos dois arquivos da branch GitHub; comparado comprimento e hash FNV-1a de codepoints.
- Sintaxe PHP: `php -l` em `ReadinessController.php` e `ReadinessTest.php` — **sem erros**; cópias locais exatas verificadas.
- Não realizado: Laravel/PHPUnit/Vitest/build, integração com MySQL, instalação de dependências ou navegadores reais. Isso impede homologação total.

## Macroentrega 07 — Reprodutibilidade de dependências e segurança do QA

Checkpoints com código, verificação ou documentação:

1. [x] Validador offline `qa-lock.mjs` para lock npm v3 e consistência de dependências.
2. [x] Nove testes nativos de lockfile executados: **9/9 aprovados** com Node.js v22.16.0.
3. [x] `preflight` do APP integrado à inspeção de lockfile (fail-closed).
4. [x] Scripts npm `qa:lock` e `qa:native` adicionados.
5. [x] Dockerfile PHP 8.4 com Composer e extensões PDO MySQL/SQLite, mbstring, XML, ZIP.
6. [x] `compose.qa.yaml` com MySQL 8.4 privado, API Laravel e APP Node 22.
7. [x] Runner `qa/run.sh` com segredos externos, banco obrigatório `*_qa` e sem comandos destrutivos.
8. [x] Seis testes de bloqueio do runner executados: **6/6 aprovados**, sem Docker nem MySQL.
9. [x] Verificação sintática `bash -n` dos dois scripts e parse YAML do Compose; sem erros.
10. [x] Manuais de QA, rastreabilidade e progresso atualizados em ambos os repositórios.

Gates ainda pendentes:

11. [x] `composer.lock` já presente na main da API, 113 pacotes; inspeção estática coerente com manifesto.
12. [x] `package-lock.json` do APP já versionado na main, inspecionado: npm v3, 525 entradas, ranges corretos, origem HTTPS e checksums presentes. Não gerado nesta entrega.
13. [ ] Executar Compose/Composer, migrations MySQL, PHPUnit/Pint e registrar evidências.
14. [ ] Executar `npm ci`, Vitest, lint, build e QA integrado/mobile real.

**Progresso macro 07 atualizado: 12/14 = 85,71%.** Não houve execução de Docker, MySQL, Composer, npm ci ou do produto completo no navegador; infraestrutura indisponível.

**Evolução total do projeto — índice provisório do roadmap: 6/12 = 50,00%.** Não aumentar esse indicador enquanto o marco 07 não estiver integralmente concluído. O número mede exclusivamente marcos iguais, **não** a fração real de implementação do negócio financeiro, ainda sem especificação validada.

### Evidências desta macro

- `node --check` nos dois scripts de lock: sem erro; `node --test scripts/qa-lock.test.mjs`: **9 pass / 0 fail**.
- `bash -n qa/run.sh` e `bash -n qa/selftest.sh`: aprovados; `bash qa/selftest.sh`: **6 aprovações / 0 falhas**, executado em diretórios temporários.
- `compose.qa.yaml`: YAML parseado com PyYAML 6.0.3, 3 serviços, nenhuma porta MySQL exposta.
- Conteúdo dos scripts publicados comparado com cópias locais por comprimento e checksum FNV-1a de codepoints.
- O `package-lock.json` existente do APP foi inspecionado diretamente no GitHub: 525 entradas, 0 sem HTTPS, 0 sem integridade, 0 divergências de dependências diretas. `composer.lock` da API também foi encontrado na main: 113 pacotes resolvidos, dependências principais presentes e nenhuma URL de origem não HTTPS na inspeção estática.
- Estado dos bloqueios: Composer, Docker, extensões PDO, registros de pacotes npm/Packagist e banco MySQL indisponíveis para execução aqui.

## Próxima implementação

Completar o marco 07 em ambiente com registros e Docker acessíveis: validar ambos os locks com as ferramentas reais (`composer validate`, `composer install`, `npm ci`) e rodar o QA isolado integralmente. Depois avançar ao marco 08 de homologação com MySQL e PWA, sem assumir regras financeiras.

## Macroentrega 08 — MySQL PHPUnit seguro, regressões e prova de RBAC

### Critérios concluídos nesta macro
1. [x] Corrigir teste de regressão `account_updated` vs `account_email_changed`.
2. [x] Criar `phpunit.mysql.xml` com driver MySQL explícito, sem `:memory:`.
3. [x] Proteger a suíte antes de carregar o autoload Laravel.
4. [x] Recusar `APP_ENV` diferente de testing, driver diferente de mysql e DB malformado.
5. [x] Recusar DB_HOST externo, porta inesperada e senha de banco vazia.
6. [x] Fixar `DB_URL=""` no Compose e bloquear configuração de URL externa.
7. [x] Executar **10/10** cenários PHP da política de isolamento MySQL.
8. [x] Executar **10/10** autotestes shell do runner (lock/credenciais/banco/papéis).
9. [x] Criar comando opt-in de resolução de `composer.lock` com Composer real e sem plugins/scripts.
10. [x] Criar espera de `/ready` limitada, tolerante a falhas transitórias e sem tokens.
11. [x] Executar **8/8** testes Node da espera de prontidão.
12. [x] Criar prova de RBAC negativo via conta comum sem alterar registros.
13. [x] Executar **7/7** testes Node da prova de RBAC.
14. [x] Exigir duas contas distintas no QA full e atualizar as documentações/roadmaps.

### Checkpoints finais de infraestrutura
15. [x] `composer.lock` já versionado na main da API; estrutura de 113 pacotes, hash em formato válido e dependências principais conferidas. Não gerado por esta macro.
16. [ ] Executar PHPUnit/Pint/migrations contra MySQL real e guardar evidências.
17. [ ] Executar `npm ci`, Vitest, ESLint e build do APP com evidências.
18. [ ] Executar smoke autenticado e homologação PWA em navegadores reais.

**Macro 08 = 15/18 = 83,33%**, critérios definidos previamente para a entrega. Os 35 testes de isolamento foram executados e passaram, mas não incluem o funcionamento completo do Laravel/React com banco real.

**Índice global técnico do roadmap = 6/12 = 50,00% de macromarcos concluídos.** O fato de PRs #7 já estarem na main e de a macro #8 estar em revisão não autoriza contabilizar marcos incompletos. O progresso funcional do produto financeiro continua impossível de mensurar com precisão sem especificação aprovada dos repasses.

### Evidências da macro 08

- `node --test scripts/wait-api-ready.test.mjs scripts/probe-rbac.test.mjs` — 15/15 aprovados, Node 22.16.0.
- `php qa/mysql-safety.test.php` — 10/10 aprovados com PHP CLI 8.4.24.
- `bash qa/selftest.sh` — 10/10 aprovados; nenhum serviço Docker ou banco real executado.
- `node --check`, `php -l`, `bash -n` — sem erros de sintaxe nos arquivos testados.
- Arquivos testados em cópias locais foram conferidos contra os conteúdos publicados nos repositórios por comprimento e checksum FNV-1a.
- Ambos os lockfiles estão versionados. PHPUnit/Pint em MySQL, npm ci/Vitest/build e QA mobile real continuam não executados no executor atual. A inspeção do lock Composer não substitui `composer validate`.

## Próxima prioridade

Concluir os gates reais dos marcos 07/08 com infraestrutura e Composer conectados. Só então elevar o percentual de marcos concluídos. O domínio financeiro exige as regras de negócio aprovadas antes de criar transações, retenções ou cálculos.

**Atualização de evidência — Macro 08:** uma nova conferência da árvore GitHub identificou `composer.lock` já presente na `main` da API (316.325 bytes no objeto Git, 81 pacotes de produção e 32 dev; `content-hash` com formato hexadecimal de 32 caracteres; requisitos principais encontrados; nenhuma URL `source`/`dist` não HTTPS). Não houve download/execução do Composer; a integridade completa depende de `composer validate --strict` e `composer install`. Essa evidência elevou a Macro 08 para **15/18 = 83,33%** e a Macro 07 para **12/14 = 85,71%**, sem alterar o índice global de macromarcos fechados (**6/12 = 50,00%**).

## Macroentrega 09 — Segurança PWA offline e acessibilidade

### Critérios entregues ou verificados

1. [x] Middleware global do grupo API para cabeçalhos de privacidade e anti-cache.
2. [x] Tratamento central das respostas de exceções 401/403 com a mesma política.
3. [x] Cinco testes Laravel de headers em sucesso, login protegido e acesso negado escritos.
4. [x] Sintaxe PHP de dois novos arquivos validada localmente pelo PHP 8.4.
5. [x] Política única Workbox: precache de shell estático, sem runtime caching.
6. [x] Oito testes Node nativos da política de cache executados: **8/8 aprovados**.
7. [x] Fallback offline autorizado somente em `/` e `/index.html`, sem páginas presumidas.
8. [x] Área privada oculta imediatamente ao ficar offline e sessão local descartada.
9. [x] Login pendente não pode restaurar sessão durante perda de conexão.
10. [x] Acessibilidade de base: skip link, aviso offline, foco teclado, touch 44px, preferências de movimento/contraste.
11. [x] OpenAPI 0.7, manuais, scripts QA e roadmap documentados.

### Gates ainda não cumpridos

12. [ ] Executar Vitest de UI, especialmente queda de conexão e login pendente.
13. [ ] Executar PHPUnit/MySQL dos cabeçalhos de segurança com API real.
14. [ ] Executar `npm ci`, build PWA e inspecionar artefato real do service worker e Cache Storage.
15. [ ] Executar testes móveis, navegação por teclado e auditoria de acessibilidade/desempenho em navegadores/dispositivos.

**Macro 09: 11/15 = 73,33%** dos checkpoints, com quatro gates de execução real pendentes.

**Evolução total provisória: 6/12 = 50,00%** dos macromarcos integralmente concluídos na `main` de ambos os repositórios. PRs #7/#8 foram incorporados, mas os seus critérios de QA não foram totalmente fechados. Macro #9 já foi mesclada, mas a homologação permanece pendente, logo não foi contabilizada como marco concluído. Esta métrica é de roadmap técnico com peso igual e não representa % real de operações financeiras.

### Evidência desta entrega

- `node --test src/pwa/cachePolicy.test.mjs` — **8/8 pass** Node.js 22.16.0.
- `node --check` nos dois arquivos JS da política — sem erros.
- `php -l` no middleware e no teste feature novos — sem erros de sintaxe, PHP 8.4.
- Conteúdos executados comparados aos blobs de GitHub via comprimento/checksum FNV-1a.
- Três testes Vitest de UI e cinco testes feature Laravel foram escritos, **não executados**: faltam npm ci/Composer/PHPUnit, MySQL e navegador.
- Nenhum endpoint ou cálculo financeiro foi introduzido.

## Próxima entrega

Fechar os gates reais de QA de 07–10 (dependências versionadas, testes Laravel/Vitest e execução MySQL/React ainda pendentes), executar ensaio de backup/restauração em banco QA separado e inspecionar PWA gerado em navegadores. Para produção, formalizar criptografia de backup, retenção e RPO/RTO.

## Macroentrega 10 — Operação segura, continuidade e release verificável

### 17 checkpoints implementados/verificados

1. [x] Middleware de correlação com X-Request-ID UUID gerado pelo servidor.
2. [x] Telemetria de resumo opcional, sem URL, IP, credenciais ou payload.
3. [x] Cabeçalho de correlação preservado também em respostas de exceção.
4. [x] Cinco testes Laravel de request ID e logs escritos; não executados.
5. [x] Sintaxe PHP dos novos arquivos operacionais validada.
6. [x] Script de backup transacional MySQL exclusivamente QA.
7. [x] Verificação independente de integridade gzip e SHA-256.
8. [x] Ensaio de restauração somente em novo schema `*_restore_qa`, com confirmação.
9. [x] Backup fora do repositório/webroot, com modo 0700/0600.
10. [x] **13/13 autotestes de backup** executados com Docker simulado.
11. [x] Gate somente leitura de configuração de produção (HTTPS, cache JWT, debug, CORS, banco).
12. [x] **13/13 testes de pré-flight de produção** executados com fixtures.
13. [x] ErrorBoundary React com fallback privado, dois testes Vitest escritos.
14. [x] Verificador de release do PWA em `dist/` e origem HTTPS da API.
15. [x] **11/11 testes de release Node** executados com artefatos temporários.
16. [x] Correção de CORS para PATCH administrativo e exposição de X-Request-ID ao navegador; testes escritos.
17. [x] OpenAPI 0.8, runbooks, integração com QA e documentação operacional publicados.

### Cinco gates não homologados

18. [ ] Executar PHPunit/MySQL/Composer/Pint e validar os novos endpoints/headers reais.
19. [ ] Executar npm ci, Vitest, ESLint, build real e inspeção de service worker/Cache Storage.
20. [ ] Executar backup e restauração de MySQL QA real, validar contagem e integridade dos dados.
21. [ ] Implantar e testar em staging: HTTPS, CORS, blacklist JWT compartilhada, alertas, recuperação e rollback.
22. [ ] Aprovar e testar backup criptografado, retenção, RPO/RTO e política de continuidade de produção.

**Macro 10: 17/22 = 77,27%** dos checkpoints entregues. A evidência desta fase inclui **37/37 testes nativos executados e aprovados** (26 Shell com fixtures/mocks + 11 Node de builds simulados) e validações de sintaxe PHP/Bash/Node. Os novos testes Laravel e Vitest foram **escritos, mas não executados**.

**Evolução total do roadmap técnico provisório: 6/12 = 50,00% de macromarcos integralmente concluídos**. Não elevar este índice apenas porque código parcial foi mesclado ou entregue em PR. A medição usa marcos de mesmo peso e **não** representa 50% das operações financeiras, cujo escopo funcional continua pendente de fonte aprovada.

Documentos: `docs/MACRO_10_OPERACAO.md` nos dois repositórios, `ops/README.md` na API. No APP, `npm run qa:release:test` cobre o gate de publicação; na API, `ops/preflight-selftest.sh` e `ops/selftest.sh` cobrem bloqueios sem tocar em produção.
