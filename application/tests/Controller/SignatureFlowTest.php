<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\MailSignature;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\InvoiceTemplate;
use App\Repository\MailSignatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The way through the UI: create signatures, have the letterhead preset one
 * when an invoice is created, change it on the invoice, find it under the
 * mail that goes out – and removing a signature leaves its invoices without
 * one instead of failing.
 */
final class SignatureFlowTest extends WebTestCase
{
    public function testSignaturesRequireAnAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/signature');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testSignaturesCanBeCreatedThroughTheForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request('GET', '/admin/signature/new');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Signatur anlegen')->form([
            'mail_signature[label]'              => 'Achim Krämer',
            'mail_signature[text]'               => "Achim Krämer\r\nachim-kraemer.com",
            'mail_signature[defaultForTemplate]' => InvoiceTemplate::AchimKraemer->value,
        ]));
        self::assertResponseRedirects('/admin/signature');

        // An HTML signature is shown rendered in the list, defused: the bold
        // text stays, a script does not.
        $crawler = $client->request('GET', '/admin/signature/new');
        $client->submit($crawler->selectButton('Signatur anlegen')->form([
            'mail_signature[label]'  => 'Mirwena',
            'mail_signature[format]' => MailSignature::FORMAT_HTML,
            'mail_signature[text]'   => '<table><tr><td><b>Mirwena</b><script>alert(1)</script></td></tr></table>',
        ]));
        self::assertResponseRedirects('/admin/signature');

        $signatures = static::getContainer()->get(MailSignatureRepository::class)->findAllOrdered();
        self::assertCount(2, $signatures);
        self::assertSame("Achim Krämer\nachim-kraemer.com", $signatures[0]->getText());
        self::assertSame(InvoiceTemplate::AchimKraemer, $signatures[0]->getDefaultForTemplate());
        self::assertTrue($signatures[1]->isHtml());

        $crawler = $client->request('GET', '/admin/signature');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.data .signature-html b'));
        self::assertCount(0, $crawler->filter('table.data .signature-html script'));
        self::assertStringContainsString('Achim Krämer', $crawler->filter('table.data .chip')->text());
    }

    /** Only one signature can be the default of a letterhead: the one saved last takes it over. */
    public function testALetterheadHasOnlyOneDefaultSignature(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $first  = $this->signature('Erste', default: InvoiceTemplate::Mirwena);
        $second = $this->signature('Zweite');

        $crawler = $client->request('GET', '/admin/signature/'.$second->getId().'/edit');
        $client->submit($crawler->selectButton('Änderungen speichern')->form([
            'mail_signature[defaultForTemplate]' => InvoiceTemplate::Mirwena->value,
        ]));
        self::assertResponseRedirects('/admin/signature');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(MailSignature::class, $first->getId())->getDefaultForTemplate());
        self::assertSame(InvoiceTemplate::Mirwena, $em->find(MailSignature::class, $second->getId())->getDefaultForTemplate());
    }

    public function testInvoiceIsCreatedWithTheChosenSignatureAndSentWithIt(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->admin());

        $achim   = $this->signature('Achim Krämer', "Achim Krämer\nachim-kraemer.com", InvoiceTemplate::AchimKraemer);
        $mirwena = $this->signature('Mirwena', '<b>Mirwena</b>', InvoiceTemplate::Mirwena, MailSignature::FORMAT_HTML);

        $customer = (new Customer())->setFirstname('Max')->setLastname('Muster')->setEmail('max@example.com');
        $project  = (new Project())->setName('Signatur-Projekt')->setCustomer($customer);
        $task     = (new Task())->setName('Beratung')->setHours('2.00')->setProject($project);
        foreach ([$customer, $project, $task] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        // Reload from the database so the project knows its task (inverse side).
        $em->clear();

        // The project page presets the default of the checked letterhead and
        // tells the script the default of every other one.
        $crawler = $client->request('GET', '/admin/project/'.$project->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Rechnung aus Auswahl erstellen')->form();
        self::assertSame((string) $achim->getId(), $form['signature']->getValue());
        self::assertSame(
            (string) $mirwena->getId(),
            $crawler->filter('input[name="template"][value="mirwena"]')->attr('data-default-signature'),
        );

        $form['tasks'][0]->tick();
        $form['template']->select('mirwena');
        $form['signature']->select((string) $mirwena->getId());
        $client->submit($form);
        self::assertResponseRedirects();

        $invoice = $em->getRepository(Invoice::class)->findOneBy(['project' => $project->getId()]);
        self::assertNotNull($invoice);
        self::assertSame($mirwena->getId(), $invoice->getSignature()?->getId());

        try {
            // On the invoice the chosen signature is selected and previewed; another one can be stored.
            $crawler = $client->request('GET', '/admin/invoice/'.$invoice->getId());
            self::assertResponseIsSuccessful();
            self::assertSame((string) $mirwena->getId(), $crawler->selectButton('Signatur speichern')->form()['signature']->getValue());
            self::assertCount(1, $crawler->filter('[data-signatur-vorschau]:not([hidden]) b'));

            $client->submitForm('Signatur speichern', ['signature' => (string) $achim->getId()]);
            self::assertResponseRedirects('/admin/invoice/'.$invoice->getId());
            $em->refresh($invoice);
            self::assertSame($achim->getId(), $invoice->getSignature()?->getId());

            // The mail carries the signature behind the delimiter, in place of the sender's name.
            $client->request('GET', '/admin/invoice/'.$invoice->getId());
            $client->submitForm('Per E-Mail senden');
            self::assertResponseRedirects('/admin/invoice/'.$invoice->getId());
            self::assertEmailCount(1);

            $email = self::getMailerMessage();
            self::assertInstanceOf(Email::class, $email);
            self::assertStringEndsWith("Mit freundlichen Grüßen\n\n-- \nAchim Krämer\nachim-kraemer.com", (string) $email->getTextBody());
            self::assertNull($email->getHtmlBody());

            // Removing the signature leaves the invoice without one.
            $client->request('GET', '/admin/signature');
            $client->submitForm('Entfernen');
            self::assertResponseRedirects('/admin/signature');
            $em->clear();
            self::assertNull($em->find(Invoice::class, $invoice->getId())->getSignature());
        } finally {
            @\unlink(static::getContainer()->getParameter('invoices_path').$invoice->getPdfFilename());
        }
    }

    private function admin(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('signatur-admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function signature(
        string $label,
        string $text = 'Achim Krämer',
        ?InvoiceTemplate $default = null,
        string $format = MailSignature::FORMAT_TEXT,
    ): MailSignature {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $signature = (new MailSignature())->setLabel($label)->setText($text)->setFormat($format)->setDefaultForTemplate($default);
        $em->persist($signature);
        $em->flush();

        return $signature;
    }
}
