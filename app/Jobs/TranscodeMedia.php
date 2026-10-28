<?php

namespace App\Jobs;

use App\Actions\Conversions\ConversionExecution;
use App\Exceptions\ConversionFailure;
use App\Services\AudioTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class TranscodeMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 800;

    public function __construct(public string $conversionId, public int $generation, public string $token) {}

    public function handle(ConversionExecution $execution, AudioTranscoder $transcoder): void
    {
        $claim = $execution->claim($this->conversionId, $this->generation, $this->token);
        if (! $claim) {
            return;
        }
        try {
            $result = $transcoder->convert($claim['source'], $claim['reservation'], fn (float $seconds, bool $validating): bool => $execution->heartbeat($this->conversionId, $this->generation, $this->token, $seconds, $validating));
            $execution->finalize($this->conversionId, $this->generation, $this->token, $result);
        } catch (ConversionFailure $error) {
            $execution->failed($this->conversionId, $this->generation, $this->token, $error);
        } catch (ProcessTimedOutException) {
            $execution->failed($this->conversionId, $this->generation, $this->token, new ConversionFailure('timeout', true));
        }
    }

    public function failed(?Throwable $error): void
    {
        $transient = $error instanceof TimeoutExceededException || $error instanceof MaxAttemptsExceededException || ($error instanceof QueryException && (str_starts_with((string) $error->getCode(), '08') || $error->getCode() === '40001' || in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 2006, 2013], true)));
        app(ConversionExecution::class)->failed($this->conversionId, $this->generation, $this->token, new ConversionFailure($error instanceof TimeoutExceededException ? 'timeout' : 'worker_failed', $transient));
    }
}
