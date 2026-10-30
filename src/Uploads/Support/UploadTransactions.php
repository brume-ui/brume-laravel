<?php

namespace Brume\Uploads\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use LogicException;
use WeakMap;

/** Exact-connection lifecycle events are authoritative; manager callbacks have no receiver affinity. */
class UploadTransactions
{
    protected WeakMap $dispatchers;

    protected array $operations = [];

    protected int $sequence = 0;

    public function __construct()
    {
        $this->dispatchers = new WeakMap;
    }

    public function register(Connection $connection, Closure $commit, Closure $rollback): void
    {
        $dispatcher = $connection->getEventDispatcher();
        if ($connection->transactionLevel() < 1 || ! $dispatcher instanceof Dispatcher) {
            throw new LogicException('Upload cleanup requires an active model transaction with connection events.');
        }
        if (! isset($this->dispatchers[$dispatcher])) {
            $dispatcher->listen(TransactionCommitted::class, fn ($event) => $this->committed($event->connection));
            $dispatcher->listen(TransactionRolledBack::class, fn ($event) => $this->rolledBack($event->connection));
            $this->dispatchers[$dispatcher] = true;
        }
        $id = ++$this->sequence;
        $this->operations[$id] = ['connection' => $connection, 'level' => $connection->transactionLevel(), 'commit' => $commit, 'rollback' => $rollback];

    }

    protected function committed(Connection $connection): void
    {
        $level = $connection->transactionLevel();
        if ($level === 0) {
            $this->complete($this->matching($connection), 'commit');

            return;
        }
        foreach ($this->matching($connection) as $id) {
            $this->operations[$id]['level'] = min($this->operations[$id]['level'], $level);
        }
    }

    protected function rolledBack(Connection $connection): void
    {
        $this->complete($this->matching($connection, $connection->transactionLevel()), 'rollback');
    }

    protected function matching(Connection $connection, ?int $aboveLevel = null): array
    {
        return array_keys(array_filter($this->operations, fn ($operation) => $operation['connection'] === $connection && ($aboveLevel === null || $operation['level'] > $aboveLevel)));
    }

    protected function complete(array $ids, string $outcome): void
    {
        $callbacks = [];
        // Dispose the entire terminal batch before cleanup/reporting can start a new transaction.
        foreach ($ids as $id) {
            $callbacks[] = $this->operations[$id][$outcome];
            unset($this->operations[$id]);
        }
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Persistence has already completed; report storage cleanup failures without inviting a retry.
                report($e);
            }
        }
    }
}
