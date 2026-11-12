# Fluxo Repasse — API Laravel + MySQL

API REST do **Fluxo Repasse**, com identidade JWT, gestão básica de ciclo de contas e auditoria de segurança.

## Tecnologias
PHP 8.3+, Laravel 12, MySQL 8+, JWT (php-open-source-saver/jwt-auth), testes PHPUnit.

## Capacidades disponíveis
- `GET /api/v1/health`: disponibilidade básica.
- `POST /api/v1/auth/login`: autenticação, limitação de tentativas e login auditado.
- `GET /api/v1/auth/me`: perfil autenticado mínimo.
- `POST /api/v1/auth/refresh`: rotação manual do JWT válido.
- `POST /api/v1/auth/logout`: revogação individual.
- `POST /api/v1/auth/password`: troca da própria senha, revogação global dos JWTs anteriores.
- `php artisan repasse:user:create`: provisionamento interativo sem senha fixa.
- `php artisan repasse:user:status EMAIL ativo|inativo`: controle de conta via CLI, revogação global das sessões.
- Auditoria mínima de login, logout, refresh, credenciais e suspensão; sem senha, e-mail digitado ou IP em claro.

Nenhuma rota de repasse, valor financeiro, beneficiário ou cadastro público foi criada. Identidade **não equivale** a autorização financeira.

## Instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Criar o banco MySQL e configurar credenciais no .env
php artisan migrate
php artisan repasse:user:create
php artisan serve --host=127.0.0.1 --port=8000
```
Na atualização de ambiente existente, preservar APP_KEY/JWT_SECRET e executar migrations **aditivas**, após backup.

## QA e pré-homologação
```bash
bash scripts/qa-preflight.sh
composer test
composer lint
php artisan migrate:status
php artisan route:list --path=api
```
Sem `composer.lock`, Composer/dependências instaladas e testes MySQL reais **não existe homologação**. Gerar o lock e evidências em ambiente conectado antes de produção.

## Documentação
- [Instalação/migração](docs/INSTALACAO.md)
- [Arquitetura de identidade](docs/ARQUITETURA.md)
- [OpenAPI 0.3](docs/openapi.yaml)
- [QA](docs/QA.md)
- [Segurança/LGPD](docs/SEGURANCA.md)
- [Deploy](docs/DEPLOY.md)
- [Roadmap](docs/ROADMAP.md)
- [Requisitos funcionais pendentes](docs/REQUISITOS_PENDENTES.md)
- [AGENTS](AGENTS.md)

Frontend: [fluxo-repasse-app](https://github.com/paulodias-dev/fluxo-repasse-app).

> O histórico funcional do Gemini ainda não foi recuperado. Nenhum contrato financeiro foi inventado.
