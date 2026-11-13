# Segurança de identidade — Macroentrega 03

## Proteções implementadas
- Autenticação JWT curta por Bearer, não por query string.
- Rate-limit em login e troca de senha.
- Usuários inativos não podem autenticar; middleware nega qualquer JWT de conta desativada.
- `auth_version` monotônica por usuário: troca de senha, suspensão ou reativação invalida todos os tokens anteriormente assinados.
- `av` incluída no JWT e mantida no refresh; valores ausentes ou diferentes recusados.
- Troca de senha exige senha atual válida, confirmação e política forte.
- Auditoria mínima, com hash HMAC de IP e sem armazenar senhas nem tokens.
- Lock de registro e transação ao alterar credenciais.
- Tokens antigos são invalidados mesmo se falhar a gravação da blacklist (controle por versão no banco).

## Riscos não resolvidos
- Privacidade: definir retenção da auditoria, base legal LGPD e acesso a logs.
- MFA, prevenção avançada de abuso, segurança de e-mail, recuperação de senha e permissões por papéis ainda não foram projetados.
- Sem RBAC, o endpoint de perfil **não concede acesso a finanças**; não criar operações sem autorização explícita.
- O cache da blacklist deve ser compartilhado; sem isso o logout individual pode não revogar imediatamente em múltiplas instâncias, mas mudança de versão é checada no MySQL.
- O app guarda JWT em memória para evitar persistência; XSS ainda é risco. Exigir CSP e revisão de dependências.
- Atualização APP_KEY invalidaria a capacidade de correlacionar hashes de IP; planejar rotação e retenção.
- Prevenir spoofing de IP com configuração segura de trusted proxies.
- Dados de auditoria em DB não são imutáveis contra administradores da própria base; trilha externa exigiria desenho próprio.

## Antes do ambiente produtivo
Backup/restore, HTTPS, monitoramento, testes de invasão, política de sessão, revisão LGPD, RPO/RTO, rotação de segredos, autenticação reforçada para operações de alto risco, verificação das bibliotecas e proteção no proxy.

## Controles RBAC — Macroentrega 04

O único privilégio implementado é `users.manage`, destinado a administrar contas; não permite repasses, comissões, conciliação ou acesso a fundos.

- Não há permissão automática para usuários cadastrados por HTTP.
- Administradores técnicos são concedidos e revogados exclusivamente por comandos de console confirmados, com auditoria e invalidação de sessões.
- Operações protegidas usam middleware de identidade + versão de sessão + autorização em banco, com política de negação por padrão.
- Status de conta é alterado com lock/transaction e incremento de `auth_version`, revogando JWTs anteriores.
- A API não permite desativação da conta própria ou de outra conta admin; para contas admin existe rotina CLI com proteção do último operador ativo.
- Auditoria liga operador e conta afetada por IDs, sem armazenar senhas ou tokens.
- O frontend pode esconder rotas, mas não é uma barreira de segurança.
- Não há política de delegação, tenant, gestão de papéis via UI, importação de pessoas ou reenvio de senha nesta fase.

**Pendências de segurança:** revisão de acesso por tenant caso aplicável, retenção LGPD de eventos, MFA, recuperação segura de senha, revisão de logs, teste de privilégio escalado e validação em ambiente MySQL real.

## Macro 05 — Redução de exposição e integridade

- Consultas de histórico exigem `users.manage` no backend; o componente PWA sozinho não concede acesso.
- Dados de auditoria expostos são limitados a tipo, data, ID do evento e ID do operador. Não incluir `ip_hash`, dados pessoais do payload, JWT, senha ou valor financeiro.
- Filtro por usuário, paginação fixa, enum de eventos e limite de consultas reduzem exposição acidental.
- Alterar e-mail revoga JWTs anteriores, evitando sessão com identidade desatualizada.
- Endpoints administrativos retornam 422 ao receber tentativa explícita de inserir `roles`, `permissions`, `auth_version`, senha ou outros campos proibidos; não conceder permissões por mass assignment.
- Não publicar esses eventos em Cache Storage, logs de frontend ou relatórios externos sem revisão LGPD.

**Limites:** a tabela existente não é imutável perante administradores do banco; retenção, consentimento/base legal, exportação, agregados e consulta por intervalos extensos requerem revisão de privacidade. Não afirmar conformidade LGPD integral sem avaliação jurídica e técnica.

