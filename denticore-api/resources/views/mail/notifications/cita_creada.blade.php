@extends('mail.layout')

@section('content')
    <p>{{ $clinicName }} reservó tu cita de {{ $data['type'] }} con {{ $data['dentist'] }} para el {{ $data['date'] }} a las {{ $data['time'] }}.</p>
    @isset($links['confirm'])
        <p><a href="{{ $links['confirm'] }}">Confirmar mi asistencia</a></p>
    @endisset
    <p>Si no puedes asistir, comunícate con la clínica.</p>
@endsection
