{{-- Error HTTP 405. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Método no permitido')
@section('code', '405')
@section('heading', 'Acción no permitida')
@section('message', 'Esta acción no está disponible por esta vía. Intenta de nuevo desde el panel.')
