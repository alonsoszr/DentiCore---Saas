<?php

namespace App\Support\ReferenceData;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inserciones por lote en tablas existentes, siempre con el contexto de la clínica.
 */
final class ReferenceDatasetRows
{
    public function createCatalogs(Tenant $tenant): void
    {
        TenantContext::run($tenant, function () use ($tenant): void {
            DB::table('appointment_types')->insert([
                'tenant_id' => $tenant->id,
                'name' => 'Consulta sintética CDR',
                'default_duration_min' => 30,
                'color' => '#176B6A',
            ]);
            DB::table('procedure_catalog')->insert([
                'tenant_id' => $tenant->id,
                'code' => 'CDR',
                'name' => 'Procedimiento sintético CDR',
                'price' => '100.00',
                'requires_tooth' => false,
                'requires_surface' => false,
                'is_active' => true,
            ]);
        });
    }

    /** @param list<int> $ids */
    public function generate(Tenant $tenant, array $ids, string $date, int $author, int $offset): void
    {
        $dentist = User::query()->where('tenant_id', $tenant->id)->where('role', 'dentist')->orderBy('id')->firstOrFail();
        $type = (int) DB::table('appointment_types')->where('tenant_id', $tenant->id)->where('name', 'Consulta sintética CDR')->soleValue('id');
        $procedure = (int) DB::table('procedure_catalog')->where('tenant_id', $tenant->id)->where('code', 'CDR')->soleValue('id');
        $batch = json_encode($ids, JSON_THROW_ON_ERROR);

        $expectedPatients = count($ids);
        $actual = [
            ...$this->attentions($batch, $date, $dentist),
            $this->appointments($batch, $date, $dentist, $author, $type, $offset),
            ...$this->budgets($batch, $dentist, $procedure),
        ];
        $expected = [
            $expectedPatients * ReferenceDatasetPlan::ATTENTIONS_PER_PATIENT,
            $expectedPatients * ReferenceDatasetPlan::ENTRIES_PER_PATIENT,
            $expectedPatients * ReferenceDatasetPlan::APPOINTMENTS_PER_PATIENT,
            $expectedPatients * ReferenceDatasetPlan::BUDGETS_PER_PATIENT,
            $expectedPatients * ReferenceDatasetPlan::BUDGETS_PER_PATIENT * ReferenceDatasetPlan::LINES_PER_BUDGET,
        ];
        if ($actual !== $expected) {
            throw new RuntimeException("Las filas generadas del lote de {$tenant->slug} no coinciden con el CDR.");
        }
    }

    /** @return array{int, int} */
    private function attentions(string $batch, string $date, User $dentist): array
    {
        $attentions = DB::affectingStatement(<<<'SQL'
            WITH batch AS (SELECT value::bigint AS id FROM jsonb_array_elements_text(?::jsonb)),
            dates AS (
                SELECT p.tenant_id, p.id AS patient_id, visit,
                       ?::date - (9 - visit) * interval '35 days' AS opened
                  FROM patients p JOIN batch USING (id)
                  CROSS JOIN generate_series(1, ?::integer) AS visit
            )
            INSERT INTO attentions (tenant_id, patient_id, dentist_id, status, is_first_attention, opened_at, closed_at, closed_by_system, created_at)
            SELECT tenant_id, patient_id, ?, 'cerrada_incompleta', visit = 1, opened, opened + interval '30 minutes', true, opened
              FROM dates
            SQL, [$batch, $date, ReferenceDatasetPlan::ATTENTIONS_PER_PATIENT, $dentist->id]);

        $entries = DB::affectingStatement(<<<'SQL'
            WITH batch AS (SELECT value::bigint AS id FROM jsonb_array_elements_text(?::jsonb)),
            visits AS (
                SELECT a.*, row_number() OVER (PARTITION BY a.patient_id ORDER BY a.opened_at, a.id) AS visit
                  FROM attentions a JOIN batch b ON b.id = a.patient_id
            ),
            finding AS (
                SELECT s.id AS state_id, s.finding_id, s.color FROM finding_states s ORDER BY s.id LIMIT 1
            )
            INSERT INTO odontogram_entries
              (tenant_id, patient_id, chain_patient_id, attention_id, entry_type, tooth,
               finding_id, finding_state_id, color, origin, author_id, author_cop, recorded_at, created_at)
            SELECT v.tenant_id, v.patient_id, v.patient_id, v.id, 'evolucion', 16,
                   f.finding_id, f.state_id, f.color, 'manual', v.dentist_id, ?,
                   v.opened_at + entry * interval '1 minute', v.opened_at
              FROM visits v CROSS JOIN finding f
              CROSS JOIN generate_series(1, ?::integer) AS entry
             WHERE (entry - 1) % ?::integer = v.visit - 1
             ORDER BY v.patient_id, v.opened_at, entry
            SQL, [$batch, $dentist->cop_number, ReferenceDatasetPlan::ENTRIES_PER_PATIENT, ReferenceDatasetPlan::ATTENTIONS_PER_PATIENT]);

        return [$attentions, $entries];
    }

