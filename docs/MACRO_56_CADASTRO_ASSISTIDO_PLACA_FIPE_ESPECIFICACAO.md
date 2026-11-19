# Macroentrega 56 — Cadastro assistido de veículos: placa, catálogo e FIPE
**Status: PLANEJADO — SOMENTE ESPECIFICAÇÃO; NENHUM CÓDIGO IMPLEMENTADO.**  
**Produto:** Fluxo Repasse, módulo existente Meus veículos / Veículo 360.  
**Data da decisão:** 19/11/2026. **Prioridade:** evolução do cadastro do MVP empresarial antes da discussão/implementação do Financeiro.  
**Fonte visual obrigatória:** Metronic Demo 1 da `main`, PWA mobile-first; Macro 50/55 e `AGENTS.md`.  
**Referências de código existentes:** React `src/app/modules/repasse/VehiclesPage.tsx` + `api.ts`; Laravel `app/Http/Controllers/Api/V1/RepasseCaseController.php`; cadastro express Macro 39; contratos de sessão/tenant Macro 51.  
**Documento de planejamento:** requisitos, narrativa, decisões, contrato proposto, checklist técnico, matriz de testes e critérios de aceite. Exigir revisão de contrato de fornecedor e QA real antes da implementação/produção.

## 1. Objetivo, contexto e fronteiras
Hoje o cadastro express exige somente `title` (identificação) e permite placa/compra opcionais. O novo fluxo deve reduzir digitação e erros mantendo a simplicidade do MVP. Um único acionamento **Cadastrar veículo** abrirá uma escolha entre **Consultar pela placa** e **Escolher meu veículo** (catálogo guiado). Ambos desembocam no **mesmo formulário de conferência** e no **mesmo POST de veículo**, sem duplicar cadastros, shell, sidebar, módulos, financeiros ou tabelas paralelas.

**Princípios inegociáveis**
1. **Placa opcional na criação**, com pendência visível quando ausente; não bloquear o operador se não possui placa, se o catálogo está incompleto ou se fornecedor externo caiu. **No fechamento da venda**, exigir placa válida quando aplicável ou um caminho excepcional justificado e auditável para veículo legitimamente sem placa. A regra exata de exceção requer validação de produto/jurídica antes de alterar endpoint de venda; enquanto não aprovada, não introduzir trava nova silenciosa.
2. **FIPE é referência de preço médio, não identificação garantida, avaliação individual nem preço de compra/venda.** Nunca copiar valor FIPE para `purchase_amount_cents`, `sale_amount_cents`, receita ou resultado. Valor de compra permanece input voluntário do operador.
3. **Consulta de placa ≠ consulta FIPE**: atributos de placa vêm de serviço externo próprio; preço depende de correspondência precisa com tipo, marca, modelo, ano-modelo e versão/código FIPE. Se ambíguo, pedir escolha. Não alegar API oficial da FIPE.
4. **Campos editáveis e revisão humana** antes de gravar; dados de fornecedor nunca prevalecem sobre revisão explícita do operador e nunca geram compra/venda/receita/despesa/anexo automaticamente.
5. **Isolamento total entre tenants**, JWT e autorização sempre no servidor; nunca confiar em tenant_id do cliente. Nenhuma consulta por placa revela veículos, custos, histórico ou identidade de outro tenant.
6. **PWA nativo**: poucos passos, uma decisão por vez, botões proporcionais, touch acessível, foco, fluxo cancelável, sem exigir Internet para preencher campos manualmente (salvar continua online).
7. **Sem novo produto social/Marketplace**, sem mudanças financeiras e sem execução nesta macro de documentação.

## 2. Narrativa de experiência — histórias do usuário

### 2.1. Primeira ação e escolha
Operador autenticado com `cases.manage` toca em **Adicionar veículo** na Frota. Em telefone, abre seletor em **bottom sheet** compacto de duas opções visuais, com texto objetivo, compatível com safe area e teclado. Desktop exibe modal central Metronic. A primeira opção diz **Consultar pela placa** (informar placa e tentar identificar automaticamente). A segunda diz **Escolher meu veículo** (tipo, marca, modelo, ano-modelo e versão). Há botão Fechar e retorno sem perda de contexto. A seleção é apresentação de um único fluxo, não duas fichas.

