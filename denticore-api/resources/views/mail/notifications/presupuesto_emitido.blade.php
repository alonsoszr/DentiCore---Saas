@extends('mail.layout')

@section('content')
    <p>{{ $clinicName }} emitió tu presupuesto {{ $data['number'] }} por S/ {{ $data['total'] }}.</p>
    <p>Está vigente hasta el {{ $data['expires_on'] }} a las 23:59.</p>
    <p><a href="{{ $links['budgets'] }}">Ver mis presupuestos</a></p>
@endsection
