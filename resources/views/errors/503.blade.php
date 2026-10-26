{{-- Error HTTP 503. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'En mantenimiento')
@section('code', '503')
@section('heading', 'Estamos en mantenimiento')
@section('message', 'Estamos haciendo mejoras. Vuelve en unos minutos, por favor.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Reintentar</a>
@endsection
