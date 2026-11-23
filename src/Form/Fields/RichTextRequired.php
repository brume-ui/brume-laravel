<?php

namespace Brume\Form\Fields;

use Brume\Html\HtmlSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * @internal Server half of "required" for rich text. Laravel's own required rule already rejects
 * null and blank strings; this adds documents that only look empty (`<p><br></p>`,
 * `<p>&nbsp;</p>`), judged on the submitted HTML by HtmlSanitizer::isEmpty — the same rule as
 * the client's isEmptyHtml. A document that sanitises to nothing (only a script, say) fails too,
 * so a required field is never stored as null. The failure is recorded as Laravel's Required
 * rule, so the message (and any custom `required` message) is exactly required's.
 */
final class RichTextRequired implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || (! HtmlSanitizer::isEmpty($value) && app(HtmlSanitizer::class)->clean($value) !== null)) {
            return;
        }
        if ($this->validator !== null) {
            $this->validator->addFailure($attribute, 'Required');
        } else {
            $fail('validation.required')->translate();
        }
    }
}
