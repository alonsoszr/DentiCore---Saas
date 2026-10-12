@extends('mail.layout')

@section('content')
    <p>Una predicción de riesgo de caries que solicitaste en {{ $clinicName }} resultó de nivel alto.</p>
    <p>Reconoce la alerta con un plan preventivo, una cita de control o una justificación.</p>
    <p><a href="{{ $links['alerts'] }}">Ver mis alertas</a></p>
@endsection
