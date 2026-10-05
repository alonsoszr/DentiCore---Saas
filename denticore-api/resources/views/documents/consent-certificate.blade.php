<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <title>Constancia de consentimiento</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 4px; vertical-align: top; }
        td.label { width: 32%; color: #4b5563; }
        .text { white-space: pre-wrap; border: 1px solid #d1d5db; padding: 8px; }
        .hash { font-family: DejaVu Sans Mono, monospace; font-size: 9px; word-break: break-all; }
    </style>
</head>
<body>
    <h1>Constancia de consentimiento para el tratamiento de datos personales</h1>
    <div>{{ $clinic->legal_name ?? $clinic->name }}@if ($clinic->ruc) · RUC {{ $clinic->ruc }}@endif</div>

    <h2>Datos del registro</h2>
    <table>
        <tr><td class="label">Titular</td><td>{{ $patient->first_name }} {{ $patient->last_name }}</td></tr>
        <tr><td class="label">Historia clínica</td><td>{{ $patient->clinical_record_number }}</td></tr>
        <tr>
            <td class="label">Otorgado por</td>
            <td>
                @if ($representative)
                    Representante legal: {{ $representative->first_name }} {{ $representative->last_name }}
                @else
                    El titular
                @endif
            </td>
        </tr>
        <tr><td class="label">Canal</td><td>{{ $channel }}</td></tr>
        <tr><td class="label">Fecha y hora</td><td>{{ $grantedAt }}</td></tr>
        <tr><td class="label">Versión de la plantilla</td><td>{{ $consent->consent_template_version }}</td></tr>
    </table>

    <h2>Finalidades</h2>
    <table>
        @foreach ($purposes as $purpose)
            <tr><td class="label">{{ $purpose['label'] }}</td><td>{{ $purpose['granted'] ? 'Otorgada' : 'No otorgada' }}</td></tr>
        @endforeach
    </table>

    <h2>Texto presentado</h2>
    <div class="text">{{ $consent->rendered_text }}</div>

    <h2>Verificación</h2>
    <table>
        <tr><td class="label">Huella SHA-256 del texto</td><td class="hash">{{ $consent->text_sha256 }}</td></tr>
        <tr><td class="label">Sello de evidencia</td><td class="hash">{{ $consent->evidence_hmac }}</td></tr>
        <tr><td class="label">Identificador</td><td class="hash">{{ $consent->uuid }}</td></tr>
    </table>
</body>
</html>
