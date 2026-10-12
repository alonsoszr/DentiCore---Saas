<?php

namespace App\Modules\Risk\Console;

use App\Modules\Risk\Services\ModelVersionService;
use App\Modules\Risk\Services\RiskEngineUnavailable;
use App\Support\Http\BusinessRuleException;
use Illuminate\Console\Command;

/**
 * `risk:register-model {version} {--activate}` (Plan TASK-080; supuesto S-09; RN-84): registra en
 * `model_versions` una versión que el motor publica en `GET /v1/models` y, con `--activate`, la
 * deja como la única activa. Es fontanería de E1: los endpoints de CUS-61 llegan en E3.
 */
class RegisterModelCommand extends Command
{
    protected $signature = 'risk:register-model {version : Versión SemVer publicada por el motor} {--activate : Activa la versión tras registrarla}';

    protected $description = 'Registra (y opcionalmente activa) una versión del modelo de riesgo';

    public function handle(ModelVersionService $versions): int
    {
        $version = (string) $this->argument('version');

        try {
            $model = $versions->register($version);
            $this->info("Versión {$version} registrada (AUC {$model->auc_validation}).");

            if ($this->option('activate')) {
                $versions->activate($model);
                $this->info("Versión {$version} activa.");
            }
        } catch (BusinessRuleException|RiskEngineUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
