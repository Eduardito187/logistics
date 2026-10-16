{{-- Error HTTP 504. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Tiempo de espera agotado')
@section('code', '504')
@section('heading', 'El servicio tardó en responder')
@section('message', 'No obtuvimos respuesta a tiempo. Intenta de nuevo en unos minutos.')
