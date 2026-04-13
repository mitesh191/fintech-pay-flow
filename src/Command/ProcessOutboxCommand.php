<?php

declare(strict_types=1);

namespace App\Command;

use App\Messenger\OutboxEventMessage;
use App\Repository\OutboxEventRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Outbox relay worker — bridges the transactional outbox to downstream brokers.
 *
 * Run continuously via Supervisor (see docker/supervisor/supervisord.conf):
 *   php bin/console app:process-outbox --batch-size=200 --sleep=100
 *
 * Scale strategy
 * ───────────────
 * • Multiple replicas can run in parallel because each batch is claimed
 *   with an UPDATE … WHERE status = 'pending' (optimistic lock on row).
 * • Exponential back-off inside OutboxEvent::recordFailure() prevents
 *   hot-spot retries on failing events.
 * • Poison pills (failed after max_retries) are skipped by the status filter
 *   and must be investigated via SELECT … WHERE status = 'failed'.
 *
 * At 2 M transfers/second each INSERT generates 1 outbox row → 2 M rows/s.
 * With --batch-size=500 and a 50 ms sleep, a single relay process can drain
 * ~10,000 events/s.  Scale horizontally with multiple workers partitioned
 * by event_type or aggregate_id hash.
 */
#[AsCommand(
    name:        'app:process-outbox',
    description: 'Relay pending outbox events to downstream consumers.',
)]
final class ProcessOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepo,
        private readonly EntityManagerInterface        $em,
        private readonly LoggerInterface               $logger,
        // Inject Symfony Messenger bus — swap for a Kafka producer, HTTP webhook
        // dispatcher, or any other transport without changing this command.
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events per iteration.', 100)
            ->addOption('sleep',      null, InputOption::VALUE_REQUIRED, 'Sleep between batches (ms).', 200)
            ->addOption('max-batches',null, InputOption::VALUE_REQUIRED, 'Stop after N batches (0 = infinite).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $batchSize  = (int) $input->getOption('batch-size');
        $sleepMs    = (int) $input->getOption('sleep');
        $maxBatches = (int) $input->getOption('max-batches');

        $io->title('Outbox Relay');
        $io->text(sprintf('batch-size=%d  sleep=%dms  max-batches=%s', $batchSize, $sleepMs, $maxBatches ?: '∞'));

        $iteration    = 0;
        $totalRelayed = 0;

        while ($maxBatches === 0 || $iteration < $maxBatches) {
            $events = $this->outboxRepo->findDueForProcessing($batchSize);

            if (empty($events)) {
                usleep($sleepMs * 1_000);
                $iteration++;
                continue;
            }

            $relayedInBatch = 0;

            // Mark all events as processing in a single flush to reduce DB round-trips.
            foreach ($events as $event) {
                $event->markProcessing();
            }
            $this->em->flush();

            foreach ($events as $event) {
                try {
                    $this->relay($event->getEventType(), $event->getAggregateId(), $event->getPayload());
                    $event->markPublished();
                    $relayedInBatch++;
                } catch (\Throwable $e) {
                    $this->logger->warning('Outbox relay failed', [
                        'event_id'   => (string) $event->getId(),
                        'event_type' => $event->getEventType(),
                        'error'      => $e->getMessage(),
                        'retry'      => $event->getRetryCount() + 1,
                    ]);
                    $event->recordFailure($e->getMessage());
                }
            }

            // Single flush for the entire batch of state changes.
            $this->em->flush();

            $totalRelayed += $relayedInBatch;
            $io->text(sprintf('[%s] Relayed %d events (total: %d)', date('H:i:s'), $relayedInBatch, $totalRelayed));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1_000);
            }

            $iteration++;
        }

        $io->success(sprintf('Done. Total events relayed: %d', $totalRelayed));

        return Command::SUCCESS;
    }

    /**
     * Relay an event to downstream consumers.
     *
     * Current implementation: dispatch via Symfony Messenger (if wired) or log.
     * Replace / extend with:
     *   - Kafka producer (rdkafka / php-rdkafka)
     *   - RabbitMQ publisher (php-amqplib)
     *   - HTTP webhook dispatcher
     *
     * @param array<string, mixed> $payload
     */
    private function relay(string $eventType, string $aggregateId, array $payload): void
    {
        if ($this->messageBus !== null) {
            $message = new OutboxEventMessage($eventType, $aggregateId, $payload);
            $this->messageBus->dispatch(new \Symfony\Component\Messenger\Envelope($message));
            return;
        }

        // Fallback: structured log (sufficient for single-node setups).
        $this->logger->info('Outbox event relayed', [
            'event_type'   => $eventType,
            'aggregate_id' => $aggregateId,
            'payload'      => $payload,
        ]);
    }
}
