{{-- Error HTTP 429. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Demasiadas solicitudes')
@section('code', '429')
@section('heading', 'Demasiadas solicitudes')
@section('message', 'Espera un momento antes de volver a intentarlo.')
