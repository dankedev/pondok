<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // State statis Livewire (mis. aset yang sudah disuntik) tidak boleh bocor antar test.
        Livewire::flushState();
    }
}
