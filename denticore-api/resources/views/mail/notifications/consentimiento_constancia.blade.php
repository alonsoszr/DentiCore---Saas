@extends('mail.layout')

@section('content')
    <p>Registramos tu consentimiento para el tratamiento de datos personales en {{ $clinicName }}.</p>
    <p><a href="{{ $links['certificate'] }}">Descargar la constancia</a></p>
    @isset($links['preferences'])
        <p><a href="{{ $links['preferences'] }}">Gestionar mis preferencias</a></p>
    @endisset
@endsection
