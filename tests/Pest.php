<?php

/*
|--------------------------------------------------------------------------
| Pest Bootstrap — Ihyaa backend
|--------------------------------------------------------------------------
| Feature tests boot the full Laravel app. Unit test files that need the
| container (e.g. reading config) declare `uses(Tests\TestCase::class)`
| at the top of the file — see tests/Unit/Ai/*.
*/

use Tests\TestCase;

uses(TestCase::class)->in('Feature');
