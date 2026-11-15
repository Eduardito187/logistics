{{-- Error HTTP 400. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Solicitud no válida')
@section('code', '400')
@section('heading', 'No pudimos procesar la solicitud')
@section('message', 'La dirección o los datos enviados no son válidos. Revisa el enlace e intenta de nuevo.')
