<?php

use Brume\BrumeServiceProvider;
use Brume\Support\Color;
use Brume\Tests\TestCase;

uses(TestCase::class);

it('computes the WCAG contrast of white on the default accent', function () {
    expect(Color::contrast('#ffffff', '#0e7490'))->toBeGreaterThan(5.3);
});

it('is symmetric and accepts short hex', function () {
    expect(Color::contrast('#fff', '#000'))->toEqualWithDelta(21.0, 0.0001)
        ->and(Color::contrast('#0e7490', '#ffffff'))->toBe(Color::contrast('#ffffff', '#0e7490'));
});

it('makes the provider throw in local when the accent fails 4.5:1 against white', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['brume.layout.accent' => '#7dd3fc']);

    (new BrumeServiceProvider(app()))->boot();
})->throws(RuntimeException::class, '4.5:1');

it('does not throw outside local for a low-contrast accent', function () {
    config(['brume.layout.accent' => '#7dd3fc']);

    (new BrumeServiceProvider(app()))->boot();

    expect(true)->toBeTrue();
});
