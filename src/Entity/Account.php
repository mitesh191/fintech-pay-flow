<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\Repository\AccountRepository;
use App\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $ownerName;

    #[ORM\Column(length: 20, unique: true)]
    private string $accountNumber;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    private string $balance;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    #[ORM\ManyToOne(targetEntity: ApiKey::class)]
    #[ORM\JoinColumn(name: 'api_key_id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiKey $apiKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $ownerName,
        string $currency,
        string $initialBalance = '0.0000',
        ?ApiKey $apiKey = null,
    ) {
        $this->id            = Uuid::v7();
        $this->ownerName     = $ownerName;
        $this->accountNumber = self::generateAccountNumber();
        $this->currency      = strtoupper($currency);
        $this->balance       = bcadd($initialBalance, '0', 4);
        $this->apiKey        = $apiKey;
        $this->createdAt     = new \DateTimeImmutable();
        $this->updatedAt     = new \DateTimeImmutable();
    }

    /**
     * Generate a human-readable account number: FT + 12 random digits.
     */
    private static function generateAccountNumber(): string
    {
        return 'FT' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwnerName(): string
    {
        return $this->ownerName;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function getBalanceMoney(): Money
    {
        return Money::of($this->balance, $this->currency);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getApiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function credit(Money $money): void
    {
        if ($money->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException(
                sprintf('Cannot credit %s into %s account.', $money->getCurrency(), $this->currency)
            );
        }

        $this->balance   = bcadd($this->balance, $money->getAmount(), 4);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function debit(Money $money): void
    {
        if ($money->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException(
                sprintf('Cannot debit %s from %s account.', $money->getCurrency(), $this->currency)
            );
        }

        if (bccomp($money->getAmount(), $this->balance, 4) > 0) {
            throw new InsufficientFundsException();
        }

        $this->balance   = bcsub($this->balance, $money->getAmount(), 4);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->active    = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function renameOwner(string $newOwnerName): void
    {
        if (trim($newOwnerName) === '' || mb_strlen($newOwnerName) > 255) {
            throw new \InvalidArgumentException('Owner name must be between 1 and 255 characters.');
        }

        $this->ownerName = $newOwnerName;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
