<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class VerifyRegistrationEmail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/auth/verify-email?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Confirme seu cadastro no Fluxo Repasse')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Seu ambiente do Fluxo Repasse está quase pronto.')
            ->line('Confirme seu e-mail para ativar o acesso à gestão de veículos e operações.')
            ->action('Confirmar e-mail', $url)
            ->line('Este link expira em 24 horas.');
    }
}
