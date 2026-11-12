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
