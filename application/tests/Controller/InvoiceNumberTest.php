<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A number that has gone out to a customer must never come back on a
 * different invoice – not even after the first one was deleted and its
 * tasks were released for invoicing again.
 */
final class InvoiceNumberTest extends WebTestCase
{
    public function testADeletedInvoiceDoesNotFreeItsNumberAgain(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->admin());

        $projectId = $this->projectWithOneTask();

        $first = $this->createInvoice($client, $projectId);
        self::assertStringEndsWith('-1', $first);

        $this->deleteInvoice($client, $first);

        // Same project, same task, new invoice – and a number of its own.
        $second = $this->createInvoice($client, $projectId);

        self::assertNotSame($first, $second);
        self::assertStringEndsWith('-2', $second);
    }

    public function testNumbersRunOnPerProject(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $first  = $this->createInvoice($client, $this->projectWithOneTask());
        $second = $this->createInvoice($client, $this->projectWithOneTask());

        // Two projects, two counters – both start at one, and the project
        // part of the number keeps them apart.
        self::assertStringEndsWith('-1', $first);
        self::assertStringEndsWith('-1', $second);
        self::assertNotSame($first, $second);
    }

    /** @return int the project's id; it carries one open, billable task */
    private function projectWithOneTask(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $customer = (new Customer())->setFirstname('Max')->setLastname('Muster')->setEmail('max@example.com');
        $project  = (new Project())->setName('Nummernkreis')->setCustomer($customer);
        $task     = (new Task())->setName('Beratung')->setHours('2.00')->setProject($project);

        foreach ([$customer, $project, $task] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $id = (int) $project->getId();
        // The project has to be read back so it knows its task (inverse side).
        $em->clear();

        return $id;
    }

    /** Creates an invoice from the project's first open task and returns its number. */
    private function createInvoice(KernelBrowser $client, int $projectId): string
    {
        $crawler = $client->request('GET', '/admin/project/'.$projectId);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Rechnung aus Auswahl erstellen')->form();
        $form['tasks'][0]->tick();
        $client->submit($form);
        self::assertResponseRedirects();

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $invoice = $em->getRepository(Invoice::class)->find($this->redirectedId($client));
        self::assertNotNull($invoice);

        return (string) $invoice->getNumber();
    }

    private function deleteInvoice(KernelBrowser $client, string $number): void
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $invoice = $em->getRepository(Invoice::class)->findOneBy(['number' => $number]);
        self::assertNotNull($invoice);

        $client->request('GET', '/admin/invoice/'.$invoice->getId());
        self::assertResponseIsSuccessful();
        $client->submitForm('Rechnung löschen');
        self::assertResponseRedirects();

        $em->clear();
        self::assertNull($em->getRepository(Invoice::class)->findOneBy(['number' => $number]));
    }

    /** The id out of a redirect to /admin/invoice/{id}. */
    private function redirectedId(KernelBrowser $client): int
    {
        $location = (string) $client->getResponse()->headers->get('Location');

        return (int) basename(parse_url($location, \PHP_URL_PATH) ?: '');
    }

    private function admin(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('nummern-admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
