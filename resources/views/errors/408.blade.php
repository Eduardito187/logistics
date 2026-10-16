{{-- Error HTTP 408. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Tiempo agotado')
@section('code', '408')
@section('heading', 'La solicitud tardó demasiado')
@section('message', 'La conexión tardó más de lo esperado. Revisa tu red e intenta otra vez.')
