<?php

namespace App\Services\Articles;

use DOMDocument;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Assainissement du contenu HTML des articles RSS (conception M1.12
 * §5, §7, §8) — HTMLPurifier whitelist + post-passe DOMDocument.
 *
 * Pipeline : `purify()` (balises/attributs/schémas whitelistés,
 * définitions HTML5 personnalisées) → post-traitement DOM natif
 * (validation de chaque `img src` par {@see ImageUrlPolicy}, retrait
 * des images refusées, ajout de `referrerpolicy`/`loading`).
 *
 * Fail-closed partout : entrée nulle/vide, échec `loadHTML`, `<body>`
 * absent → `''` (déclenche le fallback contenu → résumé → métadonnées,
 * appliqué dans `VeilleArticleController` — pas ici). Le HTML purifié
 * n'est **jamais** retourné sans post-traitement. Aucune regex.
 */
final class HtmlContentSanitizer
{
    /**
     * Images contrôlées par document — borné avec le plafond interne
     * d'{@see ImageUrlPolicy} (défense en profondeur, §6.2.1/§7).
     */
    private const MAX_IMAGES_PER_DOCUMENT = 50;

    private readonly HTMLPurifier $purifier;

    private readonly ImageUrlPolicy $imageUrls;

    /**
     * @param  ImageUrlPolicy|null  $imageUrls  injecté pour les tests
     *                                          (résolveur DNS factice)
     * @param  string|null  $cacheDir  répertoire du cache de définitions ;
     *                                 défaut `storage/app/htmlpurifier`
     */
    public function __construct(?ImageUrlPolicy $imageUrls = null, ?string $cacheDir = null)
    {
        $this->imageUrls = $imageUrls ?? new ImageUrlPolicy;

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed',
            'p,br,a[href|title],img[src|alt|title|width|height],strong,b,em,i,'
            .'ul,ol,li,blockquote,pre,code,h2,h3,h4,figure,figcaption,'
            .'table,thead,tbody,tr,th,td,hr,time[datetime]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.EnableID', false);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('HTML.DefinitionID', 'veille-articles');
        $config->set('HTML.DefinitionRev', 1);

        // Cache disque uniquement si le répertoire est prêt — sinon un
        // E_USER_WARNING de purifier deviendrait ErrorException sous
        // Laravel (§8.2). Repli vérifié : DefinitionCache_Null.
        // À configurer AVANT maybeGetRawHTMLDefinition : la config est
        // finalisée par cette extraction (« Cannot set directive after
        // finalization » — vérifié v4.19.1).
        $path = $cacheDir ?? storage_path('app/htmlpurifier');
        if (is_dir($path) && is_writable($path)) {
            $config->set('Cache.SerializerPath', $path);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        // Null quand la définition est déjà servie par le cache
        // sérialisé — elle contient alors déjà ces éléments
        // (« maybe » vérifié : addElement() sur null sinon).
        $def = $config->maybeGetRawHTMLDefinition();
        if ($def !== null) {
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Block', 'Flow', 'Common');
            $def->addElement('time', 'Inline', 'Flow', 'Common');
            $def->addAttribute('time', 'datetime', 'Text');
        }

        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * Contenu HTML sûr et post-traité, ou `''` si rien d'exploitable
     * (le contrôleur applique alors le fallback résumé → métadonnées).
     */
    public function sanitize(?string $html): string
    {
        $html = trim($html ?? '');
        if ($html === '') {
            return '';
        }

        // Compteurs « par document » : réinitialisés à chaque appel —
        // jamais partagés entre deux articles.
        $this->imageUrls->reset();

        return $this->postProcess($this->purifier->purify($html));
    }

    /**
     * Post-passe DOM (§7) : retire les `<img>` refusées par la politique
     * d'URL, ajoute `referrerpolicy`/`loading` aux survivantes.
     *
     * Fail-closed : échec `loadHTML` ou `<body>` absent → `''` —
     * jamais de retour du HTML purifié non post-traité (ses images
     * n'auraient pas été validées).
     */
    private function postProcess(string $purified): string
    {
        if ($purified === '') {
            return '';
        }

        $dom = new DOMDocument;
        // La PI force UTF-8 — sans elle libxml 2.9 corrompt les accents.
        // LIBXML_HTML_NOIMPLIED|NODEFDTD rejeté : destructeur sous 2.9.14.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?>'.$purified,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
        if ($body === null) {
            return '';
        }

        // NodeList VIVE : instantané obligatoire avant suppression.
        $images = iterator_to_array($dom->getElementsByTagName('img'));
        $checked = 0;
        foreach ($images as $img) {
            if (++$checked > self::MAX_IMAGES_PER_DOCUMENT
                || ! $this->imageUrls->isAllowed((string) $img->getAttribute('src'))) {
                $img->parentNode->removeChild($img);

                continue;
            }
            $img->setAttribute('referrerpolicy', 'no-referrer');
            $img->setAttribute('loading', 'lazy');
        }

        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= $dom->saveHTML($node);
        }

        return $out;
    }
}
