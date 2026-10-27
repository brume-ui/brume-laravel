<?php

use Brume\Console\Support\PackageJsonFile;

it('rejects files it cannot safely edit', function (string $json, string $message) {
    expect(fn () => PackageJsonFile::parse($json))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'invalid JSON' => ['{"a": 1,}', 'package.json 不是有效的 JSON（'],
    'empty file' => ['', 'package.json 不是有效的 JSON（'],
    'array' => ['[]', 'package.json 的最外層必須是物件'],
    'string' => ['"x"', 'package.json 的最外層必須是物件'],
    'scripts not an object' => ['{"scripts": "vite"}', 'package.json 的 "scripts" 必須是物件'],
]);

it('round-trips a file in its own style', function (string $original) {
    expect(PackageJsonFile::format(PackageJsonFile::parse($original), $original))->toBe($original);
})->with([
    'two spaces with newline' => ["{\n  \"name\": \"shop\",\n  \"dependencies\": {},\n  \"files\": [\n    \"dist\"\n  ]\n}\n"],
    'four spaces without newline' => ["{\n    \"name\": \"shop\",\n    \"scripts\": {\n        \"dev\": \"vite\"\n    }\n}"],
    'tabs' => ["{\n\t\"name\": \"shop\",\n\t\"devDependencies\": {\n\t\t\"vite\": \"^8.0.0\"\n\t}\n}\n"],
    'CRLF' => ["{\r\n  \"name\": \"shop\",\r\n  \"private\": true\r\n}\r\n"],
]);

it('writes a new file with two spaces and a trailing newline', function () {
    expect(PackageJsonFile::format((object) ['private' => true, 'scripts' => (object) ['dev' => 'vite']]))
        ->toBe("{\n  \"private\": true,\n  \"scripts\": {\n    \"dev\": \"vite\"\n  }\n}\n");
});
