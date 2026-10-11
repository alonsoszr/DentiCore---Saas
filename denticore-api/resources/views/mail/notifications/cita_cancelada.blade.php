@extends('mail.layout')

@section('content')
    <p>{{ $clinicName }} canceló tu cita de {{ $data['type'] }} con {{ $data['dentist'] }} del {{ $data['date'] }} a las {{ $data['time'] }}.</p>
    <p>Para reservar otra cita, comunícate con la clínica.</p>
@endsection