### 2.2. Caminho A — consulta de placa
1. Digitar ou colar placa Mercosul/antiga; máscara amigável opcional. Normalizar localmente para maiúsculas alfanuméricas, validar formato sem proibir colar.
2. Tocar **Consultar** explicitamente. Não consultar a cada caractere nem novamente por renderização; informar claramente consulta em andamento e permitir cancelar/seguir manual.
3. Backend verifica JWT, sessão/tenant e permissão, aplica rate limit, consulta provedor escolhido em servidor e devolve **somente atributos autorizados** (sem proprietário, CPF, RENAVAM, restrições ou outros dados sensíveis desnecessários).
4. Se identificação inequívoca: apresentar marca, modelo, ano-modelo, eventual ano de fabricação, tipo, combustível e versão **se realmente informados**, com indicação **Dados encontrados — confira antes de salvar**. A FIPE é buscada apenas após correspondência inequívoca de versão/código, com competência e fonte.
5. Se houver duas ou mais versões plausíveis: mostrar seleção curta **Qual versão é a sua?** com dados diferenciadores; não adivinhar/atribuir FIPE a qualquer versão.
6. Se placa válida mas não encontrada, fornecedor indisponível, resultado incoerente ou cotação ausente: mensagem não alarmista, **Escolher veículo manualmente**, preservando placa digitada. Não expor erro técnico.
7. Seguir para **Conferir cadastro**, com campos preenchidos/editáveis e placa opcional persistida se informada. Exibir separadamente **FIPE (mês/ano)** e **Preço de compra (opcional)**. Salvar não depende de FIPE.
8. Se o usuário alterar tipo, marca, modelo, ano-modelo ou versão após uma cotação, **invalidar imediatamente a cotação anterior** e solicitar nova correspondência; nunca exibir preço antigo como válido.

### 2.3. Caminho B — cadastro guiado
1. **Tipo**: exibir apenas grupos suportados pelo catálogo contratado (p. ex. carros/utilitários, motos, caminhões/micro-ônibus); não anunciar suporte indisponível.
2. **Marca**: busca por nome, opções ordenadas, sem centenas de itens sem filtro; seleção única.
3. **Modelo**: lista filtrada pelo tipo + marca; tolerar nomes semelhantes sem confundi-los.
4. **Ano-modelo**: opções válidas para o modelo; **ano-modelo ≠ ano de fabricação**. Ano de fabricação, se coletado, será campo distinto e opcional.
5. **Versão**: apresentar descrição completa com motorização, câmbio e combustível onde fornecido; exigir escolha explícita quando múltiplas versões distintas.
6. Cotar FIPE para combinação confirmada e competência informada; **sem valor encontrado** não bloqueia cadastro. Opcional informar placa agora ou depois.
7. Confirmar e salvar no mesmo formulário utilizado pelo caminho A. Em cada etapa: Voltar, escolha atual visível, progresso discreto e recurso de trocar de caminho mantendo o que faz sentido.
8. Se catálogo falhar/ficar sem itens ou não cobrir veículo, oferecer **Preencher dados manualmente**, preservando entradas já digitadas; permitir título identificador livre como o cadastro express legado.

### 2.4. Conclusão comum e pós-cadastro
- Formulário final curto: identificação (`title`) obrigatória ou gerada a partir da escolha **mas sempre editável**; tipo/marca/modelo/ano/versão se existentes; placa opcional; valor de compra, quilometragem e observações opcionais quando suportados/implementados. Não confundir ausência com zero.
- Campos adicionais ficam em **Mais dados**; **Salvar veículo** abre Veículo 360 e **Salvar + despesa** abre rascunho do financeiro, sem registrar despesa por conta própria. Conservar os dois caminhos do cadastro express.
- Rótulo **Placa pendente** na ficha/lista quando ausente; não exibir bloqueio permanente de navegação. Ações de venda/conclusão seguem invariantes atuais até regra de pendência ser aprovada e implementada.
- Sucesso deixa o modal, exibe resultado da API e não grava duas vezes por toque duplo, retry ou navegação; ausência de rede não implica sucesso e rascunho não é enviado sem confirmação.

## 3. Decisões aprovadas, propostas e pontos que exigem decisão
| Assunto | Estado | Regra |
| --- | --- | --- |
| Dois caminhos no botão de cadastro | **Aprovado** | Placa e seleção guiada em um único fluxo |
| Placa no cadastro inicial | **Aprovado** | Recomendada, mas opcional; sinalizar pendência |
| Cadastro sem serviço externo | **Aprovado** | Manual e guiado devem continuar utilizáveis |
| UX mobile nativa | **Aprovado** | Bottom sheet na escolha; próximas etapas compactas, responsivas |
| FIPE vs custo de compra | **Aprovado** | Dados independentes; nunca lançamento automático |
| Validação da placa na conclusão da venda | **Diretriz aprovada, exceção em revisão** | Exigir quando aplicável, definir exceção de placa ausente e auditoria antes de alterar regra atual |
| Provedor de consulta por placa e catálogo/FIPE | **Pendente** | Escolha com contrato, cobertura, licença, custos, SLA e política LGPD |
| Armazenamento de versão/código FIPE e histórico | **Proposta técnica** | Modelagem aditiva, sem mudar campos legados indevidamente |
| Atualização automática de cotação em veículo já salvo | **Não aprovada** | Não atualizar silenciosamente nem compra/venda nem histórico |
| Placa duplicada em operações distintas | **Proposta técnica** | Prevenir duplicata ativa no mesmo tenant; permitir nova operação legítima após arquivamento com histórico |
| Tipo/quilometragem e dados novos | **Proposta técnica** | Só adicionar campos após confirmação de necessidade e compatibilidade |

