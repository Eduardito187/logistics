{{-- Error HTTP 413. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Archivo demasiado grande')
@section('code', '413')
@section('heading', 'El archivo es demasiado grande')
@section('message', 'Lo que intentaste enviar supera el tamaño permitido. Prueba con un archivo más liviano.')
