# Fluxo Repasse — Etapas funcionais do produto

A evolução a partir da macro 27 passa a ser acompanhada por **etapas completas**, não por contagem de micro-PRs. Cada etapa deve entregar API, React no layout adquirido, permissões, persistência, documentação e regressão focada.

| Etapa | Resultado verificável | Código | Homologação |
| --- | --- | --- | --- |
| 1 — Acesso e cadastros | Autenticação JWT, papéis/permissões, usuários, pessoas/empresas e vínculos | Desenvolvido | Pendente de evidência integrada |
| 2 — Veículo 360 | Cadastro, origem, compra/venda, participantes, anexos privados, tarefas, checklist, notas, histórico e central operacional | Desenvolvido | Pendente de evidência integrada |
| 3 — Financeiro gerencial do veículo | Lançamentos, categorias editáveis, retificação, estorno, comprovantes privados vinculados, auditoria, filtros, relatório por categoria/mês e CSV | Macro 27 desenvolvida | Pendente de migrations/tests/browser |
| 4 — Cenários e revisão documental | Fluxo 360 integrado por veículo: simulações, revisão segregada, estados/obsolescência, pacotes SHA/ZIP e CSV documental | Macro 28 integrada em código | PHPUnit/MySQL + build/mobile/E2E pendentes |
| 5 — Motor efetivo de repasses | Minutas comerciais por veículo: modelo congelado, condições declaradas, anexos, versões, matriz de pendências e simulações não executáveis; ainda faltam regras aprovadas para cobrança, obrigação, pagamento e conciliação | **Macro 30: módulo comercial codificado; motor de dinheiro real segue bloqueado por escopo** | Validação de migração/UI e políticas comerciais ainda pendentes |
| 6 — Homologação e entrega operacional | Dashboard com módulos reais, ficha 360 resiliente, diagnóstico estrutural e runner API/React/smoke local protegido | Macro 29 codificada (gates preparados) | Testes reais, backup/restore, QA visual e aceite ainda pendentes |

**Regra:** não dividir mais uma etapa de negócio em melhorias isoladas de navegação/QA. Construir o módulo com CRUD completo, consultas, filtros, segurança e integração no ambiente comprado. Só abrir PRs em conjunto ao final da etapa, sem draft.

**Separação obrigatória:** Valores do Financeiro 360 são **gerenciais**, não prova de pagamento. Simulação/parecer não autoriza remessa de dinheiro. Etapa 5 depende de aprovação explícita de quem paga, quem recebe, valor/percentual, momento, estorno, taxa, tributos, regras de elegibilidade, PSP/banco, armazenamento e isolamento organizacional.

**Métricas:** "Etapa 3 codificada" não significa "etapa homologada" ou "% total do produto". O indicador antigo de 6/12 marcos técnicos (50%) pertence à fundação QA e não mede a conclusão financeira real.


**Macro 31 (código em PR):** Central Comercial 360 unifica a carteira de minutas dos veículos com busca, indicadores de risco documental, filtros, CSV e link para edição, sob o Metronic. Etapa 5 executável permanece bloqueada por escopo; não há repasse real nem autorização bancária.


**Macro 32 em PR — Revisões comerciais:** tramitação documental por versão congelada, revisor segregado, atribuição, comentários, decisão e retirada, histórico encadeado e consulta Metronic. Etapa 5 executável continua sem escopo financeiro aprovado; QA pendente.


**Macro 33 em PR — Simplicidade operacional:** totais reais da frota de compra, despesas, receitas extras e margens projetadas apenas para veículos com dados suficientes; lançamento rápido e cadastro essencial na interface React. Sem schema novo ou funções redundantes; execução de QA pendente.


## Macro 34 — Mobile-first: veículo → despesa/receita → venda → resultado

**Critério obrigatório de produto:** o usuário resolve o uso principal no celular. Não multiplicar módulos, navegações, formulários ou tabelas de domínio sem benefício operacional. Priorizar componentes de toque, decisões claras, campos essenciais e estados de erro/vazio preservados; funções avançadas ficam disponíveis sob demanda.

