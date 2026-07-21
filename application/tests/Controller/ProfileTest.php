<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileTest extends WebTestCase
{
    public function testUserCanChangeTheirPassword(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())->setEmail('pw@example.com')->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'old-pass-123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/profile');

        $client->submitForm('Passwort ändern', [
            'change_password[currentPassword]'      => 'old-pass-123',
            'change_password[newPassword][first]'   => 'new-pass-456',
            'change_password[newPassword][second]'  => 'new-pass-456',
        ]);
        self::assertResponseRedirects('/profile');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'pw@example.com']);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'new-pass-456'));
    }

    public function testUserCanEnableTwoFactorWithValidCode(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setEmail('2fa@example.com')->setRoles(['ROLE_USER'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/2fa/enable');
        self::assertResponseIsSuccessful();

        $secret = trim($crawler->filter('code')->text());
        $code   = TOTP::createFromSecret($secret)->now();

        $client->submitForm('Aktivieren', ['code' => $code]);
        self::assertResponseRedirects('/profile');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => '2fa@example.com']);
        self::assertTrue($reloaded->isTotpEnabled());
    }
}
