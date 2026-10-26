{{-- Error HTTP 500. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Error inesperado')
@section('code', '500')
@section('heading', 'Algo salió mal')
@section('message', 'Tuvimos un problema de nuestro lado. Ya estamos al tanto; intenta de nuevo en unos minutos.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Reintentar</a>
@endsection
