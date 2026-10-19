<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <title>Copia de la historia clínica</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
        h3 { font-size: 11px; margin: 10px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 4px; vertical-align: top; }
        .label { width: 28%; color: #4b5563; text-align: left; font-weight: normal; }
        .rows th { text-align: left; border-bottom: 1px solid #9ca3af; }
        .rows td { border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b7280; }
        .text { white-space: pre-wrap; }
        .chart td, .chart th { border: 1px solid #d1d5db; text-align: center; width: 6.25%; padding: 2px 1px; }
        .chart .tooth { font-size: 8px; color: #4b5563; font-weight: normal; }
        .chart .gap td { border: none; height: 4px; }
        .rojo { color: #b91c1c; font-weight: bold; }
        .azul { color: #1d4ed8; font-weight: bold; }
        .attention { border: 1px solid #d1d5db; padding: 6px; margin-bottom: 8px; }
        .legend { font-style: italic; color: #4b5563; }
    </style>
</head>
<body>
    <table role="presentation">
        <tr>
            <td>
                <div><strong>{{ $clinic->legal_name ?? $clinic->name }}</strong></div>
                @if ($clinic->ruc)<div>RUC {{ $clinic->ruc }}</div>@endif
                @if ($clinic->address)<div>{{ $clinic->address }}</div>@endif
            </td>
            <td class="num">
                <h1>Copia de la historia clínica</h1>
                <div>HC {{ $patient->clinical_record_number }}</div>
                <div>Generada el {{ $generatedOn }}</div>
            </td>
        </tr>
    </table>

    <h2>Ficha del paciente</h2>
    <table>
        <tr><th scope="row" class="label">Nombre</th><td>{{ $patient->first_name }} {{ $patient->last_name }}</td></tr>
        <tr><th scope="row" class="label">Documento</th><td>{{ strtoupper((string) $patient->document_type) }} {{ $patient->document_number }}</td></tr>
        <tr><th scope="row" class="label">Fecha de nacimiento</th><td>{{ $patient->birth_date?->format('d/m/Y') }}</td></tr>
        @if ($patient->sex)<tr><th scope="row" class="label">Sexo</th><td>{{ $patient->sex }}</td></tr>@endif
        @if ($patient->phone)<tr><th scope="row" class="label">Teléfono</th><td>{{ $patient->phone }}</td></tr>@endif
        @if ($patient->email)<tr><th scope="row" class="label">Correo</th><td>{{ $patient->email }}</td></tr>@endif
        @if ($patient->address)<tr><th scope="row" class="label">Dirección</th><td>{{ $patient->address }}</td></tr>@endif
        <tr><th scope="row" class="label">Estado del archivo</th><td>{{ $patient->archive_status }}</td></tr>
    </table>

    <h3>Antecedentes médicos</h3>
    @php($history = $patient->medical_history)
    @if ($history)
        <table>
            <tr><th scope="row" class="label">Alergias</th><td>{{ implode(', ', $history['alergias'] ?? []) ?: 'Ninguna registrada' }}</td></tr>
            <tr><th scope="row" class="label">Enfermedades</th><td>{{ implode(', ', $history['enfermedades'] ?? []) ?: 'Ninguna registrada' }}</td></tr>
            <tr><th scope="row" class="label">Medicamentos</th><td>{{ implode(', ', $history['medicamentos'] ?? []) ?: 'Ninguno registrado' }}</td></tr>
            @if (! empty($history['observaciones']))<tr><th scope="row" class="label">Observaciones</th><td class="text">{{ $history['observaciones'] }}</td></tr>@endif
        </table>
    @else
        <div class="muted">Sin antecedentes registrados.</div>
    @endif

    @if ($patient->representatives->isNotEmpty())
        <h3>Representantes legales</h3>
        <table class="rows">
            <tr><th>Nombre</th><th>Parentesco</th><th>Documento</th><th>Vigencia</th></tr>
            @foreach ($patient->representatives as $representative)
                <tr>
                    <td>{{ $representative->first_name }} {{ $representative->last_name }}</td>
                    <td>{{ $representative->relationship }}</td>
                    <td>{{ strtoupper($representative->document_type) }} {{ $representative->document_number }}</td>
                    <td>{{ $representative->valid_from?->format('d/m/Y') }} – {{ $representative->valid_until?->format('d/m/Y') ?? 'vigente' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Consentimientos</h2>
    @forelse ($consents as $consent)
        <table class="rows">
            <tr><th>Otorgado</th><th>Canal</th><th>Por</th><th>Finalidades aceptadas</th><th>Estado</th></tr>
            <tr>
                <td>{{ $dateTime($consent->granted_at) }}</td>
                <td>{{ $consent->channel }}</td>
                <td>
                    @if ($consent->legalRepresentative)
                        {{ $consent->legalRepresentative->first_name }} {{ $consent->legalRepresentative->last_name }} (representante)
                    @else
                        Titular
                    @endif
                </td>
                <td>{{ implode(', ', array_keys(array_filter(array_map(fn (string $column) => (bool) $consent->getAttribute($column), \App\Modules\Patients\Models\Consent::PURPOSES)))) }}</td>
                <td>{{ $consent->status }}</td>
            </tr>
            @foreach ($consent->purposeRevocations as $revocation)
                <tr><td colspan="5" class="muted">Revocada la finalidad «{{ $revocation->purpose }}» el {{ $dateTime($revocation->created_at) }} por el canal {{ $revocation->channel }}.</td></tr>
            @endforeach
        </table>
    @empty
        <div class="muted">Sin consentimientos registrados.</div>
    @endforelse

    @foreach ([['Odontograma inicial', $initialChart, $initialEntries], ['Odontograma vigente', $currentChart, $currentEntries]] as [$title, $chart, $entries])
        <h2>{{ $title }}</h2>
        @if ($chart === null)
            <div class="muted">El paciente aún no tiene su primera atención.</div>
        @else
            <table class="chart">
                @foreach (\App\Modules\Compliance\Documents\ClinicalRecordCopyRenderer::CHART_ROWS as $index => $row)
                    @if ($index === 2)<tr class="gap"><td colspan="16"></td></tr>@endif
                    <tr>
                        @if (count($row) < 16)<td colspan="3" style="border: none;"></td>@endif
                        @foreach ($row as $tooth)
                            <th scope="col" class="tooth">{{ $tooth }}</th>
                        @endforeach
                        @if (count($row) < 16)<td colspan="3" style="border: none;"></td>@endif
                    </tr>
                    <tr>
                        @if (count($row) < 16)<td colspan="3" style="border: none;"></td>@endif
                        @foreach ($row as $tooth)
                            <td>
                                @foreach ($chart[$tooth] ?? [] as $mark)
                                    <div class="{{ $mark['color'] }}">{{ $mark['label'] }}</div>
                                @endforeach
                            </td>
                        @endforeach
                        @if (count($row) < 16)<td colspan="3" style="border: none;"></td>@endif
                    </tr>
                @endforeach
            </table>
            <div class="muted">Rojo: requiere tratamiento. Azul: en buen estado (NTS 188).</div>
            @if ($entries->isNotEmpty())
                <table class="rows" style="margin-top: 6px;">
                    <tr><th>Pieza</th><th>Superficies</th><th>Hallazgo</th><th>Estado</th><th>Nota</th><th>Registrado</th></tr>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ $entry->tooth }}@if ($entry->tooth_end)–{{ $entry->tooth_end }}@endif</td>
                            <td>{{ implode(', ', $entry->surfaces) }}</td>
                            <td>{{ $entry->finding?->name }}</td>
                            <td class="{{ $entry->color ?? $entry->findingState?->color }}">{{ $entry->findingState?->name }}</td>
                            <td class="text">{{ $entry->note }}</td>
                            <td>{{ $dateTime($entry->recorded_at) }} · {{ $entry->author->name }} (COP {{ $entry->author_cop }})</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    @endforeach

    <h2>Historial por pieza</h2>
    @forelse ($toothHistory as $tooth => $history)
        <h3>Pieza {{ $tooth }}</h3>
        <table class="rows">
            <tr><th>Fecha</th><th>Tipo</th><th>Hallazgo</th><th>Estado</th><th>Corrección</th><th>Autor</th></tr>
            @foreach ($history as $entry)
                <tr>
                    <td>{{ $dateTime($entry->recorded_at) }}</td>
                    <td>{{ $entry->entry_type }}@if ($entry->correction_kind) ({{ $entry->correction_kind }})@endif</td>
                    <td>{{ $entry->finding?->name }}@if ($entry->surfaces !== []) ({{ implode(', ', $entry->surfaces) }})@endif</td>
                    <td>{{ $entry->findingState?->name }}</td>
                    <td class="text">{{ $entry->correction_reason }}@if ($entry->correction) Corregida el {{ $dateTime($entry->correction->recorded_at) }}.@endif</td>
                    <td>{{ $entry->author->name }} (COP {{ $entry->author_cop }})</td>
                </tr>
            @endforeach
        </table>
    @empty
        <div class="muted">Sin entradas en el odontograma.</div>
    @endforelse

    <h2>Atenciones</h2>
    @forelse ($attentions as $attention)
        <div class="attention">
            <strong>{{ $dateTime($attention->opened_at) }}</strong> · {{ $attention->dentist->name }} (COP {{ $attention->dentist->cop_number }}) · {{ $attention->status }}
            @if ($attention->signed_at)
                <div class="muted">Firmada el {{ $dateTime($attention->signed_at) }} por {{ $attention->signer?->name }} (COP {{ $attention->signer_cop }}).</div>
            @endif
            @if ($attention->note)
                <table>
                    @foreach (['chief_complaint' => 'Motivo de consulta', 'current_illness' => 'Enfermedad actual', 'extraoral_exam' => 'Examen extraoral', 'intraoral_exam' => 'Examen intraoral', 'indications' => 'Indicaciones'] as $field => $label)
                        @if ($attention->note->getAttribute($field))
                            <tr><th scope="row" class="label">{{ $label }}</th><td class="text">{{ $attention->note->getAttribute($field) }}</td></tr>
                        @endif
                    @endforeach
                </table>
            @endif
            @if ($attention->diagnoses->isNotEmpty())
                <h3>Diagnósticos</h3>
                <table class="rows">
                    <tr><th>Código</th><th>Descripción</th><th>Tipo</th></tr>
                    @foreach ($attention->diagnoses as $diagnosis)
                        <tr><td>{{ $diagnosis->cie10_code }}</td><td>{{ $diagnosis->cie10?->description }}</td><td>{{ $diagnosis->type }}</td></tr>
                    @endforeach
                </table>
            @endif
            @foreach ($attention->addenda as $addendum)
                <h3>Adenda del {{ $dateTime($addendum->created_at) }} · {{ $addendum->author->name }} (COP {{ $addendum->author_cop }})</h3>
                <div class="text">{{ $addendum->text }}</div>
            @endforeach
        </div>
    @empty
        <div class="muted">Sin atenciones registradas.</div>
    @endforelse

    <h2>Planes de tratamiento</h2>
    @forelse ($plans as $plan)
        <h3>{{ $plan->title }} · {{ $plan->status }} · {{ $date($plan->created_at) }}</h3>
        <table class="rows">
            <tr><th>Procedimiento</th><th>Pieza</th><th class="num">Cant.</th><th class="num">Realizada</th><th>Estado</th></tr>
            @foreach ($plan->items as $item)
                <tr>
                    <td>{{ $item->procedure->name }}</td>
                    <td>{{ $item->tooth }}@if ($item->surfaces !== []) ({{ implode(', ', $item->surfaces) }})@endif</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $item->performed_quantity }}</td>
                    <td>{{ $item->status }}</td>
                </tr>
            @endforeach
        </table>
        @if ($plan->cancel_reason)<div class="muted">Cancelado: {{ $plan->cancel_reason }}</div>@endif
    @empty
        <div class="muted">Sin planes de tratamiento.</div>
    @endforelse

    <h2>Presupuestos</h2>
    @php($budgets = $plans->flatMap->budgets)
    @if ($budgets->isEmpty())
        <div class="muted">Sin presupuestos.</div>
    @else
        <table class="rows">
            <tr><th>Número</th><th>Plan</th><th>Estado</th><th>Emitido</th><th>Vence</th><th class="num">Total</th></tr>
            @foreach ($plans as $plan)
                @foreach ($plan->budgets as $budget)
                    <tr>
                        <td>{{ $budget->number ?? 'Borrador' }}</td>
                        <td>{{ $plan->title }}</td>
                        <td>{{ $budget->status }}</td>
                        <td>{{ $date($budget->issued_at) }}</td>
                        <td>{{ $date($budget->expires_at) }}</td>
                        <td class="num">{{ $money($budget->total) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    <h2>Procedimientos realizados</h2>
    @if ($procedures->isEmpty())
        <div class="muted">Sin procedimientos realizados.</div>
    @else
        <table class="rows">
            <tr><th>Fecha</th><th>Procedimiento</th><th>Pieza</th><th class="num">Cant.</th><th>Odontólogo</th><th>Observaciones</th></tr>
            @foreach ($procedures as $procedure)
                <tr>
                    <td>{{ $dateTime($procedure->performed_at) }}</td>
                    <td>{{ $procedure->planItem->procedure->name }}</td>
                    <td>{{ $procedure->planItem->tooth }}</td>
                    <td class="num">{{ $procedure->quantity }}</td>
                    <td>{{ $procedure->dentist->name }}</td>
                    <td class="text">{{ $procedure->observations }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Predicciones de riesgo de caries</h2>
    @if ($predictions->isEmpty())
        <div class="muted">Sin predicciones.</div>
    @else
        <table class="rows">
            <tr><th>Fecha</th><th>Nivel</th><th class="num">Probabilidad</th><th class="num">Confianza</th><th>Estado</th></tr>
            @foreach ($predictions as $prediction)
                <tr>
                    <td>{{ $dateTime($prediction->predicted_at) }}</td>
                    <td>{{ ucfirst($prediction->level) }}</td>
                    <td class="num">{{ number_format((float) $prediction->probability * 100, 0) }} %</td>
                    <td class="num">{{ number_format((float) $prediction->confidence * 100, 0) }} %{{ (float) $prediction->confidence < $lowConfidence ? ' · Baja confianza' : '' }}</td>
                    <td>{{ $prediction->status }}</td>
                </tr>
            @endforeach
        </table>
        <div class="legend">{{ $riskLegend }}.</div>
    @endif
</body>
</html>
