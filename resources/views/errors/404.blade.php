{{-- Error HTTP 404. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Página no encontrada')
@section('code', '404')
@section('heading', 'No encontramos esta página')
@section('message', 'La página que buscas no existe o cambió de dirección. Revisa el enlace o vuelve al inicio.')
