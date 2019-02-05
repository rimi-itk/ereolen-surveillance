<?php

namespace App\Repository;

use App\Entity\CarouselClash;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method CarouselClash|null find($id, $lockMode = null, $lockVersion = null)
 * @method CarouselClash|null findOneBy(array $criteria, array $orderBy = null)
 * @method CarouselClash[]    findAll()
 * @method CarouselClash[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CarouselClashRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, CarouselClash::class);
    }

    // /**
    //  * @return CarouselClash[] Returns an array of CarouselClash objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?CarouselClash
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
