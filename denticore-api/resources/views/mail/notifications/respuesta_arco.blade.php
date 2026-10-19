@extends('mail.layout')

@section('content')
    <p>{{ $clinicName }} respondió tu solicitud de derechos sobre tus datos personales ({{ $data['type'] }}): quedó {{ $data['status'] }}.</p>
    <p>Por tu privacidad, la respuesta completa está en el portal del paciente.</p>
    <p><a href="{{ $links['portal'] }}">Ver la respuesta</a></p>
@endsection
