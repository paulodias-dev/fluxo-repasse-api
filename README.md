# Fluxo Repasse — API Laravel + MySQL

API REST do projeto **Fluxo Repasse**, com fundação de identidade JWT e contratos em OpenAPI.

## Estado da implementação
- Laravel 12, PHP 8.3+, MySQL 8+.
- `GET /api/v1/health` (público).
- `POST /api/v1/auth/login` com limite de tentativas.
- `GET /api/v1/auth/me`, `POST /api/v1/auth/refresh` e `POST /api/v1/auth/logout` via Bearer JWT.
- Revogação por blacklist e cadastro inicial de usuários exclusivamente pelo terminal.
- Testes feature escritos para login, acesso protegido, logout, refresh e rate-limit.
- Nenhuma rota financeira ou cadastro público nesta etapa.

## Instalação após merge do PR
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

## Qualidade
```bash
composer test
composer lint
php artisan route:list --path=api
```

## Documentação
- [Instalação](docs/INSTALACAO.md)
- [Arquitetura](docs/ARQUITETURA.md)
- [Contrato OpenAPI](docs/openapi.yaml)
- [Roadmap](docs/ROADMAP.md)
- [Segurança](docs/SEGURANCA.md)
- [QA](docs/QA.md)
- [Deploy](docs/DEPLOY.md)
- [Requisitos de negócio pendentes](docs/REQUISITOS_PENDENTES.md)
- [Diretrizes de desenvolvimento](AGENTS.md)

**Homologação pendente:** gerar/commit `composer.lock`, executar dependências, testes e migrations no MySQL. O histórico do Gemini não está disponível; nenhuma regra de repasse foi presumida.

Frontend: [fluxo-repasse-app](https://github.com/paulodias-dev/fluxo-repasse-app).
