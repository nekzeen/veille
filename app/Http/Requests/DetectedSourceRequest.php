<?php

namespace App\Http\Requests;

use App\Services\Feed\Html\HtmlExtractor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * URL + configuration HTML facultative (M8.5) — utilisée par la
 * prévisualisation et par l'enregistrement. Seuls les champs
 * documentés passent ; les sélecteurs doivent appartenir au
 * sous-ensemble CSS pris en charge par `HtmlExtractor`.
 */
class DetectedSourceRequest extends FormRequest
{
    private const SELECTOR_KEYS = ['item', 'link', 'title', 'summary', 'date'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $selectorRule = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! HtmlExtractor::isValidSelector((string) $value)) {
                $fail("Le sélecteur « {$attribute} » n'est pas pris en charge.");
            }
        };

        $rules = [
            'url' => ['required', 'string', 'max:1024', 'url:http,https'],
            'strategy' => ['nullable', 'string', 'in:auto,selectors'],
            'enrich_pages' => ['nullable', 'integer', 'min:0', 'max:3'],
        ];
        foreach (self::SELECTOR_KEYS as $key) {
            $rules["selectors.{$key}"] = ['nullable', 'string', 'max:300', $selectorRule];
        }

        return $rules;
    }

    /**
     * Configuration HTML brute validée (telle qu'acceptée pour la
     * prévisualisation et la détection serveur) — `null` si rien de
     * renseigné. Le nettoyage canonique est assuré par
     * `SourceSubscriptionService::sanitizeHtmlConfig`.
     *
     * @return array<string, mixed>|null
     */
    public function htmlConfig(): ?array
    {
        $selectors = [];
        foreach (self::SELECTOR_KEYS as $key) {
            $value = $this->validated("selectors.{$key}");
            if (is_string($value) && trim($value) !== '') {
                $selectors[$key] = $value;
            }
        }

        $strategy = $this->validated('strategy');
        $enrichPages = $this->validated('enrich_pages');
        if ($selectors === [] && $strategy === null && $enrichPages === null) {
            return null;
        }

        return [
            'strategy' => $strategy ?? 'auto',
            'enrich_pages' => $enrichPages ?? 0,
            'selectors' => $selectors,
        ];
    }
}
