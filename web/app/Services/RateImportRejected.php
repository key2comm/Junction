<?php

namespace App\Services;

use RuntimeException;

/** A rate import whose rows have errors; nothing was applied. */
class RateImportRejected extends RuntimeException
{
    /** @param  list<string>  $errors */
    public function __construct(public readonly array $errors, public readonly int $rows)
    {
        parent::__construct($errors[0] ?? 'The import was rejected.');
    }
}
