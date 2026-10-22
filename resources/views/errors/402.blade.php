{{-- Error HTTP 402. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'No disponible')
@section('code', '402')
@section('heading', 'Esta sección no está disponible')
@section('message', 'Esta opción no está habilitada por ahora. Si crees que es un error, contáctanos.')
