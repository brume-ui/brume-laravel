<?php

namespace Brume\Html;

use RuntimeException;

/**
 * Rich text HTML over `brume.html.max_bytes` or `brume.html.max_depth`, refused before parsing.
 *
 * Save paths should call {@see HtmlSanitizer::limitViolation()} first and turn the result into a
 * validation error; this exception is the backstop. On the read path it surfaces as a server
 * error whose message tells the operator which limit to raise.
 */
final class HtmlRejected extends RuntimeException
{
    /**
     * @param  'bytes'|'depth'  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $limit,
    ) {
        parent::__construct($reason === 'bytes'
            ? "Rich text HTML is larger than {$limit} bytes; raise brume.html.max_bytes (null disables the limit) to accept it."
            : "Rich text HTML nests elements deeper than {$limit} levels; raise brume.html.max_depth (null disables the limit) to accept it.");
    }
}
