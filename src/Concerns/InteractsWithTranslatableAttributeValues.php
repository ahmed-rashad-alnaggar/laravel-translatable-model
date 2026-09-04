<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy;
use Illuminate\Support\Arr;

trait InteractsWithTranslatableAttributeValues
{
    /**
     * Retrieve the **raw translation value** of a translatable column for the given locale.
     * If no translation resolves, whether directly or via the fallback strategy,
     * fall back to its raw placeholder value.
     *
     * @param string $key
     * @param string $locale
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy $fallbackStrategy
     * @return string|null
     */
    protected function getTranslatableColumnValue(string $key, string $locale, FallbackStrategy $fallbackStrategy): ?string
    {
        return $this->getTranslationWithResolvedKey($key, $locale, $fallbackStrategy) ?? $this->getAttributeFromArray($key);
    }

    /**
     * Retrieve the **raw value** of a column with its nested translatables' translations merged in.
     * If no translation resolves for one of its leaves, whether directly or via
     * the fallback strategy, fall back to that leaf's raw placeholder value.
     *
     * @param string $key
     * @param string $locale
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy $fallbackStrategy
     * @return string|null
     */
    protected function getColumnNestingTranslatablesValue(string $key, string $locale, FallbackStrategy $fallbackStrategy): ?string
    {
        if (is_null($this->getAttributeFromArray($key))) {
            return null;
        }

        $attribute = $this->getArrayAttributeByKey($key);

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $translationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}", keyVerifiedExistenceAgainstModelData: true);
            $translation = $this->getTranslationWithResolvedKey($translationKey, $locale, $fallbackStrategy);

