<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Livewire\Inbox;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Jobs\ProcessFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/../fixture.php';

beforeEach(function () {
    Storage::fake('local');
    config(['files.save_disk' => 'local']);
    Bus::fake([ProcessFile::class]);
    $this->actingAs(User::factory()->create());
});

test('importiert eine hochgeladene ZIP über den beA-Eingang', function () {
    Event::fake([BeaMessageImported::class]);
    $zip = UploadedFile::fake()->createWithContent('Nachricht_100000001.zip', file_get_contents(beaFixtureZip()));

    Livewire::test(Inbox::class)
        ->set('zips', [$zip])
        ->assertHasNoErrors()
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('beA 100000001 importiert');

    expect(BeaMessage::where('bea_id', '100000001')->exists())->toBeTrue();
});

test('ZIPs über 12 MB werden nicht schon vom Livewire-Upload abgewiesen', function () {
    $zip = UploadedFile::fake()->create('gross.zip', 13000, 'application/zip');

    Livewire::test(Inbox::class)
        ->set('zips', [$zip])
        ->assertHasNoErrors('zips.0');
});

test('zeigt einen gescheiterten Upload an', function () {
    Livewire::test(Inbox::class)
        ->call('_uploadErrored', 'zips', null, true)
        ->assertHasErrors('zips')
        ->assertSee('zips failed to upload');
});
