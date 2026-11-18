<?php

namespace App\Notifications;

use App\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TenantInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TenantInvitation $invitation,
        public readonly string $token,
        public readonly string $tenantName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/auth/invitation?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Convite para o '.$this->tenantName.' no Fluxo Repasse')
            ->greeting('Você recebeu um convite!')
            ->line('A empresa '.$this->tenantName.' convidou você para colaborar no Fluxo Repasse.')
            ->line('Crie sua senha para acessar o ambiente com as permissões atribuídas pela empresa.')
            ->action('Aceitar convite', $url)
            ->line('Este convite expira em 7 dias.');
    }
}
