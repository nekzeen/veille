<?php

namespace App\Mail;

use App\Models\ArticleVeille;
use App\Models\VeilleAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Digest quotidien d'une veille : nom, période affichée en heure de
 * Paris, jusqu'à 50 articles [titre, source, date, mot-clé, liens].
 * Le template Blade échappe toutes les données issues des flux.
 */
class VeilleDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, ArticleVeille>  $items
     */
    public function __construct(
        public readonly VeilleAlert $alert,
        public readonly Collection $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Veille « %s » — %d article(s)',
                $this->alert->veille->name, $this->items->count()),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.veille-digest',
            text: 'emails.veille-digest-text',
        );
    }
}