            if (! is_null($translation)) {
                Arr::set($attribute, $nestedConcreteTranslatableAttribute, $this->decodeNestedTranslation($translation));
            }
        }

        return $this->castColumnNestingTranslatablesArrayValue($key, $attribute);
    }

    /**
     * Set or add translation for a translatable column.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return mixed
     * @internal
     */
    protected function setTranslatableColumn(string $key, mixed $value, string $locale): mixed
    {
        $placeholder = $this->getAttributeFromArray($key);

        // Run the value through Eloquent's normal set pipeline first (casts/
        // mutators), so the translation stored is the same normalized, storable
        // form a native column would hold - e.g. a Stringable or custom cast
        // object resolved to its plain value - then restore the raw attribute
        // to its previous placeholder.
        $returnValue = parent::setAttribute($key, $value);

        $this->setTranslationWithResolvedKey($key, $this->getAttributeFromArray($key), $locale);

        $this->attributes[$key] = $placeholder;

        return $returnValue;
    }

    /**
     * Set a nesting column while handling its nested translatable attributes.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return mixed
     * @internal
     */
    protected function setColumnNestingTranslatables(string $key, mixed $value, string $locale): mixed
    {
        $oldAttribute = $this->getArrayAttributeByKey($key);
        $oldResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $oldResolvedTranslationKeys[$this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}", keyVerifiedExistenceAgainstModelData: true)] = $nestedConcreteTranslatableAttribute;
        }

        // Run the whole payload through the real cast pipeline first (e.g. array
        // cast, encryption), so each tracked leaf's translation is extracted in
        // its normalized, storable form rather than the raw pre-cast input.
        $returnValue = parent::setAttribute($key, $value);

        $newAttribute = $this->getArrayAttributeByKey($key);
        $newResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $newResolvedTranslationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}", keyVerifiedExistenceAgainstModelData: true);
            $translation = $this->encodeNestedTranslation(Arr::get($newAttribute, $nestedConcreteTranslatableAttribute));

            // Restore the placeholder by resolved key, not position - the item may
            // have moved, but its previous value still needs to be looked up from
            // where it used to sit in $oldAttribute.
            //
            // A resolved key with no entry in $oldResolvedTranslationKeys is a
            // genuinely new leaf - there is no prior placeholder to preserve,
            // so the placeholder is `null`, not whatever happens to sit at the
            // new position in $newAttribute.
            $placeholder = array_key_exists($newResolvedTranslationKey, $oldResolvedTranslationKeys)
                ? Arr::get($oldAttribute, $oldResolvedTranslationKeys[$newResolvedTranslationKey])
                : null;

            $this->setTranslationWithResolvedKey($newResolvedTranslationKey, $translation, $locale);

            Arr::set($newAttribute, $nestedConcreteTranslatableAttribute, $placeholder);

            $newResolvedTranslationKeys[$newResolvedTranslationKey] = $nestedConcreteTranslatableAttribute;
        }

        $this->removeTranslationsWithResolvedKeys(array_keys(array_diff_key($oldResolvedTranslationKeys, $newResolvedTranslationKeys)));

        $this->attributes[$key] = $this->castColumnNestingTranslatablesArrayValue($key, $newAttribute);

        return $returnValue;
    }

    /**
     * Set or add translation for a **concrete translatable json attribute**.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return static
     * @internal
     */
    protected function fillTranslatableJsonAttribute(string $key, mixed $value, string $locale): static
    {
        $this->setTranslationWithResolvedKey($this->resolveTranslationKey($key), $this->encodeNestedTranslation($value), $locale);

        return $this;
    }

    /**
     * Set a nesting json attribute while handling its nested translatable attributes.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return static
     * @internal
     */
    protected function fillJsonAttributeNestingTranslatables(string $key, mixed $value, string $locale): static
    {
        $jsonAttributeKey = str_replace('.', '->', $key);
        [$column, $path] = explode('.', $key, 2);

        $oldAttribute = Arr::get($this->getArrayAttributeByKey($column), $path);
        $oldResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $oldResolvedTranslationKeys[$this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}", keyVerifiedExistenceAgainstModelData: true)] = $nestedConcreteTranslatableAttribute;
        }

        // Write the incoming value first, so live attribute data reflects the new
        // structure and the second `resolveNestedConcreteTranslatableAttributes()`
        // call below enumerates leaves (and any wildcard positions) against
        // the actual post-update state, the same way `setColumnNestingTranslatables()`
        // resolves against post-cast data.
        parent::fillJsonAttribute($jsonAttributeKey, $value);

        $newResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $newResolvedTranslationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}", keyVerifiedExistenceAgainstModelData: true);
            $translation = $this->encodeNestedTranslation(Arr::get($value, $nestedConcreteTranslatableAttribute));

            // Restore the placeholder by resolved key, not position - the item
            // may have moved, but its previous value still needs to be looked
            // up from where it used to sit under $oldAttribute.
            //
            // A resolved key with no entry in $oldResolvedTranslationKeys is a
            // genuinely new leaf - there is no prior placeholder to preserve,
            // so the placeholder is `null`, not whatever happens to sit at the
            // new position in $value.
            $placeholder = array_key_exists($newResolvedTranslationKey, $oldResolvedTranslationKeys)
                ? Arr::get($oldAttribute, $oldResolvedTranslationKeys[$newResolvedTranslationKey])
                : null;

            $this->setTranslationWithResolvedKey($newResolvedTranslationKey, $translation, $locale);

            Arr::set($value, $nestedConcreteTranslatableAttribute, $placeholder);

            $newResolvedTranslationKeys[$newResolvedTranslationKey] = $nestedConcreteTranslatableAttribute;
        }

        $this->removeTranslationsWithResolvedKeys(array_keys(array_diff_key($oldResolvedTranslationKeys, $newResolvedTranslationKeys)));

        // Overwrite the just-written real values with placeholders, so the JSON
        // column ends up holding placeholder data rather than translations.
        return parent::fillJsonAttribute($jsonAttributeKey, $value);
    }

    /**
     * Set a json attribute nested within a translatable attribute.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return static
     * @internal
     * 
     * @throws \LogicException
     */
    protected function fillJsonAttributeNestedWithinTranslatableAttribute(string $key, mixed $value, string $locale): static
    {
        $wildcardKey = $this->normalizeConcreteKeyToLookupWildcardPattern($key);

        $translatable =
            Arr::first(
                array_keys($this->getCachedTranslatablesMap()['literals']),
                static fn (string $translatable): bool => str_starts_with($key, "{$translatable}.")
            )
            ?? Arr::first(
                array_keys($this->getCachedTranslatablesMap()['wildcards']),
                static fn (string $translatable): bool => str_starts_with($wildcardKey, "{$translatable}.")
            );

        $keySegments = explode('.', $key);
        $translatableSegmentsCount = count(explode('.', $translatable));
        $concreteTranslatable = implode('.', array_slice($keySegments, 0, $translatableSegmentsCount));

        $translationKey = $this->resolveTranslationKey($concreteTranslatable);
        $translation = $this->getTranslationWithResolvedKey($translationKey, $locale, $this->getDefaultTranslationsFallbackStrategy());

        if (is_null($translation)) {
            throw new \LogicException("Unable to set the json attribute [{$key}]: its enclosing translatable attribute [{$concreteTranslatable}] has no translation for the locale [{$locale}].");
        }

        if (! str_contains($concreteTranslatable, '.')) {
            // Swap the translation in as the live attribute value, so
            // parent::fillJsonAttribute() applies the incoming value on top of the
            // translation's own structure - through the column's real cast pipeline -
            // rather than on top of the raw placeholder; then extract the merged
            // result and restore the placeholder.

            $placeholder = $this->getAttributeFromArray($concreteTranslatable);

            $this->attributes[$concreteTranslatable] = $translation;

            parent::fillJsonAttribute(str_replace('.', '->', $key), $value);

            $translation = $this->getAttributeFromArray($concreteTranslatable);

            $this->attributes[$concreteTranslatable] = $placeholder;
        } else {
            $path = implode('.', array_slice($keySegments, $translatableSegmentsCount));

            $translation = $this->decodeNestedTranslation($translation);

            Arr::set($translation, $path, $value);

            $translation = $this->encodeNestedTranslation($translation);
        }

        $this->setTranslationWithResolvedKey($translationKey, $translation, $locale);

        return $this;
    }

    /**
     * Cast the array value of a column nesting translatable attributes back into its storable form.
     *
     * @param string $key
     * @param array $value
     * @return string
     * @internal
     */
    protected function castColumnNestingTranslatablesArrayValue(string $key, array $value): string
    {
        $value = $this->asJson($value, $this->getJsonCastFlags($key));

        $value = $this->isEncryptedCastable($key)
            ? $this->castAttributeAsEncryptedString($key, $value)
            : $value;

        if ($this->isClassCastable($key)) {
            unset($this->classCastCache[$key]);
        }

        return $value;
    }

    /**
     * Encode a nested translatable attribute value for database - JSON-encoded so the round-trip
     * through `decodeNestedTranslation()` is exact for any type; `null` passes through
     * unencoded since it's the removal signal `setTranslationWithResolvedKey()` checks for.
     *
     * @param mixed $value
     * @return string|null
     */
    protected function encodeNestedTranslation(mixed $value): ?string
    {
        return is_null($value) ? null : json_encode($value);
    }

    /**
     * Decode a nested translatable attribute value read back from database.
     *
     * @param string $value
     * @return mixed
     */
    protected function decodeNestedTranslation(string $value): mixed
    {
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
