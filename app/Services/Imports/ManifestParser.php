<?php

namespace App\Services\Imports;

interface ManifestParser
{
    public function parse(string $contents): array;
}
