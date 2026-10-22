{{-- Error HTTP 403. Hereda el marco de errors.layout: no depende de @vite ni de Vue, CSS embebido,
     consciente de sesión y sin filtrar detalles internos (sin mensaje de excepción ni stack). --}}
@extends('errors.layout')

@section('title', 'Sin acceso')
@section('code', '403')
@section('heading', 'No tienes acceso a esta página')
@section('message', 'Tu cuenta no tiene permiso para ver esto. Si crees que deberías tenerlo, contacta a quien te dio el acceso.')
