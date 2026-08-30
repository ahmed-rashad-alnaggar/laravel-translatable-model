<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\ModelTranslationsRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @phpstan-type TranslatablesMap array{
 *     literals: array<string, true>, // Fully-qualified literal translatable keys (e.g. "title", "city.name", "settings.profile.bio")
 *     wildcards: array<string, array<array{wildcard: bool, value?: string, idField?: string}>>, // Wildcard pattern, with any custom `idField` stripped to a bare `*` (e.g. "faq.*.question") => its parsed segments' descriptors
 *     nested: array<string, array<string>>, // Literal or wildcard-pattern nesting prefix (e.g. "city", "settings", "settings.profile", "faq", "faq.*", "items", "items.*") => relative keys of its literal or wildcard-pattern translatable leaves beneath it
 * }
 *
 * There are two related concepts throughout this trait:
 * - Translatable attribute: identifies a key by its live position in the
 *   model's `$attributes` array. It comes in two forms - a literal key (e.g. `title`,
 *   `city.name`, `settings.profile.bio`) with a fixed path, and a wildcard-declared one
 *   (e.g. `faq.*.question`, `items.*:uuid.name`), used concretely with a numeric position in
 *   place of `*` (e.g. `faq.3.question`, `items.5.name`).
 * - Translation key: what's actually persisted in the database. It's identical to the
 *   translatable attribute for a literal translatable - the two only diverge
 *   for a wildcard-declared one, where the translation key replaces each
 *   position with a delimited, `idField`-resolved identity segment
 *   (e.g. `faq.@{7}@.question`, `items.!!!uuid!!!@{550e8400-...}@.name`), so the stored translation survives
 *   reordering or removal of its siblings.
 *
 * A translatable attribute is used for the public translation CRUD surface
 * (e.g. public methods in `ManagesTranslations` trait) and anywhere translatability
 * itself is being questioned (e.g. `isTranslatableAttribute()` and its siblings).
 * A translation key is used wherever dealing with the actually stored/queued translations.
 */
trait InteractsWithTranslatableAttributes
{
    /**
     * Cached, organized lookup tables for the translatable attributes.
     *
     * @var TranslatablesMap
     * @internal
     */
    protected array $cachedTranslatablesMap = [
        'literals' => [],
        'wildcards' => [],
        'nested' => [],
    ];

    /**
     * Whether the translatable attributes map have been fully resolved and cached.
     *
     * @var bool
     * @internal
     */
    protected bool $isCachedTranslatablesMapResolved = false;

    /**
     * Get all declared or discovered translatable attribute keys, **without resolving wildcard patterns**.
     *
     * @return array<string>
     */
    public function getTranslatables(): array
    {
        $literals = array_keys($this->getCachedTranslatablesMap()['literals']);
        $wildcards = array_values(array_map(
            static function (array $patternSegments): string {
                $patternSegments = array_map(
                    static function (array $patternSegment): string {
                        return $patternSegment['wildcard']
                            ? ($patternSegment['idField'] === 'id' ? '*' : "*:{$patternSegment['idField']}")
                            : $patternSegment['value'];
                    },
                    $patternSegments
                );

                return implode('.', $patternSegments);
            },
            $this->getCachedTranslatablesMap()['wildcards']
        ));

        return array_merge($literals, $wildcards);
    }

    /**
     * Get all declared or discovered translatable attribute keys,
     * resolving wildcard patterns into their concrete positional keys against
     * the current model instance's data.
     *
     * @return array<string>
     */
    public function getConcreteTranslatables(): array
    {
        $concreteTranslatables = array_keys($this->getCachedTranslatablesMap()['literals']);

        foreach (array_keys($this->getCachedTranslatablesMap()['nested']) as $key) {
            if (str_contains($key, '.')) {
                continue;
            }

            $nestedConcreteTranslatables = array_map(
                static fn (string $nestedConcreteTranslatable): string => "{$key}.{$nestedConcreteTranslatable}",
                $this->resolveNestedConcreteTranslatableAttributes($key)
            );

            array_push(
                $concreteTranslatables,
                ...$nestedConcreteTranslatables,
            );
        }

        return array_values(array_unique($concreteTranslatables));
    }

