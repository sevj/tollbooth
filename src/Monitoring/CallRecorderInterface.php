<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring;

interface CallRecorderInterface
{
    public function record(CallRecord $call): void;
}
