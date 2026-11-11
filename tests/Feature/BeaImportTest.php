<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaArchive;
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
    config(['files.save_disk' => 'local', 'bea.disk' => 'beas']);
    // Verarbeitungskette des Files-Packages (OCR, PDF/A …) hier nicht ausführen
    Bus::fake([ProcessFile::class]);
    // Listener der Anwendung nicht ausführen (schrieben in die echte Legacy-Datenbank)
    Event::fake([BeaMessageImported::class, BeaFilesAccepted::class, NewFiles::class]);
    $this->actingAs(User::factory()->create());
});

function ingestFixture(): BeaMessage
{
    return app(BeaImporter::class)->ingest(beaFixtureZip(), 'Nachricht_100000001.zip')['imported'][0];
}

test('Einlesen legt die ZIP auf der Disk beas ab und erfasst alle Dateien, aber nichts in files', function () {
    $result = app(BeaImporter::class)->ingest(beaFixtureZip(), 'Nachricht_100000001.zip');

    expect($result['imported'])->toHaveCount(1)->and($result['skipped'])->toBe([]);

    $message = BeaMessage::firstOrFail();
    expect($message->status)->toBe('ingested')
        ->and($message->bea_id)->toBe('100000001')
        ->and($message->aktenzeichen)->toBe('26/0001')
        ->and($message->aktenzeichen_absender)->toBe('1 C 123/26')
        ->and($message->sender_name)->toBe('Amtsgericht Musterstadt')
        ->and($message->integrity)->toBe('complete')
        ->and($message->vhn_signature)->toBeIn(['signature_ok', 'notool'])
        ->and(File::count())->toBe(0);

    $archive = $message->archive;
    expect($archive)->not->toBeNull()
        ->and($archive->sha256)->toBe(hash('sha256', Storage::disk('beas')->get($archive->path)))
        ->and($archive->path)->toStartWith('archive/'.date('Y').'/');

    $rows = $message->messageFiles->keyBy('name');
    expect($rows)->toHaveCount(8) // 2 Anhänge, 1 Signatur, 5 technische Dateien
        ->and($rows['Ladung.pdf']->role)->toBe('attachment')
        ->and($rows['Ladung.pdf']->category)->toBe('verfuegung')
        ->and($rows['Ladung.pdf']->suggested)->toBeTrue()
        ->and($rows['Ladung.pdf']->signed)->toBeTrue()
        ->and($rows['Ladung.pdf']->signature_name)->toBe('Ladung.pdf.p7s')
        ->and($rows['Klageerwiderung.pdf']->category)->toBe('schriftsatz')
        ->and($rows['Klageerwiderung.pdf']->signed)->toBeFalse()
        ->and($rows['Ladung.pdf.p7s']->role)->toBe('signature')
        ->and($rows['Ladung.pdf.p7s']->suggested)->toBeFalse()
        ->and($rows['vhn.xml']->role)->toBe('meta')
        ->and($rows['xjustiz_nachricht.xml']->vhn_covered)->toBeTrue()
        ->and($message->verifications)->toHaveCount(1)
        ->and($message->verifications->first()->trigger)->toBe('ingest');

    Event::assertDispatched(BeaMessageImported::class, fn (BeaMessageImported $event) => $event->message->is($message)
        && $event->aktenzeichen === '26/0001'
        && $event->aktenzeichenAbsender === '1 C 123/26'
        && $event->data['xjustiz']['parties'][0]['name'] === 'Erika Musterfrau');
    Event::assertNotDispatched(NewFiles::class);
});

test('eine bereits eingelesene Nachricht wird übersprungen und die ZIP nicht doppelt abgelegt', function () {
    $zip = beaFixtureZip();

    app(BeaImporter::class)->ingest($zip);
    $second = app(BeaImporter::class)->ingest($zip);

    expect($second['imported'])->toBe([])
        ->and($second['skipped'])->toBe(['100000001'])
        ->and($second['archive'])->toBeNull()
        ->and(BeaMessage::count())->toBe(1)
        ->and(BeaArchive::count())->toBe(1);
    Event::assertDispatchedTimes(BeaMessageImported::class, 1);
});

