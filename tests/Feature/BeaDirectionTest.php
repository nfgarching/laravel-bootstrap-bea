<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Livewire\Pending;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Parser\BeaMessageParser;
use Nfgarching\Bea\Services\BeaImporter;
use Nfgarching\Files\Events\NewFiles;
use Nfgarching\Files\Jobs\ProcessFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/../fixture.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('beas');
    config([
        'files.save_disk' => 'local',
        'bea.disk' => 'beas',
        'bea.own_safe_ids' => [],
    ]);
    Bus::fake([ProcessFile::class]);
    Event::fake([BeaMessageImported::class, BeaFilesAccepted::class, NewFiles::class]);
    $this->actingAs(User::factory()->create());
});

test('empfangene Nachricht: Richtung „in“, Datum ist der Eingang im Postfach', function () {
    $message = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];

    expect($message->direction)->toBe(BeaMessage::DIRECTION_IN)
        ->and($message->isOutgoing())->toBeFalse()
        ->and($message->datedLabel())->toBe('Eingang')
        ->and($message->dated_at->equalTo($message->received_at))->toBeTrue()
        ->and($message->aktenzeichen)->toBe('26/0001')
        ->and($message->aktenzeichen_absender)->toBe('1 C 123/26');
});

test('gesendete Nachricht wird am Journal erkannt: Datum ist der Versand, eigenes Az. getauscht', function () {
    $message = app(BeaImporter::class)->ingest(beaFixtureZipOutgoing())['imported'][0];

    expect($message->bea_id)->toBe('100000009')
        ->and($message->direction)->toBe(BeaMessage::DIRECTION_OUT)
        ->and($message->datedLabel())->toBe('Gesendet')
        ->and($message->received_at)->toBeNull()
        ->and($message->dated_at->equalTo($message->sent_at))->toBeTrue()
        // eigenes Aktenzeichen steht bei gesendeten Nachrichten beim Absender
        ->and($message->aktenzeichen)->toBe('1 C 123/26')
        ->and($message->aktenzeichen_absender)->toBe('26/0001')
        ->and($message->data['direction'])->toBe('out')
        ->and($message->data['export_fields'])->not->toHaveKey('Eingang Postfach');

    Event::assertDispatched(BeaMessageImported::class, fn ($e) => $e->aktenzeichen === '1 C 123/26');
});

test('ohne Journal erkennt die eigene SAFE-ID die gesendete Nachricht', function () {
    $sender = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0]->sender_safe_id;
    BeaMessage::query()->delete();
    expect($sender)->not->toBeEmpty();

    config(['bea.own_safe_ids' => [$sender]]);
    $parser = new ReflectionMethod(BeaMessageParser::class, 'direction');

    expect($parser->invoke(app(BeaMessageParser::class), [], ['safe_id' => $sender], []))->toBe('out')
        ->and($parser->invoke(app(BeaMessageParser::class), [], ['safe_id' => 'DE.Justiz.x'], []))->toBe('in');
});

test('Liste und Modal zeigen gesendete Nachrichten mit Versanddatum und sortieren gemeinsam', function () {
    $in = app(BeaImporter::class)->ingest(beaFixtureZip())['imported'][0];
    $out = app(BeaImporter::class)->ingest(beaFixtureZipOutgoing())['imported'][0];
    $out->update(['dated_at' => $in->dated_at->copy()->addDay(), 'subject' => 'Klage eingereicht']);

    $this->get(route('bea.index'))->assertOk()->assertSeeInOrder(['Klage eingereicht', 'Ladung zum Termin']);
    $this->get(route('bea.index', ['richtung' => 'out']))->assertOk()
        ->assertSee('Klage eingereicht')->assertDontSee('Ladung zum Termin')
        ->assertSee('Empfänger (gesendet von', false)
        ->assertSee(e($out->recipient_name), false);

    Livewire::test(Pending::class)
        ->assertSeeInOrder(['Klage eingereicht', 'Ladung zum Termin'])
        ->assertSee('gesendet')
        ->assertSee($out->dated_at->timezone(config('app.timezone'))->format('d.m.Y H:i'));
});

test('die Migration erkennt bereits eingelesene gesendete Nachrichten nach', function () {
    $out = app(BeaImporter::class)->ingest(beaFixtureZipOutgoing())['imported'][0];
    // Stand vor der Migration: Richtung unbekannt, Aktenzeichen wie im Export
    DB::table('bea_messages')->where('id', $out->id)->update([
        'direction' => 'in',
        'dated_at' => null,
        'aktenzeichen' => '26/0001',
        'aktenzeichen_absender' => '1 C 123/26',
    ]);

    $migration = require __DIR__.'/../../database/migrations/2026_11_14_000000_add_direction_to_bea_messages_table.php';
    $migration->down();
    $migration->up();

    $out->refresh();
    expect($out->direction)->toBe('out')
        ->and($out->dated_at->equalTo($out->sent_at))->toBeTrue()
        ->and($out->aktenzeichen)->toBe('1 C 123/26')
        ->and($out->aktenzeichen_absender)->toBe('26/0001');
});
