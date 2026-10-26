{{-- Error HTTP 422. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Datos incorrectos')
@section('code', '422')
@section('heading', 'Revisa los datos ingresados')
@section('message', 'Algunos datos no son correctos o faltan. Revísalos e intenta de nuevo.')
