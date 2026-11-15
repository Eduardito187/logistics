<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Planning\Application\Command\DeletePlan\DeletePlan;
use Logistics\Planning\Application\Command\RemoveStopFromPlan\RemoveStopFromPlan;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Presentation\Broadcasting\PlansUpdated;
use Logistics\Planning\Presentation\Filament\PlanResource\Pages\ListPlans;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Borrar un plan de reparto: solo sin publicar (borrador o propuesta). Se verifica el caso de uso, el
// contrato HTTP (204 / 404 / 409), el evento de dominio en el outbox, que los pedidos quedan igual que al
// quitar una parada, el aviso `plans.updated` con `deleted: true` y la acción del admin.

const PLAN_DELETION_DATE = '2031-04-09';

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->withoutMiddleware();
});

/** Pedido recibido para el día del plan, con origen y destino dados. */
function pdShipment(string $reference, string $origin = 'la_paz', string $destination = 'la_paz'): ShipmentRecord
{
    return ShipmentRecordFactory::new()->create([
        'external_id' => $reference,
        'status' => 'RECEIVED',
        'delivery_date' => PLAN_DELETION_DATE,
        'origin_department' => $origin,
        'destination_department' => $destination,
    ]);
}

/**
 * Plan propuesto con una parada por cada pedido dado, en ese orden.
 *
 * @param list<ShipmentRecord> $shipments
 * @param array<string, mixed> $attributes
 */
function pdProposedPlan(array $shipments, array $attributes = []): PlanRecord
{
    $plan = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->proposed()->create($attributes);

    $sequence = 1;
    foreach ($shipments as $shipment) {
        PlannedStopRecordFactory::new()->scheduled($sequence)->create([
            'plan_id' => $plan->getKey(),
            'shipment_id' => $shipment->getKey(),
        ]);
        $sequence++;
    }

    return $plan;
}

/**
 * Cargas de los eventos `planning.plan_deleted` del outbox para ese plan.
 *
 * @return list<array<string, mixed>>
 */
function pdDeletedEvents(string $planId): array
{
    $payloads = [];
    foreach (DB::table('outbox_messages')->where('event_name', 'planning.plan_deleted')->get() as $row) {
        $payload = is_string($row->payload) ? json_decode($row->payload, true) : (array) $row->payload;
        if (is_array($payload) && ($payload['plan_id'] ?? null) === $planId) {
            /** @var array<string, mixed> $payload */
            $payloads[] = $payload;
        }
    }

    return $payloads;
}

/**
 * Avisos `plans.updated` capturados hasta ahora, en orden.
 *
 * @return list<PlansUpdated>
 */
function pdPlanEvents(): array
{
    $events = [];
    foreach (Event::dispatched(PlansUpdated::class) as $arguments) {
        /** @var PlansUpdated $event */
        $event = $arguments[0];
        $events[] = $event;
    }

    return $events;
}

/** Activa un transporte de tiempo real y captura el aviso (sin encolar ni difundir nada). */
function pdRealtimeOn(): void
{
    config(['broadcasting.default' => 'pusher']);
    Event::fake([PlansUpdated::class]);
}

// ──────────────────────────── Caso de uso ────────────────────────────

it('DeletePlan borra el plan propuesto con sus paradas y deja el evento en el outbox', function (): void {
    $first = pdShipment('SO-DEL-1');
    $second = pdShipment('SO-DEL-2');
    $plan = pdProposedPlan([$first, $second]);
    $planId = (string) $plan->getKey();
    // Otro plan del mismo día: no se toca.
    $other = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->proposed()->withStops(2)->create();

    app(CommandBus::class)->dispatch(new DeletePlan($planId));

    $events = pdDeletedEvents($planId);

    expect(PlanRecord::query()->whereKey($planId)->exists())->toBeFalse()
        ->and(PlannedStopRecord::query()->where('plan_id', $planId)->count())->toBe(0)
        ->and(PlanRecord::query()->whereKey($other->getKey())->exists())->toBeTrue()
        ->and(PlannedStopRecord::query()->where('plan_id', $other->getKey())->count())->toBe(2)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['plan_date'])->toBe(PLAN_DELETION_DATE)
        ->and($events[0]['vehicle_id'])->toBe((string) $plan->vehicle_id)
        ->and($events[0]['shipment_ids'])->toBe([(string) $first->getKey(), (string) $second->getKey()])
        ->and($events[0]['stop_count'])->toBe(2);
});

