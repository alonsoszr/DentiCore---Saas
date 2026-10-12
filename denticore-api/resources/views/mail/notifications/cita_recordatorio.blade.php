@extends('mail.layout')

@section('content')
    <p>Te recordamos tu cita de {{ $data['type'] }} en {{ $clinicName }} con {{ $data['dentist'] }} el {{ $data['date'] }} a las {{ $data['time'] }}.</p>
    @isset($links['confirm'])
        <p><a href="{{ $links['confirm'] }}">Confirmar mi asistencia</a></p>
    @endisset
    <p>Si no puedes asistir, comunícate con la clínica.</p>
@endsection
