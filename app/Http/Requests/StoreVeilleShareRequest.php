<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VeilleShare;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation d'un partage (M10_Conception_Partage §4) :
 * scope single ⇒ veille_id obligatoire ET appartenant au user ;
 * scope all ⇒ veille_id interdit. Label court, expiration future.
 */
class StoreVeilleShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:single,all'],
            'veille_id' => [
                'prohibited_if:scope,all',
                'required_if:scope,single',
                'integer',
                'exists:veilles,id,user_id,'.$this->user()->id,
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'veille_id.required_if' => 'Choisissez une veille pour un partage individuel.',
            'veille_id.exists' => 'Cette veille ne vous appartient pas.',
            'expires_at.after' => 'L\u2019expiration doit être dans le futur.',
        ];
    }

    public function veilleId(): ?int
    {
        return $this->validated('scope') === VeilleShare::SCOPE_SINGLE
            ? (int) $this->validated('veille_id')
            : null;
    }
}
