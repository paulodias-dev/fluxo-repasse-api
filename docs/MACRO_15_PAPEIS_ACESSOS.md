# Macroentrega 15 — Papéis e Acessos

## Entrega funcional
- Gestão de papéis técnicos por nome, identificador imutável e conjunto de permissões existentes.
- Atribuição de papéis a usuários existentes, com persistência em `role_user`.
- RBAC específico `roles.manage` (autorização exclusivamente no backend, sessão JWT atual).
- Bloqueio de escalonamento: somente permissões que o operador já detém podem ser atribuídas; não é possível modificar papéis com permissões superiores.
- Proteção do papel `platform_admin`, de contas associadas a ele e de alterações dos próprios papéis do operador.
- Revogação de JWTs por incremento `auth_version` após mudanças efetivas de papel/atribuição; eventos de auditoria com operador.
- APP React integrado à navegação existente, sem layout duplicado e sem permissões de acesso hardcoded no frontend.

## Rotas
- `GET /api/v1/admin/access`: papéis administráveis e permissões autorizadas.
- `POST /api/v1/admin/access/roles`: criar papel.
- `PATCH /api/v1/admin/access/roles/{role}`: editar nome/permissões do papel.
- `GET /api/v1/admin/access/users/{user}/roles`: listar papéis do usuário.
- `PUT /api/v1/admin/access/users/{user}/roles`: atribuir conjunto final de papéis, preservando proteção das contas técnicas.

## Operação após merge
- Executar `php artisan db:seed --class=AdminUserSeeder` no banco correto autorizado, de modo idempotente, para provisionar o novo direito no papel técnico.
- Reautenticar para receber a permissão atualizada e acessar a aba **Papéis e acessos**.
- Executar teste focalizado: `php artisan test --filter=AccessManagementTest`; frontend: lint/build da branch após `npm ci`.
- Não executar `migrate:fresh` ou alteração destrutiva em banco.

## Limite do escopo
Este módulo trata da administração da plataforma. Regras financeiras (pagador, recebedor, evento gerador, comissão, repasse, autorização de pagamento e conciliação) permanecem pendentes de especificação e aprovação. Não presumir o domínio de repasses.

## Qualidade
Testes feature focalizados foram escritos, mas NÃO executados nesta sessão. O código precisa de validação em runtime antes do merge final e uso em produção.
