<?php

namespace App\Services\IntegrationRuns;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Bus\QueueingDispatcher;

/**
 * Stands in for the bus while a batched fetch job dispatches its processing
 * jobs, so each one joins the fetch job's run without the ~50 fetch jobs
 * changing how they dispatch.
 *
 * The job is counted into the batch before it is queued, then queued through
 * the real dispatcher, so its own queue, connection and delay are kept
 * (`Batch::add()` would move every job onto the batch's queue). Anything
 * that is not an unbatched processing job passes straight through.
 */
class RunBatchDispatcher implements QueueingDispatcher
{
    public function __construct(
        private QueueingDispatcher $dispatcher,
        private BatchRepository $batches,
        private string $batchId,
    ) {}

    public function dispatch($command): mixed
    {
        if ($command instanceof BaseProcessingJob && $command->batchId === null) {
            $this->batches->incrementTotalJobs($this->batchId, 1);
            $command->withBatchId($this->batchId);
        }

        return $this->dispatcher->dispatch($command);
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatcher->dispatchSync($command, $handler);
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return $this->dispatcher->dispatchNow($command, $handler);
    }

    public function dispatchToQueue($command): mixed
    {
        return $this->dispatcher->dispatchToQueue($command);
    }

    public function findBatch(string $batchId): mixed
    {
        return $this->dispatcher->findBatch($batchId);
    }

    public function batch($jobs): mixed
    {
        return $this->dispatcher->batch($jobs);
    }

    public function hasCommandHandler($command): bool
    {
        return $this->dispatcher->hasCommandHandler($command);
    }

    public function getCommandHandler($command): mixed
    {
        return $this->dispatcher->getCommandHandler($command);
    }

    public function pipeThrough(array $pipes): static
    {
        $this->dispatcher->pipeThrough($pipes);

        return $this;
    }

    public function map(array $map): static
    {
        $this->dispatcher->map($map);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->dispatcher->{$method}(...$parameters);
    }
}