## 4. Integridade semântica e campos
| Dado | Origem autorizada | Pode ser editado? | Regras |
| --- | --- | --- | --- |
| `title` | Escolha/nome digitado | Sim | 2–180 caracteres; existente e obrigatório |
| `plate` | Digitação ou confirmação | Sim | Opcional na criação, formato válido quando preenchida; normalização canônica |
| `brand`, `model`, `model_year` | Provedor ou seleção/manual | Sim | Existing: `brand` até 100, `model` até 120, `model_year` inteiro |
| `vehicle_type`, `version`, `fipe_code` | Catálogo, quando licenciado | Sim/seleção | Campos **propostos**, não existem no contrato atual; decidir schema e exposição |
| `manufacture_year`, `fuel`, `transmission` | Provedor/revisão | Sim | Metadados opcionais; nunca inferir sem fonte |
| FIPE em centavos + referência | Fonte licenciada e versão confirmada | Valor só de consulta | Propor registro separado com timestamp, fonte, competência, código e status; não é valor operacional |
| `purchase_amount_cents` | Operador | Sim | Existing; inteiro centavos, nullable ≠ 0; nunca derivar da FIPE |
| `sale_amount_cents` | Operador na venda | Sim pela regra de venda | Não pré-preencher da FIPE |
| `chassis` e documentos | Operador, fluxo atual | Sim com autorização | Não requisitar/desvendar dado restrito de placa |
| `source_confidence`/origem | Backend | Não diretamente | Exibir `confirmado`/`requer escolha`/`não encontrado`, nunca score fictício |

**Regra de correspondência:** tipo + marca + modelo + **ano-modelo** + versão exata/código FIPE; não escolher valor arbitrário por menor distância textual. Uma placa pode indicar apenas modelo genérico e ano, exigindo escolha de versão. Em divergência entre placa e catálogo, mostrar conflito; escolha do usuário não apaga o sinal de divergência nem sobrescreve campos sem confirmação. Se a referência for antiga, marcar **Referência de MM/AAAA** em vez de anunciar preço atual.

## 5. Integrações e diligência de fornecedor — bloqueio antes de codificar
A FIPE declara oficialmente que **não oferece API nem download de sua base** para esse uso: https://www.fipe.org.br/pt-br/indices/veiculos/. Ela publica consultas por modelo/ano-modelo e competência mensal. Portanto, **não presumir endpoint oficial**, scraping irrestrito ou direito de redistribuir dados. Uma integração comercial/terceirizada deve ser juridicamente permitida e testada.

**Checklist de compra técnica (não preencher ficticiamente):**
- [ ] Definir fornecedor de placa, fornecedor/catálogo de versões e fonte contratada para preço FIPE (um ou vários) com termos escritos.
- [ ] Verificar licença de consulta, exibição, retenção, cache, logs, redistribuição e uso por SaaS multiempresa.
- [ ] Medir cobertura de placas antigas/Mercosul, categorias, ano-modelo/versões, dados ausentes, tempo médio/p95, taxa de erro e precisão com amostra real autorizada.
- [ ] Checar preço por chamada, franquia, limites, burst, ambientes de teste e alertas de orçamento; calcular custo por cadastro sem registrar números hipotéticos como reais.
- [ ] Confirmar disponibilidade, SLA, política de manutenção, suporte, contingência, descontinuação e mudança de schema.
- [ ] Validar origem/licitude dos dados, controlador/operador, DPA/contrato LGPD, região do armazenamento e suboperadores, se aplicável.
- [ ] Testar payloads reais autorizados (sem colocar placa real ou dados pessoais em fixtures públicas); criar adapter substituível e mocks determinísticos.
- [ ] Aprovar a escolha e registrar evidência antes de abrir tarefa de integração real.

## 6. Arquitetura alvo (proposta, sem implementação)
```text
React/Metronic (apenas apresentação, sem chaves)
   ├─ escolha placa ──> GET /api/v1/vehicles/lookup/plate?plate=... [PROPOSTO]
   ├─ escolha guiada ─> GET /api/v1/vehicles/catalog/types|brands|models|years|versions [PROPOSTO]
   ├─ versão definida > GET /api/v1/vehicles/fipe/quote?... [PROPOSTO]
   └─ confirmação ────> POST /api/v1/repasse-cases [EXISTENTE, evoluir contrato de forma compatível]
                                │
Laravel API: JWT + session.current + cases.manage + rate limit
   ├─ AdapterPlateProvider (fornecedor permitido, payload mínimo)
   ├─ AdapterCatalogProvider (catálogo tipado, IDs estáveis)
   ├─ AdapterFipeProvider (cotações licenciadas por competência)
   ├─ normalizador/validador + cache seguro + proteção contra falhas
   └─ TenantDB: persiste somente dados confirmados e tenant correto
```
Os nomes das rotas são **contratos propostos**, não promessas de endpoints existentes. Evitar um GET por letra digitada; buscar catalogação com filtros estritos, paginação/limites, debounce local somente no filtro de opções quando adequado, abort de respostas antigas. Cada consulta externa usa `request_id` técnico e observa as restrições anti-cache `no-store` para dados associados à placa; dados de catálogos sem vínculo pessoal podem ter cache controlado conforme licença.

