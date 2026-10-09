<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $budget->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 4px; vertical-align: top; }
        td.label { width: 28%; color: #4b5563; }
        .lines th { text-align: left; border-bottom: 1px solid #9ca3af; }
        .lines td { border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 2px 4px; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .terms { white-space: pre-wrap; border: 1px solid #d1d5db; padding: 8px; }
        .logo { max-height: 56px; max-width: 180px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="">
                @endif
                <div><strong>{{ $clinic->legal_name ?? $clinic->name }}</strong></div>
                @if ($clinic->ruc)<div>RUC {{ $clinic->ruc }}</div>@endif
                @if ($clinic->address)<div>{{ $clinic->address }}</div>@endif
            </td>
            <td class="num">
                <h1>Presupuesto {{ $budget->number }}</h1>
                <div>Emitido el {{ $issuedOn }}</div>
                <div>Vence el {{ $expiresOn }} a las 23:59</div>
            </td>
        </tr>
    </table>

    <h2>Paciente</h2>
    <table>
        <tr><td class="label">Nombre</td><td>{{ $patient->first_name }} {{ $patient->last_name }}</td></tr>
        <tr><td class="label">Historia clínica</td><td>{{ $patient->clinical_record_number }}</td></tr>
        @if ($dentist)
            <tr><td class="label">Odontólogo</td><td>{{ $dentist->name }} · COP {{ $dentist->cop_number }}</td></tr>
        @endif
    </table>

    <h2>Detalle</h2>
    <table class="lines">
        <tr>
            <th>Procedimiento</th>
            <th>Pieza</th>
            <th class="num">Cant.</th>
            <th class="num">Precio</th>
            <th class="num">Desc.</th>
            <th class="num">Subtotal</th>
        </tr>
        @foreach ($budget->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td>{{ $line->tooth }}@if ($line->surfaces !== []) ({{ implode(', ', $line->surfaces) }})@endif</td>
                <td class="num">{{ $line->quantity }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $line->discount_pct }} %</td>
                <td class="num">{{ $money($line->subtotal) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="totals" style="width: 45%; margin-left: 55%; margin-top: 8px;">
        <tr><td>Suma de subtotales</td><td class="num">{{ $money($budget->subtotal) }}</td></tr>
        <tr><td>Descuentos</td><td class="num">{{ $money($budget->discount_total) }}</td></tr>
        <tr><td>Base imponible</td><td class="num">{{ $money($budget->base_amount) }}</td></tr>
        <tr><td>IGV</td><td class="num">{{ $money($budget->igv_amount) }}</td></tr>
        <tr class="total"><td>Total</td><td class="num">{{ $money($budget->total) }}</td></tr>
    </table>
    <div>{{ $budget->prices_include_igv ? 'Los precios incluyen IGV.' : 'Los precios no incluyen IGV.' }}</div>

    @if ($budget->terms_snapshot)
        <h2>Condiciones</h2>
        <div class="terms">{{ $budget->terms_snapshot }}</div>
    @endif
</body>
</html>
