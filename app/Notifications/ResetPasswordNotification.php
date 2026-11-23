<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/auth/reset-password?token='.urlencode($this->token)
            .'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Redefina sua senha no Fluxo Repasse')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos uma solicitação para redefinir sua senha.')
            ->action('Redefinir senha', $url)
            ->line('Este link expira em 60 minutos. Se você não fez esta solicitação, ignore este e-mail.');
    }
}
