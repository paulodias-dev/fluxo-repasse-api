# Fluxo Repasse — Medição global provisória (Macroentrega 07)

> Documento compartilhado pelos repositórios API e APP. Esta medição é um **índice de marcos do roadmap**, não uma estimativa comprovada de esforço, custo, valor de negócio, homologação ou conclusão das operações financeiras.

## Regra matemática estável
- Denominador: **12 macromarcos definidos** abaixo, cada um com peso idêntico (1/12).
- Integrado na `main` dos dois repositórios: **6 de 12 = 50,00%**.
- A Macro 06 foi incorporada. A Macro 07 está implementada parcialmente nos PRs #7, mas o seu marco global **não é considerado concluído** sem lockfiles genuínos e instalação executada; por isso o índice de marcos concluídos continua **6 de 12 = 50,00%**.
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
| 07 | Lockfiles legítimos e instalação reproduzível npm/Composer | Parcial, PR #7 aberto | Runner QA/Compose e testes nativos; lockfiles ainda ausentes |
| 08 | PHPUnit, Pint, migrations/testes MySQL reais, Vitest e build executados | Pendente | Evidências obrigatórias |
| 09 | PWA mobile real, acessibilidade, performance e atualização offline segura | Pendente | Dispositivos e navegadores |
| 10 | Deploy, backup/restore, observabilidade, privacidade e segurança homologados | Pendente | Aprovação operacional |
| 11 | Especificação **aprovada** das regras de repasse, papéis de negócio e integrações | Pendente de fonte | Não inventar processos |
| 12 | Operações reais do produto, integrações financeiras e aceite E2E do negócio | Pendente de escopo | Não criar transações sem regras |

## Percentuais

`main = 6 ÷ 12 × 100 = 50,00%`

`main + macro 07 em PR = 6 ÷ 12 × 100 = 50,00%` (o marco 07 ainda está incompleto; não contar entregas parciais como marcos concluídos)

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
13. [ ] Gerar/versionar `composer.lock` via Composer com acesso ao Packagist.
14. [ ] Gerar/versionar `package-lock.json` via npm com acesso ao registry.
15. [ ] Executar PHPUnit/MySQL/migrations com banco e extensões verdadeiros.
16. [ ] Executar npm ci, Vitest, ESLint, build e E2E PWA/API em dispositivos reais.

**Progresso da macro 06: 12/16 = 75,00% (checkpoints).**

## Evidência de execução nesta entrega

- Executor: Node.js `v22.16.0`, PHP CLI `8.4.24`.
- Testes nativos: `node --test scripts/smoke-api.test.mjs` — **8 pass, 0 fail**, em cópia local exata dos dois arquivos da branch GitHub; comparado comprimento e hash FNV-1a de codepoints.
- Sintaxe PHP: `php -l` em `ReadinessController.php` e `ReadinessTest.php` — **sem erros**; cópias locais exatas verificadas.
- Não realizado: Laravel/PHPUnit/Vitest/build, integração com MySQL, instalação de dependências ou navegadores reais. Isso impede homologação total.

## Próxima implementação
**Macro 07** — fechar lockfiles e ambiente de dependências legítimo, rodar suites completas e confirmar prontidão/identidade contra MySQL. Após essa evidência, avançar para dispositivos PWA e critérios de produção.

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

11. [ ] Gerar e commitar `composer.lock` resolvido de fontes reais.
12. [ ] Gerar e commitar `package-lock.json` resolvido do npm real.
13. [ ] Executar Compose/Composer, migrations MySQL, PHPUnit/Pint e registrar evidências.
14. [ ] Executar `npm ci`, Vitest, lint, build e QA integrado/mobile real.

**Progresso macro 07: 10/14 = 71,43%.** Não houve execução de Docker, MySQL, Composer, npm ci ou do produto completo no navegador; infraestrutura indisponível.

**Evolução total do projeto — índice provisório do roadmap: 6/12 = 50,00%.** Não aumentar esse indicador enquanto o marco 07 não estiver integralmente concluído. O número mede exclusivamente marcos iguais, **não** a fração real de implementação do negócio financeiro, ainda sem especificação validada.

### Evidências desta macro

- `node --check` nos dois scripts de lock: sem erro; `node --test scripts/qa-lock.test.mjs`: **9 pass / 0 fail**.
- `bash -n qa/run.sh` e `bash -n qa/selftest.sh`: aprovados; `bash qa/selftest.sh`: **6 aprovações / 0 falhas**, executado em diretórios temporários.
- `compose.qa.yaml`: YAML parseado com PyYAML 6.0.3, 3 serviços, nenhuma porta MySQL exposta.
- Conteúdo dos scripts publicados comparado com cópias locais por comprimento e checksum FNV-1a de codepoints.
- Estado dos bloqueios: Composer, Docker, extensões PDO, registros de pacotes npm/Packagist e banco MySQL indisponíveis para execução aqui.

## Próxima implementação

Completar o marco 07 em ambiente com registros e Docker acessíveis: resolver/versionar lockfiles legítimos e rodar o QA isolado integralmente. Depois avançar ao marco 08 de homologação com MySQL e PWA, sem assumir regras financeiras.
