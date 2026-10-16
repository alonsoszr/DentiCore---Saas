<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Platform\Services\ClinicSettingsService;
use App\Support\Http\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `portal.self_booking` (SDD §4.2; RF-026, RF-148): la reserva desde el portal solo
 * está disponible si la clínica habilitó el autoagendamiento; si no, 403.
 */
class EnsureSelfBookingEnabled
{
    public function __construct(private ClinicSettingsService $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws BusinessRuleException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->current()->self_booking_enabled) {
            throw new BusinessRuleException('RF-148', 'La clínica no tiene habilitada la reserva de citas desde el portal.', status: 403);
        }

        return $next($request);
    }
}
