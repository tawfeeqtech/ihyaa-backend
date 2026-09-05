<?php

namespace App\Exceptions\Search;

use RuntimeException;
use Throwable;

/**
 * محرك البحث (Meilisearch) غير متاح — SRS-UI-28 · search-api.md §1.
 * تُترجم إلى 503 SEARCH_UNAVAILABLE retryable:true في طبقة التحكم.
 */
class SearchUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Search engine unavailable', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