    private function appointments(string $batch, string $date, User $dentist, int $author, int $type, int $offset): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH batch AS (
                SELECT value::bigint AS id, ordinality FROM jsonb_array_elements_text(?::jsonb) WITH ORDINALITY
            ),
            dates AS (
                SELECT p.tenant_id, p.id AS patient_id, appointment,
                       CASE WHEN appointment = 1
                         THEN ?::date + interval '1 day 9 hours' + (ordinality - 1 + ?::integer) * interval '30 minutes'
                         ELSE ?::date - (appointment - 1) * interval '35 days' + interval '9 hours'
                       END AS starts
                  FROM patients p JOIN batch USING (id)
                  CROSS JOIN generate_series(1, ?::integer) AS appointment
            )
            INSERT INTO appointments
                (tenant_id, patient_id, dentist_id, appointment_type_id, starts_at, ends_at, status, origin, created_by, created_at)
            SELECT tenant_id, patient_id, ?, ?, starts, starts + interval '30 minutes',
                   CASE WHEN appointment = 1 THEN 'programada' ELSE 'atendida' END, 'clinica', ?, starts
              FROM dates
            SQL, [$batch, $date, $offset, $date, ReferenceDatasetPlan::APPOINTMENTS_PER_PATIENT, $dentist->id, $type, $author]);
    }

    /** @return array{int, int} */
    private function budgets(string $batch, User $dentist, int $procedure): array
    {
        $budgets = DB::affectingStatement(<<<'SQL'
            WITH batch AS (SELECT value::bigint AS id FROM jsonb_array_elements_text(?::jsonb)),
            plans AS (
                INSERT INTO treatment_plans (tenant_id, patient_id, title, created_by)
                SELECT p.tenant_id, p.id, 'Plan sintético CDR ' || number, ?
                  FROM patients p JOIN batch USING (id) CROSS JOIN generate_series(1, ?::integer) AS number
                RETURNING id, tenant_id, patient_id
            ),
            items AS (
                INSERT INTO plan_items (tenant_id, treatment_plan_id, position, procedure_id)
                SELECT p.tenant_id, p.id, position, ?
                  FROM plans p CROSS JOIN generate_series(1, ?::integer) AS position
                RETURNING id, tenant_id, treatment_plan_id, procedure_id
            )
            INSERT INTO budgets (tenant_id, patient_id, treatment_plan_id, prices_include_igv,
                                 subtotal, base_amount, igv_amount, total, dentist_id)
            SELECT p.tenant_id, p.patient_id, p.id, false, 400.00, 400.00, 72.00, 472.00, ?
              FROM plans p
            SQL, [$batch, $dentist->id, ReferenceDatasetPlan::BUDGETS_PER_PATIENT, $procedure, ReferenceDatasetPlan::LINES_PER_BUDGET, $dentist->id]);

        // El disparador de líneas comprueba que el presupuesto exista y continúe en borrador.
        $lines = DB::affectingStatement(<<<'SQL'
            WITH batch AS (SELECT value::bigint AS id FROM jsonb_array_elements_text(?::jsonb))
            INSERT INTO budget_lines (tenant_id, budget_id, plan_item_id, procedure_id, description, unit_price, quantity, subtotal)
            SELECT b.tenant_id, b.id, i.id, i.procedure_id, 'Procedimiento sintético CDR', 100.00, 1, 100.00
              FROM budgets b JOIN batch ON batch.id = b.patient_id
              JOIN plan_items i ON i.tenant_id = b.tenant_id AND i.treatment_plan_id = b.treatment_plan_id
            SQL, [$batch]);

        return [$budgets, $lines];
    }
}