it('los pedidos de un plan borrado quedan igual que al quitar una parada: mismo estado y sin plan', function (): void {
    $removed = pdShipment('SO-REMOVED');
    $kept = pdShipment('SO-KEPT');
    $deleted = pdShipment('SO-DELETED');
    $planWithRemoval = pdProposedPlan([$removed, $kept]);
    $planToDelete = pdProposedPlan([$deleted]);
    $bus = app(CommandBus::class);

    $bus->dispatch(new RemoveStopFromPlan((string) $planWithRemoval->getKey(), (string) $removed->getKey()));
    $bus->dispatch(new DeletePlan((string) $planToDelete->getKey()));

    $statusAfterRemoval = ShipmentRecord::query()->whereKey($removed->getKey())->value('status');
    $statusAfterDeletion = ShipmentRecord::query()->whereKey($deleted->getKey())->value('status');

    expect($statusAfterDeletion)->toBe($statusAfterRemoval)
        ->and($statusAfterDeletion)->toBe('RECEIVED')
        ->and(PlannedStopRecord::query()->where('shipment_id', $removed->getKey())->exists())->toBeFalse()
        ->and(PlannedStopRecord::query()->where('shipment_id', $deleted->getKey())->exists())->toBeFalse()
        // El pedido liberado puede volver a planificarse en otro viaje.
        ->and(ShipmentRecord::query()->whereKey($deleted->getKey())->exists())->toBeTrue();
});

it('DeletePlan rechaza un plan publicado y no borra nada ni deja evento', function (): void {
    $plan = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->published()->withStops(2)->create();
    $planId = (string) $plan->getKey();

    expect(fn () => app(CommandBus::class)->dispatch(new DeletePlan($planId)))
        ->toThrow(PlanningException::class, 'ya publicado');

    expect(PlanRecord::query()->whereKey($planId)->value('status'))->toBe('PUBLISHED')
        ->and(PlannedStopRecord::query()->where('plan_id', $planId)->count())->toBe(2)
        ->and(pdDeletedEvents($planId))->toBe([]);
});

it('DeletePlan sobre un plan que no existe falla con el motivo', function (): void {
    expect(fn () => app(CommandBus::class)->dispatch(new DeletePlan((string) Str::uuid7())))
        ->toThrow(PlanningException::class, 'El plan indicado no existe.');
});

it('DeletePlan también borra un borrador sin paradas', function (): void {
    $plan = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->create();
    $planId = (string) $plan->getKey();

    app(CommandBus::class)->dispatch(new DeletePlan($planId));

    $events = pdDeletedEvents($planId);

    expect(PlanRecord::query()->whereKey($planId)->exists())->toBeFalse()
        ->and($events)->toHaveCount(1)
        ->and($events[0]['shipment_ids'])->toBe([]);
});

// ──────────────────────────── HTTP ────────────────────────────

