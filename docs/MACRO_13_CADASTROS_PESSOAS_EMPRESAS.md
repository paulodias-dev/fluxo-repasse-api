# Macroentrega 13 — Cadastro operacional de Pessoas e Empresas

## Resultado de produto

Primeiro módulo operacional persistente do Fluxo Repasse além de usuários/autenticação: **Pessoas e Empresas**, sem dados artificiais e sem simular pagamentos. O cadastro não pressupõe se a pessoa paga, recebe, intermedeia ou aprova qualquer repasse.

### Funcionalidades implementadas

- Cadastro do tipo **Pessoa** ou **Empresa**, nome/razão social, e-mail, telefone e observações internas opcionais.
- Listagem paginada (15 por página), filtros por nome/e-mail, tipo e status.
- Detalhamento e edição, com bloqueio de atribuição de permissões, valores, contas, chave PIX ou tenant inexistente.
- Desativação/reativação **sem exclusão física**, mediante confirmação no APP.
- Auditoria persistente por cadastro (`party_events`): criação, edição, inativação, reativação, ID do operador e data. Sem armazenar payload, saldo ou contato no histórico.
- Autorização deny-by-default na API por `parties.manage`, mesmo quando a UI for acessada diretamente.
- Criação e gravação do evento na **mesma transação**, para não salvar cadastro sem histórico.
- Documentação OpenAPI 0.9 e tela React/PWA pareada, mobile-first.

## Estrutura adicionada

`parties`: id, type (person/company), name, email?, phone?, notes?, is_active, created_at, updated_at.

`party_events`: id, party_id (FK), actor_user_id (FK opcional, sem apagar eventos ao excluir usuário), event_type, created_at. Não existe operação de exclusão do cadastro nesta macro.

API versão `/api/v1`:

| Método | Rota | Operação |
|---|---|---|
| GET | /parties | Página com filtros |
| POST | /parties | Criar pessoa/empresa |
| GET | /parties/{party} | Consultar registro |
| PATCH | /parties/{party} | Editar campos de cadastro |
| PATCH | /parties/{party}/status | Ativar/desativar |
| GET | /parties/{party}/events | Histórico mínimo |

Todas as rotas exigem Bearer JWT, conta ativa, `auth_version` válida e permissão `parties.manage`. Writes mantêm rate limit do admin, e respostas privadas usam `no-store`.

## Instalação no MacBook (MySQL local, sem Docker)

Em uma instalação de desenvolvimento autorizada, **faça backup do banco existente antes de aplicar migrations**:

```bash
cd fluxo-repasse-api
composer install
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
```

O seeder é idempotente: mantém `users.manage`, cria `parties.manage`, associa ambas ao papel técnico `platform_admin`; **não cria contas ou senhas** e não concede privilégios às contas comuns. Para usuários administradores já existentes, o novo item aparecerá após novo login. Em produção, só executar migrations/seed com plano de deploy e backup aprovados.

No APP pareado:

```bash
cd fluxo-repasse-app
npm ci
npm run dev
```

Entrar com um usuário que possua `platform_admin` e acessar a aba **Pessoas e empresas**. A interface não contém mock de registros.

Para testes isolados (SQLite em memória, **não o MySQL operacional**):

```bash
QA_MODE=sqlite bash qa/run-local.sh
```

O arquivo `tests/Feature/PartiesModuleTest.php` contém 8 testes de cadastro, busca, status, RBAC, histórico e rejeição de dados financeiros. **Não executados neste executor**, pois Laravel/Composer não estão disponíveis aqui. Não afirmar aceite de homologação antes da execução no Mac.

## Limites e próximas decisões

**Não implementado:** documentos fiscais (CPF/CNPJ), identificação bancária, chave PIX, beneficiários e pagadores, divisão por empresa/tenant, aprovação, cálculo de comissão/taxas, regras de repasse, saldo, obrigação financeira, saque, transferência e conciliação. Esses elementos dependem do escopo de negócio original, que não pôde ser recuperado do link do Gemini. Não inventar regras a partir do nome "Fluxo Repasse".

O cadastro desta macro é **global na instalação atual**, pois o projeto ainda não possui modelo de organizações/tenants aprovado. Se o modelo definitivo for multiempresa, **não liberar uso compartilhado em produção** antes de implementar isolamento e migrar/atribuir registros.

Dados de contato e observações requerem política de acesso, retenção e finalidade segundo LGPD; nenhum registro deve ser público.

## Progresso verificável

- **Código funcional desta macro:** API, banco, autorização, auditoria e UI implementados e publicados para revisão; testes escritos.
- **Homologação:** pendente executar migrations/seed, PHPUnit/Pint e npm ci/Vitest/build em ambiente real.
- **Percentual da macro:** 8 de 10 checkpoints de implementação e preparação = **80%**, restando duas validações integradas.
- **Percentual total do produto financeiro:** **não aferível honestamente** sem especificação de regras de repasses. O indicador histórico de 6/12 = 50% refere-se apenas ao antigo roadmap técnico, não a módulos de negócio.

**Próxima macro funcional recomendada:** estrutura de contratos/vínculos entre pessoas e empresas **somente após aprovação** das relações de negócio, seguida das regras e eventos de repasse. Não utilizar regras, valores ou taxas fictícias em produção.
