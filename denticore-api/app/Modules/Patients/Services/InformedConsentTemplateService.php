<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsentTemplate;
use App\Modules\Patients\Models\InformedConsentTemplateVersion;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de plantillas de consentimiento informado (CUS-82; SDD §5.10 «Preparar texto», RF-072).
 * El cuerpo se versiona solo cuando cambia; el título y la asociación de procedimientos no crean
 * una versión. La desactivación no altera los consentimientos ya firmados (DD-31).
 */
class InformedConsentTemplateService
{
    /**
     * @param  array{title: string, body: string, procedures: list<string>}  $data
     */
    public function create(array $data, User $user): InformedConsentTemplate
    {
        return DB::transaction(function () use ($data, $user): InformedConsentTemplate {
            $procedures = $this->resolveProcedures($data['procedures']);
            $this->ensureSingleActiveTemplate($data['procedures'], $procedures, null);

            $template = new InformedConsentTemplate([
                'title' => $data['title'],
                'is_active' => true,
                'current_version' => 1,
            ]);
            $template->save();

            $this->storeVersion($template, $data['body'], 1, $user);
            $this->syncProcedures($template, $procedures);

            return $template->load('currentVersion', 'procedures');
        });
    }

    /**
     * @param  array{title?: string, body?: string, procedures?: list<string>}  $data
     */
    public function update(InformedConsentTemplate $template, array $data, User $user): InformedConsentTemplate
    {
        return DB::transaction(function () use ($template, $data, $user): InformedConsentTemplate {
            if (array_key_exists('title', $data)) {
                $template->title = $data['title'];
            }

            if (array_key_exists('body', $data)) {
                $current = $template->currentVersion;
                if ($current === null || hash('sha256', $data['body']) !== $current->body_sha256) {
                    $versionNumber = $current === null ? 1 : $current->version + 1;
                    $this->storeVersion($template, $data['body'], $versionNumber, $user);
                    $template->current_version = $versionNumber;
                }
            }

            if (array_key_exists('procedures', $data)) {
                $procedures = $this->resolveProcedures($data['procedures']);

                if ($template->is_active) {
                    $this->ensureSingleActiveTemplate($data['procedures'], $procedures, $template);
                }

                $this->syncProcedures($template, $procedures);
            }

            $template->save();

            return $template->load('currentVersion', 'procedures');
        });
    }

    public function deactivate(InformedConsentTemplate $template): InformedConsentTemplate
    {
        $template->is_active = false;
        $template->save();

        return $template->load('currentVersion', 'procedures');
    }

    /**
     * @param  list<string>  $uuids
     * @return list<Procedure>
     *
     * @throws ValidationException
     */
    private function resolveProcedures(array $uuids): array
    {
        /** @var list<Procedure> $procedures */
        $procedures = Procedure::query()->whereIn('uuid', $uuids)->get()->all();
        $found = collect($procedures)->pluck('uuid')->flip();
        $errors = [];

        foreach ($uuids as $index => $uuid) {
            if (! $found->has($uuid)) {
                $errors["procedures.{$index}"] = 'El procedimiento no pertenece a esta clínica.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $procedures;
    }

    /**
     * RF-073: un procedimiento tiene a lo más una plantilla activa; si no, la firma no sabría qué
     * texto presentar. Bloquea los procedimientos para que dos altas simultáneas no lo eludan.
     *
     * @param  list<string>  $uuids  En el orden de la solicitud, para indicar `procedures.N`.
     * @param  list<Procedure>  $procedures
     *
     * @throws ValidationException
     */
    private function ensureSingleActiveTemplate(array $uuids, array $procedures, ?InformedConsentTemplate $except): void
    {
        $ids = array_map(fn (Procedure $procedure) => $procedure->id, $procedures);
        Procedure::query()->whereKey($ids)->lockForUpdate()->get();

        $positions = array_flip($uuids);
        $errors = [];

        foreach ($procedures as $procedure) {
            $other = InformedConsentTemplate::query()
                ->where('is_active', true)
                ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
                ->whereHas('procedures', fn ($query) => $query->whereKey($procedure->id))
                ->first();

            if ($other !== null) {
                $errors['procedures.'.$positions[$procedure->uuid]] = "El procedimiento «{$procedure->name}» ya tiene la plantilla activa «{$other->title}»; desactívela primero.";
            }
        }

        if ($errors !== []) {
            ksort($errors);

            throw ValidationException::withMessages($errors);
        }
    }

    private function storeVersion(InformedConsentTemplate $template, string $body, int $versionNumber, User $user): void
    {
        $version = new InformedConsentTemplateVersion;
        $version->forceFill([
            'uuid' => (string) Str::uuid(),
            'informed_consent_template_id' => $template->id,
            'version' => $versionNumber,
            'body' => $body,
            'body_sha256' => hash('sha256', $body),
            'created_by' => $user->id,
        ]);
        $version->save();
    }

    /**
     * El pivote lleva tenant_id como parte de la clave primaria, así que se escribe a mano.
     *
     * @param  list<Procedure>  $procedures
     */
    private function syncProcedures(InformedConsentTemplate $template, array $procedures): void
    {
        $tenantId = TenantContext::idOrFail();

        DB::table('procedure_informed_consent_template')
            ->where('tenant_id', $tenantId)
            ->where('informed_consent_template_id', $template->id)
            ->delete();

        foreach ($procedures as $procedure) {
            DB::table('procedure_informed_consent_template')->insert([
                'tenant_id' => $tenantId,
                'procedure_id' => $procedure->id,
                'informed_consent_template_id' => $template->id,
            ]);
        }
    }
}
