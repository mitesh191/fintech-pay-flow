<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Stateless Bearer-token authenticator.
 *
 * Protocol:
 *   Authorization: Bearer <raw_token>
 *
 * The raw token is SHA-256 hashed in constant-time before the DB lookup so
 * the database never stores or compares a value that could be stolen and
 * replayed directly.
 *
 * Timing note: hash('sha256', …) runs in microseconds — the DB round-trip
 * will always dominate, so there is no practical timing side-channel here.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Only intercept requests that carry an Authorization header.
     * Requests to /health bypass the firewall entirely (security: false).
     */
    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $authorization = $request->headers->get('Authorization', '');

        if (!str_starts_with($authorization, self::BEARER_PREFIX)) {
            throw new CustomUserMessageAuthenticationException(
                'Authorization header must use Bearer scheme.',
            );
        }

        $rawToken = substr($authorization, \strlen(self::BEARER_PREFIX));

        if ($rawToken === '') {
            throw new CustomUserMessageAuthenticationException('Bearer token must not be empty.');
        }

        $keyHash = ApiKey::hashToken($rawToken);

        return new SelfValidatingPassport(
            new UserBadge($keyHash, function (string $hash): ApiUser {
                $apiKey = $this->apiKeyRepository->findByHash($hash);

                if ($apiKey === null) {
                    throw new CustomUserMessageAuthenticationException('Invalid or revoked API key.');
                }

                return new ApiUser($apiKey);
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Continue to the controller — the token is now on the security stack.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => 'Unauthorized. ' . $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="fund-transfer-api"'],
        );
    }
}
