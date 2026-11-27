<?php

namespace Brume\Form\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;

/** An IPv4 or IPv6 address, stored trimmed. No zone id, brackets, port or CIDR; IPv4 has no leading zeros. */
class Ip extends Field
{
    public function type(): string
    {
        return 'ip';
    }

    public function v4(): static
    {
        $this->schema['version'] = 4;

        return $this;
    }

    public function v6(): static
    {
        $this->schema['version'] = 6;

        return $this;
    }

    /**
     * The browser trims before it checks, so the check runs on the trimmed text here too (Laravel's
     * own `ip` rule would refuse a padded address). `filter_var` agrees with the browser's
     * hand-written parser on every IPv4 and IPv6 form, including leading zeros and zone ids.
     */
    public function impliedRules(): array
    {
        $flags = match ($this->schema['version'] ?? null) {
            4 => FILTER_FLAG_IPV4,
            6 => FILTER_FLAG_IPV6,
            default => FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6,
        };
        $key = match ($this->schema['version'] ?? null) {
            4 => 'ipv4',
            6 => 'ipv6',
            default => 'ip',
        };

        return [...parent::impliedRules(), function ($attribute, $value, Closure $fail) use ($flags, $key) {
            if (! is_string($value)) {
                $fail('validation.'.$key)->translate();

                return;
            }
            $text = self::trim($value);
            if ($text !== '' && filter_var($text, FILTER_VALIDATE_IP, $flags) === false) {
                $fail($this->validationMessages[$key] ?? 'validation.'.$key)->translate();
            }
        }];
    }

    public static function trim(string $value): string
    {
        return self::jsTrim($value);
    }

    public function normalizeBlank(mixed $input): mixed
    {
        return self::blankToNull($input);
    }

    public function canonicalInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $text = self::trim($value);

        return $text === '' ? null : $text;
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }
}
