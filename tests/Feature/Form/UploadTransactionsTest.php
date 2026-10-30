<?php

use Brume\Tests\Support\Laravel12TransactionsManager;
use Brume\Uploads\Support\UploadTransactions;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;

function uploadTransactionConnection(object $manager, string $name = 'uploads'): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => $name]);
    $connection->setTransactionManager($manager);
    $connection->setEventDispatcher(new Dispatcher);

    return $connection;
}

it('cleans committed children on outer rollback with native and pinned laravel12 managers', function ($managerClass, $outermost) {
    $connection = uploadTransactionConnection(new $managerClass);
    $calls = [];
    $registry = app(UploadTransactions::class);
    $connection->beginTransaction();
    if (! $outermost) {
        $connection->beginTransaction();
    }
    $connection->beginTransaction();
    $registry->register($connection, function () use (&$calls) {
        $calls[] = 'commit';
    }, function () use (&$calls) {
        $calls[] = 'rollback';
    });
    $connection->commit();
    $connection->rollBack($outermost ? 0 : 1);
    expect($calls)->toBe(['rollback']);
    if (! $outermost) {
        $connection->rollBack();
    }
    expect($calls)->toBe(['rollback']);
})->with([DatabaseTransactionsManager::class, Laravel12TransactionsManager::class])->with([true, false]);

it('reparents earlier committed siblings and isolates exact connections with the same name', function ($managerClass) {
    $a = uploadTransactionConnection(new $managerClass, 'same');
    $b = uploadTransactionConnection(new $managerClass, 'same');
    $registry = app(UploadTransactions::class);
    $calls = [];
    $add = function ($connection, $name) use ($registry, &$calls) {
        $registry->register($connection, function () use (&$calls, $name) {
            $calls[] = $name.' commit';
        }, function () use (&$calls, $name) {
            $calls[] = $name.' rollback';
        });
    };
    $a->beginTransaction();
    $a->beginTransaction();
    $add($a, 'earlier');
    $a->commit();
    $a->beginTransaction();
    $add($a, 'later');
    $b->beginTransaction();
    $add($b, 'other');
    $a->rollBack();
    expect($calls)->toBe(['later rollback']);
    $b->commit();
    $a->commit();
    expect($calls)->toBe(['later rollback', 'other commit', 'earlier commit']);
})->with([DatabaseTransactionsManager::class, Laravel12TransactionsManager::class]);

it('reports cleanup failures without interrupting later cleanup or changing the database outcome', function ($commit) {
    $connection = uploadTransactionConnection(new Laravel12TransactionsManager);
    $connection->statement('create table outcomes (id integer)');
    $calls = [];
    $reporter = Mockery::mock(ExceptionHandler::class);
    $reporter->shouldReceive('report')->once()->withArgs(fn ($error) => $error instanceof RuntimeException && $error->getMessage() === 'cleanup');
    app()->instance(ExceptionHandler::class, $reporter);
    $connection->beginTransaction();
    $connection->insert('insert into outcomes values (1)');
    foreach (['first', 'later'] as $name) {
        $connection->beginTransaction();
        app(UploadTransactions::class)->register($connection,
            function () use (&$calls, $name) {
                $calls[] = $name;
                if ($name === 'first') {
                    throw new RuntimeException('cleanup');
                }
            },
            function () use (&$calls, $name) {
                $calls[] = $name;
                if ($name === 'first') {
                    throw new RuntimeException('cleanup');
                }
            },
        );
        $connection->commit();
    }
    $commit ? $connection->commit() : $connection->rollBack();
    expect($calls)->toBe(['first', 'later']);
    expect($connection->selectOne('select count(*) as count from outcomes')->count)->toBe($commit ? 1 : 0);
    $connection->beginTransaction();
    $connection->rollBack();
    expect($calls)->toBe(['first', 'later']);
})->with([true, false]);

it('fails registration without connection events before invoking any cleanup', function () {
    $connection = uploadTransactionConnection(new DatabaseTransactionsManager);
    $connection->unsetEventDispatcher();
    $connection->beginTransaction();
    expect(fn () => app(UploadTransactions::class)->register($connection, fn () => null, fn () => null))->toThrow(LogicException::class);
    $connection->rollBack();
});

it('keeps one listener pair per actual dispatcher across repeated operations', function () {
    $connection = uploadTransactionConnection(new DatabaseTransactionsManager);
    $dispatcher = $connection->getEventDispatcher();
    $calls = 0;
    foreach (range(1, 20) as $iteration) {
        $connection->beginTransaction();
        app(UploadTransactions::class)->register($connection, function () use (&$calls) {
            $calls++;
        }, fn () => null);
        $connection->commit();
    }
    expect($dispatcher->getListeners(TransactionCommitted::class))->toHaveCount(1);
    expect($dispatcher->getListeners(TransactionRolledBack::class))->toHaveCount(1);
    $dispatcher->dispatch(new TransactionCommitted($connection));
    $dispatcher->dispatch(new TransactionRolledBack($connection));
    expect($calls)->toBe(20);
});

it('disposes a partially registered operation if native callbacks are unavailable', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => 'no-manager']);
    $connection->setEventDispatcher(new Dispatcher);
    $connection->beginTransaction();
    $calls = 0;
    expect(fn () => app(UploadTransactions::class)->register($connection, function () use (&$calls) {
        $calls++;
    }, function () use (&$calls) {
        $calls++;
    }))->toThrow(RuntimeException::class);
    $connection->rollBack();
    expect($calls)->toBe(0);
});

it('rejects registration outside a transaction before an immediate native callback can run', function () {
    $connection = uploadTransactionConnection(new DatabaseTransactionsManager);
    $calls = 0;
    expect(fn () => app(UploadTransactions::class)->register($connection, function () use (&$calls) {
        $calls++;
    }, fn () => null))->toThrow(LogicException::class);
    expect($calls)->toBe(0);
});

it('does not reclassify committed operations when the cleanup reporter rolls back a new transaction', function ($managerClass) {
    $connection = uploadTransactionConnection(new $managerClass);
    $calls = [];
    $reporter = Mockery::mock(ExceptionHandler::class);
    $reporter->shouldReceive('report')->once()->andReturnUsing(function () use ($connection) {
        $connection->beginTransaction();
        $connection->rollBack();
    });
    app()->instance(ExceptionHandler::class, $reporter);
    $connection->beginTransaction();
    foreach (['first', 'later'] as $name) {
        $connection->beginTransaction();
        app(UploadTransactions::class)->register($connection,
            function () use (&$calls, $name) {
                $calls[] = $name.' commit';
                if ($name === 'first') {
                    throw new RuntimeException('cleanup');
                }
            },
            function () use (&$calls, $name) {
                $calls[] = $name.' rollback';
            },
        );
        $connection->commit();
    }
    $connection->commit();
    expect($calls)->toBe(['first commit', 'later commit']);
})->with([DatabaseTransactionsManager::class, Laravel12TransactionsManager::class]);
