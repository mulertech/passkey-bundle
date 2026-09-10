<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\ManagerRegistry;
use MulerTech\PasskeyBundle\Entity\WebauthnCredential;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\Bundle\Doctrine\Type as DbalType;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Ran against a real schema rather than a mocked query builder: what these methods have to get
 * right is the mapping and the stored representation, and a double would only confirm the calls
 * this test itself wrote.
 */
final class WebauthnCredentialRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private WebauthnCredentialRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = self::createEntityManager();

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([$this->entityManager->getClassMetadata(WebauthnCredential::class)]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->repository = new WebauthnCredentialRepository($registry);
    }

    /**
     * The column holds the credential id base64-encoded, so the raw binary the browser sends has
     * to be encoded before it can match. Without that, a registered key is never found again and
     * sign-in fails for a reason nothing explains.
     */
    public function testFindsACredentialByItsRawBinaryIdentifier(): void
    {
        $this->save('raw-binary-id', 'handle-1', 'MacBook');

        $found = $this->repository->findOneByCredentialId('raw-binary-id');

        self::assertInstanceOf(WebauthnCredential::class, $found);
        self::assertSame('raw-binary-id', $found->publicKeyCredentialId);
    }

    public function testFindsNothingForAnUnknownIdentifier(): void
    {
        $this->save('raw-binary-id', 'handle-1', 'MacBook');

        self::assertNull($this->repository->findOneByCredentialId('another-id'));
    }

    public function testListsOnlyTheKeysOfTheGivenUser(): void
    {
        $this->save('id-1', 'handle-1', 'MacBook');
        $this->save('id-2', 'handle-2', 'Phone of someone else');

        $credentials = $this->repository->findAllForUserEntity(
            PublicKeyCredentialUserEntity::create('ada@example.com', 'handle-1', 'Ada'),
        );

        self::assertCount(1, $credentials);
        self::assertSame('MacBook', $credentials[0]->getLabel());
    }

    /**
     * Oldest first: the list is read as a history, and a key that appears at a different place on
     * every visit cannot be recognised.
     */
    public function testListsTheKeysOldestFirst(): void
    {
        $this->save('id-1', 'handle-1', 'First', new \DateTimeImmutable('2026-01-01'));
        $this->save('id-2', 'handle-1', 'Second', new \DateTimeImmutable('2026-06-01'));

        $credentials = $this->repository->findAllForUserEntity(
            PublicKeyCredentialUserEntity::create('ada@example.com', 'handle-1', 'Ada'),
        );

        self::assertSame(['First', 'Second'], array_map(
            static fn (WebauthnCredential $credential): string => $credential->getLabel(),
            $credentials,
        ));
    }

    /**
     * The ceremony hands over a plain CredentialRecord, which carries none of our own columns: the
     * repository turns it into the entity rather than refusing what the library produces.
     */
    public function testConvertsAPlainRecordBeforeSavingIt(): void
    {
        $this->repository->saveCredentialRecord($this->record('id-1', 'handle-1'));

        $found = $this->repository->findOneByCredentialId('id-1');

        self::assertInstanceOf(WebauthnCredential::class, $found);
        self::assertSame('Passkey', $found->getLabel());
    }

    public function testSavesAnEntityAsItIs(): void
    {
        $credential = WebauthnCredential::fromRecord($this->record('id-1', 'handle-1'), 'MacBook');

        $this->repository->saveCredentialRecord($credential);

        $found = $this->repository->findOneByCredentialId('id-1');

        self::assertInstanceOf(WebauthnCredential::class, $found);
        self::assertSame('MacBook', $found->getLabel());
    }

    public function testRemovesACredential(): void
    {
        $this->save('id-1', 'handle-1', 'MacBook');

        $credential = $this->repository->findOneByCredentialId('id-1');
        self::assertInstanceOf(WebauthnCredential::class, $credential);

        $this->repository->remove($credential);

        self::assertNull($this->repository->findOneByCredentialId('id-1'));
    }

    private function save(string $id, string $userHandle, string $label, ?\DateTimeImmutable $createdAt = null): void
    {
        $credential = WebauthnCredential::fromRecord($this->record($id, $userHandle), $label);

        if (null !== $createdAt) {
            $reflection = new \ReflectionProperty(WebauthnCredential::class, 'createdAt');
            $reflection->setValue($credential, $createdAt);
        }

        $this->entityManager->persist($credential);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function record(string $id, string $userHandle): CredentialRecord
    {
        return new CredentialRecord(
            $id,
            'public-key',
            ['internal'],
            'none',
            new EmptyTrustPath(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key-bytes',
            $userHandle,
            0,
        );
    }

    private static function createEntityManager(): EntityManagerInterface
    {
        foreach ([
            'aaguid' => DbalType\AAGUIDDataType::class,
            'base64' => DbalType\Base64BinaryDataType::class,
            'trust_path' => DbalType\TrustPathDataType::class,
            'attested_credential_data' => DbalType\AttestedCredentialDataType::class,
            'public_key_credential_descriptor' => DbalType\PublicKeyCredentialDescriptorType::class,
        ] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        // PHP 8.4 makes lazy objects native; Doctrine still has to be told to use them rather
        // than the var-exporter ghosts it defaults to.
        $configuration->enableNativeLazyObjects(true);

        // The parent class is mapped by XML inside web-auth, our entity by attributes: the chain
        // is what lets one inherit from the other.
        $chain = new MappingDriverChain();
        $chain->addDriver(
            new XmlDriver(
                new SymfonyFileLocator(
                    [\dirname((string) (new \ReflectionClass(CredentialRecord::class))->getFileName(), 3).'/webauthn-symfony-bundle/src/Resources/config/doctrine-mapping' => 'Webauthn'],
                    '.orm.xml',
                ),
                '.orm.xml',
            ),
            'Webauthn',
        );
        $chain->addDriver(new AttributeDriver([__DIR__.'/../../src/Entity']), 'MulerTech\PasskeyBundle\Entity');
        $configuration->setMetadataDriverImpl($chain);

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration),
            $configuration,
        );
    }
}
