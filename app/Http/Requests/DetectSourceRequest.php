<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetectSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:1024', 'url:http,https'],
        ];
    }
}
