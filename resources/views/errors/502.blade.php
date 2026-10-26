{{-- Error HTTP 502. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Servicio no disponible')
@section('code', '502')
@section('heading', 'No pudimos conectar')
@section('message', 'Hubo un problema temporal de conexión. Intenta de nuevo en unos minutos.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Reintentar</a>
@endsection
