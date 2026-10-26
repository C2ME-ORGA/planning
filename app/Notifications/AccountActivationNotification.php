<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class AccountActivationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activation de votre compte Planning C2ME')
            ->greeting('Bonjour,')
            ->line('Un compte Planning C2ME a été créé pour vous.')
            ->line('Utilisez le lien ci-dessous pour définir votre mot de passe. Ce lien est unique et expire rapidement.')
            ->action('Activer mon compte', $this->activationUrl())
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.')
            ->salutation('L’équipe C2ME');
    }

    public function activationUrl(): string
    {
        return URL::route('account.activation.show', ['token' => $this->token]);
    }
}
