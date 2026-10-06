@extends('mail.layout')

@section('content')
    <p>Recibimos una solicitud para restablecer tu contraseña.</p>
    <p><a href="{{ $links['reset'] }}">Restablecer mi contraseña</a></p>
    <p>El enlace se puede usar una sola vez y vence en 60 minutos. Si no lo solicitaste, ignora este correo.</p>
@endsection
