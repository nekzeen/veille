<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Bases autorisées pour les tests — jamais de production. */
    private const ALLOWED_TEST_DATABASES = [':memory:', 'veille_test'];

    public function createApplication()
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if (! is_string($database) || ! in_array($database, self::ALLOWED_TEST_DATABASES, true)) {
            throw new RuntimeException(
                "Tests refusés : la connexion par défaut « {$default} » cible « {$database} ». "
                .'Seules « :memory: » et « veille_test » sont autorisées.'
            );
        }

        return $app;
    }
}
