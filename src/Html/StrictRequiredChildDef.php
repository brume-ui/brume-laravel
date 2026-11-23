<?php

namespace Brume\Html;

use HTMLPurifier_ChildDef_Required;

/**
 * HTMLPurifier's Required child definition, but children that are only whitespace once the
 * invalid ones are dropped count as none, like children that were only whitespace to begin
 * with. Otherwise `<tr>z </tr>` would keep an empty row on the first pass and lose it (and its
 * table) on the second, so cleaning would not be idempotent.
 *
 * @internal
 */
final class StrictRequiredChildDef extends HTMLPurifier_ChildDef_Required
{
    /**
     * @param  array<\HTMLPurifier_Node>  $children
     * @param  \HTMLPurifier_Config  $config
     * @param  \HTMLPurifier_Context  $context
     * @return array<\HTMLPurifier_Node>|bool
     */
    public function validateChildren($children, $config, $context): array|bool
    {
        $result = parent::validateChildren($children, $config, $context);
        if (is_array($result)) {
            foreach ($result as $node) {
                if (empty($node->is_whitespace)) {
                    return $result;
                }
            }
            $this->whitespace = true;

            return false;
        }

        return $result;
    }
}
