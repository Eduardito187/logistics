<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * Excepción dedicada del escenario de demo (TodayScenarioSeeder): señala que falta un prerrequisito
 * para armarlo —un seeder base no corrido, datos ausentes o configuración incompleta—. Tener una clase
 * propia permite distinguir estos fallos del escenario de cualquier otra excepción genérica.
 */
final class TodayScenarioSeederException extends RuntimeException {}