### 6.1. Contratos normalizados propostos
- Resposta placa `{status: "matched"|"ambiguous"|"not_found"|"temporarily_unavailable", normalized_plate, vehicle: {type,brand,model,model_year,manufacture_year?,fuel?,version?}, alternatives:[], provenance:{provider_code,checked_at}, warnings:[]}`.
- Catálogo: itens `{id,label}` ordenados, IDs persistentes **por versão do catálogo**, paginação/limites, filtro dependente do nível anterior; `null` não vira string vazia oficial.
- Cotação: `{status:"matched"|"ambiguous"|"not_found"|"unavailable", fipe_code?, description?, reference_month:"YYYY-MM", price_cents?, currency:"BRL", checked_at, source}`; retornar zero apenas quando fonte comprovadamente o informou, nunca como ausência.
- Falhas HTTP API: 401 sessão, 403 concessão, 404 registro interno inacessível ou `not_found` de catálogo conforme contrato, 422 placa/filtros inválidos, 429 limite, 503/504 fornecedor indisponível, 409 conflito de versão/duplicidade. Evitar expor estrutura do provedor.
- Escrita: continuar usando `POST /api/v1/repasse-cases` com `title` obrigatório; evoluir payload apenas com campos efetivamente aprovados e validação FormRequest. Nunca enviar `tenant_id`, preço FIPE disfarçado de compra, payload bruto da consulta, `provider_secret` ou campos desconhecidos.
- Quando preço FIPE for preservado, guardar snapshot `fipe_code`, `reference_month`, `price_cents`, `queried_at`, `source_id`, `vehicle_id`, `tenant_id` em tabela aditiva privada **somente se licença permitir**; não mutar registros passados silenciosamente.
- O frontend não deve confiar no preço livremente enviado pelo cliente como cotação autenticada: API revalida o código/competência ou recebe token opaco de cotação assinado de curta duração, se o produto precisar persistir a referência, sempre tenant-scoped.

### 6.2. Segurança, isolamento, privacidade
- Apenas servidor consulta fornecedor; chaves em secrets/env fora do Git; rotas fixas allowlist, HTTPS, timeouts, redirects externos bloqueados, defesa SSRF e limites de tamanho/formato JSON.
- `JWT tid` + middleware de sessão ativa + `cases.manage` em todas as consultas; buscar veículo salvo sempre com TenantDB. Usuário não autenticado/sem autorização não consulta nem enumera placas.
- Não aceitar tenant_id, provider URL, user_id ou custo em query para abrir escopo; retorno nunca contém nome do proprietário, CPF, endereço, RENAVAM, dado de restrição policial ou resultado financeiro.
- Auditoria mínima: ator, tenant, ação, sucesso/falha classificada, timestamp, correlação e origem genérica; sem placa bruta, token, payload fornecedor ou dados de terceiros em logs/telemetria. Placa protegida no banco conforme política LGPD; se cache de placa existir, **segregar por tenant**, criptografar onde aplicável, definir TTL curto, expurgo e contrato de retenção.
- Apenas catálogo público/licenciado e desacoplado da pessoa pode ser cacheado globalmente; **nunca** resposta de placa ou ficha de outro tenant. Toda resposta autenticada/no-store; service worker PWA não faz runtime-cache de API/dados privados.
- Validar normalização de placa no servidor (padrões brasileiros aplicáveis, incluindo `AAA9999` e `AAA9A99`), com casos reais/exceções acordadas; input em letras maiúsculas, sem espaços/hífens na representação canônica.
- Proteção contra enumeração de placas, replay malicioso, brute force e consumo de quota: rate limiting por usuário+tenant+IP de origem conforme política de privacidade, backoff e limites por plano/fornecedor; contadores internos sem mostrar existência de veículo de outra empresa.
- Checar duplicidade de placa ativa **por tenant**, não globalmente. Proposta: 409 com redirecionamento à ficha existente apenas se o usuário tem permissão; outra empresa continua invisível. Novo ciclo de negócio com placa antiga/arquivada pode ser legítimo. Confirmar regra e implantar salvaguarda transacional contra corrida.
- Tratar erros de dados desatualizados e consultas concorrentes: resposta atrasada da placa A nunca preenche formulário cujo input atual é placa B; abort, chave da requisição e validação de intenção.
- Não usar analytics externos com dados do formulário/placa; não persistir dados sensíveis em localStorage; navegação offline deve respeitar proteção de sessão.
- Testar XSS em campos externos e em `title`, injection e respostas JSON inesperadas; React escapa texto e Laravel valida/normaliza, sem `dangerouslySetInnerHTML` de provedor.

