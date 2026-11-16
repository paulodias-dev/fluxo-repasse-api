# Macroentrega 18 — Motor de cenários de repasse (prévia não executável)

## Produto entregue em código

Esta macro reúne sete frentes integradas API + APP, sem criar pagamentos reais:

1. Construtor de cenários: cadastro, pesquisa, consulta, edição, arquivamento/reabertura, dossiê, título e descrição.
2. Distribuição prevista: origem escolhida pelo operador, até 50 destinatários do mesmo dossiê e valores manuais em centavos inteiros (int64, sem float).
3. Conferência automática de soma e diferença: missing_source, missing_allocations, underallocated, overallocated e balanced. **Balanced NÃO é aprovação nem autorização de pagamento.**
4. Idempotência e concorrência: client_reference UUID único e hash inicial; replay com payload idêntico retorna o mesmo cenário; revisões só por correspondência exata de revision, com HTTP 409 em caso de conflito.
5. Histórico imutável na aplicação: cada alteração gera snapshot JSON, revisão, SHA-256, ator e data; não há endpoints para editar ou remover histórico.
6. Comparação e alternativas: variação bruta, origem e montantes por pessoa/empresa entre duas versões; fork de revisão antiga para cenário independente.
7. Indicadores e exportação: contagem de cenários, validação de valores previstos e CSV autorizado, com proteção contra fórmulas de planilha.

## Persistência

Migration aditiva 2026_11_16_000004_create_repasse_scenarios.php:
- repasse_scenarios: vínculo com dossiê, referência idempotente, hashes inicial/atual, origem, valor em centavos, revisão e arquivamento lógico.
- repasse_scenario_allocations: destinatários e alocações previstos; uma linha por cadastro/cenário.
- repasse_scenario_versions: snapshots históricos e SHA-256 por revisão, data, autor e evento.
Eventos também são registrados na trilha existente repasse_case_events, sem payload financeiro no evento.

## Segurança e APIs

13 endpoints /api/v1/finance na especificação OpenAPI 1.1: indicadores, busca de dossiês/participantes, listar/criar/consultar/substituir cenários, arquivar/reabrir, listar/examinar/comparar versões, criar alternativa e exportar CSV.

Todas as rotas exigem JWT + sessão atual + cases.manage E scenarios.manage; a nova permissão scenarios.manage é concedida ao papel técnico via seeder idempotente, não automaticamente a usuários comuns.

Escritas sob transação com locks e revisão otimista; dossiê arquivado impede mutações; origem e destinatários precisam constar como participantes ativos do dossiê. Valores têm limite seguro e não há conversão por ponto flutuante.

BRL é apenas a moeda fixa do planejador atual, não decisão de política monetária real. Não assumir regras de empresas distintas, pois o modelo de isolamento multiempresa ainda não foi aprovado. Não habilitar o módulo como serviço multi-organização sem esse requisito.

## Exclusões indispensáveis

**NÃO existe nenhum saldo, comissão automática, imposto, taxa, cobrança, ledger, obrigação, aprovação financeira, Pix, saque, webhook, liquidação, conciliação, transferência ou gateway.** O estado balanced indica exclusivamente que a soma proposta é igual ao valor previsto.

O SHA-256 dos snapshots detecta alteração isolada dos dados; não substitui armazenamento externo imutável nem controles administrativos/backup.

## Implantação segura

1. Validar branch e fazer backup do banco de desenvolvimento autorizado.
2. Executar apenas php artisan migrate, sem migrate:fresh.
3. Executar php artisan db:seed --class=AdminUserSeeder de forma idempotente; renovar sessão.
4. Teste direcionado: php artisan test --filter=RepasseScenariosTest; Pint nos arquivos alterados; lint/build frontend.
5. Smoke integrado: criar dossiê com participantes, criar cenário incompleto e balanceado, testar replay idempotente, conflito 409, snapshots, comparação/fork, arquivamento e CSV, 401/403.
6. Verificar que nenhuma rota executa pagamento ou grava movimentação financeira.

**Testes e migrations não foram executados no ambiente Laravel/MySQL nesta sessão.** Não marcar como homologado até o QA focalizado e build.

## Próximo grande bloco

Somente após especificar e aprovar: pagador e destinatário reais, fato gerador, comissões/rateio, retenções/impostos, alçadas, reconciliação, estorno, gateways, moeda e single-org/multiempresa. Não reutilizar regras de outros projetos.
