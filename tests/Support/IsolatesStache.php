<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Tests\Support;

/**
 * Gives each test that uses it its own Stache, blueprint and fieldset
 * directories, as `StacheIsolation` describes.
 *
 * Testbench calls `setUpIsolatesStache()` after the application boots and
 * `tearDownIsolatesStache()` before it is destroyed, for any test that uses the
 * trait.
 */
trait IsolatesStache
{
    /**
     * The isolation in force for the running test.
     */
    private StacheIsolation $stacheIsolation;

    /**
     * Isolate the Stache before the test runs.
     */
    protected function setUpIsolatesStache(): void
    {
        $this->stacheIsolation = StacheIsolation::start();
    }

    /**
     * Put the directories back once the test ends.
     */
    protected function tearDownIsolatesStache(): void
    {
        $this->stacheIsolation->stop();
    }
}
