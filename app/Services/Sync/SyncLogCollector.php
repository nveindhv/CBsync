<?php

namespace App\Services\Sync;

class SyncLogCollector
{
    private int $totalScanned = 0;
    private int $totalValid = 0;

    private int $sent = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $errors = 0;

    /** @var array<string,int> */
    private array $skipReasons = [];

    /** @var array<string,int> */
    private array $errorReasons = [];

    /** @var array<int,array<string,mixed>> */
    private array $samplesSkipped = [];

    /** @var array<int,array<string,mixed>> */
    private array $samplesUpdated = [];

    /** @var array<int,array<string,mixed>> */
    private array $samplesErrors = [];

    public function __construct(
        private readonly bool $logEachItem,
        private readonly int $sampleSize
    ) {}

    public function incScanned(int $n = 1): void
    {
        $this->totalScanned += $n;
    }

    public function incValid(int $n = 1): void
    {
        $this->totalValid += $n;
    }

    public function markSent(int $n = 1): void
    {
        $this->sent += $n;
    }

    public function markUpdated(int $n = 1, array $sample = []): void
    {
        $this->updated += $n;
        $this->pushSample($this->samplesUpdated, $sample);

        if ($this->logEachItem) {
            logger()->info('[ITEM]', array_merge(['status' => 'UPDATED'], $sample));
        }
    }

    public function markSkipped(string $reason, array $sample = []): void
    {
        $this->skipped += 1;
        $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;

        $sample = array_merge(['reason' => $reason], $sample);
        $this->pushSample($this->samplesSkipped, $sample);

        if ($this->logEachItem) {
            logger()->info('[ITEM]', array_merge(['status' => 'SKIPPED'], $sample));
        }
    }

    public function markError(string $reason, array $sample = []): void
    {
        $this->errors += 1;
        $this->errorReasons[$reason] = ($this->errorReasons[$reason] ?? 0) + 1;

        $sample = array_merge(['reason' => $reason], $sample);
        $this->pushSample($this->samplesErrors, $sample);

        if ($this->logEachItem) {
            logger()->error('[ITEM]', array_merge(['status' => 'ERROR'], $sample));
        }
    }

    public function summary(): array
    {
        return [
            'total_scanned' => $this->totalScanned,
            'total_valid'   => $this->totalValid,
            'sent'          => $this->sent,
            'updated'       => $this->updated,
            'skipped'       => $this->skipped,
            'errors'        => $this->errors,
            'skip_reasons'  => $this->skipReasons,
            'error_reasons' => $this->errorReasons,
            'samples'       => [
                'skipped' => $this->samplesSkipped,
                'updated' => $this->samplesUpdated,
                'errors'  => $this->samplesErrors,
            ],
        ];
    }

    private function pushSample(array &$bucket, array $sample): void
    {
        if (empty($sample)) {
            return;
        }
        if (count($bucket) >= $this->sampleSize) {
            return;
        }
        $bucket[] = $sample;
    }
}
