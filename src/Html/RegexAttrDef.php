<?php

namespace Brume\Html;

use HTMLPurifier_AttrDef;

/**
 * Accepts a CSS value only when the whole (trimmed, lowercased) value matches a fixed pattern.
 * Used for CSS3 properties HTMLPurifier does not know. Patterns must be anchored and must not
 * admit `url(`, quotes, backslashes or `;`.
 *
 * @internal
 */
final class RegexAttrDef extends HTMLPurifier_AttrDef
{
    public function __construct(private readonly string $pattern) {}

    /**
     * @param  string  $string
     * @param  \HTMLPurifier_Config  $config
     * @param  \HTMLPurifier_Context  $context
     */
    public function validate($string, $config, $context): string|false
    {
        $value = strtolower(trim($this->parseCDATA($string)));

        return preg_match($this->pattern, $value) === 1 ? $value : false;
    }
}
