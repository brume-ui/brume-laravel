<?php

use Brume\Facades\Brume;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(config('brume.route.middleware'))
        ->get('/admin/_toast', function () {
            Brume::toast('success', '已儲存');

            return redirect('/admin');
        });
});

it('shares a toast on the next response', function () {
    $this->actingAs(brumeUser(), 'brume');

    $flash = $this->followingRedirects()->get('/admin/_toast')
        ->viewData('page')['props']['brume']['flash'];

    expect($flash['type'])->toBe('success')
        ->and($flash['message'])->toBe('已儲存')
        ->and($flash['id'])->toBeString()->not->toBeEmpty();
});

it('flash ids are unique for identical messages', function () {
    $this->actingAs(brumeUser(), 'brume');

    $first = $this->followingRedirects()->get('/admin/_toast')->viewData('page')['props']['brume']['flash'];
    $second = $this->followingRedirects()->get('/admin/_toast')->viewData('page')['props']['brume']['flash'];

    expect($first['message'])->toBe('已儲存')
        ->and($second['message'])->toBe($first['message'])
        ->and($second['id'])->not->toBe($first['id']);
});

it('clears the flash after it has been shown once', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->followingRedirects()->get('/admin/_toast');

    expect($this->get('/admin')->viewData('page')['props']['brume']['flash'])->toBeNull();
});

it('rejects an unknown toast type', function () {
    Brume::toast('fatal', 'x');
})->throws(InvalidArgumentException::class);
