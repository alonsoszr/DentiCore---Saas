@extends('mail.layout')

@section('content')
    <p>La clínica {{ $clinicName }} fue cancelada. Durante los 90 días siguientes, solo sus administradores pueden acceder para solicitar y descargar la exportación de sus datos.</p>
    <p>Motivo: {{ $data['reason'] }}</p>
@endsection
