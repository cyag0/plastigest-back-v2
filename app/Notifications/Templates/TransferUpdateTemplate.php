<?php

namespace App\Notifications\Templates;

/**
 * Avances de una transferencia entre sucursales para quien la pidió (y, al
 * recibirla, para quien la envió). Las tareas de aprobar / enviar / recibir ya
 * avisan a quien le toca actuar; esto cierra el ciclo con la otra parte.
 */
class TransferUpdateTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'transfer_update';
    }

    public function getSeverity(): string
    {
        return match ($this->subType()) {
            'rejected', 'cancelled' => 'warning',
            'received'              => 'success',
            default                 => 'info',
        };
    }

    public function getTitle(): string
    {
        $number = $this->number();

        return match ($this->subType()) {
            'approved'  => "Transferencia aprobada: {$number}",
            'rejected'  => "Transferencia rechazada: {$number}",
            'shipped'   => "Transferencia en camino: {$number}",
            'received'  => "Transferencia recibida: {$number}",
            'cancelled' => "Transferencia cancelada: {$number}",
            default     => "Transferencia actualizada: {$number}",
        };
    }

    public function getMessage(): string
    {
        $transfer = $this->context['transfer'] ?? null;
        $from     = $transfer?->fromLocation?->name ?? 'la sucursal de origen';
        $to       = $transfer?->toLocation?->name ?? 'la sucursal de destino';
        $actor    = $this->context['actor_name'] ?? 'Alguien';
        $reason   = trim((string) ($this->context['reason'] ?? ''));

        return match ($this->subType()) {
            'approved'  => "{$actor} aprobó tu solicitud a {$from}. Te avisaremos cuando la envíen.",
            'rejected'  => "{$actor} rechazó tu solicitud a {$from}." . ($reason !== '' ? " Motivo: {$reason}." : ''),
            'shipped'   => "{$from} envió tu transferencia. Confírmala al recibirla en {$to}.",
            'received'  => "{$to} recibió la transferencia que enviaste." . (!empty($this->context['with_differences']) ? ' Hubo diferencias: revisa el detalle.' : ''),
            'cancelled' => "{$actor} canceló la transferencia de {$from} a {$to}." . ($reason !== '' ? " Motivo: {$reason}." : ''),
            default     => "La transferencia de {$from} a {$to} cambió de estado.",
        };
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.generic';
    }

    public function getPushData(): array
    {
        $transfer = $this->context['transfer'] ?? null;

        return [
            'event_type'       => $this->getEventType(),
            'sub_type'         => $this->subType(),
            'transfer_id'      => $transfer?->id,
            // Origen y destino: si quien abre el aviso está en otra sucursal,
            // la app le ofrece cambiarse a la que le toca (sin el dato solo
            // veía un 404).
            'from_location_id' => $transfer?->from_location_id,
            'to_location_id'   => $transfer?->to_location_id,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['transfer'] ?? null;
    }

    private function subType(): string
    {
        return (string) ($this->context['sub_type'] ?? '');
    }

    private function number(): string
    {
        $transfer = $this->context['transfer'] ?? null;

        return $transfer?->transfer_number ?? ('#' . ($transfer?->id ?? ''));
    }
}
