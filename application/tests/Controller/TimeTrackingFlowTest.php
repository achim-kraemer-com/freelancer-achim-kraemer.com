<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TimeTrackingFlowTest extends WebTestCase
{
    public function testFinishTimerRoundsUpAndCreatesLogEntry(): void
    {
        $client    = static::createClient();
        $em        = static::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())
            ->setEmail('timer-test@example.com')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('x')
            // 90 seconds already measured -> rounds up to 2 full minutes.
            ->setTimerStartedAt(new \DateTimeImmutable('-90 seconds'));

        $customer = (new Customer())->setFirstname('Test')->setLastname('Kunde')->setEmail('kunde@example.com');
        $project  = (new Project())->setName('Test-Projekt')->setCustomer($customer);
        $task     = (new Task())->setName('Test-Aufgabe')->setHours('1.00')->setProject($project);

        foreach ([$user, $customer, $project, $task] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/admin/time-tracking');
        self::assertResponseIsSuccessful();

        $client->submitForm('Aufgabe beenden & Zeit zuweisen', [
            'task' => (string) $task->getId(),
            'note' => 'Automatischer Test',
        ]);

        self::assertResponseRedirects('/admin/time-tracking');

        $em->clear();

        $updatedTask = $em->getRepository(Task::class)->find($task->getId());
        // 1.00 h + 2 min (0.03 h, rounded to 2 decimals) = 1.03 h
        self::assertSame('1.03', $updatedTask->getHours());

        $entries = $em->getRepository(TimeEntry::class)->findBy(['task' => $task->getId()]);
        self::assertCount(1, $entries);
        self::assertSame(2, $entries[0]->getMinutes());
        self::assertSame('Automatischer Test', $entries[0]->getNote());
    }
}
