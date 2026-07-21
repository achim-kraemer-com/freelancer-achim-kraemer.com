<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManagementTest extends WebTestCase
{
    public function testAdminCanCreateUser(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = (new User())->setEmail('admin-test@example.com')->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'irrelevant'));
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        $client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $client->submitForm('Anlegen', [
            'user[email]'         => 'created@example.com',
            'user[role]'          => 'ROLE_ADMIN',
            'user[plainPassword]' => 'created-pass-123',
        ]);
        self::assertResponseRedirects('/admin/users');

        $em->clear();
        $created = $em->getRepository(User::class)->findOneBy(['email' => 'created@example.com']);
        self::assertNotNull($created);
        self::assertContains('ROLE_ADMIN', $created->getRoles());
        self::assertTrue($hasher->isPasswordValid($created, 'created-pass-123'));
    }

    public function testRegularUserCannotAccessUserManagement(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setEmail('plain@example.com')->setRoles(['ROLE_USER'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }
}
