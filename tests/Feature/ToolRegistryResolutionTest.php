<?php

namespace Tests\Feature;

use App\Tools\ToolRegistry;
use Tests\TestCase;

class ToolRegistryResolutionTest extends TestCase
{
    public function test_the_tool_registry_can_be_resolved_from_the_container(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertInstanceOf(ToolRegistry::class, $registry);
        $this->assertNotNull($registry->getTool('Agent'));
    }
}
