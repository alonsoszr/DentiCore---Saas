<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de la bitácora a CSV (CUS-66, RF-187), en la respuesta y por lotes. Cada exportación
 * deja su propio evento `audit.exported` con el alcance, el rango y el número de filas (RN-67).
 *
 * El CSV lleva BOM UTF-8 para que la hoja de cálculo respete las tildes. Las celdas que empiezan
 * con `=`, `+`, `-`, `@`, tabulador o retorno se anteponen con `'` para que no se evalúen como
 * fórmulas (inyección CSV, RNF-098).
 */
class AuditLogExporter
{
    private const CHUNK_SIZE = 1000;

    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private AuditLogSearch $search,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{from: string, to: string, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null, clinic_id?: string|null}  $filters
     */
    public function export(?Tenant $clinic, array $filters, User $actor): StreamedResponse
    {
        $platform = $clinic === null;
        $query = $this->search->query($clinic?->id, $filters);

        $this->audit->record(AuditEvent::AuditExported, meta: [
            'scope' => $platform ? 'plataforma' : 'clinica',
            'from' => $filters['from'],
            'to' => $filters['to'],
            'rows' => (clone $query)->count(),
        ], actor: $actor);

        $clock = ClinicClock::for($clinic);
        $name = ($platform ? 'bitacora-plataforma-' : 'bitacora-')."{$filters['from']}-{$filters['to']}.csv";

        // El CSV se escribe al enviar la respuesta, cuando el middleware `tenant` ya terminó.
        $write = function () use ($query, $platform, $clock): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\u{FEFF}");
            $this->write($output, $this->header($platform));

            $query->reorder()->orderBy('created_at')->orderBy('id')->chunk(self::CHUNK_SIZE, function ($logs) use ($output, $platform, $clock): void {
                $this->search->withActors($logs, $platform);

                foreach ($logs as $log) {
                    $this->write($output, $this->row($log, $platform, $clock));
                }
            });

            fclose($output);
        };

        return response()->streamDownload(
            fn () => $clinic === null ? $write() : TenantContext::run($clinic, $write),
            $name,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * @return list<string>
     */
    private function header(bool $platform): array
    {
        return [
            'fecha', 'usuario_id', 'usuario', 'rol', 'accion', 'recurso', 'recurso_id',
            ...($platform ? ['clinica_id', 'clinica'] : ['paciente_id']),
            'campos', 'metadata', 'ip', 'agente', 'correlacion_id',
        ];
    }

    /**
     * @return list<string|null>
     */
    private function row(AuditLog $log, bool $platform, ClinicClock $clock): array
    {
        /** @var User|null $user */
        $user = $log->getRelationValue('user');
        /** @var Tenant|null $tenant */
        $tenant = $log->getRelationValue('clinic');
        $local = $platform && $tenant !== null ? ClinicClock::for($tenant) : $clock;

        return [
            $log->created_at->setTimezone($local->timezone())->format('Y-m-d H:i:s'),
            $user?->uuid,
            $user?->name,
            $log->actor_role,
            $log->action,
            $log->resource_type,
            $log->resource_uuid,
            ...($platform ? [$tenant?->uuid, $tenant?->name] : [$log->patient_uuid]),
            implode(' ', $log->changed_fields),
            $log->metadata === null ? null : (string) json_encode($log->metadata, JSON_UNESCAPED_UNICODE),
            $log->ip_address,
            $log->user_agent,
            $log->correlation_id,
        ];
    }

    /**
     * @param  resource  $output
     * @param  list<string|null>  $cells
     */
    private function write($output, array $cells): void
    {
        fputcsv($output, array_map(fn (?string $cell): string => self::neutralize((string) $cell), $cells), ',', '"', '');
    }

    private static function neutralize(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'{$cell}" : $cell;
    }
}
