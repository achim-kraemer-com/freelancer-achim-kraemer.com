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
 * What happens to the tasks around an invoice: the sum it is built from,
 * the lock it puts on them, and the release when it is deleted again.
 */
final class InvoiceFlowTest extends WebTestCase
{
    public function testTheInvoiceSumsUpExactlyTheChosenTasks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $projectId = $this->projectWith([
            ['name' => 'Beratung', 'hours' => '2.50'],   // 2.5 × 60 € = 150.00
            ['name' => 'Festpreis', 'price' => '99.90'], //              99.90
            ['name' => 'Spaeter', 'hours' => '1.00'],    // bleibt offen
        ]);

        $invoice = $this->createInvoice($client, $projectId, ['Beratung', 'Festpreis']);

        self::assertSame('249.90', $invoice->getTotalAmount());
        self::assertCount(2, $invoice->getTasks());

        // Nur die ausgewählten Aufgaben hängen an der Rechnung und sind gesperrt.
        self::assertTrue($this->task('Beratung')->isInvoiced());
        self::assertTrue($this->task('Festpreis')->isInvoiced());
        self::assertFalse($this->task('Spaeter')->isInvoiced());
        self::assertNull($this->task('Spaeter')->getInvoice());
    }

    public function testTheDueDateFollowsThePaymentTerm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $projectId = $this->projectWith([['name' => 'Beratung', 'hours' => '1.00']]);
        $invoice   = $this->createInvoice($client, $projectId, ['Beratung']);

        $expected = (clone $invoice->getInvoiceDate())->modify('+'.Invoice::DEFAULT_PAYMENT_TERM_DAYS.' days');

        self::assertSame($expected->format('Y-m-d'), $invoice->getDueDate()?->format('Y-m-d'));
    }

    public function testAnInvoicedTaskCanNoLongerBeEditedOrDeleted(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $projectId = $this->projectWith([['name' => 'Beratung', 'hours' => '2.00']]);
        $taskId    = $this->task('Beratung')->getId();

        // Das Löschen-Formular steht nur auf der Bearbeiten-Seite, und die ist
        // nach dem Abrechnen zu – den Token also vorher holen.
        $crawler    = $client->request('GET', '/admin/task/'.$taskId.'/edit');
        self::assertResponseIsSuccessful();
        $deleteToken = $crawler->filter('input[name="_token"]')->last()->attr('value');

        $this->createInvoice($client, $projectId, ['Beratung']);

        $client->request('GET', '/admin/task/'.$taskId.'/edit');
        self::assertResponseRedirects('/admin/project/'.$projectId);

        $client->request('POST', '/admin/task/'.$taskId.'/delete', ['_token' => $deleteToken]);
        self::assertResponseRedirects('/admin/project/'.$projectId);

        self::assertNotNull($this->task('Beratung'), 'Die abgerechnete Aufgabe darf nicht gelöscht worden sein.');
    }

    public function testDeletingTheInvoiceReleasesItsTasksAndRemovesThePdf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $projectId = $this->projectWith([
            ['name' => 'Beratung', 'hours' => '2.00'],
            ['name' => 'Festpreis', 'price' => '40.00'],
        ]);

        $invoice = $this->createInvoice($client, $projectId, ['Beratung', 'Festpreis']);
        $pdf     = static::getContainer()->getParameter('invoices_path').$invoice->getPdfFilename();
        self::assertFileExists($pdf);

        $client->request('GET', '/admin/invoice/'.$invoice->getId());
        self::assertResponseIsSuccessful();
        $client->submitForm('Rechnung löschen');
        self::assertResponseRedirects('/admin/invoice');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertNull($em->getRepository(Invoice::class)->find($invoice->getId()));
        self::assertFileDoesNotExist($pdf);

        // Die Aufgaben sind wieder offen und hängen an keiner Rechnung mehr.
        foreach (['Beratung', 'Festpreis'] as $name) {
            self::assertFalse($this->task($name)->isInvoiced(), $name.' muss wieder offen sein.');
            self::assertNull($this->task($name)->getInvoice(), $name.' darf an keiner Rechnung mehr hängen.');
        }
    }

    /**
     * @param list<array{name: string, hours?: string, price?: string}> $tasks
     *
     * @return int the project's id
     */
    private function projectWith(array $tasks): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $customer = (new Customer())->setFirstname('Max')->setLastname('Muster')->setEmail('max@example.com');
        $project  = (new Project())->setName('Rechnungslauf')->setCustomer($customer);
        $em->persist($customer);
        $em->persist($project);

        foreach ($tasks as $spec) {
            $task = (new Task())->setName($spec['name'])->setProject($project);
            isset($spec['price']) ? $task->setPrice($spec['price']) : $task->setHours($spec['hours'] ?? '1.00');
            $em->persist($task);
        }

        $em->flush();
        $id = (int) $project->getId();
        // Zurücklesen, damit das Projekt seine Aufgaben kennt (inverse Seite).
        $em->clear();

        return $id;
    }

    /**
     * Creates an invoice from the named tasks of the project, through the form
     * on the project page.
     *
     * @param list<string> $taskNames
     */
    private function createInvoice(KernelBrowser $client, int $projectId, array $taskNames): Invoice
    {
        $wanted = array_map(fn (string $name): string => (string) $this->task($name)->getId(), $taskNames);

        $crawler = $client->request('GET', '/admin/project/'.$projectId);
        self::assertResponseIsSuccessful();

        // Die Reihenfolge der Kästchen auf der Seite ist nichts, worauf man sich
        // verlassen sollte – deshalb über den Wert (die Aufgaben-Id) zuordnen.
        $values = $crawler->filter('input[name="tasks[]"]')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );

        $form = $crawler->selectButton('Rechnung aus Auswahl erstellen')->form();
        foreach ($wanted as $id) {
            $index = array_search($id, $values, true);
            self::assertIsInt($index, 'Kein Kästchen für Aufgabe '.$id.' auf der Projektseite.');
            $form['tasks'][$index]->tick();
        }

        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $invoice = $em->getRepository(Invoice::class)->find($this->redirectedId($client));
        self::assertNotNull($invoice);

        return $invoice;
    }

    private function task(string $name): Task
    {
        $task = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Task::class)->findOneBy(['name' => $name]);
        self::assertNotNull($task, 'Aufgabe "'.$name.'" nicht gefunden.');

        return $task;
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
        $user = (new User())->setEmail('rechnungslauf-admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
