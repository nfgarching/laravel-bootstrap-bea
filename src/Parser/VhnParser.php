<?php

namespace Nfgarching\Bea\Parser;

use DOMNode;

/**
 * Liest vhn.xml (Vertrauenswürdiger Herkunftsnachweis): Absender, Rolle,
 * sicherer Übermittlungsweg und die Prüfsummen der Dokumente.
 */
final class VhnParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $xml): array
    {
        $x = XmlHelper::load($xml);
        if (! $x || ! $x->document->documentElement) {
            return ['present' => false, 'error' => 'VHN-Datei nicht lesbar'];
        }

        $sender = $x->nodes('Fachlich/Absender')[0] ?? null;
        $field = fn (string $name) => $sender ? $x->text($name, $sender) : null;
        $secure = strtolower((string) $x->text('Fachlich/Sicherer_Uebermittlungsweg'));

        return [
            'present' => true,
            'version' => $x->document->documentElement->getAttribute('Version') ?: null,
            'sender' => array_filter([
                'safe_id' => $field('Nutzer_ID'),
                'title' => $field('Titel'),
                'first_name' => $field('Vorname'),
                'name' => $field('Name'),
                'street' => $field('Strasse'),
                'house_number' => $field('Hausnummer'),
                'postal_code' => $field('Postleitzahl'),
                'city' => $field('Ort'),
                'state' => $field('Bundesland'),
                'country' => $field('Land'),
                'organisation' => $field('Organisation'),
                'organisation_addition' => $field('Organisationszusatz'),
                'profession' => $field('Berufstraegereigenschaft'),
                'external_id' => $field('Externe_ID'),
            ]),
            'role' => $x->text('Fachlich/EGVP_Rollenwerte'),
            'secure_channel' => match ($secure) {
                'ja', 'true', '1' => true,
                'nein', 'false', '0' => false,
                default => null,
            },
            'hashes' => array_map(fn (DOMNode $node) => [
                'algorithm' => strtoupper($node->attributes?->getNamedItem('Algorithm')?->nodeValue ?? 'SHA256'),
                'value' => $x->text('Hashwert', $node),
            ], $x->nodes('Fachlich/Dokument')),
            'producer' => array_filter([
                'product' => $x->text('Technisch/HerstellerInformation/Name_des_Produkts_und_Softwareversion'),
                'vendor' => $x->text('Technisch/HerstellerInformation/Hersteller_des_Produkts'),
                'registration_id' => $x->text('Technisch/HerstellerInformation/Registrierungs_ID'),
            ]),
        ];
    }
}
