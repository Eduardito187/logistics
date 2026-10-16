{{-- Error HTTP 402. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Pago requerido')
@section('code', '402')
@section('heading', 'Se requiere un pago')
@section('message', 'Este recurso no está disponible en este momento. Si crees que es un error, contacta al equipo.')
