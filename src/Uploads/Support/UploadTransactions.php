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
            $this->connections[$connection] = ['frames' => [], 'pending' => [], 'outcomes' => []];
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
        if ($outcome === 'commit' && count($state['frames']) > $level + 1) {
            // A commit decrements exactly one level. Higher vanished frames belong
            // to delayed descendant events, never to this ancestor's commit.
            $state['pending'][] = [
                'frames' => array_splice($state['frames'], $level + 1),
                'parent' => $state['frames'][$level] ?? null,
            ];
        }
        if ($level < count($state['frames'])) {
            $batch = [
                'frames' => array_splice($state['frames'], $level),
                'parent' => $state['frames'][$level - 1] ?? null,
            ];
        } else {
            $batch = array_pop($state['pending']);
            if ($outcome === 'commit' && $batch !== null && count($batch['frames']) > 1) {
                // Reentrant ancestor events unwind before their descendants. Only
                // the outermost detached frame belongs to this commit event.
                $frame = array_shift($batch['frames']);
                $state['pending'][] = ['frames' => $batch['frames'], 'parent' => $frame];
                $batch['frames'] = [$frame];
            }
        }
        if ($batch === null) {
            return;
        }
        $parent = $batch['parent'];
        while ($parent !== null && isset($state['outcomes'][$parent]) && is_int($state['outcomes'][$parent])) {
            $parent = $state['outcomes'][$parent];
        }
        $terminal = $outcome === 'rollback' ? 'rollback' : ($state['outcomes'][$parent] ?? ($parent === null ? 'commit' : null));
        foreach ($batch['frames'] as $frame) {
            $state['outcomes'][$frame] = $terminal ?? $parent;
        }
        $ids = array_keys(array_filter($this->operations, fn ($operation) => in_array($operation['frame'], $batch['frames'], true)));
        if ($terminal === null) {
            foreach ($ids as $id) {
                $this->operations[$id]['frame'] = $parent;
            }
        }
        // Retain only ancestor outcomes referenced by delayed batches. Ordinary
        // sibling savepoints cannot grow an unbounded history inside a long root.
        $needed = [];
        foreach ($state['pending'] as $pending) {
            $ancestor = $pending['parent'];
            while ($ancestor !== null && isset($state['outcomes'][$ancestor]) && ! isset($needed[$ancestor])) {
                $needed[$ancestor] = $state['outcomes'][$ancestor];
                $ancestor = is_int($needed[$ancestor]) ? $needed[$ancestor] : null;
            }
        }
        $state['outcomes'] = $needed;
        if ($state['frames'] === [] && $state['pending'] === []) {
            unset($this->connections[$connection]);
        } else {
            $this->connections[$connection] = $state;
        }
        if ($terminal !== null) {
            $this->complete($ids, $terminal);
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
