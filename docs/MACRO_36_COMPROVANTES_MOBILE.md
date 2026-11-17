# Macroentrega 36 — Comprovante em poucos toques

## Objetivo do operador
1. Abrir Meus veículos → Financeiro.
2. No cartão da despesa/receita, tocar **Foto** para usar câmera do celular, se suportado, ou **Arquivo** para escolher PDF, imagem ou foto.
3. Escolher arquivo: upload imediato no **mesmo lançamento**, sem cadastro ou confirmação adicional; status e eventuais erros aparecem na ficha.
4. Comprovante privado fica disponível para download, com auditoria e SHA-256. O arquivo enviado novamente ao mesmo lançamento não gera duplicata.
5. O usuário pode consultar, baixar ou desvincular o comprovante no detalhe. Vincular arquivos já cadastrados fica em uma seção expansível.

## API e segurança
Rota **existente** POST /api/v1/repasse-cases/{case}/finance/entries/{entry}/documents, sem alteração de URL:
- FormRequest para file (PDF/JPEG/PNG/WebP até 10 MiB) OU evidence_id; nunca ambos. Controller rejeita campos desconhecidos.
- Antes de gravar um novo upload, calcula SHA-256 e verifica se a mesma evidência ativa já está vinculada ao mesmo entry_id dentro da transação e com lock no lançamento; quando sim retorna HTTP 200, created=false, preservando o mesmo ID, arquivo e evento.
- Se novo, armazena em disco privado no prefixo do veículo, grava evidência, cria vínculo autorizado e evento document_attached, retorna 201 created=true.
- Não deduplicar entre lançamentos/veículos e não permitir leitura cruzada; sem criação de arquivos públicos, URLs compartilháveis ou informações bancárias.
- Evidências arquivadas não são baixadas; SHA-256 verificado antes de download.
- API segue JWT, sessão corrente, cases.manage e CSRF/segurança padrão da aplicação.
- A presença de comprovante não significa que uma obrigação financeira foi paga ou liquidada.

## React e Metronic Demo 1
- Controles Foto/Arquivo reutilizados em três pontos da mesma página.
- Cards col-6 no mobile para lançamentos e comprovantes; botões btn-sm e tabela original no desktop.
- Seleção de arquivos dispara upload e apresenta estado "Enviando"; resultado de duplicate retorna aviso sem nova cópia.
- Erros de tipo/limite/servidor aparecem no card e no alerta do financeiro. Fotos HEIC não suportadas são orientadas a formatos compatíveis.
- Nenhum cache local de dados privados e nenhuma biblioteca de edição/conversão adicionada.

## Critérios de aceite e evidências
- Feature tests incluídos: novo PDF, repetição idempotente no mesmo lançamento, dois lançamentos distintos, 401/403, arquivo inválido, tamanho máximo, campos proibidos e ausência de duplicata no banco/disco.
- QA manual: câmera Android/iOS, seleção de PDF/foto, arquivo HEIC e tamanho excedido, URL direta e consulta de comprovante, duas colunas em 320/375/390px, desktop e temas Metronic.
- **Testes executados nesta macro: nenhum**. Build, testes automatizados e validação visual real pendentes para homologação. Não executar migrate:fresh --seed: sem migrations novas.

## Próxima direção
Reduzir atrito no acompanhamento de veículo e financeiro da frota; priorizar tarefas e anexos realmente usados, sem construir novas centrais.
