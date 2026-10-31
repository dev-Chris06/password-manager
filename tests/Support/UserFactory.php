<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Model\KdfParameters;
use Doctrine\ORM\EntityManagerInterface;

final class UserFactory
{
    public static function create(EntityManagerInterface $entityManager, string $email = 'owner@example.com'): User
    {
        $user = new User(
            $email,
            KdfParameters::defaults(),
            base64_encode(random_bytes(16)),
            password_hash(base64_encode(random_bytes(32)), \PASSWORD_BCRYPT),
            base64_encode(random_bytes(61)),
        );
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
