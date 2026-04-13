<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepositoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads ApiUser objects by their key hash (the user identifier stored
 * on the Symfony token after successful authentication).
 *
 * The "refresh" path is a no-op because the API is fully stateless:
 * every request re-authenticates independently.
 *
 * @implements UserProviderInterface<ApiUser>
 */
final class ApiKeyUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // identifier is the SHA-256 key hash set by ApiKeyAuthenticator
        $apiKey = $this->apiKeyRepository->findByHash($identifier);

        if ($apiKey === null) {
            $e = new UserNotFoundException();
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return new ApiUser($apiKey);
    }

    /** Stateless API — no session, no refresh required. */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ApiUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === ApiUser::class;
    }
}
