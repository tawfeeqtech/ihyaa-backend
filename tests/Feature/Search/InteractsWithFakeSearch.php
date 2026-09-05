<?php

namespace Tests\Feature\Search;

use Laravel\Scout\EngineManager;

/**
 * تسجيل محرك البحث الوهمي لاختبارات EPIC-06.
 *
 * الاستخدام:
 *   uses(RefreshDatabase::class);
 *   beforeEach(fn () => $this->useFakeSearchEngine());
 */
trait InteractsWithFakeSearch
{
    protected function useFakeSearchEngine(): void
    {
        config(['scout.driver' => 'fake']);

        app(EngineManager::class)->extend('fake', fn () => new FakeSearchEngine());

        FakeSearchEngine::flushAll();
    }
}
