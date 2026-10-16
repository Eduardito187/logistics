{{-- Error HTTP 500. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Error del servidor')
@section('code', '500')
@section('heading', 'Algo salió mal de nuestro lado')
@section('message', 'Tuvimos un problema al procesar tu solicitud. Ya estamos en ello; intenta de nuevo en unos minutos.')
