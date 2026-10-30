<?php

namespace Brume\Uploads\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use LogicException;
use WeakMap;

/** Exact-connection lifecycle events are authoritative; manager callbacks have no receiver affinity. */
class UploadTransactions
{
    protected WeakMap $dispatchers;

    protected WeakMap $connections;

    protected array $operations = [];

    protected int $sequence = 0;

    public function __construct()
    {
        $this->dispatchers = new WeakMap;
        $this->connections = new WeakMap;
    }

    public function register(Connection $connection, Closure $commit, Closure $rollback): void
    {
        $dispatcher = $connection->getEventDispatcher();
        if ($connection->transactionLevel() < 1 || ! $dispatcher instanceof Dispatcher) {
            throw new LogicException('Upload cleanup requires an active model transaction with connection events.');
        }
        if (! isset($this->dispatchers[$dispatcher])) {
            $dispatcher->listen(TransactionBeginning::class, fn ($event) => $this->began($event->connection));
            $dispatcher->listen(TransactionCommitted::class, fn ($event) => $this->finished($event->connection, 'commit'));
            $dispatcher->listen(TransactionRolledBack::class, fn ($event) => $this->finished($event->connection, 'rollback'));
            $this->dispatchers[$dispatcher] = true;
        }
        if (! isset($this->connections[$connection])) {
            $this->connections[$connection] = ['frames' => [], 'pending' => []];
            for ($level = 0; $level < $connection->transactionLevel(); $level++) {
                $this->connections[$connection]['frames'][] = ++$this->sequence;
            }
        }
        $frames = $this->connections[$connection]['frames'];
        $this->operations[++$this->sequence] = ['frame' => end($frames), 'commit' => $commit, 'rollback' => $rollback];
    }

    protected function began(Connection $connection): void
    {
        if (! isset($this->connections[$connection])) {
            return;
        }
        $state = $this->connections[$connection];
        $parentLevel = $connection->transactionLevel() - 1;
        if ($parentLevel < count($state['frames'])) {
            // A native completion callback began a replacement root/savepoint before
            // its predecessor's completion event. Keep that predecessor separate.
            $state['pending'][] = [
                'frames' => array_splice($state['frames'], $parentLevel),
                'parent' => $state['frames'][$parentLevel - 1] ?? null,
            ];
        }
        $state['frames'][] = ++$this->sequence;
        $this->connections[$connection] = $state;
    }

    protected function finished(Connection $connection, string $outcome): void
    {
        if (! isset($this->connections[$connection])) {
            return;
        }
        $state = $this->connections[$connection];
        $level = $connection->transactionLevel();
        if ($level < count($state['frames'])) {
            $batch = [
                'frames' => array_splice($state['frames'], $level),
                'parent' => $state['frames'][$level - 1] ?? null,
            ];
        } else {
            // Reentrant completions unwind in reverse order. An unchanged level
            // means this event belongs to the detached predecessor, not its successor.
            $batch = array_pop($state['pending']);
        }
        if ($state['frames'] === [] && $state['pending'] === []) {
            unset($this->connections[$connection]);
        } else {
            $this->connections[$connection] = $state;
        }
        if ($batch === null) {
            return;
        }
        $ids = array_keys(array_filter($this->operations, fn ($operation) => in_array($operation['frame'], $batch['frames'], true)));
        if ($outcome === 'commit' && $batch['parent'] !== null) {
            foreach ($ids as $id) {
                $this->operations[$id]['frame'] = $batch['parent'];
            }
        } else {
            $this->complete($ids, $outcome);
        }
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