## Macro 08 — Defesa antes de migrations e privilégio mínimo

`phpunit.mysql.xml` carrega `qa/phpunit-mysql-bootstrap.php` antes de PHPUnit/Laravel e falha se APP_ENV não for testing, se conexão não for MySQL privado, se nome DB não terminar em `_qa`, se DB_URL estiver preenchido ou se faltar senha. O Compose fixa DB_URL vazio para impedir sobreposição por arquivos locais.

O runner limita a API aos contêineres QA e força checagem de readiness antes do smoke. O modo completo exige administrador e usuário comum **distintos**. `qa:rbac` não possui endpoints de escrita: realiza login, perfil, leitura negada (403) e logout. O histórico de acessos registrará eventos normais, sem dados financeiros.

Essas proteções **não** substituem revisão de infraestrutura, LGPD, auditoria ou testes reais de isolamento. Nenhuma senha ou conexão de produção deve ser usada nesse processo.

## Macro 09 — Isolamento HTTP e service worker

API aplica `Cache-Control: no-store, private`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` e CSP para JSON, inclusive em respostas de exceção. Aplicação dos headers é compartilhada entre middleware e exception handler para evitar diferenças entre 200 e 401/403.

APP restringe caches Workbox ao shell estático, sem runtime caching. Se conexão cair, o JWT e a sessão da aba são descartados localmente; **não afirmar que o JWT foi revogado na API enquanto offline**. Retornar online exige novo login.

Headers e código fonte não substituem verificação em runtime de browser/proxy, pentest ou avaliação LGPD. Testes de integração e navegador seguem pendentes.

## Macro 10 — Segurança operacional e privacidade

- O ID `X-Request-ID` é gerado no servidor por requisição; entradas de clientes não são aceitas como correlatores.
- LOG_API_REQUEST_SUMMARY é opt-in e registra somente metadados técnicos fixos, sem query, IP, URL completa, Authorization, e-mail ou CPF.
- O pré-flight de produção recusa debug ativo, TLS inadequado, cache local em blacklist JWT e CORS permissivo. É uma checagem estática, não valida infraestrutura TLS nem segredos por entropia.
- Backup e restauração de QA ficam em `ops/`; o dump gzip é **não criptografado** e deve permanecer local, fora do Git/webroot, com acesso restrito. SHA-256 não atesta autenticidade.
- Restauração só cria um novo schema `*_restore_qa` com confirmação explícita. Nunca usar dumps de terceiros, bancos de produção ou ação automática destrutiva.
- Não há base para afirmar LGPD integral, backup produtivo, retenção aprovada ou RPO/RTO testados. Esses gates seguem pendentes.

### Incidente preventivo — seeder com credencial versionada

A main recebeu um `AdminUserSeeder` que criava uma conta técnica com senha em texto fixo. A macro 10 torna o seeder exclusivamente estrutural (papel/permissão). O fluxo interativo Artisan permanece para criar o primeiro usuário e conceder `users.manage`. Dois testes feature asseguram ausência de criação de usuários e idempotência, porém não foram executados.

Se a credencial já foi usada, considerá-la comprometida: trocar senha, invalidar JWTs anteriores com incremento de `auth_version`, inspecionar auditoria de acessos e restringir uso do histórico Git. Remover o literal de novos commits não o apaga do histórico publicado.

## Macro técnica 12 — correlação segura até nas respostas de erro

`RequestCorrelationId` agora entra no pipeline **antes da resolução da rota**, mas só gera IDs e telemetria para `api/*`. Em 404/405 e OPTIONS, `X-Request-ID` deve conter UUID novo do servidor, não o ID não confiável fornecido pelo cliente. Os cabeçalhos `no-store/private`, `nosniff` e `Referrer-Policy` se aplicam também ao preflight e às respostas de erro.

APP: gerações monotônicas impedem que login/refresh/senha atrasados reabram sessão privada; logout oculta dados antes do retorno remoto. Perda da sessão local **não garante revogação remota de JWT enquanto offline**. Não persistir tokens no cache.

Os novos testes Laravel/Vitest precisam da homologação real. Ver `docs/MACRO_12_SESSAO_CORRELACAO.md`.
