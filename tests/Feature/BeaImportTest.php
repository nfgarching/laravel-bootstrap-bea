<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\BeaImporter;
use Nfgarching\Files\Jobs\ProcessFile;
use Nfgarching\Files\Models\File;

uses(RefreshDatabase::class);

require_once __DIR__.'/../fixture.php';

beforeEach(function () {
    Storage::fake('local');
    config(['files.save_disk' => 'local']);
    // Verarbeitungskette des Files-Packages (OCR, PDF/A …) hier nicht ausführen
    Bus::fake([ProcessFile::class]);
    $this->actingAs(User::factory()->create());
});

test('importiert eine beA-Nachricht und löst das Event mit Aktenzeichen und Daten aus', function () {
    Event::fake([BeaMessageImported::class]);

    $result = app(BeaImporter::class)->import(beaFixtureZip(), 'Nachricht_100000001.zip');

    expect($result['imported'])->toHaveCount(1)->and($result['skipped'])->toBe([]);

    $message = BeaMessage::firstOrFail();
    expect($message->bea_id)->toBe('100000001')
        ->and($message->aktenzeichen)->toBe('26/0001')
        ->and($message->aktenzeichen_absender)->toBe('1 C 123/26')
        ->and($message->sender_name)->toBe('Amtsgericht Musterstadt')
        ->and($message->secure_channel)->toBeTrue()
        ->and($message->eeb_requested)->toBeTrue()
        ->and($message->integrity)->toBe('complete')
        ->and($message->vhn_signature)->toBeIn(['signature_ok', 'notool'])
        ->and($message->zipFile)->not->toBeNull();

    $roles = $message->messageFiles->pluck('role')->sort()->values()->all();
    expect($roles)->toBe(['attachment', 'attachment', 'export', 'signature']);

    $ladung = $message->attachments()->firstWhere('name', 'Ladung zum Termin.pdf');
    expect($ladung)->not->toBeNull()
        ->and($ladung->model_type)->toBe(BeaMessage::class)
        ->and($ladung->collection_name)->toBe('bea')
        ->and($ladung->isOwn())->toBeFalse();
    Storage::disk('local')->assertExists($ladung->file);

    Event::assertDispatched(BeaMessageImported::class, function (BeaMessageImported $event) use ($message) {
        return $event->message->is($message)
            && $event->aktenzeichen === '26/0001'
            && $event->aktenzeichenAbsender === '1 C 123/26'
            && count($event->fileIds) === 3
            && $event->data['xjustiz']['parties'][0]['name'] === 'Erika Musterfrau'
            && $event->data['received_at'] === '2026-11-02T09:16:00+01:00'
            && $event->attachments()->count() === 3;
    });
});

test('eine bereits importierte Nachricht wird übersprungen', function () {
    Event::fake([BeaMessageImported::class]);
    $zip = beaFixtureZip();

    app(BeaImporter::class)->import($zip);
    $second = app(BeaImporter::class)->import($zip);

    expect($second['imported'])->toBe([])
        ->and($second['skipped'])->toBe(['100000001'])
        ->and(BeaMessage::count())->toBe(1)
        ->and(File::where('collection_name', 'bea')->count())->toBe(5); // ZIP, 2 PDFs, p7s, Export
    Event::assertDispatchedTimes(BeaMessageImported::class, 1);
});

test('der Listener kann die Nachricht einer Akte zuordnen', function () {
    Event::listen(BeaMessageImported::class, function (BeaMessageImported $event) {
        $event->message->assignTo(User::first());
    });

    app(BeaImporter::class)->import(beaFixtureZip());

    expect(BeaMessage::first()->isAssigned())->toBeTrue()
        ->and(BeaMessage::first()->assignable->is(User::first()))->toBeTrue();
});

test('bea:import liest den Eingangsordner und löscht importierte ZIPs', function () {
    Event::fake([BeaMessageImported::class]);
    config(['bea.inbox.disk' => 'local', 'bea.inbox.path' => 'bea-inbox']);
    Storage::disk('local')->put('bea-inbox/nachricht.zip', file_get_contents(beaFixtureZip()));
    Storage::disk('local')->put('bea-inbox/kaputt.zip', 'keine zip');

    $this->artisan('bea:import')->assertFailed();

    Storage::disk('local')->assertMissing('bea-inbox/nachricht.zip');
    Storage::disk('local')->assertExists('bea-inbox/failed/kaputt.zip');
    expect(BeaMessage::count())->toBe(1);
});
