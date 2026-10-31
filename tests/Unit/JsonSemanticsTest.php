<?php

use Brume\Tests\Support\JsonSemantics;

it('compares JSON object properties independently of database serialization order', function () {
    expect(JsonSemantics::canonical('{"b":{"y":2,"x":1},"a":[]}'))->toBe(JsonSemantics::canonical('{ "a": [], "b": {"x":1,"y":2} }'));
});

it('does not normalize away JSON container list or scalar distinctions', function ($a, $b) {
    expect(JsonSemantics::canonical($a))->not->toBe(JsonSemantics::canonical($b));
})->with([
    ['{}', '[]'], ['[1,2]', '[2,1]'], ['"1"', '1'], ['1', '1.0'], ['null', 'false'], ['{"a":[{}]}', '{"a":[[]]}'],
]);
