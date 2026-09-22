<?php
declare(strict_types=1);

namespace AcquaVale;

use RuntimeException;

final class ReservationService
{
    public function lookup(string $reservationCode): array
    {
        $provider=strtolower(trim((string)\cfg('reservation.provider','')));

        // Backward compatibility: older installations had no explicit provider.
        // Prefer the Expresso credentials because that is the same reservation
        // details flow already used successfully by the local installation.
        if ($provider==='' || $provider==='auto') {
            $expressoReady=
                trim((string)\cfg('expresso.user',''))!=='' &&
                (string)\cfg('expresso.password','')!=='';

            $iplateReady=
                trim((string)\cfg('iplate.username',''))!=='' &&
                (string)\cfg('iplate.password','')!=='';

            if ($expressoReady) {
                $provider='expresso';
            } elseif ($iplateReady) {
                $provider='iplate';
            } else {
                throw new RuntimeException(
                    'Nenhum provedor de reservas está configurado. Configure Expresso ou iPlate.'
                );
            }
        }

        return match ($provider) {
            'expresso' => (new ExpressoReservationService())->lookup($reservationCode),
            'iplate' => (new IPlateReservationService())->lookup($reservationCode),
            default => throw new RuntimeException('Provedor de reservas inválido na configuração.'),
        };
    }
}
