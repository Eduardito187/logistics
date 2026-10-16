{{-- Error HTTP 403. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Acceso denegado')
@section('code', '403')
@section('heading', 'No tienes permiso para ver esto')
@section('message', 'Tu cuenta no tiene acceso a esta sección.')
