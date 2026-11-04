<?php

namespace Workbench\App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Workbench\App\Models\PromotionFreebie;

/**
 * A gift's limit is a positive integer and never below what the matched row has already handed
 * out. The floor is the stored already_given_count of the existing (or restored soft-deleted)
 * row; a new association has no history, so its floor is 1. Submitted counters are never read.
 */
final class GiftLimit implements ValidationRule
{
    private function __construct(private readonly int $given) {}

    public static function for(?PromotionFreebie $freebie): self
    {
        return new self((int) $freebie?->getRawOriginal('already_given_count'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[0-9]+$/D', $value))) {
            $fail('限量數須為正整數');

            return;
        }
        if ((int) $value < 1) {
            $fail('限量數至少為 1');

            return;
        }
        if ((int) $value < $this->given) {
            $fail("限量數不可小於已送出的 {$this->given} 份");
        }
    }
}
