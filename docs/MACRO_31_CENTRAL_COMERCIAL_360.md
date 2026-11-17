# Macroentrega 31 — Central Comercial 360

## Módulo funcional entre veículos
A Macro 30 cadastrou minutas dentro de cada veículo. A Macro 31 entrega a **central global de operação comercial**, para localizar e acompanhar as minutas sem abrir dezenas de fichas individualmente.

- API global de consulta **somente leitura**, com busca por minuta, veículo e placa, filtro ativo/arquivado ou veículo ID, paginação de 20 e ordenação estável.
- Cinco indicadores do universo filtrado: total de minutas, ativas, arquivadas, modelo desatualizado/arquivado e veículo arquivado.
- Matriz de 10 campos por minuta, progresso exato de preenchimento e lista de campos ainda sem conteúdo. Flags de modelo desatualizado e veículo arquivado não equivalem à verificação de integridade/partes/anexos.
- Exportação CSV filtrada, streaming por lotes de 100, UTF-8 BOM e prevenção de fórmulas.
- Interface React na rota /commercial-portfolio, vinculada à sidebar e ao dashboard Metronic, com cards, filtros, tabela paginada, exportação, indicadores de risco documental e atalho direto para a aba comercial da ficha do veículo.
- JWT + session.current + dupla permissão cases.manage e scenarios.manage, throttle de leitura e cache no-store. Sem migrações: usa as tabelas da Macro 30.

### Contrato HTTP
GET /api/v1/commercial-portfolio?status=all|active|archived&search=&case_id=&page=
GET /api/v1/commercial-portfolio/export?status=all|active|archived&search=&case_id=

A resposta de listagem expõe data.summary e data.terms (paginador Laravel). Campo financial_authorization=false e payment_enabled=false são informativos, **não concedem privilégios**.

### Limitações explícitas
Instalação atual é single-org. Indicadores agregados são uma fila de triagem comercial, não "documentary_complete": associação vigente de participante, integridade SHA e anexos privados são validados na leitura do detalhe individual pela API já existente. Um campo preenchido também não é regra aprovada ou assinatura jurídica.

**Nenhum gateway/Pix/ledger/obrigação, autorização bancária ou alçada financeira é criado.** Regras de base, fato gerador, pagador, beneficiário, impostos, comissão, cancelamentos e isolamento organizacional permanecem dependentes de aprovação em docs/REQUISITOS_PENDENTES.md.

### Homologação pendente
A execução de PHPUnit/Pint/MySQL, lint/build e navegação React foi adiada por solicitação do operador. Antes de uso operacional, verificar permissões negativa/positiva, paginação com muitos veículos, CSV anti-injeção, revisão de modelos, responsividade e links de detalhe.
