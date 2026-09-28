<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scheduled_task_runs` (SDD §2.12, §1.9; RNF-087): última ejecución exitosa de cada tarea
 * programada, por clínica o de plataforma (tenant_id nulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('task', 80);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->timestampTz('last_success_at');
            $table->timestampTz('watermark');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE scheduled_task_runs ADD CONSTRAINT scheduled_task_runs_task_tenant_unique UNIQUE NULLS NOT DISTINCT (task, tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
