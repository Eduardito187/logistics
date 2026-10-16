<?php

declare(strict_types=1);

/*
 * Fragmento del portal del seller (autoservicio, ADR 017 Fase 5). El mapa puerta=>closure real lo define
 * el propio package (controllers + middleware de token/permiso de access). Aquí solo se re-exporta para
 * mantener la convención de `routes/api.php` (un fragmento por contexto).
 */
return require base_path('packages/logistics/sellerportal/routes/api.php');
