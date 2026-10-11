Hola, {{ $recipientName }}:

{{ $clinicName }} cambió tu cita de {{ $data['type'] }} con {{ $data['dentist'] }}: ahora es el {{ $data['date'] }} a las {{ $data['time'] }}.
@isset($links['confirm'])

Confirma tu asistencia: {{ $links['confirm'] }}
@endisset

Si no puedes asistir, comunícate con la clínica.
