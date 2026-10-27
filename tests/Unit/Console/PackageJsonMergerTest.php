<?php

use Brume\Console\Support\PackageJsonMerger;

$ours = [
    'dependencies' => ['react' => '19.3.1', '@herb09638/brume-react' => '0.1.0-alpha.0'],
    'devDependencies' => ['vite' => '8.3.5'],
];

it('decides per package', function (array $host, bool $upgrade, array $expected) use ($ours) {
    $result = (new PackageJsonMerger)->merge($host, $ours, $upgrade);

    expect([
        'added' => array_column($result->added, 'version', 'name'),
        'raised' => array_column($result->raised, 'to', 'name'),
        'conflicts' => array_column($result->conflicts, 'newer', 'name'),
        'unverified' => array_column($result->unverified, 'host', 'name'),
    ])->toBe([...['added' => [], 'raised' => [], 'conflicts' => [], 'unverified' => []], ...$expected]);
})->with([
    'empty project gets everything' => [[], false, [
        'added' => ['react' => '19.3.1', '@herb09638/brume-react' => '0.1.0-alpha.0', 'vite' => '8.3.5'],
    ]],
    'same major caret, tilde, exact and >= are compatible' => [[
        'dependencies' => ['react' => '^19.0.0', '@herb09638/brume-react' => '0.1.0-alpha.0'],
        'devDependencies' => ['vite' => '>=7.0.0'],
    ], false, []],
    'tilde and exact on the same major count as compatible' => [[
        'dependencies' => ['react' => '~19.1.0', '@herb09638/brume-react' => '~0.1.2'],
        'devDependencies' => ['vite' => '8.0.1'],
    ], false, []],
    'older major is a conflict without upgrade' => [[
        'dependencies' => ['react' => '^18.3.1', '@herb09638/brume-react' => '0.1.0-alpha.0'],
        'devDependencies' => ['vite' => '^7.0.4'],
    ], false, ['conflicts' => ['react' => false, 'vite' => false]]],
    'older 0.x minor is a conflict' => [[
        'dependencies' => ['react' => '19.3.1', '@herb09638/brume-react' => '^0.0.9'],
        'devDependencies' => ['vite' => '8.3.5'],
    ], false, ['conflicts' => ['@herb09638/brume-react' => false]]],
    'upgrade raises older specs and keeps the operator' => [[
        'dependencies' => ['react' => '~18.3.1', '@herb09638/brume-react' => '0.0.9'],
        'devDependencies' => ['vite' => '^7.0.4'],
    ], true, ['raised' => ['react' => '~19.3.1', '@herb09638/brume-react' => '0.1.0-alpha.0', 'vite' => '^8.3.5']]],
    'newer major is a conflict, even with upgrade' => [[
        'dependencies' => ['react' => '^20.0.0', '@herb09638/brume-react' => '0.1.0-alpha.0'],
        'devDependencies' => ['vite' => '>=9.0.0'],
    ], true, ['conflicts' => ['react' => true, 'vite' => true]]],
    'unparseable specs are left alone' => [[
        'dependencies' => ['react' => 'latest', '@herb09638/brume-react' => 'workspace:*'],
        'devDependencies' => ['vite' => '^7 || ^8'],
    ], false, ['unverified' => ['react' => 'latest', '@herb09638/brume-react' => 'workspace:*', 'vite' => '^7 || ^8']]],
    'a package in the other section is found, not added twice' => [[
        'devDependencies' => ['react' => '^19.2.0', '@herb09638/brume-react' => '0.1.0-alpha.0'],
        'dependencies' => ['vite' => '^8.0.0'],
    ], false, []],
]);

it('adds missing packages to the section Brume uses', function () use ($ours) {
    $result = (new PackageJsonMerger)->merge(['dependencies' => ['lodash' => '^4.17.21']], $ours);

    expect(array_column($result->added, 'section', 'name'))->toBe([
        'react' => 'dependencies',
        '@herb09638/brume-react' => 'dependencies',
        'vite' => 'devDependencies',
    ])->and($result->changesPackageJson())->toBeTrue();
});

it('raises a spec in the section where the project keeps it', function () use ($ours) {
    $result = (new PackageJsonMerger)->merge(['devDependencies' => ['react' => '^18.0.0']], $ours, upgrade: true);

    expect($result->raised[0])->toBe(['section' => 'devDependencies', 'name' => 'react', 'from' => '^18.0.0', 'to' => '^19.3.1']);
});
