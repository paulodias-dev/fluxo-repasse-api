# Macroentrega 21 — Pacotes documentais de dossiê (prestação de contas não financeira)

## Objetivo de produto

Transformar dossiês existentes em **fotografias preservadas e verificáveis**, com manifesto JSON, seleção de evidências/cenários/pareceres, comparação histórica e ZIP privado. É uma entrega grande de integração de módulos, sem criar uma fintech fictícia.

## Nove frentes operacionais

1. **Selecionador paginado:** consultar e filtrar evidências ativas (até 40), cenários não arquivados (até 30) e pareceres (até 30), em conjuntos independentes; persistir IDs selecionados entre páginas no APP.
2. **Captura abrangente:** captura automaticamente participantes (nome/tipo/vínculo, sem dados bancários/contato), tarefas/prioridade/prazos, checklist, notas e situação do dossiê; inclui apenas as evidências, cenários e pareceres marcados.
3. **Versões financeiras históricas:** para cada cenário selecionado, congela os dados originais da revisão verificada com hash; para pareceres, preserva estado, ator, decisão, revisão e hash final da trilha.
4. **Prévia sem gravação:** calcula manifesto determinístico e SHA-256 antes da emissão. A API exige o mesmo hash no POST definitivo; se algum registro ou arquivo mudar, responde 409 e solicita nova prévia.
5. **Pacotes registrados por UUID idempotente:** transação única, sem duplicações em retentativas; preserva manifesto serializado **exatamente** em LONGTEXT, evitando a reordenação de chaves JSON pelo MySQL; sem endpoints de edição/exclusão.
6. **Indicadores de pacote:** número de participantes, tarefas, pendências, checklist, notas, evidências, cenários, pareceres e volume de anexos; sem transformar isso em aprovação de pagamento.
7. **Integridade interna:** conferir SHA-256 do manifesto e cada arquivo privado contra o checksum original; informar quais evidências se tornaram indisponíveis/adulteradas.
8. **Comparador histórico:** identificar IDs de registros incluídos, retirados e modificados entre dois pacotes do mesmo dossiê, além de alterações dos metadados do dossiê.
9. **ZIP privado sob demanda:** manifesto JSON completo e evidências selecionadas, com nomes higienizados e prefixados por ID, verificação de SHA antes/depois da compactação, sem URL pública, sem armazenamento permanente do ZIP. Um pacote com arquivos divergentes **não** pode ser baixado.

## Persistência

Migration exclusivamente aditiva: 2026_11_16_000007_create_case_packages.php
- repasse_case_packages: dossiê, UUID, hash da requisição, título/observações, manifesto serialized LONGTEXT, SHA-256, estatísticas, autor e data; FKs e índice de histórico.
- Evidências seguem no armazenamento privado Laravel existente, sem novos buckets, S3, custo ou URL pública.

## Contratos / segurança

Oito operações REST em /api/v1/repasse-cases/{case}/packages:
- GET /options?kind=evidence|scenarios|reviews&page=...
- GET /
- POST /preview
- POST /
- GET /{package}
- GET /{package}/integrity
- GET /{package}/compare?other_id=...
- GET /{package}/download

OpenAPI 1.4 em docs/openapi.yaml.

Leituras/preview/downloads exigem JWT vigente, cases.manage e scenarios.manage. Emissão também exige packages.manage. A nova permissão é provisionada ao papel platform_admin pelo AdminUserSeeder idempotente; nenhuma senha ou conta seed é adicionada.

**Restrições explícitas:**
- Pacotes NÃO criam pagamentos, obrigações, saldo, comissão automática, débito, aprovação, liquidação ou integração bancária. Não são comprovantes de quitação.
- Não disponibilizar entre organizações/tenants reais antes de definir e implementar política de isolamento organizacional.
- Limite total de evidências: 100 MiB por pacote; no máximo 40 documentos, 30 cenários e 30 pareceres. Cada documento individual permanece limitado a 10 MiB pela API de upload existente.
- Dossiês com mais de 400 registros em uma das coleções automaticamente incluídas precisam ser tratados antes da emissão; não há truncamento silencioso.
- Pacotes existentes permanecem consultáveis após arquivamento do dossiê; não se permite emitir novos enquanto status != draft.
- ZIP exige extensão PHP **ZipArchive** instalada e habilitada; retorna 503 controlado se indisponível.
- Hashes e snapshots são prova de consistência **interna**; administradores de banco/armazenamento podem modificar registros com acesso privilegiado. Isso não constitui certificado jurídico externo ou WORM.
- O ZIP contém evidências armazenadas localmente. Se um arquivo tiver sido alterado/removido depois da emissão, o manifesto continua legível e o endpoint /integrity aponta divergência, mas o ZIP é bloqueado até recuperação íntegra da fonte original.
- Não incorporar histórico financeiro de outros projetos e não tratar status reviewed como liberação de recursos.

## Homologação focalizada e implantação segura

1. Confirmar .env com disco privado local e extensão zip; fazer backup em banco autorizado.
2. Aplicar **apenas** php artisan migrate (não migrate:fresh).
3. Rodar seeder idempotente de permissões: php artisan db:seed --class=AdminUserSeeder; renovar JWT.
4. php artisan test --filter=CasePackagesTest, Pint focalizado, OpenAPI.
5. APP: npm test -- --run src/features/cases/CasePackages.test.jsx src/features/cases/CaseWorkspace.test.jsx; npm run lint e npm run build.
6. Smoke: dossiê com tarefa/checklist/nota, evidência privada PDF, cenário e parecer; selecionar; prévia; simular mudança antes do POST (409); emitir; reexecutar mesmo request_key; comparar; arquivar documento; verificar checksum; baixar ZIP, conferindo manifesto e arquivos; repetir com conta sem packages.manage (403).
7. Conferir que **nenhum** fluxo emite ordem financeira.

**Nesta sessão, PHPUnit, MySQL, Pint, Vitest, build e smoke não foram executados.** Testes foram escritos; execução/homologação precisa ser comprovada antes de merge.

## Próxima evolução

Após homologar: trilha de distribuição documental para destinatários internos, notificações e políticas de retenção/LGPD a definir. Operações bancárias reais continuam bloqueadas até a aprovação formal dos requisitos de pagador, beneficiário, fato gerador, comissões, alçadas, impostos, conciliação e PSP.


### Respeito ao arquivamento de evidências

Pacotes antigos preservam **metadados e hashes** de evidências posteriormente arquivadas, porém seu ZIP não pode reexpor o conteúdo de um arquivo que foi arquivado. O endpoint de integridade marca a evidência como indisponível e bloqueia o ZIP (409), mesmo se os bytes privados permanecerem fisicamente guardados. Isso preserva o bloqueio de download já praticado pelo módulo original de evidências.
