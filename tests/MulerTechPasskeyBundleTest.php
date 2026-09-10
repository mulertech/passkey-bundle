<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests;

use MulerTech\PasskeyBundle\Controller\PasskeyController;
use MulerTech\PasskeyBundle\MulerTechPasskeyBundle;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use MulerTech\PasskeyBundle\Repository\WebauthnUserEntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MulerTechPasskeyBundleTest extends TestCase
{
    private MulerTechPasskeyBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new MulerTechPasskeyBundle();
    }

    public function testExtensionAlias(): void
    {
        $extension = $this->loadedExtension(self::config());

        self::assertSame('mulertech_passkey', $extension->getAlias());
    }

    public function testRegistersItsServices(): void
    {
        $container = $this->load(self::config());

        self::assertTrue($container->hasDefinition(WebauthnCredentialRepository::class));
        self::assertTrue($container->hasDefinition('mulertech_passkey.user_entity_repository'));
        self::assertTrue($container->hasDefinition('mulertech_passkey.controller'));

        self::assertTrue($container->hasAlias('mulertech_passkey.credential_repository'));
        self::assertTrue($container->hasAlias(WebauthnUserEntityRepository::class));
        self::assertTrue($container->hasAlias(PasskeyController::class));
    }

    public function testCredentialRepositoryIsTaggedForDoctrine(): void
    {
        $container = $this->load(self::config());

        // Doctrine indexe son localisateur de dépôts par identifiant de service : l'identifiant
        // doit être le nom de classe, sans quoi le dépôt reste introuvable au moment où Doctrine
        // le réclame lui-même.
        $definition = $container->getDefinition(WebauthnCredentialRepository::class);

        self::assertArrayHasKey('doctrine.repository_service', $definition->getTags());
    }

    public function testUserClassAndProviderReachTheUserEntityRepository(): void
    {
        $container = $this->load(self::config([
            'user_class' => 'App\Entity\Member',
            'user_provider' => 'security.user.provider.concrete.members',
        ]));

        $arguments = $container->getDefinition('mulertech_passkey.user_entity_repository')->getArguments();

        self::assertSame('App\Entity\Member', $arguments['$userClass']);
        self::assertSame('security.user.provider.concrete.members', (string) $arguments['$userProvider']);
    }

    public function testTemplateDefaultsToTheOneShipped(): void
    {
        $container = $this->load(self::config());

        self::assertSame(
            '@MulerTechPasskey/passkey/index.html.twig',
            $container->getDefinition('mulertech_passkey.controller')->getArgument('$template'),
        );
    }

    public function testTemplateCanBeReplaced(): void
    {
        $container = $this->load(self::config(['template' => 'security/keys.html.twig']));

        self::assertSame(
            'security/keys.html.twig',
            $container->getDefinition('mulertech_passkey.controller')->getArgument('$template'),
        );
    }

    /**
     * Without a user class the bundle cannot find anyone: better a container that refuses to
     * compile than a sign-in that fails at the first ceremony.
     */
    public function testUserClassIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load([]);
    }

    /**
     * The ceremony paths belong to the application: guessing them would answer 404 at the click,
     * after the browser has already accepted the fingerprint. The container refuses to compile
     * instead.
     */
    public function testCeremonyUrlsAreRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['user_class' => 'App\Entity\User']);
    }

    public function testCeremonyUrlsReachTheController(): void
    {
        $container = $this->load(self::config([
            'register_options_url' => '/admin/passkey/register/options',
            'register_url' => '/admin/passkey/register',
        ]));

        $arguments = $container->getDefinition('mulertech_passkey.controller')->getArguments();

        self::assertSame('/admin/passkey/register/options', $arguments['$registerOptionsUrl']);
        self::assertSame('/admin/passkey/register', $arguments['$registerUrl']);
    }

    /**
     * The configuration tree accepts any scalar, so a value of the wrong type reaches the
     * extension. Refusing it when the container compiles beats a controller failing on a template
     * name that turns out to be an integer.
     */
    public function testRefusesAConfigurationValueThatIsNotAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->load(self::config(['user_class' => 42]));
    }

    public function testPrependsTheDoctrineMappingAndTheWebauthnRepositories(): void
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->prepend($container);

        $doctrine = $container->getExtensionConfig('doctrine');
        self::assertSame(
            'MulerTech\PasskeyBundle\Entity',
            $doctrine[0]['orm']['mappings']['MulerTechPasskeyBundle']['prefix'],
        );

        $webauthn = $container->getExtensionConfig('webauthn');
        self::assertSame(WebauthnCredentialRepository::class, $webauthn[0]['credential_repository']);
        self::assertSame(WebauthnUserEntityRepository::class, $webauthn[0]['user_repository']);
    }


    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        return $overrides + [
            'user_class' => 'App\\Entity\\User',
            'register_options_url' => '/passkey/register/options',
            'register_url' => '/passkey/register',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load(['mulertech_passkey' => $config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadedExtension(array $config): \Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        $container = $this->createContainer();
        $this->bundle->build($container);

        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load(['mulertech_passkey' => $config], $container);

        return $extension;
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.build_dir', '/tmp/build');

        return $container;
    }
}