- [x] Frota no celular em cards (não tabela horizontal) com ações diretas +Despesa, +Receita, Registrar/Revisar venda e Ver ficha.
- [x] Visão móvel resumida de compra, despesas e resultado, com busca direta e filtros avançados recolhidos.
- [x] Veículo 360 com ações rápidas de toque e modal curto para valor/data de venda; comprador opcional, sem outro módulo ou tela de vendas.
- [x] Entrada financeira mobile com tipo em botões, valor, descrição, categoria com sugestões reais, situação e data opcional recolhida.
- [x] Lançamentos financeiros em cards no mobile; comprovantes e estornos mantidos, com rolagem para detalhes abertos e preservação da experiência de desktop.
- [x] URL de ação rápida limpa ao salvar/cancelar, evitando reabertura acidental.
- [x] Resultado de venda datada considerado à parte da projeção; despesas/receitas previstas não entram no resultado de venda, somente no projetado. Sem dupla contagem da venda como receita extra.
- [x] API valida preço positivo e data da venda não anterior à compra, usando PATCH do veículo já existente.
- [x] OpenAPI atualizado, testes de regressão de venda e agregação escritos.
- [x] **Zero migrações, zero módulos e zero novos endpoints**.
- [ ] Executar testes PHP/MySQL, build/lint TypeScript e inspeção visual real em iPhone/Android/desktop para homologação.

**Limite:** resultado da venda é gerencial, não constitui lucro contábil nem confirmação de recebimento bancário. O estado 'arquivado' é independente de ter registrado data de venda. Nenhum pagamento, PSP ou Pix é produzido.


## Macro 35 — Marcar despesa/receita prevista como realizada

- [x] Jornada simples: veículo → Financeiro → lançamento previsto → marcar realizado → confirmar data.
- [x] API usa FormRequest e operação transacional protegida por JWT + sessão vigente + cases.manage, lock e status.
- [x] Atualiza o MESMO lançamento, preservando ID, valor, categoria, descrição e comprovantes. Não cria segunda linha de receita/despesa.
- [x] Auditoria específica 'realized' guarda status/data anteriores, operador e data nova; caso também registra evento.
- [x] Double-tap/retry com mesma data retorna changed=false. Data diferente depois da realização responde 409 e indica Retificar.
- [x] Bloqueia lançamento estornado/substituído, veículo arquivado e datas futuras; 404 para outro veículo.
- [x] API recalcula demonstrativos existentes por status, sem novo SQL de totais nem migration.
- [x] React no financeiro Metronic: filtros Todos/Previstos/Realizados, ação rápida, confirmação compacta com data e acesso a comprovantes.
- [x] Cards de resumo e lançamentos em duas colunas mobile (col-6), botões compactos e fidelidade ao Demo 1.
- [x] Swagger/OpenAPI 1.14, testes de regressão escritos e sem novos grants financeiros.
- [ ] Homologação pendente: PHPUnit SQLite/MySQL, Pint, build/lint React e conferência visual desktop/mobile.

Realizado significa ocorrência gerencial declarada; NÃO é confirmação de pagamento, Pix, recebimento em banco ou liquidação. Endpoint devolve payment_executed=false. Sem regras financeiras reais aprovadas, não habilita transações.


## Macro 36 — Comprovantes rápidos no celular (sem inflar)

- [x] Na ficha do veículo → Financeiro, cada lançamento ativo mostra **Foto** (câmera, quando suportada) e **Arquivo** (galeria, foto ou PDF) nos cards mobile de duas colunas Metronic.
- [x] O desktop mantém a tabela original, com **Anexar** direto na linha; o detalhe oferece as mesmas opções sem criar página/modal/fluxo paralelo.
- [x] Após selecionar, o arquivo é enviado ao lançamento atual, com feedback de envio, erro local no card, detalhes e comprovantes atualizados e scroll para o registro selecionado.
- [x] Formatos aceitos: PDF, JPEG/JPG, PNG, WebP até 10 MiB. Arquivo HEIC não é aceito; apresentar instrução de formato compatível. Sem conversão automática e sem armazenamento em browser.
- [x] API conserva JWT + session.current + cases.manage, veículo ativo, lançamento não estornado, armazenamento privado, nome higienizado e SHA-256 para verificação de download.
- [x] Upload **idempotente por SHA-256 dentro do mesmo lançamento ativo**, com lock existente e 200 created=false para foto/PDF repetido; sem nova cópia, tabela ou evento.
- [x] Isolamento: uploads iguais em lançamentos diferentes continuam documentos privados separados, sem reutilizar vínculo de outro lançamento.
- [x] Um componente local reutiliza os controles Foto/Arquivo em card, tabela e detalhe; **vínculo de documento anterior** fica recolhido como opção avançada.
- [x] Comprovantes são exibidos em duas colunas no celular e em tabela no desktop; download com autenticação e desvinculação protegida preservados.
- [x] FormRequest de upload, OpenAPI 1.15 e testes feature escritos para duplicidade, vínculo por lançamento, limite, formato e 401/403.
- [x] **Zero novos endpoints, tabelas, migrations, permissões, páginas ou dependências.**
- [ ] QA real e homologação: PHPUnit SQLite/MySQL, Pint, build/lint/Vitest React e conferência visual em Android/iPhone/desktop.


## Macro 37 — Rotina diária da frota (prioridade sem nova tela)

