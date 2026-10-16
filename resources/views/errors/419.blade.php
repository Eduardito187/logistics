{{-- Error HTTP 419. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Sesión expirada')
@section('code', '419')
@section('heading', 'La sesión expiró, vuelve a entrar')
@section('message', 'Por tu seguridad cerramos la sesión por inactividad. Inicia sesión de nuevo.')
