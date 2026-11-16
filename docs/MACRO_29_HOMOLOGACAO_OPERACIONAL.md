# MACROENTREGA 29 — ETAPA 6: Consolidação operacional e preparação de homologação

## Por que esta é uma macroentrega funcional
O foco não é repetir análises ou refazer telas. Esta entrega corrige obstáculos concretos para o uso do Fluxo Repasse e fornece um único caminho seguro para verificar API Laravel + React adquirido:
1. O dashboard React agora apresenta os **11 módulos reais** do projeto, mesmo quando o usuário não tem acesso. Mostra permissão faltante (sem tentar concedê-la) e indicadores efetivos por perfil, sem widgets de demonstração.
2. A ficha do Veículo 360 carrega seu identificador e dados fundamentais independentemente das consultas de histórico, financeiro, anexos, tarefas e checklist. Módulos secundários que falham não escondem a ficha nem produzem dados fictícios.
3. Novo diagnóstico **read-only**, `php artisan repasse:acceptance`, verifica tabelas e colunas dos módulos, as migrations 008–010, sete permissões técnicas, os grants do papel platform_admin, ao menos um administrador ativo e blacklist JWT. Código em `app/Support/OperationalAcceptance.php`, registro em `routes/console.php`.
4. Saída JSON segura: `php artisan repasse:acceptance --json` retorna `ready`, checks, tabelas/colunas ausentes, migrations pendentes, permissões faltantes e contagem agregada de administradores. **Não** inclui email, nome, JWT, senha, secrets ou dados financeiros. Comando só consulta banco e falha com exit code 1 em caso de estrutura incompleta; não faz migrate, seed, bootstrap ou repair.
5. Novo `qa/run-stage6.sh` consolida o aceite local em três níveis, respeitando o runner protegido existente `qa/run-local.sh`, sem usar `migrate:fresh` nem destruir banco.
6. No APP, `scripts/qa-integrated.mjs` e `scripts/qa-integrated.test.mjs` fazem verificação HTTP com Node nativo (sem novas bibliotecas) de prontidão, JWT, autorização negada, módulos, veículo, financeiro, revisões e pacotes. O teste HTTP **não faz mutação de negócio**; os logins auditados são a única escrita esperada.
7. O script HTTP rejeita qualquer host que não seja loopback, paths extras, usuário/senha na URL e execução full sem duas contas de QA distintas, ID válido de veículo e `QA_HTTP_VERIFIED=YES`. Essa confirmação somente deve ocorrer depois de verificar que o servidor Laravel LOCAL está conectado ao banco exclusivo de homologação.
8. Testes unitários PHP `OperationalAcceptanceTest.php` verificam ausência de mutações, sigilo, falhas por permissão/administrador ausentes e JWT blacklist. Suite Node nativa verifica host, 401/403, grants, cenário completo e ausência de escrita de negócio.
9. Atualização de documentação e matriz objetiva de aceite, sem percentual artificial.

## Execução segura
No repositório API:
```bash
# Leitura apenas: pode diagnosticar instalações existentes sem alterar dados.
php artisan repasse:acceptance --json

# Configure caminho do checkout React comprado (não instalar projeto paralelo).
export QA_APP_PATH=/caminho/fluxo-repasse-app
QA_STAGE=static bash qa/run-stage6.sh

# Testes PHPUnit/Pint em SQLite :memory: e build/lint do React:
QA_MODE=sqlite QA_STAGE=local bash qa/run-stage6.sh
```
Para MySQL local QA, use `QA_MODE=mysql` somente com `QA_DB_NAME=fluxo_repasse_qa` (sem espaço), usuário exclusivo, guardas `qa/mysql-local-check.php` e autorização de reconstrução de schema descartável, conforme `qa/README_LOCAL_MYSQL.md`.

Para o aceite HTTP integrado:
- Inicie a API local conectada **apenas** ao banco descartável de QA.
- Provisione um usuário administrador técnico QA e uma conta comum distinta, sem grants.
- Cadastre pelo frontend um veículo de homologação; confirme seu ID.
- Configure em sessão segura `QA_USER_EMAIL`, `QA_USER_PASSWORD`, `QA_BASIC_USER_EMAIL`, `QA_BASIC_USER_PASSWORD`, `QA_VEHICLE_ID` e `QA_HTTP_VERIFIED=YES`.
- Execute `QA_LEVEL=full QA_STAGE=full bash qa/run-stage6.sh`; o probe aceita apenas `http://127.0.0.1:8000`, `http://localhost:8000` ou loopback IPv6. Não colocar segredos no comando, no .md ou no Git.
- Nunca executar este nível em servidor que aponte para banco operacional: o login registra auditoria de autenticação.

## Aceite visual obrigatório (ainda não automatizado)
- Login admin com todos os grants: dashboard deve mostrar todos os módulos reais, indicadores não simulados e links funcionais.
- Login usuário comum: dashboard mostra módulos restritos e as permissões em falta, sem liberar navegação protegida nem chamadas administrativas.
- Usuário com `cases.manage` sem `scenarios.manage`: pode abrir Veículo 360, não pode abrir Cenários/Prestação de contas.
- Em um veículo com dados reais de QA: passar por ficha, anexos, pendências, participantes, financeiro, categorias, retificações, comprovantes privados, cenários, revisões independentes, parecer e pacotes íntegros.
- Simular erro controlado de um endpoint opcional (em QA): a ficha principal deve continuar visível com alerta da seção, sem afirmar que uma lista vazia é dado completo.
- Validar layout responsivo desktop/mobile, menus, auth refresh, troca de usuário e JWT expirado.
- Conferir backup restaurável e configurações de produção separadamente antes de qualquer deploy.

## Restrições e estado
- Não foi criada lógica real de liquidação, Pix, taxas, tributos, pagamentos, repasses ou multitenancy.
- Não houve migração destrutiva, seeder com credenciais fixas, GitHub Actions nem substituição do layout comprado.
- **Codificado, NÃO homologado**: nesta sessão do GitHub não é possível executar Composer, banco MySQL conectado, testes PHPUnit/Pint e build React no checkout completo. Os scripts só se tornam evidência quando executados no host de homologação.
- **Próximo gate:** executar logs de QA e validar uso real no browser com contas distintas. Etapa 5 de repasses monetários depende da regra formal aprovada.
