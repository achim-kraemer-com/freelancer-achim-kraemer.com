<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityAccessTest extends WebTestCase
{
    #[DataProvider('protectedUrls')]
    public function testAnonymousUserIsRedirectedToLogin(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<array{0: string}>
     */
    public static function protectedUrls(): iterable
    {
        yield 'dashboard' => ['/dashboard'];
        yield 'profile' => ['/profile'];
        yield 'customers' => ['/admin/customer'];
        yield 'invoices' => ['/admin/invoice'];
        yield 'time tracking' => ['/admin/time-tracking'];
        yield 'user management' => ['/admin/users'];
    }
}
