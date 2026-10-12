Hola, {{ $recipientName }}:

Te recordamos tu cita de {{ $data['type'] }} en {{ $clinicName }} con {{ $data['dentist'] }} el {{ $data['date'] }} a las {{ $data['time'] }}.
@isset($links['confirm'])

Confirma tu asistencia: {{ $links['confirm'] }}
@endisset

Si no puedes asistir, comunícate con la clínica.
