<?php

declare(strict_types=1);

namespace App\Http\Requests\Agency;

use App\Domain\Publishing\Enums\RecurrenceFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new recurring rule.
 *
 * The cadence fields are conditionally required, which is the whole reason this
 * is a form request rather than an inline validate(): a weekly rule with no
 * weekdays, or a monthly one with no day, saves cleanly, looks active on the
 * list, and generates nothing at all. A rule that silently does nothing is the
 * worst outcome this feature has, so it is refused at the door.
 */
final class StoreRecurringPostRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('posts.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'customer_id' => ['required', 'integer'],

            'title' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],

            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],

            /*
             | Capped at 52. "Every 400 weeks" is not a cadence anybody means,
             | and an unbounded interval is a rule whose next occurrence lies
             | beyond every horizon -- active, and permanently silent.
             */
            'interval' => ['required', 'integer', 'min:1', 'max:52'],

            /*
             | ISO-8601 weekday numbers, 1 = Monday .. 7 = Sunday, matching
             | Carbon's dayOfWeekIso. Deliberately NOT Carbon::SUNDAY, which is
             | 0 and would match no day.
             */
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,7'],

            'day_of_month' => ['nullable', 'integer', 'between:1,31'],

            'time_of_day' => ['required', 'date_format:H:i'],

            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],

            'accounts' => ['array'],
            'accounts.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $frequency = RecurrenceFrequency::tryFrom((string) $this->input('frequency'));

            if ($frequency?->usesWeekdays() === true && $this->input('weekdays', []) === []) {
                $validator->errors()->add(
                    'weekdays',
                    'Choose at least one day, or this rule will never post anything.',
                );
            }

            if ($frequency?->usesDayOfMonth() === true && $this->input('day_of_month') === null) {
                $validator->errors()->add(
                    'day_of_month',
                    'Choose a day of the month.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_on.after_or_equal' => 'A rule cannot start in the past.',
            'ends_on.after' => 'The end date has to come after the start date.',
            'body.required' => 'A rule needs something to post.',
        ];
    }
}
