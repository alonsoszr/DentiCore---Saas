@extends('mail.layout')

@section('content')
    <p>La clínica {{ $clinicName }} fue reactivada. Sus usuarios ya pueden volver a registrar información.</p>
    <p>Motivo: {{ $data['reason'] }}</p>
@endsection
