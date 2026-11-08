<?php

namespace Nfgarching\Bea\Parser;

use DOMNode;

/**
 * Liest xjustiz_nachricht.xml (Strukturdatensatz). Die Codes (Gericht,
 * Sachgebiet, Dokumentklasse, Rollen) werden unverändert übernommen; ihre
 * Bedeutung steht in den XJustiz-Codelisten (listURI/listVersionID).
 */
final class XJustizParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $xml): array
    {
        $x = XmlHelper::load($xml);
        if (! $x || ! $x->document->documentElement) {
            return ['present' => false, 'error' => 'XJustiz-Datei nicht lesbar'];
        }

        $root = $x->document->documentElement;
        $kopf = $x->nodes('nachrichtenkopf')[0] ?? null;
        $absender = $kopf ? ($x->nodes('absender', $kopf)[0] ?? null) : null;
        $empfaenger = $kopf ? ($x->nodes('empfaenger', $kopf)[0] ?? null) : null;

        return [
            'present' => true,
            'message_type' => $root->localName,
            'version' => $kopf?->attributes?->getNamedItem('xjustizVersion')?->nodeValue,
            'created_at' => $kopf ? $x->text('erstellungszeitpunkt', $kopf) : null,
            'sender' => [
                'safe_id' => $absender ? $x->text('auswahl_kommunikationspartner/*', $absender) : null,
                'aktenzeichen' => $absender ? $this->aktenzeichen($x, $absender) : null,
                'message_id' => $absender ? $x->text('eigeneNachrichtenID', $absender) : null,
            ],
            'recipient' => [
                'safe_id' => $empfaenger ? $x->text('auswahl_kommunikationspartner/*', $empfaenger) : null,
                'aktenzeichen' => $empfaenger ? $this->aktenzeichen($x, $empfaenger) : null,
            ],
            'reference_message_id' => $kopf ? $x->text('bezugNachrichtenID', $kopf) : null,
            'eeb_requested' => $this->eebRequested($x),
            'producer' => $kopf ? array_filter([
                'product' => $x->text('herstellerinformation/nameDesProdukts', $kopf),
                'vendor' => $x->text('herstellerinformation/herstellerDesProdukts', $kopf),
                'version' => $x->text('herstellerinformation/version', $kopf),
            ]) : [],
            'instances' => array_map(fn (DOMNode $node) => array_filter([
                'number' => $x->text('instanznummer', $node),
                'court' => $x->text('auswahl_instanzbehoerde//code', $node),
                'subject_area' => $x->text('sachgebiet/code', $node),
                'aktenzeichen' => $this->aktenzeichen($x, $node),
                'subject_matter' => $x->text('verfahrensgegenstand/gegenstand', $node),
            ], fn ($v) => $v !== null), $x->nodes('grunddaten//instanzdaten')),
            'parties' => array_map(fn (DOMNode $node) => array_filter([
                'number' => $x->text('beteiligtennummer', $node),
                'roles' => array_values(array_filter(array_map(
                    fn (DOMNode $role) => $x->text('rollenbezeichnung/code', $role) ?? $x->text('code', $role),
                    $x->nodes('rolle', $node),
                ))),
                'name' => $this->partyName($x, $node),
            ], fn ($v) => $v !== null && $v !== []), $x->nodes('grunddaten//beteiligung')),
            'documents' => array_map(fn (DOMNode $node) => array_filter([
                'id' => $x->text('identifikation/id', $node),
                'number' => $x->text('identifikation/nummerImUebergeordnetenContainer', $node),
                'document_class' => $x->text('dokumentklasse/code', $node),
                'display_name' => $x->text('anzeigename', $node),
                'date' => $x->text('datumDesSchreibens', $node),
                'files' => array_map(fn (DOMNode $file) => array_filter([
                    'name' => $x->text('dateiname', $file),
                    'part' => $x->text('bestandteil/code', $file),
                ]), $x->nodes('datei', $node)),
            ], fn ($v) => $v !== null && $v !== []), $x->nodes('schriftgutobjekte//dokument')),
        ];
    }

    /**
     * Aktenzeichen als Freitext oder strukturiert; ohne Unterelemente zählt der Text.
     */
    private function aktenzeichen(XmlHelper $x, DOMNode $context): ?string
    {
        return $x->text('aktenzeichen.freitext', $context)
            ?? $x->text('aktenzeichen.strukturiert', $context)
            ?? $x->text('aktenzeichen', $context);
    }

    private function partyName(XmlHelper $x, DOMNode $node): ?string
    {
        if ($organisation = $x->text('organisation//bezeichnung.aktuell', $node) ?? $x->text('bezeichnung.aktuell', $node)) {
            return $organisation;
        }

        $parts = array_filter([
            $x->text('vollerName/vorname', $node) ?? $x->text('vorname', $node),
            $x->text('vollerName/nachname', $node) ?? $x->text('nachname', $node),
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    /**
     * Anforderung eines elektronischen Empfangsbekenntnisses: Element
     * ruecksendungEEB.erforderlich (o. ä.) mit Wert true.
     */
    private function eebRequested(XmlHelper $x): bool
    {
        foreach ($x->xpath->query("//*[starts-with(local-name(), 'ruecksendungEEB')]") ?: [] as $node) {
            if (in_array(strtolower(trim($node->textContent)), ['true', '1', 'ja'], true)) {
                return true;
            }
        }

        return false;
    }
}