## 7. Estratégia anti-gargalo e resiliência
- **Sem consulta enquanto digita**, somente por toque explícito na placa. Cliente cancela fluxo pendente ao fechar/trocar etapa; backend aplica timeout com orçamento total.
- Proposta inicial a calibrar com fornecedor: *lookup* com timeout total até **6 s**, limite de no máximo **1 retry em erro transitório** com jitter e nunca retry automático em 401/403/404/422/429; respeitar `Retry-After`. Não efetuar 2 calls pagas por UI double-tap.
- Cache de catálogo com chaves por tipo/marca/modelo/ano e **versão do catálogo**; invalidação versionada. Cache de FIPE por código+competência com TTL controlado e permitido pela licença. Nada de retornos obsoletos como cotação atual.
- **Circuit breaker** após falhas repetidas, curto período de cooldown e fallback manual; fornecedor indisponível não degrada salvamento manual nem a gestão dos demais veículos.
- **Controle de custos:** orçamento de chamadas por mês, alertas, métricas de cache hit, p95, errors, latência e custo unitário (sem dados de placa nas métricas). Política de esgotamento: resposta `temporarily_unavailable`, não bloqueio do formulário.
- Catálogos paginados/searchable, listagem virtualizada apenas quando necessário, renderização sem imagens pesadas; não pré-carregar marcas/modelos/versões de todos os tipos.
- Metas iniciais **para QA/staging**, não SLA prometido: feedback de clique em < 200 ms; transição guiada com dados cacheados p95 < 500 ms; fallback ao alcançar limite de 6 s; navegação estável em rede móvel degradada. Medir e revisar com fornecedor.
- Se salvar responder sucesso mas atualização da lista falhar, exibir ID/ficha criada e permitir abrir; **não repetir POST**. Para idempotência resistente a rede, adotar request_key/UUID por intenção também no servidor com verificação transacional, caso endpoint atual ainda não ofereça isso; bloqueio frontend isolado não basta.
- Não manter rascunhos financeiros offline, nem permitir criação falsa se perdeu conexão. Conteúdo de formulário deve sobreviver à navegação entre etapas enquanto modal aberto, sem reaplicar dados de outro veículo.

## 8. PWA/UX: regras de interface e acessibilidade
- Abrir **seletor de método**, nunca exibir formulário técnico longo de início. No mobile, bottom sheet com duas escolhas; etapas posteriores usam conteúdo confortável e podem ocupar tela cheia; desktop modal centrado com largura limitada; sem novo shell.
- Opções acionáveis com rótulos e subtexto, ícones consistentes e não meramente cromáticos. Ação principal contextual por etapa; voltar sem perder dados e cancelar sem criar registro.
- Progresso discreto (por exemplo 2/5) sem tornar o fluxo obrigatório rígido; pular enriquecimento e entrar manualmente. O usuário **sempre** pode corrigir texto e buscar outra marca/modelo.
- Campos e controles com labels reais, autoFocus criterioso, ordem de Tab, Enter sem submissão inesperada, Escape com confirmação somente quando perderia dados, aria-live para erros/status, foco restaurado ao botão que abriu modal.
- Tela útil 320/375/390/768/1024/1440 px, safe area no iPhone, teclado virtual Android/iOS, landscape, zoom 200%, modo claro/escuro e reduzido movimento. Contraste/sem overflow e rolagem interna controlada, sem modal por cima de outro.
- Preservar `modal-fullscreen-sm-down`, grades `col-6` para KPIs/ações quando apropriado, botões compactos (~40px alvo de toque no padrão atual) e a tabela desktop.
- Aviso explícito sobre FIPE: `Referência FIPE, não preço de compra nem avaliação do estado do veículo`; competência mês/ano e fonte legível; estado sem FIPE não aparece como R$ 0,00.
- Integração no tour Macro 55 como orientação não intrusiva de **Adicionar veículo → escolha de método**; tour não aciona consultas pagas nem salva dados.

## 9. Plano de implementação por uma macroentrega completa (API + APP)
### 9.1. Pré-requisitos e desenho
- [ ] Levantar cobertura real e segurança do cadastro atual em API + APP, migrations, testes, seeds e `AGENTS.md`.
- [ ] Escolher fornecedores viáveis com contrato/termos e custos; validar oficialmente ausência de API FIPE da fundação, não automatizar scraping sem licença.
- [ ] Aprovar suporte de tipos e campos adicionais, política de duplicidade e exceção de placa na venda.
- [ ] Especificar schema aditivo, migração de registros existentes sem retropreencher placa/FIPE fictícios e rollback.
- [ ] Mapear endpoint de veículo já existente, payload/DTO tipado e todos os componentes que exibem marca/modelo/placa.

