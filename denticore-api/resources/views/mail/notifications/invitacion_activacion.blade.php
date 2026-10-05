@extends('mail.layout')

@section('content')
    <p>Te invitaron a usar DentiCore{{ $clinicName ? ' en '.$clinicName : '' }}.</p>
    <p><a href="{{ $links['activate'] }}">Activar mi cuenta</a></p>
    <p>El enlace se puede usar una sola vez y vence en 72 horas.</p>
@endsection
