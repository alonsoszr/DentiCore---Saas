<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semilla del catálogo de hallazgos NTS 188 (TASK-043b; SDD §2.6; RN-17, RF-078, PQ-05): los 38
 * hallazgos de §6.1 de la NTS N° 188-MINSA/DGIESP-2022, versión 1.0 del catálogo, desde
 * `database/data/nts188_findings.php`. Catálogo de plataforma publicado con el software; su
 * revisión clínica queda registrada en `database/data/NTS188_VERIFICACION.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $data = require database_path('data/nts188_findings.php');

        DB::transaction(function () use ($data): void {
            foreach ($data['findings'] as $order => $finding) {
                $findingId = DB::table('finding_catalog')->insertGetId([
                    'code' => $finding['code'],
                    'name' => $finding['name'],
                    'acronym' => $finding['acronym'],
                    'level' => $finding['level'],
                    'dentition' => $finding['dentition'],
                    'introduced_in_version' => $data['version'],
                    'is_active' => true,
                    'display_order' => $order + 1,
                ]);

                DB::table('finding_states')->insert(array_map(fn (array $state) => [
                    'finding_id' => $findingId,
                    'code' => $state['code'],
                    'name' => $state['name'],
                    'color' => $state['color'],
                    'acronym' => $state['acronym'],
                    'is_active' => true,
                ], $finding['states']));
            }
        });
    }

    /**
     * El catálogo nunca se elimina (RF-078); solo el retroceso de la migración lo retira, con la
     * excepción de retención de DI-21.
     */
    public function down(): void
    {
        $codes = array_column((require database_path('data/nts188_findings.php'))['findings'], 'code');

        DB::transaction(function () use ($codes): void {
            DB::select("select set_config('app.retention_delete', 'on', true)");
            $ids = DB::table('finding_catalog')->whereIn('code', $codes)->pluck('id');
            DB::table('finding_states')->whereIn('finding_id', $ids)->delete();
            DB::table('finding_catalog')->whereIn('id', $ids)->delete();
        });
    }
};
