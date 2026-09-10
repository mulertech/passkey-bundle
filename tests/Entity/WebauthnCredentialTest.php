<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests\Entity;

use MulerTech\PasskeyBundle\Entity\WebauthnCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

final class WebauthnCredentialTest extends TestCase
{
    public function testCarriesOverEveryFieldOfTheRecord(): void
    {
        $credential = WebauthnCredential::fromRecord($this->record(), 'MacBook');

        self::assertSame('credential-id', $credential->publicKeyCredentialId);
        self::assertSame('public-key', $credential->type);
        self::assertSame(['internal'], $credential->transports);
        self::assertSame('none', $credential->attestationType);
        self::assertSame('user-handle', $credential->userHandle);
        self::assertSame(7, $credential->counter);
    }

    public function testTakesTheLabelItIsGivenAndStampsTheDate(): void
    {
        $before = new \DateTimeImmutable();
        $credential = WebauthnCredential::fromRecord($this->record(), 'MacBook');

        self::assertSame('MacBook', $credential->getLabel());
        self::assertGreaterThanOrEqual($before, $credential->getCreatedAt());
    }

    public function testLabelCanBeRenamed(): void
    {
        $credential = WebauthnCredential::fromRecord($this->record(), 'MacBook');
        $credential->setLabel('Backup key');

        self::assertSame('Backup key', $credential->getLabel());
    }

    /**
     * The identifier belongs to the database: an entity that has never been persisted has none.
     */
    public function testHasNoIdentifierBeforeItIsPersisted(): void
    {
        self::assertNull(WebauthnCredential::fromRecord($this->record(), 'MacBook')->getId());
    }

    private function record(): CredentialRecord
    {
        return new CredentialRecord(
            'credential-id',
            'public-key',
            ['internal'],
            'none',
            new EmptyTrustPath(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key-bytes',
            'user-handle',
            7,
        );
    }
}
