<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\BeaImporter;
use Nfgarching\Bea\Services\BeaIntegrityChecker;
use Nfgarching\Bea\Services\BeaStorage;
use Nfgarching\Files\Events\NewFiles;
use Nfgarching\Files\Jobs\ProcessFile;
use Nfgarching\Files\Models\File;

uses(RefreshDatabase::class);

require_once __DIR__.'/../fixture.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('beas');
    config(['files.save_disk' => 'local', 'bea.disk' => 'beas', 'bea.inbox.disk' => null, 'bea.inbox.path' => 'inbox', 'bea.archive.path' => 'archive', 'bea.archive.year' => true, 'bea.archive.prefix' => '', 'bea.archive.suffix' => '', 'bea.archive.postfix' => '', 'bea.failed.disk' => null, 'bea.failed.path' => 'failed']);
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
        ->and($archive->path)->toStartWith(date('Y').'/archive/'.date('Y-m').'/');

    $rows = $message->messageFiles()->keyBy('name');
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
        ->and(Storage::disk('beas')->allFiles(date('Y').'/archive'))->toHaveCount(1);
    Event::assertDispatchedTimes(BeaMessageImported::class, 1);
});

test('Übernahme legt nur die gewählten Dateien bei der Nachricht ab, Akte und Namen als Kontext', function () {
    $message = ingestFixture();
    // Stellvertreter für ein Modell der Anwendung (z. B. ein Dokument)
    $target = $message->latestVerification;
    $akte = User::factory()->create();
    $name = User::factory()->create();
    $ladung = $message->messageFiles()->firstWhere('name', 'Ladung.pdf');
    $signature = $message->messageFiles()->firstWhere('name', 'Ladung.pdf.p7s');

    // Die Signaturdatei wird auch dann nicht übernommen, wenn ihre ID mitkommt
    $result = app(BeaImporter::class)->accept($message, [$ladung->id, $signature->id], [$akte, $name], target: $target);

    expect($result['files'])->toHaveCount(1)->and($result['failed'])->toBe([]);

    $file = $result['files'][0]->fresh();
    expect($file->name)->toBe('Ladung zum Termin.pdf')
        ->and($file->collection_name)->toBe('bea')
        ->and($file->model_type)->toBe($message->getMorphClass())
        ->and((int) $file->model_id)->toBe($message->id)
        ->and($file->isOriginalDocument())->toBeTrue()
        ->and($file->isLocked())->toBeTrue()
        ->and($file->custom_properties['bea']['signed'])->toBeTrue()
        ->and($file->custom_properties['bea']['signature'])->toBe('Ladung.pdf.p7s')
        ->and(File::count())->toBe(1);
    Storage::disk('local')->assertExists($file->file);

    $message->refresh();
    expect($message->status)->toBe('accepted')
        ->and($message->model->is($target))->toBeTrue()
        ->and($message->messageFiles()->firstWhere('name', 'Ladung.pdf')->fileId())->toBe($file->id)
        ->and($message->messageFiles()->firstWhere('name', 'Ladung.pdf.p7s')->isAccepted())->toBeFalse()
        ->and($message->messageFiles()->firstWhere('name', 'Klageerwiderung.pdf')->isAccepted())->toBeFalse()
        ->and($file->custom_properties['bea']['zip_path'])->toBe($ladung->zip_path);

    Event::assertNotDispatched(NewFiles::class);
    Event::assertDispatched(BeaFilesAccepted::class, fn (BeaFilesAccepted $event) => $event->fileIds === [$file->id]
        && $event->subject === 'Ladung zum Termin'
        && $event->context === [
            ['type' => $akte->getMorphClass(), 'id' => $akte->id],
            ['type' => $name->getMorphClass(), 'id' => $name->id],
        ]);
});