### 9.2. Backend Laravel
- [ ] Criar abstrações/contratos por fornecedor com normalizador, catálogo versionado, sem acoplamento React e testes por fixture.
- [ ] Implementar endpoints propostos sob JWT, `session.current`, `cases.manage`, validação FormRequest e limites de uso.
- [ ] Implementar consulta de placa com contrato mínimo, tratamento de `matched/ambiguous/not_found/unavailable` e sem dados pessoais.
- [ ] Implementar seleção tipo→marca→modelo→ano-modelo→versão com filtros seguros e correspondência determinística ao código FIPE.
- [ ] Cotar por código/competência com cache licenciado e valor em inteiros/centavos; jamais alterar compra/venda automaticamente.
- [ ] Validar novamente todos os campos salvos no backend; normalizar placa, preservar null, limitar tamanhos, rejeitar campos desconhecidos.
- [ ] Criar política transacional de duplicidade e idempotência de criação conforme decisão aprovada; impedir criação dupla por concorrência.
- [ ] Registrar trilha mínima e fonte/competência da cotação apenas se licença autorizar e houver necessidade de persistência.
- [ ] Revalidar isolamento em GET/POST/PATCH e rota de catálogo; erro de tenant cruzado nunca revela metadados de outro.
- [ ] Atualizar `docs/openapi.yaml` com exemplos, erros, scopes, contrato do fornecedor e versão de API compatível; migrations aditivas.
- [ ] Escrever testes feature PHPUnit (incl. MySQL de QA, corrida, 401/403/404, rate limiting, cache, falhas externas).

### 9.3. Frontend React
- [ ] Evoluir `VehiclesPage.tsx` preservando o componente/modal, a Frota e o Veículo 360.
- [ ] Construir bottom sheet de escolha inicial e adaptação modal desktop oficial, sem novo menu/página/tema.
- [ ] Implementar consulta placa com normalização, loading, cancelamento, tratamento de respostas tardias, manual fallback e revisão humana.
- [ ] Implementar wizard guiado dependente por tipo, marca, modelo, ano e versão com busca, voltar, ausência de dados e estado controlado.
- [ ] Criar única etapa final de revisão com `title`, placa opcional, FIPE separada e preço de compra opcional.
- [ ] Preservar `Salvar veículo` e `Salvar + despesa` sem criar lançamento antecipado e rascunhos entre etapas.
- [ ] Tratamento de duplicidade/permissão, falha de API, timeout, offline e retry explícito sem duplo POST.
- [ ] Integrar aviso de placa pendente na lista/ficha e reabrir guia opcional de primeiro uso sem efeitos financeiros.
- [ ] Garantir compatibilidade com tema claro/escuro, teclado e leitores de tela, layouts mobile e desktop.
- [ ] Escrever testes unitários de reducers/normalização/estado e E2E de todos os caminhos em browser real.

### 9.4. Qualidade e entrega
- [ ] `composer install`, migrations aditivas em base de QA, PHPUnit SQLite e MySQL 8, `vendor/bin/pint --test`.
- [ ] `npm ci`, `npm run lint`, `npm run build`, testes do produto (`qa:mobile-layout`, `qa:mvp-integrity`) e testes novos.
- [ ] E2E autenticado em Chrome/Edge/Safari/Android/iPhone; 320/375/390/768/1024/1440px e claro/escuro.
- [ ] Revisar rede lenta, serviço fora, limites da API, contrato/licença e custo de consultas em staging sem dados reais.
- [ ] Verificar dois tenants e perfis com/sem `cases.manage` + acesso cruzado, endpoint privado e testes negativos.
- [ ] Gerar evidências de comandos executados, logs higienizados e screenshots de QA; documentar falhas restantes.
- [ ] Entregar **uma macro funcional de implementação**, com PR API + PR APP sem draft, revisão, rollback e aceite. **Esta especificação não implementa isso.**

## 10. Matriz de testes verificáveis — candidatos obrigatórios
Todos os cenários abaixo são **a executar**, não testes realizados. Fixtures devem ser sintéticas/licenciadas. `A` e `B` designam tenants isolados.

