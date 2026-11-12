# Arquitetura — API

Laravel 12, PHP >= 8.2, MySQL 8+, API JSON versionada.
Camadas iniciais:
- `bootstrap/app.php`: API e console.
- `routes/api.php`: namespace público `/api/v1`.
- `app/Http/Controllers/Api/V1/HealthController.php`: health público sem dados sigilosos.
- `config/cors.php`: origem de desenvolvimento explícita.
- `tests/Feature/ApiHealthTest.php`: contrato do endpoint e ausência de rota financeira.
- Demais diretórios de Laravel oficiais para configuração, migrations de infraestrutura, testes e bootstrap.

## Princípios futuros (dependem de aprovação)
Controllers finos; Request/Policy/Service por caso de uso, transações ACID, constraints DB, auditoria imutável e idempotência em toda alteração de estado.
Isolamento tenant/organização somente após escolha de modelo operacional. Documento OpenAPI como contrato para a UI, com testes de autorização e concorrência.
Não inferir fórmula ou estruturas financeiras do nome “Repasse”.

## Contrato entregue
`GET /api/v1/health` 200 application/json:
```json
{"status":"ok","service":"fluxo-repasse-api","version":"v1"}
```
Sem autenticação porque só revela metadados não sensíveis. Nenhuma rota transacional habilitada.
