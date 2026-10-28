<?php

use App\Exceptions\ConversionFailure;
use App\Services\AudioProcessRunner;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $input = stream_get_contents(STDIN, 65537);
    if (strlen($input) > 65536) {
        throw new ConversionFailure('invalid_contract');
    }
    $contract = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    $result = (new AudioProcessRunner($contract))->run();
    echo json_encode(['event' => 'complete', 'result' => $result], JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (ConversionFailure $error) {
    echo json_encode(['event' => 'failed', 'code' => $error->reason, 'transient' => $error->transient], JSON_THROW_ON_ERROR)."\n";
    exit(2);
} catch (Throwable) {
    echo json_encode(['event' => 'failed', 'code' => 'runner_failed', 'transient' => false], JSON_THROW_ON_ERROR)."\n";
    exit(3);
}
