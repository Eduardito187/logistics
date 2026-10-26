{{-- Error HTTP 429. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Demasiados intentos')
@section('code', '429')
@section('heading', 'Espera un momento')
@section('message', 'Hiciste muchas solicitudes seguidas. Espera unos segundos e intenta de nuevo.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Reintentar</a>
@endsection
