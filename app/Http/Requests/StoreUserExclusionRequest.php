<?php

namespace App\Http\Requests;

use App\Models\UserExclusion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation des règles d'exclusion personnelles (M9.1). */
class StoreUserExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'kind' => ['required', Rule::in([UserExclusion::KIND_URL, UserExclusion::KIND_KEYWORD])],
            'pattern' => [
                'required', 'string', 'max:500',
                Rule::unique('user_exclusions', 'pattern')
                    ->where('kind', $this->input('kind'))
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('exclusion')?->id),
            ],
        ];

        if ($this->input('kind') === UserExclusion::KIND_URL) {
            $rules['subtype'] = ['required', Rule::in(['domain', 'subdomain', 'exact', 'path'])];
            $rules['pattern'][] = function (string $attribute, mixed $value, \Closure $fail): void {
                $this->validateUrlPattern((string) $value, (string) $this->input('subtype'), $fail);
            };
        }

        return $rules;
    }

    private function validateUrlPattern(string $pattern, string $subtype, \Closure $fail): void
    {
        $ok = match ($subtype) {
            'domain' => preg_match('/^([a-zA-Z0-9](-?[a-zA-Z0-9])*\.)+[a-zA-Z]{2,63}$/', $pattern) === 1,
            'subdomain' => str_starts_with($pattern, '*.')
                && preg_match('/^([a-zA-Z0-9](-?[a-zA-Z0-9])*\.)+[a-zA-Z]{2,63}$/', substr($pattern, 2)) === 1,
            'exact' => filter_var($pattern, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', $pattern) === 1,
            'path' => str_starts_with($pattern, '/') && ! str_contains($pattern, '://'),
            default => false,
        };
        if (! $ok) {
            $fail('La valeur ne correspond pas au type de règle choisi.');
        }
    }
}
