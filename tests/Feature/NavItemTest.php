<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Navbar beA: Klick => iframe-Modal (xl) mit bea.pending, SHIFT => Popup, CTRL => Tab.
 * Nach dem Übernehmen lädt reloadOpener() (Popup-Layout) das Hauptfenster neu.
 */
test('der beA-Button öffnet bea.pending im iframe-Modal in xl', function () {
    $this->actingAs(User::factory()->create());

    $html = view('bea::parts.navitems')->render();

    preg_match('/onclick="(.*?)"/s', $html, $treffer);
    $onclick = html_entity_decode($treffer[1] ?? '', ENT_QUOTES);
    $url = route('bea.pending');

    expect($onclick)->toContain("openFrameModal('".$url."', \"\", 'xl')")
        ->toContain("event.shiftKey) { openPopup('".$url."'")
        ->toContain("event.ctrlKey) { openTab('".$url."')");
});

test('die Popup-Seite bea.pending bringt reloadOpener() für Popup und Modal mit', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('bea.pending'))
        ->assertOk()
        ->assertSee('window.reloadOpener = function', false)
        ->assertSee('window.top.location.reload()', false);
});

test('die beA-Sidebar öffnet „Nicht übernommen“ im iframe-Modal in xl', function () {
    $this->actingAs(User::factory()->create());

    $html = view('bea::parts.sidebar')->render();

    preg_match('/<button class="btn btn-link nav-link".*?onclick="(.*?)"/s', $html, $treffer);
    $onclick = html_entity_decode($treffer[1] ?? '', ENT_QUOTES);
    $url = route('bea.pending');

    expect($onclick)->toContain("openFrameModal('".$url."', \"\", 'xl')")
        ->toContain("event.shiftKey) { openPopup('".$url."', 'width=1000,height=800')")
        ->toContain("event.ctrlKey) { openTab('".$url."')")
        ->and($html)->not->toContain('openModalBeaPending');
});
