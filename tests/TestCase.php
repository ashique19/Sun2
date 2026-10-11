<?php

namespace Tests;

use App\Services\Admin\ProductImageMatchIndex;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // In-memory test databases reuse ids after rollback, so a compiled index from a previous
        // test can carry the same stamp.
        ProductImageMatchIndex::forget();
    }
}
