<?php

use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Parser\BeaArchive;
use Nfgarching\Bea\Parser\BeaMessageParser;

require_once __DIR__.'/../fixture.php';

/*
 * Synthetische Testnachricht: tests/Fixtures/Nachricht_100000001_Testnachricht
 * (frei erfundene Daten, erzeugt mit tests/Fixtures/generate.sh)
 */

test('liest Aktenzeichen, Absender, Zeitpunkte und XJustiz-Daten', function () {
    $target = sys_get_temp_dir().'/bea-test-'.uniqid();
    (new BeaArchive)->extract(beaFixtureZip(), $target);

    $dirs = BeaMessageParser::messageDirectories($target);
    expect($dirs)->toBe(['Nachricht_100000001_Testnachricht']);

    $data = (new BeaMessageParser)->parse($target, $dirs[0]);

    expect($data->beaId)->toBe('100000001')
        ->and($data->osciId)->toBe('BRAK_TEST-0001')
        ->and($data->subject)->toBe('Ladung zum Termin')
        ->and($data->aktenzeichen)->toBe('26/0001')
        ->and($data->aktenzeichenAbsender)->toBe('1 C 123/26')
        ->and($data->sender['name'])->toBe('Amtsgericht Musterstadt')
        ->and($data->sender['safe_id'])->toBe('DE.Justiz.00000000-0000-0000-0000-000000000001.0001')
        ->and($data->receivedAt?->format('Y-m-d H:i'))->toBe('2026-11-02 09:16')
        ->and($data->receivedAt?->getTimezone()->getName())->toBe('Europe/Berlin')
        ->and($data->secureChannel)->toBeTrue()
        ->and($data->eebRequested)->toBeTrue()
        ->and($data->xjustiz['instances'][0]['court'])->toBe('X0000')
        ->and(array_column($data->xjustiz['parties'], 'name'))->toBe(['Erika Musterfrau', 'Beispiel GmbH'])
        ->and($data->warnings)->toBe([]);
});

test('ordnet Anhänge den XJustiz-Dokumenten zu und prüft die VHN-Prüfsummen', function () {
    $target = sys_get_temp_dir().'/bea-test-'.uniqid();
    (new BeaArchive)->extract(beaFixtureZip(), $target);
    $data = (new BeaMessageParser)->parse($target, 'Nachricht_100000001_Testnachricht');

    $byName = collect($data->attachments)->keyBy('name');

    expect($byName->keys()->all())->toBe(['Klageerwiderung.pdf', 'Ladung.pdf', 'Ladung.pdf.p7s'])
        ->and($byName['Ladung.pdf']['display_name'])->toBe('Ladung zum Termin')
        ->and($byName['Ladung.pdf']['document_class'])->toBe('017')
        ->and($byName['Ladung.pdf']['vhn_covered'])->toBeTrue()
        ->and($byName['Ladung.pdf.p7s']['signature_of'])->toBe('Ladung.pdf')
        ->and($data->integrity['complete'])->toBeTrue()
        ->and($data->metaFiles['vhn_signature'])->toEndWith('vhn.xml.p7s');
});

test('erkennt veränderte Anhänge', function () {
    $target = sys_get_temp_dir().'/bea-test-'.uniqid();
    (new BeaArchive)->extract(beaFixtureZip(), $target);
    file_put_contents($target.'/Nachricht_100000001_Testnachricht/Ladung.pdf', '%PDF-1.4 verändert');

    $data = (new BeaMessageParser)->parse($target, 'Nachricht_100000001_Testnachricht');

    expect($data->integrity['complete'])->toBeFalse()
        ->and($data->integrity['unmatched_hashes'])->toBe(1)
        ->and($data->integrity['uncovered'])->toBe(['Ladung.pdf'])
        ->and($data->warnings)->not->toBeEmpty();
});

test('verweigert Pfade außerhalb des Zielordners', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'evil').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../../evil.txt', 'x');
    $zip->close();

    (new BeaArchive)->extract($zipPath, sys_get_temp_dir().'/bea-evil-'.uniqid());
})->throws(InvalidBeaArchive::class);
