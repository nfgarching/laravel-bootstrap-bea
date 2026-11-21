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
use Nfgarching\Bea\Services\BeaIntegrityChecker;
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
    $suggested = $this->message->messageFiles()->where('suggested', true)->count();

    Livewire::test(Pending::class)
        ->assertSet('modelType', 'App\\Models\\User')
        ->assertSet('modelId', 0)
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages')
        ->assertSet('error', null)
        ->assertSet('selectedMessages', []);

    $this->message->refresh();
    expect($this->message->status)->toBe(BeaMessage::STATUS_ACCEPTED)
        ->and($this->message->model->is($this->user))->toBeTrue()
        ->and($this->message->isAssigned())->toBeFalse()
        ->and(File::count())->toBe($suggested);

    Event::assertDispatched(BeaFilesAccepted::class);
});

test('aus einem Modell heraus wird die Nachricht ihm zugeordnet', function () {
    Livewire::withQueryParams(['model_type' => User::class, 'model_id' => $this->user->id])
        ->test(Pending::class)
        ->assertSet('modelId', $this->user->id)
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages');

    expect($this->message->fresh()->model->is($this->user))->toBeTrue();
});

test('Kontext aus data geht über bea.assign.context an das Event', function () {
    Livewire::test(Pending::class)
        ->set('data', ['user_email' => $this->user->email])
        ->set('selectedMessages', [(string) $this->message->id])
        ->call('importMessages');

    Event::assertDispatched(BeaFilesAccepted::class, fn (BeaFilesAccepted $event) => $event->context === [
        ['type' => $this->user->getMorphClass(), 'id' => $this->user->id],
    ]);
});

test('eine Anlage wird zur Vorschau direkt aus der Original-ZIP ausgeliefert', function () {
    $row = $this->message->messageFiles()->where('role', 'attachment')->firstWhere('suggested', true);

    $response = $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(hash('sha256', $response->getContent()))->toBe($row->sha256)
        ->and(str_contains($response->headers->get('Content-Disposition'), 'inline'))->toBeTrue();
});

test('Vorschau, Prüfung und Übernahme lesen die ZIP der lokalen Disk direkt und lassen sie unverändert', function () {
    $archive = $this->message->archive;
    $sha = hash('sha256', Storage::disk('beas')->get($archive->path));
    $row = $this->message->messageFiles()->where('role', 'attachment')->firstWhere('suggested', true);

    $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]))->assertOk();
    app(BeaIntegrityChecker::class)->check($this->message->fresh());
    app(BeaImporter::class)->accept($this->message->fresh(), [$row->id]);

    Storage::disk('beas')->assertExists($archive->path);
    expect(hash('sha256', Storage::disk('beas')->get($archive->path)))->toBe($sha)
        ->and($this->message->fresh()->verifications()->first()->archive_intact)->toBeTrue();
});

test('eine HTML-Anlage wird in der Sandbox angezeigt', function () {
    $this->message->delete();
    $message = app(BeaImporter::class)->ingest(beaFixtureZip([
        'Anlage.html' => '<html><body><script>alert(1)</script><p>Hallo</p></body></html>',
    ]))['imported'][0];
    $row = $message->messageFiles()->firstWhere('name', 'Anlage.html');

    expect($row->isAttachment())->toBeTrue()
        ->and($row->isPreviewable())->toBeTrue();

    $response = $this->get(route('bea.file', ['message' => $message->id, 'messageFile' => $row->id]))
        ->assertOk()
        ->assertSee('Hallo');

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith('sandbox;');
});

test('auch Dateien, die in der ZIP bleiben, lassen sich ansehen', function () {
    $export = $this->message->messageFiles()->first(fn ($row) => str_ends_with($row->name, '_export.html'));
    expect($export->isAttachment())->toBeFalse()
        ->and($export->isPreviewable())->toBeTrue();

    $response = $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $export->id]))->assertOk();
    expect(hash('sha256', $response->getContent()))->toBe($export->sha256)
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith('sandbox;');

    $signature = $this->message->messageFiles()->firstWhere('role', 'signature');
    $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $signature->id]))
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy');
});

test('die Vorschau liefert nur Dateien der eigenen Nachricht', function () {
    $other = $this->message->replicate()->fill(['bea_id' => '100000002', 'data' => ['files' => []]]);
    $other->save();
    $row = $this->message->messageFiles()->firstWhere('role', 'attachment');
    $this->get(route('bea.file', ['message' => $other->id, 'messageFile' => $row->id]))->assertNotFound();
});

test('eine veränderte Anlage wird nicht angezeigt', function () {
    $row = $this->message->messageFiles()->firstWhere('role', 'attachment');
    beaUpdateFile($this->message, $row->name, ['sha256' => str_repeat('0', 64)]);

    $this->get(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]))->assertStatus(409);
});

test('Aufklappen zeigt die Anlagen mit Vorschau-Knopf fürs Modal', function () {
    $row = $this->message->messageFiles()->firstWhere('role', 'attachment');

    Livewire::test(Pending::class)
        ->assertSee('id="beaFileModal"', false)
        ->assertDontSee(route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]), false)
        ->call('toggle', $this->message->id)
        ->assertSet('expanded', $this->message->id)
        ->assertSee('Ladung zum Termin')
        ->assertSee('data-url="'.route('bea.file', ['message' => $this->message->id, 'messageFile' => $row->id]).'"', false)
        ->assertSee('beaFilePreview(event, this)', false)
        ->call('toggle', $this->message->id)
        ->assertSet('expanded', null);
});

test('Aufklappen belegt die Dateiauswahl mit den Vorschlägen vor', function () {
    $suggested = $this->message->suggestedFiles()->map(fn ($row) => (string) $row->id)->values()->all();

    Livewire::test(Pending::class)
        ->call('toggle', $this->message->id)
        ->assertSet('files.'.$this->message->id, $suggested)
        ->assertSee('wire:model.live="files.'.$this->message->id.'"', false)
        ->call('selectNoFiles', $this->message->id)
        ->assertSet('files.'.$this->message->id, [])
        ->call('selectSuggested', $this->message->id)
        ->assertSet('files.'.$this->message->id, $suggested);
});

test('einzeln gewählte Dateien werden übernommen und wählen die Nachricht mit aus', function () {
    $row = $this->message->attachmentFiles()->first(fn ($row) => $row->isSelectable());

    Livewire::test(Pending::class)
        ->call('toggle', $this->message->id)
        ->set('files.'.$this->message->id, [(string) $row->id])
        ->assertSet('selectedMessages', [(string) $this->message->id])
        ->call('importMessages')
        ->assertSet('error', null)
        ->assertSet('files', []);

    expect(File::count())->toBe(1)
        ->and($this->message->fresh()->status)->toBe(BeaMessage::STATUS_ACCEPTED);
});
