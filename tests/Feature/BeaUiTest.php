<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Livewire\Import;
use Nfgarching\Bea\Livewire\Listing;
use Nfgarching\Bea\Livewire\Select;
use Nfgarching\Bea\Livewire\Upload;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\BeaImporter;
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
        'bea.assign.types' => [
            'user' => ['label' => 'Benutzer', 'model' => User::class, 'search' => ['name'], 'display' => 'name'],
        ],
    ]);
    Bus::fake([ProcessFile::class]);
    Event::fake([BeaMessageImported::class, BeaFilesAccepted::class, NewFiles::class]);
    $this->user = User::factory()->create(['name' => 'Erika Beispiel']);
    $this->actingAs($this->user);
});

test('Upload liest eine ZIP ein und bietet die Übernahme an', function () {
    $zip = UploadedFile::fake()->createWithContent('Nachricht_100000001.zip', file_get_contents(beaFixtureZip()));

    Livewire::test(Upload::class)
        ->set('zips', [$zip])
        ->assertHasNoErrors()
        ->call('ingest')
        ->assertHasNoErrors()
        ->assertSee('beA 100000001 eingelesen')
        ->assertSee('Übernehmen');

    expect(BeaMessage::where('bea_id', '100000001')->value('status'))->toBe('ingested')
        ->and(File::count())->toBe(0);
});

test('ZIPs über 12 MB werden nicht schon vom Livewire-Upload abgewiesen', function () {
    $zip = UploadedFile::fake()->create('gross.zip', 13000, 'application/zip');

    Livewire::test(Upload::class)
        ->set('zips', [$zip])
        ->assertHasNoErrors('zips.0');
});

test('der Eingang listet ZIPs der Disk und liest die markierten ein', function () {
    Storage::disk('beas')->put('inbox/nachricht.zip', file_get_contents(beaFixtureZip()));

    Livewire::test(Import::class, ['modelType' => User::class, 'modelId' => $this->user->id])
        ->assertSee('nachricht.zip')
        ->set('selectedFiles', ['inbox/nachricht.zip'])
        ->call('ingest')
        ->assertSee('beA 100000001 eingelesen');

    Storage::disk('beas')->assertMissing('inbox/nachricht.zip');
    // Aufruf aus einem Modell heraus: Zuordnung ist vorbelegt
    expect(BeaMessage::first()->primaryAssignment()->assignable->is($this->user))->toBeTrue();
});

test('die Auswahl ist mit den Vorschlägen vorbelegt und übernimmt mit Zuordnung', function () {
    $message = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];
    $suggested = $message->messageFiles->where('suggested', true)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();

    $component = Livewire::test(Select::class, ['messageId' => $message->id])
        ->assertSee('Ladung zum Termin')
        ->assertSee('signiert')
        ->assertDontSee('vhn.xml');

    expect(collect($component->get('selected'))->sort()->values()->all())->toBe($suggested);

    $component
        ->set('search.user', 'Erika')
        ->assertSee('Erika Beispiel')
        ->call('addAssignment', 'user', $this->user->id)
        ->call('accept')
        ->assertRedirect(route('bea.show', ['message' => $message->id, 'tab' => 'dateien']));

    $message->refresh();
    expect($message->status)->toBe('accepted')
        ->and($message->primaryAssignment()->assignable->is($this->user))->toBeTrue()
        ->and(File::count())->toBe(2);
});

test('Liste und Detailseite zeigen Nachricht, Dateien und Prüfung', function () {
    $message = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];

    $this->get(route('bea.index'))->assertOk()->assertSee('Ladung zum Termin')->assertSee('Übernehmen');
    $this->get(route('bea.index', ['status' => 'accepted']))->assertOk()->assertDontSee('Ladung zum Termin');
    $this->get(route('bea.show', $message))->assertOk()->assertSee('Amtsgericht Musterstadt');
    $this->get(route('bea.show', ['message' => $message, 'tab' => 'dateien']))->assertOk()->assertSee('Ladung.pdf.p7s')->assertSee('vhn.xml');
    $this->get(route('bea.show', ['message' => $message, 'tab' => 'pruefung']))->assertOk()->assertSee('Prüfverlauf')->assertSee('Einlesen');
    $this->get(route('bea.archive', $message))->assertOk();

    $this->post(route('bea.verify', $message))->assertRedirect(route('bea.show', ['message' => $message, 'tab' => 'pruefung']));
    expect($message->verifications()->count())->toBe(2);
});

test('das Listing findet Nachrichten über Zuordnung und über übernommene Dateien', function () {
    $message = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];
    $other = User::factory()->create();

    Livewire::test(Listing::class, ['model_type' => $this->user->getMorphClass(), 'model_id' => $this->user->id])
        ->assertDontSee('Ladung zum Termin');

    app(BeaImporter::class)->accept($message, $message->messageFiles->where('suggested', true)->pluck('id')->all(), [$this->user]);

    Livewire::test(Listing::class, ['model_type' => $this->user->getMorphClass(), 'model_id' => $this->user->id])
        ->assertSee('Ladung zum Termin');
    Livewire::test(Listing::class, ['model_type' => $other->getMorphClass(), 'model_id' => $other->id])
        ->assertDontSee('Ladung zum Termin');
});
