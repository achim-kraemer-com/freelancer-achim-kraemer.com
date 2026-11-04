<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The way through the UI: pick a contact from the portal, find the customer
 * form pre-filled, save it.
 *
 * The portal is answered by a MockHttpClient – the test must not depend on
 * another machine. What is being tested is this side: that the list appears,
 * that the pick reaches the form with the guesses applied, and that a portal
 * that is down costs a sentence instead of an error page.
 */
final class CustomerPortalTest extends WebTestCase
{
    public function testThePickerNeedsAnAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/customer/portal');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheContactsOfThePortalAreListed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers($this->liste());

        $crawler = $client->request('GET', '/admin/customer/portal');

        self::assertResponseIsSuccessful();
        $tabelle = $crawler->filter('table.data')->text();
        self::assertStringContainsString('Musterfirma GmbH', $tabelle);
        self::assertStringContainsString('Max Muster', $tabelle);
        self::assertStringContainsString('max@example.com', $tabelle);
        self::assertStringContainsString('Musterweg 7', $tabelle);
        self::assertStringContainsString('49477 Ibbenbüren', $tabelle);
        self::assertStringContainsString('Erika Beispiel', $tabelle);
    }

    public function testTheSearchTermReachesThePortal(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $urls = [];
        $this->portalAnswers(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"contacts":[],"count":0}');
        });

        $crawler = $client->request('GET', '/admin/customer/portal?q=Muster');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Kein Kontakt passt zu', $crawler->filter('.empty')->text());
        self::assertCount(1, $urls);
        self::assertStringContainsString('q=Muster', $urls[0]);
    }

    public function testThePickFillsTheCustomerFormAndSaves(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers(new MockResponse((string) \json_encode(['contact' => $this->max()])));

        $crawler = $client->request('GET', '/admin/customer/new?kontakt=7');
        self::assertResponseIsSuccessful();

        // The hint says the fields were guessed.
        self::assertStringContainsString('Musterfirma GmbH (Max Muster)', $crawler->filter('.alert-info')->text());

        $form = $crawler->selectButton('Speichern')->form();
        self::assertSame('Max', $form->get('customer[firstname]')->getValue());
        self::assertSame('Muster', $form->get('customer[lastname]')->getValue());
        self::assertSame('Musterfirma GmbH', $form->get('customer[companyName]')->getValue());
        self::assertSame('max@example.com', $form->get('customer[email]')->getValue());
        self::assertSame('05451 123456', $form->get('customer[phone]')->getValue());
        self::assertSame('+49 170 1234567', $form->get('customer[mobile]')->getValue());
        self::assertSame('Musterweg', $form->get('customer[street]')->getValue());
        self::assertSame('7', $form->get('customer[housenumber]')->getValue());
        self::assertSame('49477', $form->get('customer[plz]')->getValue());
        self::assertSame('Ibbenbüren', $form->get('customer[city]')->getValue());
        self::assertSame('Deutschland', $form->get('customer[country]')->getValue());
        self::assertSame('https://www.musterfirma.de', $form->get('customer[website]')->getValue());

        // Saving the pre-filled form does not go to the portal again.
        $client->submit($form);
        self::assertResponseRedirects();

        $kunde = static::getContainer()->get(CustomerRepository::class)->findOneBy(['email' => 'max@example.com']);
        self::assertNotNull($kunde);
        self::assertSame('Muster', $kunde->getLastname());
        self::assertSame('Musterweg', $kunde->getStreet());
        self::assertSame('7', $kunde->getHousenumber());
        self::assertTrue($kunde->isActive());
    }

    public function testACorrectionInTheFormSurvivesTheSave(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers(new MockResponse((string) \json_encode(['contact' => $this->max()])));

        $crawler = $client->request('GET', '/admin/customer/new?kontakt=7');
        $form    = $crawler->selectButton('Speichern')->form();
        $form['customer[firstname]'] = 'Maximilian';
        $form['customer[street]']    = 'Andere Straße';

        // The query string travels with the form action; the guess must not
        // overwrite the correction on the way back.
        $client->submit($form);
        self::assertResponseRedirects();

        $kunde = static::getContainer()->get(CustomerRepository::class)->findOneBy(['email' => 'max@example.com']);
        self::assertNotNull($kunde);
        self::assertSame('Maximilian', $kunde->getFirstname());
        self::assertSame('Andere Straße', $kunde->getStreet());
    }

    public function testAContactWithoutMailAddressStopsAtTheForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers(new MockResponse('{"contact":{"id":9,"name":"Erika Beispiel"}}'));

        $crawler = $client->request('GET', '/admin/customer/new?kontakt=9');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Speichern')->form());

        // No redirect: the form comes back and asks for the address – with
        // 422, as Symfony answers an invalid submission.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#customer_email_error1');
        self::assertSame(0, static::getContainer()->get(CustomerRepository::class)->count([]));
    }

    public function testAContactThePortalDoesNotKnowSaysSo(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers(new MockResponse('{"error":"Kontakt nicht gefunden."}', ['http_code' => 404]));

        $client->request('GET', '/admin/customer/new?kontakt=999');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'kennt das Portal nicht');
    }

    public function testAnUnreachablePortalIsNoErrorPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers(static function (): never {
            throw new class('Name oder Dienst nicht bekannt') extends \RuntimeException implements TransportExceptionInterface {};
        });

        $crawler = $client->request('GET', '/admin/customer/portal');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('nicht erreichbar', $crawler->filter('.alert-danger')->text());
    }

    public function testAFullPageSaysThereMayBeMore(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        // Exactly as many as the picker asks for – so there may be more.
        $viele = [];
        for ($i = 1; $i <= 100; ++$i) {
            $viele[] = ['id' => $i, 'name' => 'Kontakt '.$i, 'emails' => [], 'phones' => [], 'tags' => []];
        }

        $this->portalAnswers(new MockResponse((string) \json_encode(['contacts' => $viele, 'count' => 240])));

        $crawler = $client->request('GET', '/admin/customer/portal');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ersten 100 Kontakte', $crawler->filter('.sub')->last()->text());
    }

    public function testAShortListSaysNothingAboutMore(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->portalAnswers($this->liste());

        $crawler = $client->request('GET', '/admin/customer/portal');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ersten 100', (string) $client->getResponse()->getContent());
        self::assertCount(2, $crawler->filter('table.data tbody tr'));
    }

    public function testTheCustomerListLinksToThePicker(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request('GET', '/admin/customer');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/customer/portal"]'));
    }

    /** The answer the mocked portal gives, as a response or a callback. */
    private function portalAnswers(MockResponse|callable $answer): void
    {
        static::getContainer()->set('http_client', new MockHttpClient($answer));
    }

    /** @return array<string, mixed> */
    private function max(): array
    {
        return [
            'id'           => 7,
            'name'         => 'Max Muster',
            'organisation' => 'Musterfirma GmbH',
            'emails'       => ['max@example.com', 'max@firma.de'],
            'phones'       => ['05451 123456', '+49 170 1234567'],
            'street'       => 'Musterweg 7',
            'postalCode'   => '49477',
            'city'         => 'Ibbenbüren',
            'country'      => 'Deutschland',
            'website'      => 'https://www.musterfirma.de',
            'note'         => 'Kennt sich mit Dächern aus.',
            'tags'         => ['Geschäftlich'],
        ];
    }

    private function liste(): MockResponse
    {
        return new MockResponse((string) \json_encode([
            'contacts' => [
                ['id' => 9, 'name' => 'Erika Beispiel', 'emails' => [], 'phones' => [], 'tags' => []],
                $this->max(),
            ],
            'count' => 2,
        ]));
    }

    private function admin(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('portal-admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