### Cadastro e regras de negócio
| ID | Cenário | Resultado esperado |
| --- | --- | --- |
| CV-01 | Abrir Adicionar veículo no mobile | Escolha dos dois métodos; foco e retorno íntegros |
| CV-02 | Abrir no desktop | Modal Metronic sem largura excessiva |
| CV-03 | Placa antiga válida | Normaliza e consulta somente ao tocar |
| CV-04 | Placa Mercosul válida | Normaliza corretamente sem corrupção |
| CV-05 | Placa com letras minúsculas, hífen ou espaço | Formata sem perder caracteres |
| CV-06 | Placa incompleta/inválida | Erro local acessível; nenhuma chamada paga |
| CV-07 | Colar placa e apagar parte | Não consulta automaticamente |
| CV-08 | Consulta retorna match único | Campos disponíveis preenchidos, editáveis |
| CV-09 | Consulta retorna múltiplas versões | Escolha explícita; FIPE não presumida |
| CV-10 | Consulta só identifica marca/modelo | Mantém campos faltantes e seleção de versão |
| CV-11 | Placa não encontrada | Fallback guiado com placa preservada |
| CV-12 | Provedor lança 429 | Mensagem amigável, retry controlado e fallback |
| CV-13 | Provedor 500/503/timeout | Falha isolada, cadastro manual disponível |
| CV-14 | Resposta com dados incoerentes | Exigir conferência, não salvar FIPE errada |
| CV-15 | Trocar placa A por placa B durante request | Resposta A descartada |
| CV-16 | Alterar versão após cotação | Cotação antiga invalidada |
| CV-17 | FIPE indisponível com veículo identificado | Permite salvar sem valor FIPE |
| CV-18 | FIPE disponível | Exibir código, preço, mês/ano e aviso de referência |
| CV-19 | FIPE antiga em cache | Exibir competência antiga, nunca como atual |
| CV-20 | Selecionar tipo, marca, modelo, ano, versão | Filtragem correta e revisão única |
| CV-21 | Voltar de versão para marca | Campos dependentes invalidados apropriadamente |
| CV-22 | Marca/modelo sem catálogo | Entrada manual, sem bloqueio |
| CV-23 | Duas descrições de modelo parecidas | Opções distintas com ID e ano corretos |
| CV-24 | Ano fabricação diferente de ano-modelo | Não misturar nem cotar pelo ano errado |
| CV-25 | Veículo não encontrado na FIPE | Salva manual e identifica FIPE ausente |
| CV-26 | Cadastrar sem placa | Salva e marca pendência na ficha |
| CV-27 | Cadastrar com placa | Normaliza e grava no tenant correto |
| CV-28 | Corrigir `title` sugerido | Mantém texto do operador |
| CV-29 | Valor de compra vazio | `NULL`, nunca 0/valor FIPE |
| CV-30 | FIPE R$ X e compra R$ Y | Campos e cálculos independentes |
| CV-31 | Salvar + despesa | Abre rascunho sem despesa criada |
| CV-32 | Salvar veículo | Abre mesma ficha existente |
| CV-33 | Cancelar depois de preencher | Não cria registro e confirma perda se necessário |
| CV-34 | Duplo toque Salvar | Uma criação, sem duplicar |
| CV-35 | POST salva mas refresh falha | Mostra sucesso/ID; não reenviar POST |
| CV-36 | Concluir venda sem placa | Regras atuais preservadas até decisão da exceção; futura regra testada isoladamente |
| CV-37 | Placa usada por veículo ativo do mesmo tenant | Política aprovada de duplicidade aplicada sob corrida |
| CV-38 | Nova operação do mesmo veículo arquivado | Não bloquear sem justificativa; histórico separado |
| CV-39 | Editar ficha após cadastro guiado | Dados persistentes exibidos e editáveis |
| CV-40 | App offline antes de salvar | Não inventa sucesso nem salva segredo offline |

### Segurança/isolamento/auditoria
| ID | Cenário | Resultado esperado |
| --- | --- | --- |
| SEC-01 | Anônimo consulta placa/catálogo privado | 401, não chama fornecedor |
| SEC-02 | JWT expirado/inativo | 401, não vaza resposta |
| SEC-03 | Usuário sem `cases.manage` consulta | 403, não consulta fornecedor |
| SEC-04 | Forjar `tenant_id` no payload/query/header | Ignorado/rejeitado; tenant do JWT prevalece |
| SEC-05 | Forjar veículo do tenant B em sessão A | 404/negação, sem dados de B |
| SEC-06 | Mesma placa em A e B | Não mostra nome/ID/veículo de B; segregação íntegra |
| SEC-07 | Solicitar campos extras do fornecedor | Nunca expor proprietário/CPF/RENAVAM |
| SEC-08 | Alterar provider URL no request | Rejeitar; sem SSRF |
| SEC-09 | Injeção/XSS em texto de resposta | Escape/validação sem execução de script |
| SEC-10 | Payload JSON inesperado/grande | Rejeitar sem travar processo nem revelar segredos |
| SEC-11 | Logs de falha/provedor | Nenhuma placa bruta, token, CPF, custo ou payload sensível |
| SEC-12 | Serviço cacheado e sessão troca de tenant | Nenhuma resposta privada anterior reaproveitada |
| SEC-13 | Service worker offline | Nenhum cache persistente de resposta privada |
| SEC-14 | Rate limit e muitas placas em sequência | Resposta 429, controle de quota sem enumeração |
| SEC-15 | Concorrência de POST com placa igual | Política transacional funciona, sem duplicata ativa |
| SEC-16 | Repetição de request_key com payload distinto | 409 e nenhum dado modificado |
| SEC-17 | Usuário não autorizado acessa cotação persistida | Negação; tenant-scoped |
| SEC-18 | Desativação de tenant durante lookup | Nenhum salvamento após sessão inválida |

