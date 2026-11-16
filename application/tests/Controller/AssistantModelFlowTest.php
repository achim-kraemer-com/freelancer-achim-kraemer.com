<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssistantModel;
use App\Entity\User;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Verwaltung der Sprachmodelle: anlegen, Vorgabe setzen, entfernen.
 *
 * Der Schlüssel darf nur verschlüsselt in der Datenbank landen – daran hängt,
 * dass eine gestohlene Datenbank nicht auch den Anbieter-Zugang hergibt.
 */
final class AssistantModelFlowTest extends WebTestCase
{
    public function testDasErsteModellWirdAngelegtUndIstVorgabe(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $client->request('GET', '/admin/modell/neu');
        self::assertResponseIsSuccessful();

        $client->submitForm('Modell anlegen', [
            'assistant_model[label]'           => 'Claude',
            'assistant_model[provider]'        => AssistantModel::PROVIDER_ANTHROPIC,
            'assistant_model[model]'           => 'claude-opus-5',
            'assistant_model[apiKeyPlain]'     => 'sk-geheim-123',
        ]);

        self::assertResponseRedirects('/admin/modell');

        $modell = $this->modelle()[0];
        self::assertSame('Claude', $modell->getLabel());
        self::assertTrue($modell->isDefault(), 'Das erste Modell ist zwangsläufig die Vorgabe.');

        // In der Datenbank steht nicht der Schlüssel, sondern sein Chiffrat.
        self::assertNotSame('sk-geheim-123', $modell->getApiKey());
        self::assertSame('sk-geheim-123', static::getContainer()->get(SecretCipher::class)->decrypt($modell->getApiKey()));
    }

    /** Ein leer gelassener Schlüssel lässt den bisherigen unangetastet. */
    public function testEinLeererSchluesselBleibtDerAlte(): void
    {
        $client = static::createClient();
        $user   = $this->admin();
        $client->loginUser($user);

        $modell = $this->modell($user, 'Claude', 'sk-alt');
        $vorher = $modell->getApiKey();

        $client->request('GET', '/admin/modell/'.$modell->getId().'/bearbeiten');
        $client->submitForm('Speichern', [
            'assistant_model[label]'    => 'Claude Opus',
            'assistant_model[provider]' => AssistantModel::PROVIDER_ANTHROPIC,
            'assistant_model[model]'    => 'claude-opus-5',
        ]);

        self::assertResponseRedirects('/admin/modell');

        $this->em()->clear();
        $frisch = $this->em()->getRepository(AssistantModel::class)->find($modell->getId());

        self::assertSame('Claude Opus', $frisch->getLabel());
        self::assertSame($vorher, $frisch->getApiKey());
    }

    /** Die Vorgabe lässt sich umhängen, und sie bleibt eindeutig. */
    public function testDieVorgabeWechselt(): void
    {
        $client = static::createClient();
        $user   = $this->admin();
        $client->loginUser($user);

        $erstes = $this->modell($user, 'Claude', 'sk-1', vorgabe: true);
        $zweites = $this->modell($user, 'GPT', 'sk-2');

        $crawler = $client->request('GET', '/admin/modell');
        $token   = $crawler->filter('form[action="/admin/modell/'.$zweites->getId().'/vorgabe"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/modell/'.$zweites->getId().'/vorgabe', ['_token' => $token]);

        self::assertResponseRedirects('/admin/modell');

        $this->em()->clear();
        $repo = $this->em()->getRepository(AssistantModel::class);

        self::assertFalse($repo->find($erstes->getId())->isDefault());
        self::assertTrue($repo->find($zweites->getId())->isDefault());
    }

    /** Fällt die Vorgabe weg, rückt das nächste Modell nach. */
    public function testNachDemLoeschenDerVorgabeRuecktEinesNach(): void
    {
        $client = static::createClient();
        $user   = $this->admin();
        $client->loginUser($user);

        $vorgabe = $this->modell($user, 'Claude', 'sk-1', vorgabe: true);
        $anderes = $this->modell($user, 'GPT', 'sk-2');

        $crawler = $client->request('GET', '/admin/modell');
        $token   = $crawler->filter('form[action="/admin/modell/'.$vorgabe->getId().'/loeschen"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/modell/'.$vorgabe->getId().'/loeschen', ['_token' => $token]);

        self::assertResponseRedirects('/admin/modell');

        $this->em()->clear();
        self::assertTrue($this->em()->getRepository(AssistantModel::class)->find($anderes->getId())->isDefault());
    }

    /** Das Modell eines anderen Benutzers gehört nicht hierher. */
    public function testFremdeModelleSindTabu(): void
    {
        $client = static::createClient();
        $fremd  = $this->admin('fremd@example.com');
        $modell = $this->modell($fremd, 'Fremdes', 'sk-fremd');

        $client->loginUser($this->admin());
        $client->request('GET', '/admin/modell/'.$modell->getId().'/bearbeiten');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOhneAdminRolleKeinZugang(): void
    {
        $client = static::createClient();

        $user = (new User())->setEmail('nur-user@example.com')->setRoles(['ROLE_USER'])->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/modell');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<AssistantModel> */
    private function modelle(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(AssistantModel::class)->findBy([], ['id' => 'ASC']);
    }

    private function modell(User $user, string $label, string $key, bool $vorgabe = false): AssistantModel
    {
        $modell = (new AssistantModel())
            ->setOwner($user)
            ->setLabel($label)
            ->setProvider(AssistantModel::PROVIDER_ANTHROPIC)
            ->setModel('claude-opus-5')
            ->setApiKey(static::getContainer()->get(SecretCipher::class)->encrypt($key))
            ->setIsDefault($vorgabe);

        $this->em()->persist($modell);
        $this->em()->flush();

        return $modell;
    }

    private function admin(string $email = 'modell-test@example.com'): User
    {
        $user = (new User())->setEmail($email)->setRoles(['ROLE_ADMIN'])->setPassword('x');

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
