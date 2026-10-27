<?php

namespace Brume\Console\Concerns;

/**
 * Laravel's console components append "." to messages that do not end in ASCII punctuation,
 * which turns Chinese copy into "。." — so Brume prints its info/warn/error lines itself,
 * in the same layout.
 */
trait WritesMessages
{
    protected function message(string $level, string $message): void
    {
        $style = match ($level) {
            'info' => 'bg=blue;fg=white',
            'warn' => 'bg=yellow;fg=black',
            'error' => 'bg=red;fg=white',
        };

        $this->newLine();
        $this->line(sprintf('  <%s;options=bold> %s </> %s', $style, strtoupper($level), $message));
        $this->newLine();
    }
}
