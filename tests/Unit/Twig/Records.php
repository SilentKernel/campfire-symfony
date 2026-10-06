<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

/** Builds saved-looking entities (ids are assigned by Doctrine, so set them by reflection). */
final class Records
{
    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public static function saved(object $entity, int $id, ?\DateTimeImmutable $updatedAt = null): object
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $class = $class->getParentClass() ?: throw new \LogicException('No id property.');
        }
        $class->getProperty('id')->setValue($entity, $id);
        if (null !== $updatedAt && method_exists($entity, 'setUpdatedAt')) {
            $entity->setUpdatedAt($updatedAt);
        }

        return $entity;
    }
}
