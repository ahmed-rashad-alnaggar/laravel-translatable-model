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
     * @return mixed
     */
    protected function getColumnNestingTranslatablesValue(string $key, string $locale, FallbackStrategy $fallbackStrategy): mixed
    {
        $attribute = $this->getArrayAttributeByKey($key);

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $translationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}");
            $translation = $this->getTranslationWithResolvedKey($translationKey, $locale, $fallbackStrategy);

            if (! is_null($translation)) {
                Arr::set($attribute, $nestedConcreteTranslatableAttribute, $this->decodeNestedTranslation($translation));
            }
        }

        return $this->castColumnNestingTranslatablesArrayValue($key, $attribute);
    }

    /**
     * Set or add translation for a **listed translatable column**.
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
     * Set or add translation for a **listed translatable json attribute**.
     *
     * @param string $key
     * @param mixed $value
     * @param string $locale
     * @return static
     * @internal
     */
    protected function fillTranslatableJsonAttribute(string $key, mixed $value, string $locale): static
    {
        $jsonAttributeKey = str_replace('.', '->', $key);
        [$column, $path] = explode('.', $key, 2);

        $attribute = $this->getArrayAttributeByKey($column);
        $placeholder = Arr::get($attribute, $path);

        $this->setTranslationWithResolvedKey($this->resolveTranslationKey($key), $this->encodeNestedTranslation($value), $locale);

        return parent::fillJsonAttribute($jsonAttributeKey, $placeholder);
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
            $oldResolvedTranslationKeys[$this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}")] = $nestedConcreteTranslatableAttribute;
        }

        // Run the whole payload through the real cast pipeline first (e.g. array
        // cast, encryption), so each tracked leaf's translation is extracted in
        // its normalized, storable form rather than the raw pre-cast input.
        $returnValue = parent::setAttribute($key, $value);

        $newAttribute = $this->getArrayAttributeByKey($key);
        $newResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $newResolvedTranslationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}");
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
            $oldResolvedTranslationKeys[$this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}")] = $nestedConcreteTranslatableAttribute;
        }

        // Write the incoming value first, so live attribute data reflects the new
        // structure and the second `resolveNestedConcreteTranslatableAttributes()`
        // call below enumerates leaves (and any wildcard positions) against
        // the actual post-update state, the same way `setColumnNestingTranslatables()`
        // resolves against post-cast data.
        parent::fillJsonAttribute($jsonAttributeKey, $value);

        $newResolvedTranslationKeys = [];

        foreach ($this->resolveNestedConcreteTranslatableAttributes($key) as $nestedConcreteTranslatableAttribute) {
            $newResolvedTranslationKey = $this->resolveTranslationKey("{$key}.{$nestedConcreteTranslatableAttribute}");
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
     * Cast the array value of a column nesting translatable attributes back into its storable form.
     *
     * @param string $key
     * @param array $value
     * @return mixed
     * @internal
     */
    protected function castColumnNestingTranslatablesArrayValue(string $key, array $value): mixed
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
