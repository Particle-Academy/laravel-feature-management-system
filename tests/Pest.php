<?php

/*
| All current test classes extend ParticleAcademy\Fms\Tests\TestCase directly
| (PHPUnit class-style). A `uses(TestCase::class)->in(...)` here would conflict
| with the explicit inheritance and Pest errors with "test case can not be
| used / already uses ...". If we add Pest function-style tests later, scope
| the `uses()` call to a folder that contains only those.
*/
