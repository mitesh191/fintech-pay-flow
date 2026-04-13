<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\JsonRequestTrait;
use App\DTO\TransferRequest;
use App\Repository\TransactionRepositoryInterface;
use App\Security\ApiUser;
use App\Serializer\TransactionNormalizer;
use App\Service\TransferServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/transfers', name: 'api_transfers_')]
final class TransferController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly TransferServiceInterface       $transferService,
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly ValidatorInterface             $validator,
        private readonly RateLimiterFactory             $transferLimiterFactory,
        private readonly TransactionNormalizer          $transactionNormalizer,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $caller  = $this->getApiUser();
        // Rate-limit per authenticated user, not IP — prevents shared-IP bypass.
        $limiter = $this->transferLimiterFactory->create($caller->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Please slow down.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dto    = TransferRequest::fromArray($data);
        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[] = ['field' => $error->getPropertyPath(), 'message' => $error->getMessage()];
            }

            return $this->json(['error' => 'Validation failed.', 'details' => $details], Response::HTTP_BAD_REQUEST);
        }

        $transaction = $this->transferService->transfer($dto, $caller->getUserIdentifier());

        return $this->json($this->transactionNormalizer->normalize($transaction), Response::HTTP_CREATED);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page     = max(1, (int) $request->query->get('page', 1));
        $limit    = min(100, max(1, (int) $request->query->get('limit', 20)));
        $apiKeyId = $this->getApiUser()->getApiKey()->getId();

        $transactions = $this->txRepo->findAllPaginated($page, $limit, $apiKeyId);
        $total        = $this->txRepo->countAll($apiKeyId);

        return $this->json([
            'data'       => array_map($this->transactionNormalizer->normalize(...), $transactions),
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        // Uuid::fromString() is lenient with 16-byte strings (treats them as
        // raw binary UUIDs) so an explicit format check is required before parsing.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return $this->json(['error' => 'Invalid transaction ID.'], Response::HTTP_BAD_REQUEST);
        }

        $uuid = Uuid::fromString($id);

        $transaction = $this->txRepo->findByUuid($uuid);
        if ($transaction === null) {
            return $this->json(['error' => sprintf('Transaction "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        // Ownership: the caller must own the source account of the transaction.
        $sourceOwner = $transaction->getSourceAccount()->getApiKey()?->getId();
        if ($sourceOwner === null || (string) $sourceOwner !== $this->getApiUser()->getUserIdentifier()) {
            return $this->json(['error' => sprintf('Transaction "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->transactionNormalizer->normalize($transaction));
    }

    private function getApiUser(): ApiUser
    {
        $user = $this->getUser();
        assert($user instanceof ApiUser, 'Firewall guarantees an ApiUser on all /api/* routes.');
        return $user;
    }
}

