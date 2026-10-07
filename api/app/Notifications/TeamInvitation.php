<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitación a unirse a una empresa. Va a una dirección sin cuenta (notificación a demanda) y NO se encola:
 * el enlace lleva el token y no queremos dejarlo guardado en la tabla de trabajos.
 */
class TeamInvitation extends Notification
{
    public function __construct(
        public string $organizationName,
        public string $inviterName,
        public string $roleLabel,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} te invitó a {$this->organizationName} en Hamster AI Agents")
            ->greeting('Hola')
            ->line("{$this->inviterName} te invitó a unirte al equipo de {$this->organizationName} como {$this->roleLabel}.")
            ->action('Aceptar la invitación', $this->url)
            ->line('La invitación vence el '.$this->expiresAt->locale('es')->translatedFormat('j \d\e F').'. Si no la esperabas, ignora este correo: no pasa nada hasta que la aceptes.');
    }
}
