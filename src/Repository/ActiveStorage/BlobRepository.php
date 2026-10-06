<?php

declare(strict_types=1);

namespace App\Repository\ActiveStorage;

use App\Entity\ActiveStorage\Blob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Blob> */
final class BlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Blob::class);
    }

    public function findOneByKey(string $key): ?Blob
    {
        return $this->findOneBy(['key' => $key]);
    }
}
