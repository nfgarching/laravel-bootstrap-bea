<?php

use Nfgarching\Bea\Services\BeaClassifier;

test('schlägt Verfügungen, Schriftsätze und Schreiben vor, Anlagen nicht', function (string $name, ?string $display, string $category, bool $suggested) {
    $classifier = BeaClassifier::fromConfig(config('bea.suggest'));

    expect($classifier->classify($name, $display))->toBe(['category' => $category, 'suggested' => $suggested]);
})->with([
    ['Ladung.pdf', 'Ladung zum Termin', 'verfuegung', true],
    ['Beschluss.pdf', null, 'verfuegung', true],
    ['Klageerwiderung.pdf', null, 'schriftsatz', true],
    ['Schriftsatz mit Anlagen.pdf', null, 'schriftsatz', true],
    ['Anschreiben.pdf', null, 'schreiben', true],
    ['K1.pdf', null, 'anlage', false],
    ['Anlage B 12 Mietvertrag.pdf', null, 'anlage', false],
    ['scan0001.pdf', null, 'sonstiges', true],
    ['bild.jpg', null, 'sonstiges', false],
]);

test('die XJustiz-Dokumentklasse entscheidet, wenn sie in einer Regel steht', function () {
    $classifier = new BeaClassifier([['category' => 'verfuegung', 'suggest' => true, 'classes' => ['017']]], []);

    expect($classifier->classify('dok1.pdf', null, '017'))->toBe(['category' => 'verfuegung', 'suggested' => true])
        ->and($classifier->classify('dok1.pdf', null, '099'))->toBe(['category' => 'sonstiges', 'suggested' => false]);
});