test('die Files bleiben auch nach einer Zuordnung bei der Nachricht', function () {
    $message = ingestFixture();
    $erwiderung = $message->messageFiles()->firstWhere('name', 'Klageerwiderung.pdf');

    $file = app(BeaImporter::class)->accept($message, [$erwiderung->id])['files'][0];

    expect($file->fresh()->model_type)->toBe($message->getMorphClass())
        ->and($file->fresh()->isLocked())->toBeFalse();

    $target = $message->latestVerification;
    app(BeaImporter::class)->accept($message->fresh(), [], target: $target);

    expect($message->fresh()->model->is($target))->toBeTrue()
        ->and($file->fresh()->model_type)->toBe($message->getMorphClass())
        ->and((int) $file->fresh()->model_id)->toBe($message->id);
    Event::assertNotDispatched(NewFiles::class);
});

test('Übernahme bricht ab, wenn die Original-ZIP verändert wurde', function () {
    $message = ingestFixture();
    Storage::disk('beas')->put($message->archive->path, 'manipuliert');

    app(BeaImporter::class)->accept($message, $message->messageFiles()->where('role', 'attachment')->pluck('id')->all());
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

test('bea:import liest einen Eingang auf eigener Disk ein; Archiv und failed bleiben auf beas', function () {
    Storage::fake('scans');
    config(['bea.inbox.disk' => 'scans', 'bea.inbox.path' => 'beA_Import']);
    Storage::disk('scans')->put('beA_Import/nachricht.zip', file_get_contents(beaFixtureZip()));
    Storage::disk('scans')->put('beA_Import/kaputt.zip', 'keine zip');

    $this->artisan('bea:import')->assertFailed();

    Storage::disk('scans')->assertMissing('beA_Import/nachricht.zip');
    Storage::disk('scans')->assertMissing('beA_Import/kaputt.zip');
    expect(Storage::disk('beas')->files('failed'))->toHaveCount(1)
        ->and(BeaMessage::count())->toBe(1)
        ->and(Storage::disk('beas')->exists(BeaMessage::first()->archive->path))->toBeTrue();
});

test('Ordner der Originale wie im PathGenerator, Jahresebene abschaltbar', function () {
    $storage = app(BeaStorage::class);
    $time = mktime(12, 0, 0, 11, 23, 2026);

    config(['bea.archive.path' => 'beas']);
    expect($storage->archiveDirectory($time))->toBe('2026/beas/2026-11');

    config(['bea.archive.suffix' => 'akv']);
    expect($storage->archiveDirectory($time))->toBe('2026/akv/beas/2026-11');

    config(['bea.archive.prefix' => 'kanzlei', 'bea.archive.suffix' => '', 'bea.archive.postfix' => 'zip']);
    expect($storage->archiveDirectory($time))->toBe('kanzlei/2026/beas/2026-11/zip');

    config(['bea.archive.year' => false, 'bea.archive.prefix' => '', 'bea.archive.postfix' => '']);
    expect($storage->archiveDirectory($time))->toBe('beas/2026-11');

    config(['bea.archive.year' => 'false']);
    expect($storage->archiveDirectory($time))->toBe('beas/2026-11');
});

test('Originale auf BEA_DISK, Eingang und failed auf eigener Disk', function () {
    Storage::fake('data');
    Storage::fake('scans');
    config([
        'bea.disk' => 'data',
        'bea.archive.path' => 'beas',
        'bea.archive.suffix' => 'akv',
        'bea.inbox.disk' => 'scans',
        'bea.inbox.path' => 'beA_Import',
        'bea.failed.disk' => 'scans',
        'bea.failed.path' => 'beA_Import/failed',
    ]);
    Storage::disk('scans')->put('beA_Import/nachricht.zip', file_get_contents(beaFixtureZip()));
    Storage::disk('scans')->put('beA_Import/kaputt.zip', 'keine zip');

    $this->artisan('bea:import')->assertFailed();

    $archive = BeaMessage::sole()->archive;
    expect($archive->disk)->toBe('data')
        ->and($archive->path)->toStartWith(date('Y').'/akv/beas/'.date('Y-m').'/')
        ->and(Storage::disk('data')->exists($archive->path))->toBeTrue()
        ->and(Storage::disk('scans')->files('beA_Import/failed'))->toHaveCount(1)
        ->and(Storage::disk('scans')->files('beA_Import'))->toBe([]);
});

test('Archiv und failed unterhalb des Eingangs werden nicht als Eingang behandelt', function () {
    config(['bea.inbox.path' => 'eingang', 'bea.archive.year' => false, 'bea.archive.path' => 'eingang/archive', 'bea.failed.path' => 'eingang/failed']);
    Storage::disk('beas')->put('eingang/nachricht.zip', file_get_contents(beaFixtureZip()));

    $this->artisan('bea:import')->assertSuccessful();

    $storage = app(BeaStorage::class);
    $archived = BeaMessage::sole()->archive->path;
    expect($archived)->toStartWith('eingang/archive/')
        ->and($storage->inboxFiles())->toBe([])
        ->and($storage->isInboxFile($archived))->toBeFalse()
        ->and($storage->isInboxFile('eingang/nachricht.zip'))->toBeTrue();
});

test('xml-Dateien und alle technischen Prüfprotokolle werden nie übernommen', function () {
    $zip = beaFixtureZip([
        'technisches_pruefprotokoll_01_Ladung.pdf.xml' => '<protokoll/>',
        'technisches_pruefprotokoll_vhn.xml.xml' => '<protokoll/>',
        'Strukturdaten.xml' => '<daten/>',
    ]);
    $message = app(BeaImporter::class)->ingest($zip, 'Nachricht_100000001.zip')['imported'][0];

    $rows = $message->messageFiles()->keyBy('name');
    expect($rows['technisches_pruefprotokoll_01_Ladung.pdf.xml']->role)->toBe('meta')
        ->and($rows['technisches_pruefprotokoll_vhn.xml.xml']->role)->toBe('meta')
        ->and($rows['technisches_pruefprotokoll_vhn.xml.xml']->kind)->toBe('technical_report')
        ->and($rows['Strukturdaten.xml']->role)->toBe('meta')
        ->and($rows['Strukturdaten.xml']->kind)->toBe('excluded')
        ->and($rows['Strukturdaten.xml']->suggested)->toBeFalse();

    // Auch eine früher als Anhang eingelesene xml-Datei wird nicht übernommen
    beaUpdateFile($message, 'Strukturdaten.xml', ['role' => 'attachment', 'kind' => null, 'suggested' => true]);
    $result = app(BeaImporter::class)->accept($message->fresh(), $message->messageFiles()->where('role', 'attachment')->pluck('id')->all());

    expect(collect($result['files'])->map(fn (File $f) => $f->name)->filter(fn ($n) => str_ends_with(strtolower($n), '.xml')))->toBeEmpty()
        ->and($message->fresh()->messageFiles()->firstWhere('name', 'Strukturdaten.xml')->isAccepted())->toBeFalse();
});

test('eine Sammel-ZIP mit einer ZIP je Nachricht liest jede Nachricht mit eigenem Original ein', function () {
    $zip = beaFixtureZipCollection([
        'Nachricht_100000001.zip' => beaFixtureZip(),
        'Nachricht_100000009.zip' => beaFixtureZipOutgoing(),
        'kaputt.zip' => tap(tempnam(sys_get_temp_dir(), 'bea'), fn ($p) => file_put_contents($p, 'keine zip')),
    ]);

    $result = app(BeaImporter::class)->ingest($zip, 'Posteingang.zip');

    expect(collect($result['imported'])->pluck('bea_id')->sort()->values()->all())->toBe(['100000001', '100000009'])
        ->and($result['archives'])->toHaveCount(2)
        ->and(BeaMessage::pluck('archive_name')->sort()->values()->all())->toBe(['Nachricht_100000001.zip', 'Nachricht_100000009.zip'])
        ->and(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'kaputt.zip')))->toBeTrue();

    // Jede Nachricht lässt sich aus ihrem eigenen Original übernehmen
    $message = BeaMessage::where('bea_id', '100000009')->sole();
    $files = app(BeaImporter::class)->accept($message, $message->suggestedFiles()->pluck('id')->all())['files'];
    expect($files)->not->toBeEmpty();

    // Erneut eingelesen: alles bekannt
    $again = app(BeaImporter::class)->ingest($zip, 'Posteingang.zip');
    expect($again['imported'])->toBe([])->and($again['skipped'])->toHaveCount(2);
});