- [x] Reutiliza GET /api/v1/operations/vehicles, indicadores existentes e PATCH de tarefas. **Não cria central, banco, endpoint ou grant novos.**
- [x] Novo sort=priority é calculado no SQL ANTES da paginação: veículos ativos com tarefas vencidas → abertas → despesas previstas → identificação a completar → demais ativos → arquivados. Empate estável por quantidade de tarefas e atualização/ID.
- [x] Nenhum prazo artificial para despesas, obrigação documental, pagamento, comissão ou SLA é criado pela ordenação.
- [x] FormRequest específico para o filtro da carteira, preservando JWT Bearer, session.current, cases.manage e validações anteriores.
- [x] A tela Meus veículos usa sort=priority como escolha padrão DO APP, mantendo sort=updated como padrão da API antiga para compatibilidade.
- [x] Filtros de frota (busca, atenção, situação, ordenação e página) passam a ser lidos/escritos na URL, permitindo links diretos reais e navegação de voltar/avançar.
- [x] Quatro filtros compactos com valores reais (vencidas, tarefas abertas, despesas previstas, identificação); contagens podem se sobrepor; estado de filtro é evidente.
- [x] Nos cards mobile, cada veículo tem a próxima ação operacional baseada em contagens atuais; atalhos abrem a aba Pendências, Financeiro previsto ou Ficha. Ações de compra/venda/finanças permanecem.
- [x] Ficha do veículo permite **Concluir tarefa** diretamente no celular, mantendo select de situação no desktop, ordem de tarefas abertas antes das concluídas e formulários compactos.
- [x] Deep link 'financeStatus=planned' apresenta os lançamentos previstos na mesma aba Financeiro (sem nova tela).
- [x] Dashboard Metronic mantém cards de indicadores col-6 no mobile e apresenta atalhos compactos vinculados à lista de frota filtrada; funções avançadas permanecem acessíveis.
- [x] OpenAPI 1.16 documenta priority; feature tests escritos para ordem, arquivamento, paginação/filtros, conclusão de tarefa e realização de despesa.
- [ ] QA ainda não executado: composer test/lint, PHPUnit SQLite/MySQL, build/lint React e inspeção visual real mobile/desktop.

Nenhuma mutação financeira nova ou integração bancária. Medição global de conclusão não pode ser derivada destas etapas sem reavaliar requisitos e homologação. Não executar migrate:fresh --seed: esta macro não tem migration.


## Macroentrega 41 — Comparativo de rentabilidade REAL da frota (etapa integrada API + React)

**Status do código:** implementado em branch; testes PHPUnit/MySQL e homologação de navegador ainda pendentes. O frontend correspondente integra a MESMA macro no repositório `fluxo-repasse-app`, sem subdividir em pequenas etapas.

- [x] Extensão do endpoint existente `GET /api/v1/operations/vehicles` com filtro `performance`: all, sold_profit, sold_loss, sold_break_even, sold_unknown, not_sold e purchase_missing.
- [x] Margem de venda datada = venda + receitas extras realizadas − compra − despesas realizadas. Não inclui previstos, estornados, recebimentos bancários nem lucros presumidos. Compra ausente (NULL) é distinta de compra zero.
- [x] Ordenações `sale_result_desc` e `sale_result_asc` no SQL **antes da paginação**, apenas margens conhecidas primeiro, NULL por último, desempate determinístico por id.
- [x] Indicadores globais: vendas datadas/sem venda, compra não informada, margens positivas/negativas/zero, venda sem margem apurável e somatório SEPARADO de margens positivas/prejuízos, preservando o total líquido anterior.
- [x] Busca, atenção operacional, situação draft/archived e filtro de rentabilidade combináveis. Indicadores sempre do portfólio autorizado inteiro, independentemente da página e do filtro. Arquivado pode ser venda lucrativa/prejuízo, mas não participa de filtro operacional de pendência.
- [x] Mesmo JWT/sessão vigente e `cases.manage` (sem permissões novas); apenas leitura. Sem tabelas, migrations, endpoints, escrita financeira ou código bancário novo.
- [x] OpenAPI atualizado para 1.17.0 e feature tests adicionados para classificação, concorrência lógica com despesas realizadas/previstas/estornadas, saldo assinado, filtros combinados, arquivados, 19 veículos em 2 páginas, NULL, validação 422, JWT 401 e acesso 403.
- [ ] **QA obrigatório ainda não executado:** `composer test`, `composer lint`, teste MySQL 8/SQLite QA e smoke HTTP autenticado. Não atestar produção sem esses gates.

**Progresso:** entrega funcional codificada em ambos repositórios, mas % global de conclusão do produto não é inferível desta macro.
