Hola, {{ $recipientName }}:

{{ $clinicName }} emitió tu presupuesto {{ $data['number'] }} por S/ {{ $data['total'] }}.

Está vigente hasta el {{ $data['expires_on'] }} a las 23:59.

Ver mis presupuestos: {{ $links['budgets'] }}
