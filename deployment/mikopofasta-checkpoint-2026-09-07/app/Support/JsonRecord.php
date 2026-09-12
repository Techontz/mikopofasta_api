<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Emits a key/value map as a JSON OBJECT, even when it is empty.
 *
 * WHY THIS EXISTS. PHP has one array type and JSON has two container types, so
 * the distinction between `{}` and `[]` does not survive a round trip:
 *
 *     json_decode('{"dynamicFormData":{}}', true)  ->  ['dynamicFormData' => []]
 *     json_encode(['dynamicFormData' => []])       ->  '{"dynamicFormData":[]}'
 *
 * An empty record goes out as an empty ARRAY. Everything downstream that is
 * typed — the registration wizard's schema says `Record<string, …>` — then
 * refuses it with "expected record, received array", and it refuses it only in
 * the empty case, which is why it survives every test with data in it and fails
 * the first time somebody saves a draft before answering any of the questions.
 *
 * Casting to an object is the whole fix. `(object) []` encodes as `{}`, and
 * `(object) ['a' => 1]` encodes as `{"a":1}` — the populated case is unchanged,
 * which is what makes this safe to apply to every emitter of a record.
 *
 * A LIST BECOMES A KEYED OBJECT rather than being discarded: `['x','y']` goes
 * out as `{"0":"x","1":"y"}`. That shape is wrong for a record, but it is wrong
 * data that was already stored, and reporting it faithfully as a record beats
 * either dropping it silently or emitting a container the client refuses.
 *
 * Null passes through. A record that has never been set is absent, not empty,
 * and the two mean different things to a client that distinguishes them.
 */
final class JsonRecord
{
    /**
     * @param array<array-key, mixed>|null $value
     */
    public static function from(?array $value): ?object
    {
        return $value === null ? null : (object) $value;
    }
}
