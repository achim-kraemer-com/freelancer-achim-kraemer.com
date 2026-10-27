<?php

declare(strict_types=1);

// Reicht PHPStan den EntityManager, damit die Doctrine-Erweiterung Repositories
// und DQL kennt. Wird nur von phpstan.dist.neon geladen, nicht von den Tests.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
