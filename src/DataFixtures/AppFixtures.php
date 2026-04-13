<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\ApiKey;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    /**
     * The raw bearer token used by the test API client.
     * The SHA-256 hash of this value is stored in the database; this constant
     * is shared with SecureWebTestCase so tests never hard-code the token string.
     */
    public const TEST_API_KEY_RAW = 'test-api-key-fixture-00000000000001';

    public function load(ObjectManager $manager): void
    {
        // Create the test API key — only the hash is persisted.
        $apiKey = new ApiKey('Test Client', self::TEST_API_KEY_RAW);
        $manager->persist($apiKey);

        $accounts = [
            ['Alice', 'USD', '10000.0000'],
            ['Bob',   'USD', '5000.0000'],
            ['Carol', 'EUR', '7000.0000'],
        ];

        foreach ($accounts as [$name, $currency, $balance]) {
            $manager->persist(new Account($name, $currency, $balance, $apiKey));
        }

        $manager->flush();
    }
}
