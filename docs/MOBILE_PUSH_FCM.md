# Macro 04 — Push nativo Android/iOS (backend)

## Funcionalidades do código
- `GET /api/v1/mobile/devices`: dispositivos do próprio usuário autenticado, sem tokens.
- `POST /api/v1/mobile/devices`: registro com `device_id` UUID, `platform` android/ios, `push_token` e **`consent=true` obrigatório**.
- `DELETE /api/v1/mobile/devices/{deviceId}`: revoga o registro próprio, apaga token criptografado e hash.
- `DELETE /api/v1/mobile/devices/all`: revoga todos os dispositivos do usuário.
- Token criptografado com Laravel APP_KEY; `SHA-256` apenas para deduplicação.
- Cada dispositivo pertence a um único usuário/empresa ativa. Registro de outro usuário no mesmo dispositivo ou token revoga o vínculo anterior; `auth_version` precisa coincidir no envio.
- `CollaborationAccess::notify` continua salvando os avisos; quando habilitado, após commit despacha `SendCollaborationPush` à fila default.
- Job envia exclusivamente notificação genérica, sem mensagens, dados de veículos ou empresa; FCM HTTPS v1 com OAuth assinado em servidor.
- Token expirado / FCM UNREGISTERED desativa o dispositivo.
- Padrão `MOBILE_PUSH_ENABLED=false` (fail closed).

## Configuração de produção obrigatória
1. Criar um projeto Firebase, apps Android/iOS, APNs no Firebase e habilitar FCM HTTP v1. A assinatura e IDs dos apps devem corresponder aos da plataforma.
2. Guardar o JSON privado da conta de serviço em **caminho absoluto fora do repositório e de public/**, com permissões restritivas e backup seguro.
3. Configurar em `.env`: `MOBILE_PUSH_ENABLED=true`, `MOBILE_PUSH_PROJECT_ID=...`, `MOBILE_PUSH_CREDENTIALS_FILE=/caminho/privado/service-account.json`. Reiniciar workers e limpar cache de config de forma controlada.
4. Executar migrations e instalar/configurar workers da fila `default` com supervisão e tentativas, pois o envio é assíncrono. Monitorar falhas e limites do FCM.
5. Provisionar `APP_KEY` válido e persistente; rotacioná-lo somente com plano para criptografia dos tokens.
6. O app deve pedir consentimento explícito, registrar token e observar `onTokenRefresh`. Logout e desativação devem revogar dispositivo. Sem consentimento, nenhum token será cadastrado.
7. Conferir em dois tenants distintos: logout offline, migração de conta, 401, token revogado, rotação e push recebido em Android/iOS físicos. Configurar canais Android e permissão de notificação a partir de Android 13.

## Observações
- Notificações push servem como aviso de atividade, nunca como autorização de acesso aos dados. Ao abrir, consultar API com JWT válido.
- Sem credenciais Firebase, APNs e dispositivos não há evidência de entrega de push externo, embora contratos e dispatch estejam implementados.
- Laravel pode reenviar em caso de timeout (sem semântica *exactly-once* de entrega externa); o app não deve interpretar push como operação financeira ou evento confirmado.
- HTTP para Google é feito somente a endpoints fixos; chave de serviço não trafega para Flutter/React.
- Retenção/limpeza de tokens revogados deve seguir política LGPD; registros podem ser purgados por rotina operacional depois do prazo de auditoria definido.
