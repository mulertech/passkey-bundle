<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use MulerTech\PasskeyBundle\Entity\WebauthnCredential;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        /** @var WebauthnCredential|null $credential */
        $credential = $this->createQueryBuilder('c')
            ->where('c.publicKeyCredentialId = :id')
            // The column uses the "base64" Doctrine type: the raw binary id has to be encoded
            // to match what is stored.
            ->setParameter('id', base64_encode($publicKeyCredentialId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $credential;
    }

    /**
     * @return list<WebauthnCredential>
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        /** @var list<WebauthnCredential> $credentials */
        $credentials = $this->createQueryBuilder('c')
            ->where('c.userHandle = :userHandle')
            ->setParameter('userHandle', $publicKeyCredentialUserEntity->id)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $credentials;
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        if (!$credentialRecord instanceof WebauthnCredential) {
            $credentialRecord = WebauthnCredential::fromRecord($credentialRecord, 'Passkey');
        }

        $this->getEntityManager()->persist($credentialRecord);
        $this->getEntityManager()->flush();
    }

    public function remove(WebauthnCredential $credential): void
    {
        $this->getEntityManager()->remove($credential);
        $this->getEntityManager()->flush();
    }
}
