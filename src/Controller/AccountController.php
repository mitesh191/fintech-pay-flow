<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\JsonRequestTrait;
use App\DTO\CreateAccountRequest;
use App\Entity\Account;
use App\Exception\AccountNotFoundException;
use App\Exception\NonZeroBalanceException;
use App\Repository\AccountRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Security\ApiUser;
use App\Serializer\AccountNormalizer;
use App\Serializer\TransactionNormalizer;
use App\Service\AccountServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/accounts', name: 'api_accounts_')]
final class AccountController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly AccountServiceInterface        $accountService,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ValidatorInterface             $validator,
        private readonly AccountRepositoryInterface     $accountRepository,
        private readonly AccountNormalizer              $accountNormalizer,
        private readonly TransactionNormalizer          $transactionNormalizer,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dto    = CreateAccountRequest::fromArray($data);
        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[] = ['field' => $error->getPropertyPath(), 'message' => $error->getMessage()];
            }

            return $this->json(['error' => 'Validation failed.', 'details' => $details], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->accountService->create($dto, $this->getApiUser()->getApiKey());

        return $this->json($this->accountNormalizer->normalize($account), Response::HTTP_CREATED);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page     = max(1, (int) $request->query->get('page', 1));
        $limit    = min(100, max(1, (int) $request->query->get('limit', 20)));
        $apiKeyId = $this->getApiUser()->getApiKey()->getId();

        $accounts = $this->accountRepository->findPaginated($page, $limit, $apiKeyId);
        $total    = $this->accountRepository->countAll($apiKeyId);

        return $this->json([
            'data'       => array_map($this->accountNormalizer->normalize(...), $accounts),
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
        $account = $this->loadOwnedAccount($id);
        if ($account === null) {
            return $this->json(['error' => sprintf('Account "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->accountNormalizer->normalize($account));
    }

    #[Route('/{id}/transactions', name: 'transactions', methods: ['GET'])]
    public function transactions(string $id, Request $request): JsonResponse
    {
        $account = $this->loadOwnedAccount($id);
        if ($account === null) {
            return $this->json(['error' => sprintf('Account "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $accountUuid  = $account->getId();
        $transactions = $this->transactionRepository->findByAccount($accountUuid, $page, $limit);
        $total        = $this->transactionRepository->countByAccount($accountUuid);

        return $this->json([
            'data'       => array_map(
                $this->transactionNormalizer->normalizeForList(...),
                $transactions,
            ),
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $account = $this->loadOwnedAccount($id);
        if ($account === null) {
            return $this->json(['error' => sprintf('Account "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!array_key_exists('owner_name', $data)) {
            return $this->json(
                ['error' => 'Validation failed.', 'details' => [['field' => 'owner_name', 'message' => 'owner_name is required.']]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $errors = $this->validator->validate(trim((string) $data['owner_name']), [
            new NotBlank(message: 'owner_name must not be blank.'),
            new Length(
                min: 1,
                max: 255,
                maxMessage: 'owner_name must not exceed 255 characters.',
            ),
        ]);

        if (count($errors) > 0) {
            $details = [];
            foreach ($errors as $error) {
                $details[] = ['field' => 'owner_name', 'message' => $error->getMessage()];
            }
            return $this->json(['error' => 'Validation failed.', 'details' => $details], Response::HTTP_BAD_REQUEST);
        }

        $updated = $this->accountService->updateOwnerName($account, trim((string) $data['owner_name']));

        return $this->json($this->accountNormalizer->normalize($updated));
    }

    #[Route('/{id}', name: 'deactivate', methods: ['DELETE'])]
    public function deactivate(string $id): JsonResponse
    {
        $account = $this->loadOwnedAccount($id);
        if ($account === null) {
            return $this->json(['error' => sprintf('Account "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accountService->deactivate($account);
        } catch (NonZeroBalanceException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Load an account and verify the authenticated caller owns it.
     * Returns null (→ 404) instead of 403 to prevent account enumeration.
     */
    private function loadOwnedAccount(string $id): ?Account
    {
        try {
            $account = $this->accountService->getById($id);
        } catch (AccountNotFoundException) {
            return null;
        }

        $ownerId = $account->getApiKey()?->getId();
        if ($ownerId === null || (string) $ownerId !== $this->getApiUser()->getUserIdentifier()) {
            return null;
        }

        return $account;
    }

    private function getApiUser(): ApiUser
    {
        $user = $this->getUser();
        assert($user instanceof ApiUser, 'Firewall guarantees an ApiUser on all /api/* routes.');
        return $user;
    }
}