    /**
     * Check if the attribute is translatable.
     *
     * @param string $key
     * @return bool
     */
    public function isTranslatableAttribute(string $key): bool
    {
        $wildcardKey = $this->normalizeConcreteKeyToLookupWildcardPattern($key);

        return isset($this->getCachedTranslatablesMap()['literals'][$key])
            || isset($this->getCachedTranslatablesMap()['wildcards'][$wildcardKey]);
    }

    /**
     * Check if the attribute nests any translatable attributes.
     *
     * @param string $key
     * @return bool
     */
    public function isNestingTranslatableAttributes(string $key): bool
    {
        $wildcardKey = $this->normalizeConcreteKeyToLookupWildcardPattern($key);

        return isset($this->getCachedTranslatablesMap()['nested'][$key])
            || isset($this->getCachedTranslatablesMap()['nested'][$wildcardKey]);
    }

    /**
     * Get the model's organized lookup tables for translatable attributes,
     * resolving and caching them once per instance.
     *
     * @return TranslatablesMap
     * @internal
     */
    public function getCachedTranslatablesMap(): array
    {
        if ($this->isCachedTranslatablesMapResolved) {
            return $this->cachedTranslatablesMap;
        }

        $translatables = $this->hasDynamicTranslatables()
            ? $this->discoverTranslatables()
            : $this->translatables();

        foreach ($translatables as $translatable) {
            $this->rememberTranslatable($translatable);
        }

        $this->isCachedTranslatablesMapResolved = true;

        return $this->cachedTranslatablesMap;
    }

    /**
     * Register a new translatable attribute for a model with dynamic translatables.
     *
     * @param string $key
     * @return static
     */
    public function rememberDynamicTranslatable(string $key): static
    {
        if ($this->hasDynamicTranslatables()) {
            $this->rememberTranslatable($key);
        }

        return $this;
    }

    /**
     * Parse and register a translatable attribute into the cached, organized lookup tables.
     *
     * @param string $key
     * @return void
     * @internal
     */
    protected function rememberTranslatable(string $key): void
    {
        if (str_contains($key, '*')) {
            $this->rememberWildcardTranslatable($key);

            return;
        }

        if (isset($this->cachedTranslatablesMap['literals'][$key])) {
            return;
        }

        $this->cachedTranslatablesMap['literals'][$key] = true;

        if (! str_contains($key, '.')) {
            return;
        }

        $keySegments = explode('.', $key);

        array_pop($keySegments);

        $traversedPath = '';

        foreach ($keySegments as $keySegment) {
            $traversedPath = $traversedPath === '' ? $keySegment : "{$traversedPath}.{$keySegment}";

            $this->cachedTranslatablesMap['nested'][$traversedPath][] = substr($key, strlen($traversedPath) + 1);
        }
    }

    /**
     * Parse and register a wildcard translatable attribute pattern.
     *
     * @param string $key
     * @return void
     * @internal
     */
    protected function rememberWildcardTranslatable(string $key): void
    {
        // Strip any custom `idField` declaration from the wildcard translatable pattern,
        // so every wildcard segment is a bare `*`, for a better lookup performance.
        $lookupKey = preg_replace('/(?<=\.)\*:[^.]+(?=\.)/', '*', $key);

        if (isset($this->cachedTranslatablesMap['wildcards'][$lookupKey])) {
            return;
        }

        $keySegments = explode('.', $key);
        $lastKeySegmentIndex = count($keySegments) - 1;
        $traversedPath = '';
        $patternSegments = [];

        foreach ($keySegments as $index => $keySegment) {
            if ($keySegment === '*' || str_starts_with($keySegment, '*:')) {
                $patternSegments[] = [
                    'wildcard' => true,
                    'idField' => str_contains($keySegment, ':') ? Str::after($keySegment, ':') : 'id',
                ];
            } else {
                $patternSegments[] = ['wildcard' => false, 'value' => $keySegment];
            }

            if ($index < $lastKeySegmentIndex) {
                $lookupKeySegment = str_starts_with($keySegment, '*:')
                    ? '*'
                    : $keySegment;
                $traversedPath = $traversedPath === '' ? $lookupKeySegment : "{$traversedPath}.{$lookupKeySegment}";

                $this->cachedTranslatablesMap['nested'][$traversedPath][] = substr($lookupKey, strlen($traversedPath) + 1);
            }
        }

        $this->cachedTranslatablesMap['wildcards'][$lookupKey] = $patternSegments;
    }