### Qualidade visual, desempenho e operação
| ID | Cenário | Resultado esperado |
| --- | --- | --- |
| UX-01 | 320px Android / 375px iPhone / 390px Android | Sem overflow; controles tocáveis |
| UX-02 | 768px tablet / 1024 e 1440px desktop | Layout responsivo sem regressão da Frota |
| UX-03 | Tema claro e escuro | Contraste, foco e avisos legíveis |
| UX-04 | Zoom 200%, teclado virtual e safe area | Modal acessível e botão salvar visível |
| UX-05 | Leitor de tela, Tab, Enter, Escape | Ordem e status anunciados, fechamento seguro |
| UX-06 | Navegação voltar/avançar | Não perde rascunho nem reabre ação indevidamente |
| UX-07 | Tour da Macro 55 | Guia não dispara consulta externa nem salva |
| PERF-01 | Lista de marcas grande | Busca/scroll fluidos, resposta paginada |
| PERF-02 | Conexão 3G simulada | Feedback imediato, fallback ao timeout |
| PERF-03 | Provedor indisponível por 5 minutos | Circuit breaker, cadastros manuais continuam |
| PERF-04 | 10 usuários no mesmo tenant fazem lookup | Limites, sem tempestade de retries |
| PERF-05 | 2 tenants fazem lookup simultâneo | Cache seguro e nenhum cruzamento de dados |
| PERF-06 | Atualização mensal da competência FIPE | Cache invalidado/rotulado, sem valores obsoletos ocultos |
| OPS-01 | Chave/contrato de fornecedor ausente | Feature degradada com mensagem; API principal permanece |
| OPS-02 | Alteração de schema do fornecedor | Adapter falha controlada, telemetria higienizada |
| OPS-03 | Rollback de migration aditiva | Dados históricos não perdidos; plano validado |
| OPS-04 | Consulta repetida com plate igual | Medir cache/limite sem duplicar custo desnecessário |
| OPS-05 | Alerta de orçamento de API esgotado | Fornecedor desligado com fallback manual |
| OPS-06 | Provedor substituído | Contratos normalizados e UI permanecem compatíveis |

## 11. Critérios objetivos de aceite (bloqueadores)
- **Produto**: dois métodos explícitos, cadastro manual garantido, um formulário final, sem placa obrigatória no início, FIPE isolada dos valores comerciais, nenhuma despesa involuntária, nenhum passo desnecessário.
- **Funcional**: dados corretos e conferíveis, versões ambíguas não atribuídas, competência FIPE exibida, alterações invalidam referência anterior, `Salvar + despesa` mantém semântica atual e sem duplicidade.
- **Segurança**: autenticação, permissões, tenant em todas as operações, isolamento A/B, sem dados pessoais de consulta de placa, prova de limites/rate limit e sem cache privado no PWA.
- **Operação**: timeout/retry/circuit breaker, custos monitoráveis, serviço substituível, fallback em falhas, idempotência de criação e logs sem PII.
- **Compatibilidade**: API anterior aceita registros antigos, sem migração destrutiva, sem alterar margens/valores de venda, layout Metronic preservado e testes mobile/desktop.
- **Evidência**: testes reais executados e com relatório; matriz `CV`, `SEC`, `UX`, `PERF`, `OPS` registrada com resultado/ambiente. Nenhum item [x] sem execução comprovada.
- **Gate de lançamento**: escolha de fornecedor e revisão LGPD/licença aprovadas, migração em QA/rollback testados, p95/custo aferidos, revisão de PRs API+APP e aceite funcional em dispositivos reais.

## 12. Não escopo e proteção do MVP
Não construir Marketplace, rede social, chat, leitura de documentos oficiais, rastreamento de proprietário, débito, multa, seguro, vistoria, Pix, avaliação de mercado própria, precificação automática, compra/venda automática, nova página de gestão, novo dashboard ou segunda ficha de veículo. Não substituir nem simplificar regras e auditorias do módulo Financeiro já entregue.

## 13. Responsabilidades e sequência da futura macro
1. **Produto/compliance:** aprovar fornecedor/licenças, tipos suportados, exceção de placa na venda e campos adicionais.
2. **API:** contracts/adapters, segurança, cache controlado, migrations aditivas, idempotência, OpenAPI e testes.
3. **Frontend:** escolha de método, busca e seleção, revisão única, mensagens/fallback, acessibilidade e compatibilidade onboarding.
4. **QA:** matriz funcional + negativa + visual + resiliência em dois tenants, evidências e rollback.
5. **Integração:** PRs API+APP não draft, review, merge planejado, sem declarar conclusão antes de build/testes e homologação.

## 14. Rastreabilidade da decisão
Decisão do usuário em 19/11/2026: adicionar ao roadmap **duas formas de cadastro** (consulta pela placa e guiado tipo/marca/modelo/ano/versão), exigir experiência intuitiva para qualquer pessoa em **mobile-first**, manter **placa opcional na criação e pendência sinalizada**, oferecer fallback em falha de consulta e **documentar com narrativa + checklist técnico e testes detalhados**. O módulo Financeiro será tratado separadamente. Este arquivo é uma especificação futura, **não comprova código ou homologação**.
