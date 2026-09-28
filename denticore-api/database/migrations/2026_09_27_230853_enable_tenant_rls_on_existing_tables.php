<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * RLS en las tablas de clínica existentes (TASK-007; SDD §2.13, DD-40). Toda tabla BT nueva
 * la habilita en su propia migración con Schema::enableTenantRls().
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['encryption_keys', 'patients'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            RowLevelSecurity::disable($table);
        }
    }
};