    /**
     * Applies only to a wildcard-declared translatable; any other key is returned as is.
     * Resolve a model translatable attribute key (e.g. `faq.3.question`, `items.5.name`)
     * into its identity-based, resolved translation key - the key actually persisted
     * in the database (e.g. `faq.@{7}@.question`, `items.!!!uuid!!!@{550e8400-...}@.name`).
     *
     * @param string $key
     * @return string
     */
    protected function resolveTranslationKey(string $key): string
    {
        $wildcardKey = $this->normalizeConcreteKeyToLookupWildcardPattern($key);

        if (is_null($patternSegments = $this->getCachedTranslatablesMap()['wildcards'][$wildcardKey] ?? null)) {
            return $key;
        }

        $keySegments = explode('.', $key);
        $column = $keySegments[0];

        $attribute = $this->getArrayAttributeByKey($column);
        $resolvedParts = [$column];

        for ($i = 1; $i < count($keySegments); $i++) {
            $keySegment = $keySegments[$i];
            $patternSegment = $patternSegments[$i];

            if (! is_array($attribute) || ! array_key_exists($keySegment, $attribute)) {
                return $key;
            }

            if ($patternSegment['wildcard']) {
                $item = $attribute[$keySegment];

                if (! is_array($item) || ! array_key_exists($patternSegment['idField'], $item)) {
                    return $key;
                }

                $idFieldName = $patternSegment['idField'];
                $idFieldValue = $item[$idFieldName];

                $resolvedParts[] = $idFieldName === 'id'
                    ? "@{{$idFieldValue}}@"
                    : "!!!{$idFieldName}!!!@{{$idFieldValue}}@";
            } else {
                $resolvedParts[] = $keySegment;
            }

            $attribute = $attribute[$keySegment];
        }

        return implode('.', $resolvedParts);
    }

