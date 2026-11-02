<?php

namespace App\Http\Requests;

use App\Models\Veille;
use Illuminate\Validation\Rules\Unique;

class UpdateVeilleRequest extends StoreVeilleRequest
{
    public function authorize(): bool
    {
        $veille = $this->route('veille');

        return $veille instanceof Veille && $this->user()?->can('update', $veille) === true;
    }

    protected function nameUniqueRule(): Unique
    {
        return parent::nameUniqueRule()->ignore($this->route('veille'));
    }
}
