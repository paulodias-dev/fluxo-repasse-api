# Fluxo Repasse — API Laravel + MySQL

Fundação técnica da API REST do **Fluxo Repasse**.

## Tecnologias
- PHP >= 8.2, Laravel 12 e MySQL 8+.
- API JSON versionada em `/api/v1`, com CORS configurável.
- Endpoint público `GET /api/v1/health`, sem dados sensíveis.
- Teste feature PHPUnit de contrato e confirmação de ausência de endpoints financeiros.

## Instalação
```bash
cp .env.example .env
composer install
php artisan key:generate
# Criar banco e usuário MySQL e configurar as credenciais no .env
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

## Qualidade
```bash
composer test
composer lint
php artisan route:list --path=api
```

## Documentos
- [Instalação e publicação](docs/INSTALACAO.md)
- [Arquitetura/contratos](docs/ARQUITETURA.md)
- [Roadmap](docs/ROADMAP.md)
- [Requisitos pendentes](docs/REQUISITOS_PENDENTES.md)
- [Padrões de execução](AGENTS.md)

**Atenção:** o histórico funcional do Gemini ainda não foi recuperado. Nenhuma transação, cálculo, movimentação financeira ou regra de repasse está implementada ou aprovada neste bootstrap. `composer.lock` e homologação com MySQL continuam pendentes.

Frontend: [fluxo-repasse-app](https://github.com/paulodias-dev/fluxo-repasse-app).
