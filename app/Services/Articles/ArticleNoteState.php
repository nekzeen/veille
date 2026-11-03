<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\User;

/**
 * Notes personnelles sur les articles (DataModel §3.9, §4.D-bis, §4.E).
 *
 * La note appartient à l'utilisateur authentifié et protège l'article
 * de la purge (`note IS NOT NULL`). Une note vide ou composée
 * d'espaces équivaut à une absence ; `user_id` vient toujours de la
 * session, jamais du client.
 */
final class ArticleNoteState
{
    /** La note personnelle, ou null si absente. */
    public function getNote(User $user, Article $article): ?string
    {
        return ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->value('note');
    }

    /**
     * Enregistrer/modifier : trim, puis upsert sur la PK composite —
     * is_read/is_favorite/catégorie/archivage préservés.
     * Une valeur vide délègue à la suppression.
     */
    public function saveNote(User $user, Article $article, ?string $note): void
    {
        $note = trim((string) $note);
        if ($note === '') {
            $this->deleteNote($user, $article);

            return;
        }

        ArticleUser::updateOrCreate(
            ['user_id' => $user->id, 'article_id' => $article->id],
            ['note' => $note],
        );
    }

    /**
     * Supprimer la note : `note` repasse à NULL en préservant les
     * autres données personnelles ; si la ligne ne contient plus rien
     * d'autre, elle est supprimée — règle centralisée §3.9.
     */
    public function deleteNote(User $user, Article $article): void
    {
        $row = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $row->hasPersonalDataBesides(['note'])) {
            $row->delete();

            return;
        }

        $row->note = null;
        $row->save();
    }
}
