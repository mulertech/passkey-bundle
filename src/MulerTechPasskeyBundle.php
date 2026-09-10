<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use MulerTech\PasskeyBundle\Controller\PasskeyController;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use MulerTech\PasskeyBundle\Repository\WebauthnUserEntityRepository;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class MulerTechPasskeyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mulertech_passkey';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('user_class')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The application user entity, which implements PasskeyUserInterface.')
                ->end()
                ->scalarNode('user_provider')
                    ->defaultValue('security.user.provider.concrete.app_user_provider')
                    ->cannotBeEmpty()
                    ->info('User provider service queried to find an account by its identifier.')
                ->end()
                ->scalarNode('template')
                    ->defaultValue('@MulerTechPasskey/passkey/index.html.twig')
                    ->cannotBeEmpty()
                    ->info('Template of the key management page. Overridable in templates/bundles/MulerTechPasskeyBundle/.')
                ->end()
            ->end();
    }

    /**
     * Declares the Doctrine mapping of the entity and names the repositories to web-auth.
     *
     * Both blocks are laid down before the application's own configuration, never after: a
     * project wanting its own repositories declares them and wins.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'MulerTechPasskeyBundle' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/src/Entity',
                        'prefix' => 'MulerTech\PasskeyBundle\Entity',
                        'alias' => 'MulerTechPasskey',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('webauthn', [
            'credential_repository' => WebauthnCredentialRepository::class,
            'user_repository' => WebauthnUserEntityRepository::class,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $userClass = $config['user_class'];
        $userProvider = $config['user_provider'];
        $template = $config['template'];

        if (!\is_string($userClass) || !\is_string($userProvider) || !\is_string($template)) {
            throw new \InvalidArgumentException('mulertech_passkey: user_class, user_provider and template must be strings.');
        }

        $services = $container->services();

        // Identifiant = nom de classe, contrairement à la convention des autres bundles maison :
        // Doctrine résout un dépôt d'entité par le `repositoryClass` de l'entité, et va le chercher
        // dans le localisateur construit à partir du tag. Ce localisateur est indexé par
        // identifiant de service, et un alias n'y figure pas. Sous un identifiant court, le dépôt
        // reste introuvable dès que Doctrine en a besoin lui-même, par exemple pour résoudre une
        // entité passée en paramètre de route.
        $services->set(WebauthnCredentialRepository::class)
            ->args([new Reference('doctrine')])
            ->tag('doctrine.repository_service')
            ->public();
        $services->alias('mulertech_passkey.credential_repository', WebauthnCredentialRepository::class);

        $services->set('mulertech_passkey.user_entity_repository', WebauthnUserEntityRepository::class)
            ->args([
                '$userProvider' => new Reference($userProvider),
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
                '$userClass' => $userClass,
            ]);
        $services->alias(WebauthnUserEntityRepository::class, 'mulertech_passkey.user_entity_repository')
            ->public();

        // Le contrôleur étend AbstractController, qui souscrit à des services par
        // ServiceSubscriberInterface. Lui passer le conteneur entier ne suffit pas : ses
        // dépendances y sont privées, et `render()` refuse de s'exécuter faute de trouver Twig.
        // L'autowiring appelle son `setContainer` avec le localisateur que la souscription
        // construit, c'est-à-dire celui qui contient réellement twig, router et les autres.
        $services->set('mulertech_passkey.controller', PasskeyController::class)
            ->autowire()
            ->autoconfigure()
            ->args([
                '$userEntities' => new Reference('mulertech_passkey.user_entity_repository'),
                '$credentials' => new Reference('mulertech_passkey.credential_repository'),
                '$translator' => new Reference('translator'),
                '$template' => $template,
            ]);
        $services->alias(PasskeyController::class, 'mulertech_passkey.controller')
            ->public();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The repository extends ServiceEntityRepository: without that class, container
        // autoloading would fail on a dependency the project has not installed.
        if (!class_exists(ServiceEntityRepository::class)) {
            throw new \LogicException('mulertech/passkey-bundle requires doctrine/doctrine-bundle.');
        }
    }
}
