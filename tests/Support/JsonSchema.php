<?php

namespace Brume\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates PHP output against resources/schema/v0.json (copied from @herb09638/brume-schema).
 * The document has no $id, so it is registered under a fixed URI and definitions are
 * addressed by JSON pointer, e.g. JsonSchema::errors($props, 'SharedProps').
 */
final class JsonSchema
{
    public const URI = 'https://brume.local/schema/v0.json';

    /**
     * @return list<string> empty when $data matches #/$defs/{$definition}
     */
    public static function errors(mixed $data, string $definition): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(20);
        $validator->resolver()->registerFile(self::URI, dirname(__DIR__, 2).'/resources/schema/v0.json');

        // Round-trip through JSON so PHP arrays become the objects/arrays the frontend receives.
        $json = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $validator->validate($json, self::URI.'#/$defs/'.$definition);

        if ($result->isValid()) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $errors) {
            foreach ($errors as $error) {
                $messages[] = "{$path}: {$error}";
            }
        }

        return $messages;
    }
}
