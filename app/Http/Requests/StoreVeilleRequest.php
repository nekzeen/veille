<?php

namespace App\Http\Requests;

use App\Models\Source;
use App\Models\UserSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class StoreVeilleRequest extends FormRequest
{
    /** Garde-fou applicatif : nombre maximal de mots-clés par veille. */
    private const MAX_TERMS = 50;

    /**
     * Taille maximale du champ : 50 termes de 255 caractères +
     * 49 séparateurs de ligne au pire (CRLF) — borné à 12848.
     */
    private const MAX_KEYWORDS_LENGTH = self::MAX_TERMS * 255 + 49 * 2;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->nameUniqueRule()],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'keywords' => ['nullable', 'string', 'max:'.self::MAX_KEYWORDS_LENGTH],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['integer', 'distinct', Rule::exists('sources', 'id')],
        ];
    }

    /** Unicité du nom scopée aux veilles de l'utilisateur (UNIQUE user_id+name). */
    protected function nameUniqueRule(): Unique
    {
        return Rule::unique('veilles', 'name')
            ->where('user_id', $this->user()->id);
    }

    /**
     * Second niveau : longueur des termes normalisés et accessibilité
     * effective des sources (catalogue ou abonnement personnel, §3.6/§5).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $terms = $this->terms();
            if (count($terms) > self::MAX_TERMS) {
                $validator->errors()->add(
                    'keywords',
                    'Une veille accepte au maximum '.self::MAX_TERMS.' mots-clés.',
                );
            }
            foreach ($terms as $term) {
                if (mb_strlen($term) > 255) {
                    $validator->errors()->add('keywords', 'Chaque mot-clé doit faire au plus 255 caractères.');
                    break;
                }
            }

            $this->validateSourcesAccessible($validator);
        });
    }

    /**
     * Termes saisis — un par ligne — normalisés selon §3.5 (trim +
     * minuscules) ; lignes vides et doublons éliminés.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        // keywords[]=… (tableau) est rejeté par la règle `string` ;
        // on ne dérive des termes que d'une vraie chaîne, sans cast.
        $keywords = $this->input('keywords');
        if (! is_string($keywords)) {
            return [];
        }

        $terms = [];
        foreach (preg_split('/\r\n|\r|\n/', $keywords) ?: [] as $line) {
            $term = mb_strtolower(trim($line), 'UTF-8');
            if ($term !== '' && ! in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /** @return list<int> */
    public function sourceIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('sources', [])));
    }

    /** Refuse toute source ni catalogue ni couverte par un abonnement. */
    private function validateSourcesAccessible(Validator $validator): void
    {
        $ids = $this->sourceIds();
        if ($ids === []) {
            return;
        }

        $sources = Source::whereIn('id', $ids)->get()->keyBy('id');
        $subscribed = UserSource::where('user_id', $this->user()?->id)
            ->whereIn('source_id', $ids)->pluck('source_id')->flip();

        foreach ($ids as $sourceId) {
            $source = $sources->get($sourceId);
            if ($source === null) {
                continue; // déjà rejetée par la règle exists
            }
            if (! $source->is_catalogue && ! $subscribed->has($sourceId)) {
                $validator->errors()->add('sources', 'Une ou plusieurs sources sélectionnées ne sont pas accessibles.');

                return;
            }
        }
    }
}
