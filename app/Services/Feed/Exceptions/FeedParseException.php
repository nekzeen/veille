<?php

namespace App\Services\Feed\Exceptions;

/**
 * Erreur de niveau flux : le document XML fourni n'est pas exploitable
 * comme flux RSS/Atom (vide, XML illisible, ou document valide qui
 * n'est pas un flux). À distinguer des avertissements non bloquants
 * portés par {@see ParsedFeed::$warnings} et des champs simplement
 * absents sur un article (représentés par null).
 */
final class FeedParseException extends \RuntimeException {}
