{{-- Diseño común de los correos (SDD §4.8; RF-155, RNF-049). --}}
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $clinicName ?? 'DentiCore' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f6f7;font-family:system-ui,'Segoe UI',Roboto,sans-serif;color:#2b3036;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dfe2e6;border-radius:8px;padding:24px;">
        <p style="margin:0 0 16px;font-weight:700;">{{ $clinicName ?? 'DentiCore' }}</p>
        <p style="margin:0 0 12px;">Hola, {{ $recipientName }}:</p>
        @yield('content')
    </div>
</body>
</html>
