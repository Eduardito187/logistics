{{-- Error HTTP 405. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Acción no disponible')
@section('code', '405')
@section('heading', 'No se puede hacer esto aquí')
@section('message', 'Esta acción no está disponible por este medio. Vuelve e intenta de nuevo.')
