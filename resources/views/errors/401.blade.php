{{-- Error HTTP 401. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Inicia sesión')
@section('code', '401')
@section('heading', 'Necesitas iniciar sesión')
@section('message', 'Para ver esta página primero tienes que iniciar sesión.')
