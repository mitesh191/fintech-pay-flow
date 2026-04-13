<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for all integration tests.
 *
 * Boots the full Symfony kernel against the test database, loads fixtures
 * once per class (not per test), and provides a pre-authenticated HTTP client.
 *
 * Security: the real ApiKeyAuthenticator runs on every request — no firewall
 * bypass. Tests must supply a valid Bearer token.
 */
abstract class SecureWebTestCase extends WebTestCase
{
    protected static KernelBrowser $client;

    /** Raw bearer token seeded by AppFixtures. */
    protected const API_KEY = AppFixtures::TEST_API_KEY_RAW;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $loader = new Loader();
        $loader->addFixture(new AppFixtures());

        $executor = new ORMExecutor($em, new ORMPurger($em));
        $executor->execute($loader->getFixtures());

        static::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        static::ensureKernelShutdown();
        static::$client = static::createClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        static::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        static::ensureKernelShutdown();

        parent::tearDownAfterClass();
    }

    /** Make an authenticated API request. */
    protected function api(
        string $method,
        string $uri,
        array  $body    = [],
        array  $headers = [],
    ): \Symfony\Component\HttpFoundation\Response {
        $serverHeaders = ['HTTP_AUTHORIZATION' => 'Bearer ' . static::API_KEY];
        foreach ($headers as $name => $value) {
            $serverHeaders['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        static::$client->request(
            $method,
            $uri,
            [],
            [],
            $serverHeaders,
            $body !== [] ? (string) json_encode($body, JSON_THROW_ON_ERROR) : '',
        );

        return static::$client->getResponse();
    }

    /** Decode the last response body as JSON. */
    protected function json(): array
    {
        $content = static::$client->getResponse()->getContent();

        return (array) json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }
}
