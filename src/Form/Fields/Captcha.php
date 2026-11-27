<?php

namespace Brume\Form\Fields;

use Brume\Form\Conditions;
use Brume\Form\FormValidator;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * The typed answer to an image from mews/captcha. It is not a form value: it is never one of the
 * form's fields(), so it cannot reach the model, `saving` callbacks or relation planning. FormSaver
 * checks it first, with the package's `captcha` rule, and a wrong answer is a 422 on `__captcha__`.
 */
class Captcha extends Field
{
    public const NAME = '__captcha__';

    /** Test seam for the "package missing" branch; null means ask class_exists(). */
    public static ?Closure $availability = null;

    protected string $config = 'default';

    public function __construct(?string $label = null)
    {
        parent::__construct(self::NAME, $label ?? __('brume::form.captcha'));
        $this->schema['required'] = true;
    }

    public static function installed(): bool
    {
        return (self::$availability ?? fn () => class_exists(\Mews\Captcha\Captcha::class))();
    }

    public function type(): string
    {
        return 'captcha';
    }

    /** One of the named configs in config/captcha.php (`flat`, `mini`, `math`, ...). */
    public function config(string $name): static
    {
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid captcha config name.');
        }
        $this->config = $name;

        return $this;
    }

    /** Only `required` reaches the browser; `captcha` can be checked on the server alone. */
    public function serverRules(?Model $record = null, ?Model $model = null, bool $includeUnique = true): array
    {
        return ['bail', 'required', 'string', 'captcha'];
    }

    public function assertAnswer(array $values): void
    {
        $answer = $values[self::NAME] ?? null;
        if (is_string($answer)) {
            $answer = trim($answer);
            $answer = $answer === '' ? null : $answer;
        }
        FormValidator::translations();
        $messages = [];
        foreach ($this->validationMessages as $rule => $message) {
            $messages[self::NAME.'.'.$rule] = $message;
        }
        Validator::make([self::NAME => $answer], [self::NAME => $this->serverRules()], $messages, [self::NAME => $this->label])->validate();
    }

    public function toSchema(?Model $record = null): array
    {
        $src = route('brume.captcha', $this->config === 'default' ? [] : ['config' => $this->config], false);

        return ['src' => $src] + parent::toSchema($record);
    }

    public function required(bool $on = true): static
    {
        throw new LogicException('A captcha is always required.');
    }

    public function readOnly(bool|Closure $when = true): static
    {
        throw new LogicException('A captcha cannot be read-only.');
    }

    public function disabled(bool $on = true): static
    {
        throw new LogicException('A captcha cannot be disabled.');
    }

    public function showWhen(Conditions $condition): static
    {
        throw new LogicException('A captcha is always shown.');
    }

    public function enableWhen(Conditions $condition): static
    {
        throw new LogicException('A captcha is always enabled.');
    }
}
