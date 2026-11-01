<?php

namespace App\Services\Feed;

/**
 * Résolution DNS d'un nom d'hôte — injectable pour permettre des
 * résolveurs factices en tests (multi-IP, mixte, rebinding).
 */
interface IpResolverInterface
{
    /**
     * Toutes les adresses IP (IPv4 et IPv6) retournées pour l'hôte.
     *
     * @return list<string> vide si aucune réponse exploitable
     */
    public function resolve(string $host): array;
}
