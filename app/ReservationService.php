<?php
declare(strict_types=1);

namespace AcquaVale;

use RuntimeException;

final class ReservationService
{
    public function lookup(string $reservationCode): array
    {
        // Existing installations may have either the Expresso or iPlate config.
        $provider=strtolower(trim((string)\cfg('reservation.provider','')));
        if ($provider==='') {
            $provider=trim((string)\cfg('iplate.username',''))!=='' ? 'iplate' : 'expresso';
        }

        return match ($provider) {
            'expresso' => (new ExpressoReservationService())->lookup($reservationCode),
            'iplate' => (new IPlateReservationService())->lookup($reservationCode),
            default => throw new RuntimeException('Provedor de reservas inválido na configuração.'),
        };
    }
}
