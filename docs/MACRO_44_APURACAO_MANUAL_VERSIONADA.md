# Macroentrega 44 — Apuração manual versionada, uma macro completa API + React

## Resultado do MVP entregue em código
O operador já possuía na ficha Veículo 360 a preparação documental de uma minuta, valor de referência manual e modelo percentual. Esta macro completa a **apuração assistida e seu histórico**: acrescentar/deduzir valores explícitos, pedir ao backend o recálculo de rateio, salvar uma fotografia imutável, consultar versões e exportar. **Não é um módulo paralelo** de contas a pagar, saldo ou pagamento.

### Operação na mesma ficha Metronic
1. Abrir Veículo > Condições comerciais > minuta > Preparação do repasse.
2. Em **Apuração manual e histórico**, informar opcionalmente até 20 linhas: tipo `addition|deduction`, descrição 2–100 caracteres e valor informado em BRL (convertido para centavos inteiros pelo cliente).
3. Clicar Salvar apuração hipotética. Referência vem exclusivamente do campo manual da minuta. Nenhuma regra de impostos, taxas ou comissões é extraída do texto livre.
4. Backend confere contrato/modelo/partes/parecer/fonte, calcula `reference_cents + additions_cents - deductions_cents`; rejeita resultado não positivo, acima de 10^12 ou insuficiente para todos os participantes receberem 1 centavo na simulação.
5. O rateio usa **DistributionCalculator existente**, 10000 basis points explicitamente informados, maior resto e desempate por ID. `allocated_cents` sempre igual ao net e `difference_cents=0` em simulações válidas.
6. Fotografia recebe SHA-256, fonte snapshot SHA, revisão da minuta/modelo, rateio, parâmetros manuais, parecer documental, alertas e flags SEMPRE falsas de `financial_policy_approved`, `financial_authorization`, `obligation_created`, `payment_enabled` e `executable`.
7. Histórico paginado de 15 registros permanece consultável após alterações da minuta. `stale=true` sinaliza divergência da fotografia com a fonte atual; não retroedita dados históricos. Exportação CSV só após verificação SHA.
8. Para nova versão o operador seleciona explicitamente uma fotografia e salva novo registro com `replaces_record_id`; nenhum histórico é excluído.

### Persistência mínima
**Uma migration aditiva**, `2026_11_17_000003_create_preparation_records.php`, cria `repasse_preparation_records`. Guarda uma linha por fotografia: id, case_id, term_id, request_key UNIQUE, digest de intenção, SHA da fonte, JSON snapshot, SHA de conteúdo, valor referência/acréscimo/dedução/net, referência opcional à fotografia anterior, autor e data. Não existe `updated_at` nem endpoint UPDATE/DELETE.

**Importante:** não usar `migrate:fresh` em bases persistentes. Após backup e aprovação do PR de API, aplicar `php artisan migrate` normalmente. Não cria planos, credenciais ou lançamentos bancários.

### Contratos da API
- `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/records?page=1`
- `POST /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/records`
- `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/records/{record}`
- `GET /api/v1/repasse-cases/{case}/commercial-terms/{term}/preparation/records/{record}/export`

**POST:**
```json
{
  "request_key": "cb10aaf2-a65c-46ba-b7d2-a5f75f4fe2c5",
  "expected_snapshot_sha256": "SHA-256 hexadecimal da preparação atual",
  "adjustments": [
    {"kind": "addition", "label": "Acréscimo ajustado manualmente", "amount_cents": 1000},
    {"kind": "deduction", "label": "Dedução ajustada manualmente", "amount_cents": 100}
  ],
  "replaces_record_id": null
}
```
`adjustments=[]` é válido (nenhum ajuste); valor de referência manual é obrigatório na minuta e nunca enviado/arbitrado pelo cliente no POST de apuração. Apenas centavos inteiros, jamais floats.

**Segurança:** JWT Bearer + `session.current` + `cases.manage` E `scenarios.manage`. Request valida campos permitidos e `expected_snapshot_sha256`; transação com `lockForUpdate` do veículo e minuta; idempotência por `request_key`, payload/ator/escopo; replay igual retorna HTTP 200 created=false, novo registro HTTP 201, reuso divergente ou SHA da fonte desatualizado HTTP 409, entradas inválidas 422 e acesso cruzado 404. O servidor não confia em IDs de destinatários enviados pelo cliente.

**Integridade:** snapshot imutável com SHA-256 canônico de arrays associativos para evitar diferenças de ordenação de chaves JSON em MySQL e SQLite; arrays de linhas preservam ordem. A lista exibe `stale` de fonte; a leitura individual e a exportação revalidam integridade antes da saída. Hash não substitui assinatura jurídica ou WORM.

### Critérios de implementação/aceite
- Base explícita 10001, +1000, −100 => net 10901 centavos; rateio 60/40 deve retornar 6541 e 4360, diferença 0.
- Sem ajustes, salvar com `adjustments=[]` sem erro e rateio igual à referência.
- Mesma chave/payload/ator não duplica; chave reaproveitada para payload diferente retorna 409.
- Revisar minuta altera snapshot de origem, marca fotografias anteriores como stale e impede criação com SHA antigo. Retry da chave salva continua idempotente.
- Valor de referência ausente, net negativo/zero, excesso de ajuste, campo inesperado e veículo/minuta arquivados bloqueiam escrita sem efeitos parciais.
- JWT inválido 401, falta de qualquer permissão 403, veículo/minuta/registro de outro escopo 404.
- Histórico >15 registros em duas páginas; documento modificado indevidamente retorna 409 no show/export.
- Mobile 320–767 duas colunas de KPIs e ações; desktop preserva tabela/cards no Metronic original, sem app shell novo ou tela extra.

### Estado real dos testes
Testes feature **escritos mas não executados** nesta sessão. Testes MySQL/PHPUnit/Pint, build/lint React e navegador fazem parte da homologação final, que o responsável pediu executar depois da entrega do código do MVP. A etapa de repasse monetário real continua bloqueada por regras financeiras e governança não aprovadas.
