# Macroentrega 25 — Gestão integrada de acessos e Veículo 360

## Entrega de backend

- `POST /api/v1/admin/users`: `role_ids` opcional. Cadastro e atribuição de papéis na mesma transação SQL; campos sensíveis continuam proibidos.
- `PATCH /api/v1/admin/users/{user}`: `role_ids` opcional. Atualização cadastral e de papéis numa única transação, preservando o contrato antigo quando o campo é omitido.
- Somente usuários com **users.manage** acessam as rotas administrativas existentes. Para enviar `role_ids`, é obrigatória também **roles.manage**; não é possível elevar permissões acima das do operador, atribuir `platform_admin`, editar os próprios papéis ou substituir os de contas protegidas.
- Alterações efetivas de papéis ou e-mail incrementam `auth_version` uma única vez, invalidando tokens anteriores. Ações são auditadas como `account_created`, `account_roles_changed`, `account_updated` ou `account_email_changed`, conforme aplicável.
- Sem `role_ids`, criar/editar usuários segue os contratos anteriores. Não há migração nova e nenhum dado existente é apagado.
- `AdminUserRoleOnboardingTest`: oito testes de criação com papéis, negação de privilégios, papel protegido, edição atômica, rollback e revogação de JWT.

## Integração frontend

- Permissões da sessão protegem a montagem de telas operacionais, administrativas e financeiras por URL direta, além do menu existente.
- Cadastro já permite seleção de papéis. Edição atualiza nome, e-mail e papéis em uma operação; se a leitura dos acessos existentes falhar, a tela NÃO envia `role_ids` e informa a preservação dos privilégios.
- A antiga rota demonstrativa `/apps/user-management/*` redireciona para `/users`, que exige **users.manage**.
- O Veículo 360 busca participantes ativos por nome/e-mail, com paginação independente; uma falha nessa lista não impede a leitura da ficha.
- O shell Metronic adquirido, menus, cards e identidade visual foram preservados; nenhum segundo layout ou aplicativo foi criado.

## Plano de aceite

1. Com base de homologação, executar `php artisan test --filter=AdminUserRoleOnboardingTest` e regressões de `AdminUsersTest` e `AccessManagementTest`.
2. Executar `vendor/bin/pint --test`, validando PHP e padrões do projeto.
3. No frontend, executar `npm ci`, `npm run build` e `npm run lint`.
4. Testar perfis: somente `users.manage`, somente `cases.manage`, `cases.manage + scenarios.manage` e `users.manage + roles.manage`.
5. Confirmar: rota direta negada antes de carregar o módulo, alteração de papéis com revogação JWT, perfil protegido preservado, cadastro com papel atribuído e busca de participantes além da primeira página.

**Estado:** código e testes publicados em branch de revisão; suites e smoke autenticado com MySQL/React não foram executados nesta sessão. PR não equivale a merge, deploy nem homologação. Nenhuma operação bancária, pagamento ou repasse foi criada.
