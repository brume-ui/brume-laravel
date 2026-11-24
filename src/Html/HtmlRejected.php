<?php

namespace Brume\Html;

use RuntimeException;

/**
 * Rich text HTML over one of the `brume.html` limits (`max_bytes`, `max_depth`, `max_elements`,
 * `max_attribute_bytes`, `max_classes`), refused before HTMLPurifier processes it.
 *
 * Save paths should call {@see HtmlSanitizer::limitViolation()} first and turn the result into a
 * validation error; this exception is the backstop. On the read path it surfaces as a server
 * error whose message tells the operator which limit to raise.
 */
final class HtmlRejected extends RuntimeException
{
    /**
     * @param  'bytes'|'depth'|'elements'|'attributes'  $reason
     * @param  int  $limit  the limit that was passed (for 'attributes': bytes of one value, classes on one element, or words in one tag)
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $limit,
    ) {
        parent::__construct(match ($reason) {
            'bytes' => "Rich text HTML is larger than {$limit} bytes, or cleaning it would produce several times that; raise brume.html.max_bytes (null disables the limit) to accept it.",
            'depth' => "Rich text HTML nests elements deeper than {$limit} levels; raise brume.html.max_depth (null disables the limit) to accept it.",
            'attributes' => "Rich text HTML has an attribute value longer than brume.html.max_attribute_bytes, more classes on one element than brume.html.max_classes, or a tag with more words (attribute names and the words in their values) than max_attribute_bytes / 8; the limit passed is {$limit}. Raise those limits (null disables them) to accept it.",
            default => "Rich text HTML has more than {$limit} elements; raise brume.html.max_elements (null disables the limit) to accept it.",
        });
    }
}
