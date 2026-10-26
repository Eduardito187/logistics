{{-- Error HTTP 408. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Tardó demasiado')
@section('code', '408')
@section('heading', 'La conexión tardó demasiado')
@section('message', 'Revisa tu conexión a internet e intenta de nuevo.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Reintentar</a>
@endsection
