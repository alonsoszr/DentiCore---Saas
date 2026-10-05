@extends('mail.layout')

@section('content')
    <p>La clínica {{ $clinicName }} fue suspendida. Mientras dure la suspensión, sus usuarios solo pueden consultar información.</p>
    <p>Motivo: {{ $data['reason'] }}</p>
@endsection
