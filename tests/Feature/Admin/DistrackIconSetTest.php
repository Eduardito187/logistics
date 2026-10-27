<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Support\Icons\DistrackIconSet;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentIcon;

// Set de íconos propio DisTrack (docs/filament-iconos-custom-animados.md): cada ícono del panel es un SVG
// `ds-*` de resources/svg/distrack/ con su grupo animable y su acento, incluidos los internos de Filament.

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('maps every Filament icon alias to an existing DisTrack icon', function (): void {
    $aliases = DistrackIconSet::filamentAliases();

    expect($aliases)->not->toBeEmpty();

    foreach ($aliases as $alias => $icon) {
        expect($icon)->toStartWith('ds-')
            ->and(resource_path('svg/distrack/'.substr($icon, 3).'.svg'))->toBeFile()
            ->and(FilamentIcon::resolve($alias))->toBe($icon);
    }
});

it('keeps every DisTrack SVG animatable and self-hosted', function (): void {
    $files = glob(resource_path('svg/distrack/*.svg')) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $svg = (string) file_get_contents($file);
        $withoutNamespace = str_replace('http://www.w3.org/2000/svg', '', $svg);

        expect($svg)->toContain('viewBox="0 0 24 24"')
            ->toContain('stroke="currentColor"')
            ->toMatch('/class="ds-i ds-m-(spin|pulse|bounce|pop|wiggle|nudge-r|nudge-l|nudge-u|nudge-d|swing|blink|draw)"/')
            ->and(preg_match_all('/class="[^"]*\bds-accent\b/', $svg))->toBe(1, basename($file))
            ->and($withoutNamespace)->not->toContain('http');
    }
});

it('renders the DisTrack version of the icons Filament draws without an alias', function (): void {
    // Selector de tema, DateTimePicker y RichEditor usan Heroicons fijos: los sobreescribe config/blade-heroicons.php.
    foreach (['o-sun', 's-moon', 'm-computer-desktop', 'c-calendar', 'm-magnifying-glass'] as $name) {
        expect(svg('heroicon-'.$name)->toHtml())->toContain('ds-i');
    }

    // Lo que no tiene versión propia sigue cayendo al Heroicon original (sin romper).
    expect(svg('heroicon-o-academic-cap')->toHtml())->not->toContain('ds-i');
});

it('draws the admin panel with DisTrack icons', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);

    $html = $this->actingAs($admin)->get(Filament::getCurrentPanel()?->getUrl() ?? '/admin')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('ds-i ds-m-')
        ->toContain('ds-accent');
});
