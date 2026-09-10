<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MulerTech\PasskeyBundle\Repository\WebauthnUserEntityRepository;
use MulerTech\PasskeyBundle\Tests\Fixture\PasskeyUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class WebauthnUserEntityRepositoryTest extends TestCase
{
    public function testMapsAUserOntoTheWebauthnModel(): void
    {
        $user = new PasskeyUser('ada@example.com', 'Ada Lovelace', 'handle-1');

        $entity = $this->repository($user)->findOneByUsername('ada@example.com');

        self::assertNotNull($entity);
        self::assertSame('ada@example.com', $entity->name);
        self::assertSame('handle-1', $entity->id);
        self::assertSame('Ada Lovelace', $entity->displayName);
    }

    public function testFallsBackToTheIdentifierWhenThereIsNoDisplayName(): void
    {
        $user = new PasskeyUser('ada@example.com', '', 'handle-1');

        $entity = $this->repository($user)->findOneByUsername('ada@example.com');

        self::assertNotNull($entity);
        self::assertSame('ada@example.com', $entity->displayName);
    }

    public function testReturnsNothingForAnUnknownIdentifier(): void
    {
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willThrowException(new UserNotFoundException());

        $repository = new WebauthnUserEntityRepository(
            $provider,
            $this->createStub(EntityManagerInterface::class),
            PasskeyUser::class,
        );

        self::assertNull($repository->findOneByUsername('nobody@example.com'));
    }

    /**
     * An application can hold users that carry no passkey: they are simply not WebAuthn users.
     */
    public function testReturnsNothingForAUserThatCarriesNoPasskey(): void
    {
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn(new InMemoryUser('ada@example.com', null));

        $repository = new WebauthnUserEntityRepository(
            $provider,
            $this->createStub(EntityManagerInterface::class),
            PasskeyUser::class,
        );

        self::assertNull($repository->findOneByUsername('ada@example.com'));
    }

    public function testFindsAUserByItsHandle(): void
    {
        $user = new PasskeyUser('ada@example.com', 'Ada Lovelace', 'handle-1');

        $entityRepository = $this->createStub(EntityRepository::class);
        $entityRepository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($entityRepository);

        $repository = new WebauthnUserEntityRepository(
            $this->createStub(UserProviderInterface::class),
            $entityManager,
            PasskeyUser::class,
        );

        $entity = $repository->findOneByUserHandle('handle-1');

        self::assertNotNull($entity);
        self::assertSame('handle-1', $entity->id);
    }

    public function testReturnsNothingForAnUnknownHandle(): void
    {
        $entityRepository = $this->createStub(EntityRepository::class);
        $entityRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($entityRepository);

        $repository = new WebauthnUserEntityRepository(
            $this->createStub(UserProviderInterface::class),
            $entityManager,
            PasskeyUser::class,
        );

        self::assertNull($repository->findOneByUserHandle('unknown'));
    }

    /**
     * The handle is generated on first use and written straight away: a ceremony that ran against
     * a handle nobody stored would register a key nothing could ever find again.
     */
    public function testGeneratesAndPersistsTheHandleOnFirstUse(): void
    {
        $user = new PasskeyUser('ada@example.com', 'Ada Lovelace', null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn($user);

        $repository = new WebauthnUserEntityRepository($provider, $entityManager, PasskeyUser::class);
        $entity = $repository->findOneByUsername('ada@example.com');

        self::assertNotNull($entity);
        self::assertNotNull($user->getWebauthnUserHandle());
        self::assertSame($user->getWebauthnUserHandle(), $entity->id);
    }

    public function testKeepsTheHandleItAlreadyHas(): void
    {
        $user = new PasskeyUser('ada@example.com', 'Ada Lovelace', 'handle-1');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn($user);

        $repository = new WebauthnUserEntityRepository($provider, $entityManager, PasskeyUser::class);

        self::assertNotNull($repository->findOneByUsername('ada@example.com'));
    }

    /**
     * Users are created by the application, never by a ceremony.
     */
    public function testSavingAUserEntityDoesNothing(): void
    {
        $user = new PasskeyUser('ada@example.com', 'Ada Lovelace', 'handle-1');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn($user);

        $repository = new WebauthnUserEntityRepository($provider, $entityManager, PasskeyUser::class);
        $entity = $repository->findOneByUsername('ada@example.com');

        self::assertNotNull($entity);
        $repository->saveUserEntity($entity);
    }

    private function repository(PasskeyUser $user): WebauthnUserEntityRepository
    {
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn($user);

        return new WebauthnUserEntityRepository(
            $provider,
            $this->createStub(EntityManagerInterface::class),
            PasskeyUser::class,
        );
    }
}
