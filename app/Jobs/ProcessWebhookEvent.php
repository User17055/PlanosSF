<?php

namespace App\Jobs;

use App\Models\WebhookInbox;
use App\Services\Billing\BillingException;
use App\Services\Billing\ReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300, 900, 1800, 3600];
    }

    public function __construct(public int $inboxId) {}

    public function handle(ReconciliationService $service): void
    {
        $inbox = WebhookInbox::find($this->inboxId);
        if (! $inbox || $inbox->status === 'processed') {
            return;
        }
        $inbox->forceFill(['status' => 'processing', 'attempts' => $inbox->attempts + 1])->save();

        try {
            $result = $service->processInbox($inbox);
            $inbox->forceFill(['status' => 'processed', 'processed_at' => now(), 'result' => mb_substr($result, 0, 1000)])->save();
        } catch (BillingException $e) {
            $inbox->forceFill(['status' => $e->isRetryable() ? 'retrying' : 'failed', 'result' => mb_substr($e->getMessage(), 0, 1000)])->save();
            if ($e->isRetryable()) {
                throw $e;
            }
        } catch (\Throwable $e) {
            $inbox->forceFill(['status' => 'retrying', 'result' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        WebhookInbox::whereKey($this->inboxId)->update(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