it('DELETE /plans/{id} responde 204 sin cuerpo y el plan ya no existe; repetirlo responde 404', function (): void {
    $plan = pdProposedPlan([pdShipment('SO-HTTP-1'), pdShipment('SO-HTTP-2')]);
    $planId = (string) $plan->getKey();

    $response = $this->deleteJson("/api/v1/app/plans/{$planId}")->assertStatus(204);

    expect($response->getContent())->toBe('')
        ->and(PlanRecord::query()->whereKey($planId)->exists())->toBeFalse()
        ->and(PlannedStopRecord::query()->where('plan_id', $planId)->count())->toBe(0)
        ->and(pdDeletedEvents($planId))->toHaveCount(1);

    $this->getJson("/api/v1/app/plans/{$planId}")->assertStatus(404);

    $this->deleteJson("/api/v1/app/plans/{$planId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    // El segundo intento no deja otro evento.
    expect(pdDeletedEvents($planId))->toHaveCount(1);
})->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.destroy'), 'la ruta se registra al integrar routes/api/planning.php');

it('DELETE /plans/{id} de un plan publicado responde 409 y no lo borra', function (): void {
    $plan = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->published()->withStops(2)->create();
    $planId = (string) $plan->getKey();

    $this->deleteJson("/api/v1/app/plans/{$planId}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conflict');

    expect(PlanRecord::query()->whereKey($planId)->exists())->toBeTrue()
        ->and(PlannedStopRecord::query()->where('plan_id', $planId)->count())->toBe(2)
        ->and(pdDeletedEvents($planId))->toBe([]);
})->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.destroy'), 'la ruta se registra al integrar routes/api/planning.php');

it('DELETE /plans/{id} de un plan inexistente responde 404 con el motivo', function (): void {
    $response = $this->deleteJson('/api/v1/app/plans/'.Str::uuid7())
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    expect($response->json('detail'))->toBe('El plan indicado no existe.');
})->skip(fn (): bool => !Route::has('api.v1.app.planning.plans.destroy'), 'la ruta se registra al integrar routes/api/planning.php');

// ──────────────────────────── Aviso en tiempo real ────────────────────────────

it('plans.updated lleva deleted true cuando se construye para un plan borrado', function (): void {
    $event = new PlansUpdated('2026-12-01', '01a13700-3333-7333-8333-333333333333', ['la_paz'], true);

    expect($event->broadcastWith())->toBe([
        'plan_date' => '2026-12-01',
        'plan_id' => '01a13700-3333-7333-8333-333333333333',
        'deleted' => true,
    ]);
});

it('borrar un plan avisa una vez con deleted true a las ciudades que tenía antes de borrarse', function (): void {
    pdRealtimeOn();
    $node = NodeFactory::new()->distributionCenter()->inDepartment(Department::SantaCruz)->create(['lat' => -17.78, 'lng' => -63.18]);
    $vehicleId = (string) VehicleFactory::new()->homeBase((string) $node->getKey())->create()->getKey();
    $plan = pdProposedPlan([pdShipment('SO-RT-1', 'cochabamba', 'oruro')], ['vehicle_id' => $vehicleId]);
    $planId = (string) $plan->getKey();

    app(CommandBus::class)->dispatch(new DeletePlan($planId));

    $events = pdPlanEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['plan_date' => PLAN_DELETION_DATE, 'plan_id' => $planId, 'deleted' => true]);

    $channels = array_map(static fn (PrivateChannel $channel): string => $channel->name, $events[0]->broadcastOn());
    sort($channels);

    expect($channels)->toBe([
        'private-planning.all',
        'private-planning.city.cochabamba',
        'private-planning.city.oruro',
        'private-planning.city.santa_cruz',
    ]);
});

it('si la transacción del borrado se revierte no sale ningún aviso y el plan sigue', function (): void {
    pdRealtimeOn();
    $plan = pdProposedPlan([pdShipment('SO-RT-2')]);
    $planId = (string) $plan->getKey();

    try {
        DB::transaction(function () use ($planId): void {
            app(CommandBus::class)->dispatch(new DeletePlan($planId));

            throw new RuntimeException('falla posterior al borrado');
        });
    } catch (RuntimeException) {
        // Esperado: la transacción se revierte.
    }

    Event::assertNotDispatched(PlansUpdated::class);

    expect(PlanRecord::query()->whereKey($planId)->exists())->toBeTrue()
        ->and(PlannedStopRecord::query()->where('plan_id', $planId)->count())->toBe(1)
        ->and(pdDeletedEvents($planId))->toBe([]);
});

it('borrar un plan publicado no avisa', function (): void {
    pdRealtimeOn();
    $plan = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->published()->withStops(1)->create();

    try {
        app(CommandBus::class)->dispatch(new DeletePlan((string) $plan->getKey()));
    } catch (PlanningException) {
        // Esperado: un plan publicado no se borra.
    }

    Event::assertNotDispatched(PlansUpdated::class);
});

it('sin transporte de tiempo real el borrado funciona y no emite nada', function (): void {
    config(['broadcasting.default' => 'null']);
    Event::fake([PlansUpdated::class]);
    $plan = pdProposedPlan([pdShipment('SO-RT-3')]);

    app(CommandBus::class)->dispatch(new DeletePlan((string) $plan->getKey()));

    Event::assertNotDispatched(PlansUpdated::class);
    expect(PlanRecord::query()->whereKey($plan->getKey())->exists())->toBeFalse();
});

// ──────────────────────────── Admin ────────────────────────────

it('la acción Delete plan del admin borra un plan sin publicar y no se ofrece en uno publicado', function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);

    $proposed = pdProposedPlan([pdShipment('SO-ADMIN-1')]);
    $published = PlanRecordFactory::new()->onDate(PLAN_DELETION_DATE)->published()->withStops(1)->create();

    Livewire::test(ListPlans::class)
        ->assertTableActionVisible('deletePlan', $proposed)
        ->assertTableActionHidden('deletePlan', $published)
        ->callTableAction('deletePlan', $proposed)
        ->assertOk();

    expect(PlanRecord::query()->whereKey($proposed->getKey())->exists())->toBeFalse()
        ->and(PlanRecord::query()->whereKey($published->getKey())->exists())->toBeTrue();
});

it('el planificador no ve la acción Delete plan', function (): void {
    Filament::setCurrentPanel('admin');
    $planner = User::factory()->create();
    $planner->assignRole(Role::Planificador);
    $this->actingAs($planner);

    $proposed = pdProposedPlan([pdShipment('SO-ADMIN-2')]);

    Livewire::test(ListPlans::class)
        ->assertTableActionHidden('deletePlan', $proposed);

    expect(PlanRecord::query()->whereKey($proposed->getKey())->exists())->toBeTrue();
});
