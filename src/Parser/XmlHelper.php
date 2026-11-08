<?php

namespace Nfgarching\Bea\Parser;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * XPath über local-name(), damit Namensräume und Schema-Versionen
 * (XJustiz 3.x, VHN 1/2) keine Rolle spielen.
 */
final class XmlHelper
{
    public readonly DOMXPath $xpath;

    public function __construct(public readonly DOMDocument $document)
    {
        $this->xpath = new DOMXPath($document);
    }

    public static function load(string $xml): ?self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: keine externen Entitäten/DTDs nachladen
        $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? new self($document) : null;
    }

    /**
     * Pfad aus local-names, z. B. "nachrichtenkopf/absender/aktenzeichen".
     * Der erste Schritt wird überall unterhalb des Kontexts gesucht, "//"
     * innerhalb des Pfads steht für beliebig tiefe Nachfahren.
     */
    public static function path(string $path): string
    {
        $selector = implode('/', array_map(
            fn ($p) => match ($p) {
                '' => '',
                '*' => '*',
                default => "*[local-name()='{$p}']",
            },
            explode('/', ltrim($path, '/')),
        ));

        return './/'.$selector;
    }

    public function text(string $path, ?DOMNode $context = null): ?string
    {
        $node = $this->xpath->query(self::path($path), $context ?? $this->document)?->item(0);
        if (! $node) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return list<DOMNode>
     */
    public function nodes(string $path, ?DOMNode $context = null): array
    {
        $list = $this->xpath->query(self::path($path), $context ?? $this->document);

        return $list ? iterator_to_array($list) : [];
    }

    public function attribute(string $path, string $attribute, ?DOMNode $context = null): ?string
    {
        $node = $this->nodes($path, $context)[0] ?? null;
        $value = $node?->attributes?->getNamedItem($attribute)?->nodeValue;

        return $value === null || $value === '' ? null : $value;
    }
}
