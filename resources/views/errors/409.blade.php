{{-- Error HTTP 409. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Datos cambiaron')
@section('code', '409')
@section('heading', 'Esta información cambió mientras trabajabas')
@section('message', 'Estos datos se actualizaron hace un momento. Vuelve a cargar la página e intenta otra vez.')

@section('extra-actions')
    <a class="btn" href="{{ request()->url() }}">Recargar</a>
@endsection
