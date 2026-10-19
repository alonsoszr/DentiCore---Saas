Hola, {{ $recipientName }}:

Una solicitud ARCO ({{ $data['type'] }}) de {{ $clinicName }} vence el {{ \Illuminate\Support\Carbon::parse($data['due_date'])->format('d/m/Y') }} y aún no tiene respuesta.

El plazo interno es de 10 días hábiles desde su recepción (Ley N° 29733).

Atender la solicitud: {{ $links['request'] }}
