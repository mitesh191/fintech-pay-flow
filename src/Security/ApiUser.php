<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Wraps an authenticated ApiKey as a Symfony security principal.
 *
 * This class is instantiated by ApiKeyAuthenticator on every authenticated
 * request and carries the full ApiKey entity so controllers can access the
 * caller's identity without an extra DB round-trip.
 */
final class ApiUser implements UserInterface
{
    public function __construct(private readonly ApiKey $apiKey) {}

    /**
     * Returns the API key's UUID — used as the stable user identifier
     * throughout the security system and for ownership comparisons.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->apiKey->getId();
    }

    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    /** No sensitive credentials to erase for a hashed-token principal. */
    public function eraseCredentials(): void {}

    public function getApiKey(): ApiKey
    {
        return $this->apiKey;
    }
}