    /**
     * Get every nested translatable attribute beneath the given concrete key,
     * a wildcard-declared translatable beneath it is expanded into its concrete
     * positional attribute keys per instance data.
     *
     * @param string $key
     * @return array<string>
     */
    public function resolveNestedConcreteTranslatableAttributes(string $key): array
    {
        $lookupKey = isset($this->getCachedTranslatablesMap()['nested'][$key])
            ? $key
            : $this->normalizeConcreteKeyToLookupWildcardPattern($key);

        [$concreteTranslatableAttributes, $nestedWildcardTranslatableAttributes] =
            Arr::partition(
                $this->getCachedTranslatablesMap()['nested'][$lookupKey] ?? [],
                static fn ($nestedKey) => ! str_contains($nestedKey, '*')
            );

        // Recursively walk a wildcard pattern's segments from a given depth,
        // matching them against live array data, and collect the concrete,
        // position-based relative path of every leaf reached.
        $traverseAndCollectConcreteTranslatableAttributesForWildcardPatternSegments = static function (mixed $target, array $patternSegments, int $currentPatternSegmentIndex, string $traversedPath) use (&$traverseAndCollectConcreteTranslatableAttributesForWildcardPatternSegments): array {
            // Every pattern segment has been consumed; $traversedPath is a complete concrete translatable attribute.
            if ($currentPatternSegmentIndex === count($patternSegments)) {
                return [$traversedPath];
            }

            $patternSegment = $patternSegments[$currentPatternSegmentIndex];

            if (! $patternSegment['wildcard']) {
                if (! is_array($target) || ! array_key_exists($patternSegment['value'], $target)) {
                    return [];
                }

                return $traverseAndCollectConcreteTranslatableAttributesForWildcardPatternSegments(
                    $target[$patternSegment['value']],
                    $patternSegments,
                    $currentPatternSegmentIndex + 1,
                    $traversedPath === '' ? $patternSegment['value'] : "{$traversedPath}.{$patternSegment['value']}"
                );
            }

            if (! is_array($target)) {
                return [];
            }

            $patternConcreteTranslatableAttributes = [];

            foreach ($target as $index => $item) {
                if (! is_array($item) || ! ctype_digit((string) $index)) {
                    continue;
                }

                array_push(
                    $patternConcreteTranslatableAttributes,
                    ...$traverseAndCollectConcreteTranslatableAttributesForWildcardPatternSegments($item, $patternSegments, $currentPatternSegmentIndex + 1, $traversedPath === '' ? (string) $index : "{$traversedPath}.{$index}")
                );
            }

            return $patternConcreteTranslatableAttributes;
        };

        if (filled($nestedWildcardTranslatableAttributes)) {
            $keySegments = explode('.', $key);
            $keySegmentsCount = count($keySegments);

            $column = $keySegments[0];
            $attribute = $keySegmentsCount === 1
                ? $this->getArrayAttributeByKey($column)
                : Arr::get($this->getArrayAttributeByKey($column), implode('.', array_slice($keySegments, 1)));

            foreach ($nestedWildcardTranslatableAttributes as $nestedWildcardTranslatableAttribute) {
                $patternSegments = $this->getCachedTranslatablesMap()['wildcards']["{$lookupKey}.{$nestedWildcardTranslatableAttribute}"];

                array_push(
                    $concreteTranslatableAttributes,
                    ...$traverseAndCollectConcreteTranslatableAttributesForWildcardPatternSegments($attribute, $patternSegments, $keySegmentsCount, '')
                );
            }
        }

        return $concreteTranslatableAttributes;
    }

    /**
     * Replace any concrete key's numeric segments with wildcards, for looking up
     * its corresponding registered wildcard pattern (e.g. `faq.3.question`
     * becomes `faq.*.question`).
     *
     * An invalid concrete key containing a wildcard segment is replaced with a non-matching key.
     *
     * @param string $key
     * @return string
     */
    protected function normalizeConcreteKeyToLookupWildcardPattern(string $key): string
    {
        return str_contains($key, '*')
            ? '__invalid_concrete_key__'
            : preg_replace('/(?<=\.)\d+(?=\.|$)|(?<=^)\d+(?=\.)/', '*', $key);
    }

    /**
     * A dot-notated array of the translatable attributes.
     *
     * @return array<string>
     */
    protected function translatables(): array
    {
        return [];
    }

    /**
     * Whether the translatable attributes should be resolved dynamically.
     *
     * @return bool
     */
    protected function hasDynamicTranslatables(): bool
    {
        return false;
    }

    /**
     * Discover translatable attributes from existing translations in the database.
     *
     * @return array<string>
     */
    protected function discoverTranslatables(): array
    {
        return array_values(
            array_unique(
                array_map(
                    // Replace any delimited identity segment with its wildcard form
                    // (e.g. `faq.@{7}@.question` becomes `faq.*.question`, 
                    // `items.!!!uuid!!!@{550e8400-...}@.name` becomes `items.*:uuid.name`).
                    static function (string $key): string {
                        return preg_replace_callback(
                            '/(?<=\.)(?:!!!([^!.]+)!!!)?@\{[^.]*\}@(?=\.)/',
                            static fn (array $matches): string => isset($matches[1]) ? "*:{$matches[1]}" : '*',
                            $key
                        );
                    },
                    app(ModelTranslationsRepository::class)->getModelKeys($this->getMorphClass(), $this->getKey())
                )
            )
        );
    }
}
