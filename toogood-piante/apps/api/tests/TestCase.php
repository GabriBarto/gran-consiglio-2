<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Protezione: i test non devono mai girare sul database di sviluppo.
        $connection = config('database.default');
        $database = (string) config("database.connections.$connection.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("I test devono usare un database che finisce con _test (trovato: '$database').");
        }
    }
}
