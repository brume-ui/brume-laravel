<?php

namespace Workbench\App\Support;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Workbench\App\Models\PromotionAction;

/**
 * 任選 N 樣: N is a positive integer no larger than this action's gift count. The count is the
 * row-local submitted freebies list when present, otherwise the action's stored active gifts
 * (a new action has none, so any N fails until gifts are submitted with it).
 */
final class ChoiceCount implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> the validated action row */
    private array $row = [];

    public function __construct(private readonly ?PromotionAction $action) {}

    public function setData(array $data): static
    {
        $this->row = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[0-9]+$/D', $value))) {
            $fail('任選數量須為正整數');

            return;
        }
        if ((int) $value < 1) {
            $fail('任選數量至少為 1');

            return;
        }
        $available = array_key_exists('freebies', $this->row)
            ? (is_array($this->row['freebies']) ? count($this->row['freebies']) : 0)
            : ($this->action?->freebies()->count() ?? 0);
        if ((int) $value > $available) {
            $fail("任選數量不可大於贈品數 {$available} 樣");
        }
    }
}
