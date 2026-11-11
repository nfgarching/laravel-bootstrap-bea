<?php

namespace Nfgarching\Bea\Services;

/**
 * Ordnet einen Anhang einer Kategorie zu und schlägt vor, ob er übernommen
 * werden soll (Regeln in config bea.suggest). Der Vorschlag ist nur eine
 * Vorauswahl – entschieden wird bei der Übernahme von Hand.
 *
 * Bewusst ohne Laravel-Abhängigkeiten, damit er sich isoliert testen lässt.
 */
final class BeaClassifier
{
    /**
     * @param  list<array<string, mixed>>  $rules  category, suggest, pattern?, classes?, extensions?
     * @param  list<string>  $fallbackExtensions
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $fallbackExtensions = ['pdf'],
    ) {}

    /**
     * @param  array<string, mixed>  $config  Inhalt von config('bea.suggest')
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            array_values($config['rules'] ?? []),
            array_map('strtolower', $config['fallback_extensions'] ?? ['pdf']),
        );
    }

    /**
     * @return array{category: string, suggested: bool}
     */
    public function classify(string $name, ?string $displayName = null, ?string $documentClass = null): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $subjects = array_filter([$displayName, pathinfo($name, PATHINFO_FILENAME)], fn ($v) => $v !== null && $v !== '');

        foreach ($this->rules as $rule) {
            if (! empty($rule['extensions']) && ! in_array($extension, array_map('strtolower', (array) $rule['extensions']), true)) {
                continue;
            }

            $byClass = $documentClass !== null && $documentClass !== ''
                && in_array($documentClass, array_map('strval', (array) ($rule['classes'] ?? [])), true);

            $byPattern = false;
            if (! empty($rule['pattern'])) {
                foreach ($subjects as $subject) {
                    if (@preg_match($rule['pattern'], $subject) === 1) {
                        $byPattern = true;
                        break;
                    }
                }
            }

            if ($byClass || $byPattern) {
                return ['category' => (string) $rule['category'], 'suggested' => (bool) ($rule['suggest'] ?? false)];
            }
        }

        return ['category' => 'sonstiges', 'suggested' => in_array($extension, $this->fallbackExtensions, true)];
    }
}
