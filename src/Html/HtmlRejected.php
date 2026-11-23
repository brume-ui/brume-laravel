<?php

namespace Brume\Html;

use RuntimeException;

/**
 * Rich text HTML over `brume.html.max_bytes`, `max_depth` or `max_elements`, refused before
 * HTMLPurifier processes it.
 *
 * Save paths should call {@see HtmlSanitizer::limitViolation()} first and turn the result into a
 * validation error; this exception is the backstop. On the read path it surfaces as a server
 * error whose message tells the operator which limit to raise.
 */
final class HtmlRejected extends RuntimeException
{
    /**
     * @param  'bytes'|'depth'|'elements'  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $limit,
    ) {
        parent::__construct(match ($reason) {
            'bytes' => "Rich text HTML is larger than {$limit} bytes; raise brume.html.max_bytes (null disables the limit) to accept it.",
            'depth' => "Rich text HTML nests elements deeper than {$limit} levels; raise brume.html.max_depth (null disables the limit) to accept it.",
            default => "Rich text HTML has more than {$limit} elements; raise brume.html.max_elements (null disables the limit) to accept it.",
        });
    }
}
