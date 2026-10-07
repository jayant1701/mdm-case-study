<?php

namespace App\Data;

final readonly class AssetWriteResult
{
    public function __construct(public bool $created, public bool $changed) {}
}
