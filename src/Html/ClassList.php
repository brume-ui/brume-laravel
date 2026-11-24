<?php

namespace Brume\Html;

use HTMLPurifier_AttrDef_HTML_Class;

/**
 * HTMLPurifier's class attribute, with duplicates dropped through a hash lookup. The stock
 * definition searches the classes kept so far for each new one, which is quadratic in their
 * number (6 s for one element with 60 000 distinct classes).
 *
 * @internal
 */
final class ClassList extends HTMLPurifier_AttrDef_HTML_Class
{
    /**
     * @param  array<string>  $tokens
     * @param  \HTMLPurifier_Config  $config
     * @param  \HTMLPurifier_Context  $context
     * @return list<string>
     */
    protected function filter($tokens, $config, $context): array
    {
        $allowed = $config->get('Attr.AllowedClasses');
        $forbidden = $config->get('Attr.ForbiddenClasses');
        $kept = [];
        foreach ($tokens as $token) {
            if (($allowed === null || isset($allowed[$token])) && ! isset($forbidden[$token])) {
                // The prefix keeps numeric class names apart as array keys ("1" and "01").
                $kept['c'.$token] = $token;
            }
        }

        return array_values($kept);
    }
}