test('Übernahme übergibt nur die gewählten Dateien an die Hauptzuordnung', function () {
    $message = ingestFixture();
    $akte = User::factory()->create();
    $name = User::factory()->create();
    $ladung = $message->messageFiles->firstWhere('name', 'Ladung.pdf');
    $signature = $message->messageFiles->firstWhere('name', 'Ladung.pdf.p7s');

    // Die Signaturdatei wird auch dann nicht übernommen, wenn ihre ID mitkommt
    $result = app(BeaImporter::class)->accept($message, [$ladung->id, $signature->id], [$akte, $name]);

    expect($result['files'])->toHaveCount(1)->and($result['failed'])->toBe([]);

    $file = $result['files'][0]->fresh();
    expect($file->name)->toBe('Ladung zum Termin.pdf')
        ->and($file->collection_name)->toBe('bea')
        ->and($file->model_type)->toBe($akte->getMorphClass())
        ->and((int) $file->model_id)->toBe($akte->id)
        ->and($file->isOriginalDocument())->toBeTrue()
        ->and($file->isLocked())->toBeTrue()
        ->and($file->custom_properties['bea']['signed'])->toBeTrue()
        ->and($file->custom_properties['bea']['signature'])->toBe('Ladung.pdf.p7s')
        ->and(File::count())->toBe(1);
    Storage::disk('local')->assertExists($file->file);

    $message->refresh();
    expect($message->status)->toBe('accepted')
        ->and($message->assignments)->toHaveCount(2)
        ->and($message->primaryAssignment()->assignable->is($akte))->toBeTrue()
        ->and($ladung->fresh()->isAccepted())->toBeTrue()
        ->and($signature->fresh()->file_id)->toBeNull()
        ->and($message->messageFiles->firstWhere('name', 'Klageerwiderung.pdf')->isAccepted())->toBeFalse();

    Event::assertDispatched(NewFiles::class, fn (NewFiles $event) => $event->fileIds === [$file->id]
        && $event->model_type === $akte->getMorphClass()
        && $event->model_id === $akte->id
        && $event->subject === 'Ladung zum Termin'
        && $event->data['bea_id'] === '100000001');
    Event::assertDispatched(BeaFilesAccepted::class, fn (BeaFilesAccepted $event) => $event->fileIds === [$file->id]
        && count($event->assignments) === 2);
});

test('ohne Zuordnung bleiben die Dateien bei der Nachricht und werden mit der ersten Zuordnung nachgereicht', function () {
    $message = ingestFixture();
    $erwiderung = $message->messageFiles->firstWhere('name', 'Klageerwiderung.pdf');

    $file = app(BeaImporter::class)->accept($message, [$erwiderung->id])['files'][0];

    expect($file->fresh()->model_type)->toBe($message->getMorphClass())
        ->and($file->fresh()->isLocked())->toBeFalse();
    Event::assertNotDispatched(NewFiles::class);

    $akte = User::factory()->create();
    app(BeaImporter::class)->accept($message->fresh(), [], [$akte]);

    expect($file->fresh()->model_type)->toBe($akte->getMorphClass());
    Event::assertDispatched(NewFiles::class, fn (NewFiles $event) => $event->fileIds === [$file->id]);
});

test('Übernahme bricht ab, wenn die Original-ZIP verändert wurde', function () {
    $message = ingestFixture();
    Storage::disk('beas')->put($message->archive->path, 'manipuliert');

    app(BeaImporter::class)->accept($message, $message->messageFiles->where('role', 'attachment')->pluck('id')->all());
})->throws(InvalidBeaArchive::class);

test('die erneute Prüfung hält ZIP, Dateien und Herkunftsnachweis im Verlauf fest', function () {
    $message = ingestFixture();

    $ok = app(BeaIntegrityChecker::class)->check($message);

    expect($ok->archive_intact)->toBeTrue()
        ->and($ok->files_intact)->toBeTrue()
        ->and($ok->integrity)->toBe('complete')
        ->and($ok->result['files'])->toHaveCount(8);

    Storage::disk('beas')->put($message->archive->path, 'manipuliert');
    $broken = app(BeaIntegrityChecker::class)->check($message->fresh());

    expect($broken->archive_intact)->toBeFalse()
        ->and($broken->passed())->toBeFalse()
        ->and($message->verifications()->count())->toBe(3);
});

test('bea:import liest den Eingang der Disk ein, ohne Dateien zu übernehmen', function () {
    Storage::disk('beas')->put('inbox/nachricht.zip', file_get_contents(beaFixtureZip()));
    Storage::disk('beas')->put('inbox/kaputt.zip', 'keine zip');

    $this->artisan('bea:import')->assertFailed();

    Storage::disk('beas')->assertMissing('inbox/nachricht.zip');
    Storage::disk('beas')->assertMissing('inbox/kaputt.zip');
    expect(Storage::disk('beas')->files('failed'))->toHaveCount(1)
        ->and(BeaMessage::count())->toBe(1)
        ->and(BeaMessage::first()->status)->toBe('ingested')
        ->and(BeaMessage::first()->archive->source)->toBe('inbox')
        ->and(File::count())->toBe(0);
});
