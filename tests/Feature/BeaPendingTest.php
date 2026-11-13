<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Livewire\Pending;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\BeaImporter;
use Nfgarching\Core\Modals\ModalRegistry;
use Nfgarching\Files\Events\NewFiles;
use Nfgarching\Files\Jobs\ProcessFile;
use Nfgarching\Files\Models\File;

uses(RefreshDatabase::class);

require_once __DIR__.'/../fixture.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('beas');
    config([
        'files.save_disk' => 'local',
        'bea.disk' => 'beas',
        'bea.inbox.disk' => null,
        'bea.inbox.path' => 'inbox',
        'bea.archive.path' => 'archive',
        'bea.failed.path' => 'failed',
        'bea.assign.types' => [
            'user' => ['label' => 'Benutzer', 'model' => User::class, 'search' => ['name'], 'display' => 'name'],
        ],
        'bea.assign.context' => [
            'user_email' => ['type' => 'user', 'column' => 'email'],
        ],
    ]);
    Bus::fake([ProcessFile::class]);
    Event::fake([BeaMessageImported::class, BeaFilesAccepted::class, NewFiles::class]);
    $this->user = User::factory()->create(['name' => 'Erika Beispiel']);
    $this->actingAs($this->user);
    $this->message = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];
});

test('das Popup listet nur nicht übernommene Nachrichten', function () {
    $this->get(route('bea.pending'))
        ->assertOk()
        ->assertSee('nicht übernommene Nachrichten')
        ->assertSee($this->message->subject);

    $this->message->update(['status' => BeaMessage::STATUS_ACCEPTED]);

    Livewire::test(Pending::class)
        ->assertSee('Keine nicht übernommenen Nachrichten');
});

test('Standard User: übernimmt die vorgeschlagenen Dateien ohne Zuordnung', function () {
    $suggested = $this->message->messageFiles->where('suggested', true)->count();

    Livewire::test(Pending::class)
        ->assertSet('modelType', 'App\\Models\\User')
        ->assertSet('modelId', 0)
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages')
        ->assertSet('error', null)
        ->assertSet('selectedMessages', []);

    $this->message->refresh();
    expect($this->message->status)->toBe(BeaMessage::STATUS_ACCEPTED)
        ->and($this->message->assignments)->toHaveCount(0)
        ->and(File::count())->toBe($suggested);

    Event::assertDispatched(BeaFilesAccepted::class);
});

test('aus einem Modell heraus wird die Nachricht ihm zugeordnet', function () {
    Livewire::withQueryParams(['model_type' => User::class, 'model_id' => $this->user->id])
        ->test(Pending::class)
        ->assertSet('modelId', $this->user->id)
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages');

    expect($this->message->fresh()->primaryAssignment()->assignable->is($this->user))->toBeTrue();
});

test('Kontext aus data wird über bea.assign.context zugeordnet', function () {
    Livewire::test(Pending::class)
        ->set('data', ['user_email' => $this->user->email])
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages');

    expect($this->message->fresh()->primaryAssignment()->assignable->is($this->user))->toBeTrue();
});

test('das Modal fragt erst nach dem Öffnen ab und übernimmt den Kontext aus dem Event', function () {
    Livewire::test(Pending::class, ['modal' => true])
        ->assertSet('open', false)
        ->assertDontSee($this->message->subject)
        ->dispatch('openModalBeaPending', data: ['model_type' => User::class, 'model_id' => (string) $this->user->id, 'data' => []])
        ->assertSet('open', true)
        ->assertSet('modelType', User::class)
        ->assertSet('modelId', $this->user->id)
        ->assertDispatched('show-modal', modalName: Pending::MODAL_NAME)
        ->assertSee($this->message->subject)
        ->assertSee('Erika Beispiel')
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages')
        ->assertDispatched('hide-modal', modalName: Pending::MODAL_NAME);

    expect($this->message->fresh()->status)->toBe(BeaMessage::STATUS_ACCEPTED);
});

test('die Modal-Registrierung bindet die Komponente ein', function () {
    expect(app(ModalRegistry::class)->visibleModals())->toContain('bea::parts.modals');
});

test('eine Anlage wird zur Vorschau direkt aus der Original-ZIP ausgeliefert', function () {
    $row = $this->message->messageFiles->where('role', 'attachment')->firstWhere('suggested', true);

    $response = $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(hash('sha256', $response->getContent()))->toBe($row->sha256)
        ->and(str_contains($response->headers->get('Content-Disposition'), 'inline'))->toBeTrue();
});

test('die Vorschau liefert nur Anlagen der eigenen Nachricht', function () {
    $meta = $this->message->messageFiles->firstWhere('role', 'meta');
    $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $meta->id]))->assertNotFound();

    $other = $this->message->replicate()->fill(['bea_id' => '100000002']);
    $other->save();
    $row = $this->message->messageFiles->firstWhere('role', 'attachment');
    $this->get(route('bea.file', ['message' => $other->id, 'messageFile' => $row->id]))->assertNotFound();
});

test('eine veränderte Anlage wird nicht angezeigt', function () {
    $row = $this->message->messageFiles->firstWhere('role', 'attachment');
    $row->update(['sha256' => str_repeat('0', 64)]);

    $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]))->assertStatus(409);
});

test('Aufklappen, Vorschau wählen und schließen', function () {
    $attachments = $this->message->messageFiles->where('role', 'attachment');
    $other = $attachments->firstWhere('suggested', false) ?? $attachments->last();

    $component = Livewire::test(Pending::class)
        ->call('toggle', $this->message->id)
        ->assertSet('expanded', $this->message->id)
        ->assertSee('Ladung zum Termin');

    expect($component->get('previewFile'))->not->toBeNull();
    $component->assertSee(route('bea.file', ['message' => $this->message->id, 'messageFile' => $component->get('previewFile')]), false);

    $component->call('preview', $other->id)
        ->assertSet('previewFile', $other->id)
        ->call('preview', 999999)
        ->assertSet('previewFile', null)
        ->call('toggle', $this->message->id)
        ->assertSet('expanded', null);
});
