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
                $this->syncProcedures($template, $this->resolveProcedures($data['procedures']));
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
        $found = collect($procedures)->pluck('uuid');

        if (collect($uuids)->diff($found)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'procedures.0' => 'El procedimiento no pertenece a esta clínica.',
            ]);
        }

        return $procedures;
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
