# Fluxo Repasse — Medição global provisória (Macroentrega 08)

> Documento compartilhado pelos repositórios API e APP. Esta medição é um **índice de marcos do roadmap**, não uma estimativa comprovada de esforço, custo, valor de negócio, homologação ou conclusão das operações financeiras.

## Regra matemática estável
- Denominador: **12 macromarcos definidos** abaixo, cada um com peso idêntico (1/12).
- Integrado na `main` dos dois repositórios: **6 de 12 = 50,00%**.
- A Macro 06 foi incorporada. A Macro 07 teve seus PRs #7 incorporados à main, mas o seu marco global **não é considerado concluído** sem instalação e suítes executadas; por isso o índice de marcos concluídos continua **6 de 12 = 50,00%**.
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
| 08 | PHPUnit, Pint, migrations/testes MySQL reais, Vitest e build executados | Em execução (PR #8) | PHPUnit MySQL seguro, 35 novos testes isolados; integração real pendente |
| 09 | PWA mobile real, acessibilidade, performance e atualização offline segura | Pendente | Dispositivos e navegadores |
| 10 | Deploy, backup/restore, observabilidade, privacidade e segurança homologados | Pendente | Aprovação operacional |
| 11 | Especificação **aprovada** das regras de repasse, papéis de negócio e integrações | Pendente de fonte | Não inventar processos |
| 12 | Operações reais do produto, integrações financeiras e aceite E2E do negócio | Pendente de escopo | Não criar transações sem regras |

## Percentuais

`main = 6 ÷ 12 × 100 = 50,00%`

`main + macro 08 em PR = 6 ÷ 12 × 100 = 50,00%` (marcos 07 e 08 ainda incompletos; não contar entregas parciais como marcos concluídos)

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
