<?php

use Brume\BrumeServiceProvider;
use Brume\Support\Color;
use Brume\Tests\TestCase;
use Illuminate\Support\Facades\Log;

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

it('makes the provider throw in local for an accent that is not a hex color', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['brume.layout.accent' => 'red; background: url(x)']);

    (new BrumeServiceProvider(app()))->boot();
})->throws(RuntimeException::class, '#rrggbb');

it('logs a warning and falls back to the default accent outside local for a non-hex value', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['brume.layout.accent' => 'red; background: url(x)']);
    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, '#rrggbb'));

    (new BrumeServiceProvider(app()))->boot();

    expect(config('brume.layout.accent'))->toBe('#0e7490');
});

it('accepts #rgb and #rrggbb accents', function () {
    expect(Color::isHex('#0e7490'))->toBeTrue()
        ->and(Color::isHex('#FFF'))->toBeTrue()
        ->and(Color::isHex('0e7490'))->toBeFalse()
        ->and(Color::isHex('#0e749'))->toBeFalse()
        ->and(Color::isHex('red'))->toBeFalse();
});
