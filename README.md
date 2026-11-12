# Fluxo Repasse — API Laravel + MySQL

Fundação técnica da API REST do **Fluxo Repasse**.

## Tecnologias
- PHP >= 8.2, Laravel 12, MySQL 8+.
- API JSON versionada em `/api/v1` com CORS configurável.
- Health público `GET /api/v1/health`, sem dados sensíveis.
- Teste PHPUnit de contrato; nenhuma rota financeira publicada.

## Instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
# Criar banco MySQL e usuário, configurar credenciais no .env
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

## Verificação
```bash
composer test
composer lint
php artisan route:list --path=api
```

## Manuais
- [Instalação](docs/INSTALACAO.md) · [Arquitetura](docs/ARQUITETURA.md) · [Roadmap](docs/ROADMAP.md)
- [Requisitos pendentes](docs/REQUISITOS_PENDENTES.md) · [QA](docs/QA.md) · [Deploy](docs/DEPLOY.md)
- [AGENTS — padrões técnicos](AGENTS.md)

**Status:** bootstrap técnico, sem cálculo, beneficiário ou transação financeira. O histórico do Gemini ainda não foi acessado. Gerar/commitá-lo `composer.lock` e homologar com MySQL real antes de produção.

Frontend: [fluxo-repasse-app](https://github.com/paulodias-dev/fluxo-repasse-app).
