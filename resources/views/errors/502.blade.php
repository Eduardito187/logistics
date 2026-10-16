{{-- Error HTTP 502. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Error de puerta de enlace')
@section('code', '502')
@section('heading', 'Servicio no disponible')
@section('message', 'Estamos teniendo problemas de conexión con el servicio. Intenta de nuevo en unos minutos.')
