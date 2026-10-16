{{-- Error HTTP 409. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Conflicto')
@section('code', '409')
@section('heading', 'Hubo un conflicto')
@section('message', 'El recurso cambió mientras lo editabas. Vuelve a cargar e intenta de nuevo.')
