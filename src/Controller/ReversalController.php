<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\JsonRequestTrait;
use App\Security\ApiUser;
use App\Serializer\TransactionNormalizer;
use App\Service\ReversalServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/transfers', name: 'api_transfers_')]
final class ReversalController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly ReversalServiceInterface $reversalService,
        private readonly ValidatorInterface       $validator,
        private readonly TransactionNormalizer    $transactionNormalizer,
    ) {}

    #[Route('/{id}/reverse', name: 'reverse', methods: ['POST'])]
    public function reverse(string $id, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return $this->json(['error' => 'Invalid transaction ID.'], Response::HTTP_BAD_REQUEST);
        }

        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            return $this->json([
                'error'   => 'Validation failed.',
                'details' => [['field' => 'reason', 'message' => 'Reason is required and must be at most 500 characters.']],
            ], Response::HTTP_BAD_REQUEST);
        }

        $caller      = $this->getApiUser();
        $reversalTx  = $this->reversalService->reverse($id, $reason, $caller->getUserIdentifier());

        return $this->json($this->transactionNormalizer->normalize($reversalTx), Response::HTTP_CREATED);
    }

    private function getApiUser(): ApiUser
    {
        $user = $this->getUser();
        assert($user instanceof ApiUser, 'Firewall guarantees an ApiUser on all /api/* routes.');
        return $user;
    }
}
