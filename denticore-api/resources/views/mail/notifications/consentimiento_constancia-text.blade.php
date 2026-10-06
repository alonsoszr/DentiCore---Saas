Hola, {{ $recipientName }}:

Registramos tu consentimiento para el tratamiento de datos personales en {{ $clinicName }}.

Descarga la constancia: {{ $links['certificate'] }}
@isset($links['preferences'])

Gestiona tus preferencias: {{ $links['preferences'] }}
@endisset
