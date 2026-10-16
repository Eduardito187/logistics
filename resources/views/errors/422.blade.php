{{-- Error HTTP 422. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Datos inválidos')
@section('code', '422')
@section('heading', 'No pudimos procesar los datos')
@section('message', 'Revisa la información enviada e inténtalo nuevamente.')
