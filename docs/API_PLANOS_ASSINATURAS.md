# API de planos e assinaturas

Prefixo /api/v1. A migration mantém empresas preexistentes em acesso legado. Novas empresas recebem o plano gratuito inicial. A configuração inicial usa cotas nulas (ilimitadas). O operador define os limites e cria as ofertas pagas em centavos de real. Uma alteração de preço afeta novas contratações. A assinatura existente conserva o valor contratado.

## Empresa autenticada

| Método e caminho | Entrada | Resultado |
|---|---|---|
| GET /billing/overview | vehicle_id opcional da própria empresa | plan (nulo para legado), active_vehicles, vehicle_photos, offers e subscription |
| POST /billing/checkout | plan_id | id, status e checkout_url; administrador da empresa |
| POST /billing/subscriptions/{id}/cancel | nenhuma | 204; encerra renovação e preserva período pago |
| POST /billing/notifications/{environment} | notificação assinada do Mercado Pago | 204 após processamento |

Configurar no painel do fornecedor a URL HTTPS da notificação com o sufixo test ou production. Habilitar eventos de assinaturas, cobranças recorrentes e pagamentos. O retorno do checkout é navegação. A vigência depende da cobrança consultada na API do fornecedor. A API usa a data do débito e um mês de vigência. A expiração retorna ao plano base. Cada ambiente exige o token privado, a chave de assinatura do webhook e a URL HTTPS de retorno. Preservar a APP_KEY já usada pelo Laravel para manter acesso às credenciais criptografadas.

## Operador da plataforma

JWT com sessão válida e papel global platform_admin. Consultas paginadas aceitam page.

| Método e caminho | Entrada e resultado |
|---|---|
| GET /platform/users | provider (método do último login), active_since, created_vehicle (0 ou 1). Retorna empresa, identidade, atividade e indicador de cadastro. Datas sem observação são nulas. |
| GET /platform/plans | Catálogo completo. |
| POST /platform/plans ou PUT /platform/plans/{id} | name, monthly_cents, vehicle_limit, photo_limit e available. Limite nulo é ilimitado, zero bloqueia novas inclusões. |
| GET /platform/plans/{id}/impact | Valores propostos opcionais vehicle_limit e photo_limit. Lista empresas acima de pelo menos uma cota, com consumo de veículos e máximo de fotos em um veículo. |
| GET /platform/companies | Empresas, estado de migração e plano base. |
| PUT /platform/companies/{id}/plan | plan_id de plano gratuito disponível. Migra empresa legada de forma explícita. O plano pago depende da confirmação da cobrança. |
| GET /platform/companies/{id}/billing | Assinaturas e cobranças com paginação independente por subscriptions_page e charges_page. |
| GET /platform/gateways | Situação dos ambientes, URL de retorno e indicador de configuração. |
| PUT /platform/gateways/{environment} | token, secret, return_url, active. Campos omitidos conservam o valor anterior. |
| POST /platform/gateways/{environment}/test | Verifica a credencial na API do Mercado Pago. Retorna connected. |

Uma inclusão que excede a cota retorna HTTP 409 com code plan_limit_reached, quota, limit e used. Veículos ativos estão em acompanhamento e sem data de venda. Só imagens ativas classificadas como foto_veiculo consomem a cota de fotos. Reduções não removem dados. A atividade considera acesso à frota/ficha e operações autenticadas de escrita fora da autenticação. O registro de atividade tem granularidade de um minuto.
