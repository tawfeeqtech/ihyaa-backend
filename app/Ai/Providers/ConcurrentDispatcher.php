<?php

namespace App\Ai\Providers;

use App\Ai\Support\EvaluationTimeBudget;
use App\Exceptions\Ai\EvaluationTimeoutException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * موزّع الطلبات المتزامنة — plan.md §1.3 (SRS-AI-P01/P02/P03).
 *
 * مجمّع Guzzle Async Pool (EachPromise) بتزامن 5 — طلب لكل Sub-Agent —
 * مع مهلة 45s لكل طلب و connect_timeout 10s على عميل Guzzle المكشوف.
 *
 * التزامن الحقيقي (I/O متوازٍ):
 * - المهام المتزامنة (كل `callable` تُرجع قيمة مباشرة) تُنفَّذ في المجمّع بالتتابع داخل العملية الواحدة؛
 * - للتوازي الفعلي مرِّر مهاماً تُرجع `PromiseInterface` غير حاجبة
 *   (مثل `$dispatcher->client()->postAsync(...)`) — يعتمدها المجمّع وينتظرها.
 *
 * التجمع بعد اكتمال الجميع: لا يُكمل `run()` إلا بعد تسوية كل المهام
 * (أو انقضاء مهلة كل طلب) — SRS-AI-P02.
 */
final class ConcurrentDispatcher
{
    private readonly ClientInterface $client;

    /**
     * @param  int  $concurrency  عدد الطلبات المتزامنة (SRS-AI-P01 — 5)
     * @param  float  $timeout  مهلة كل طلب بالثواني (SRS-AI-P03/E01 — 45)
     * @param  float  $connectTimeout  مهلة الاتصال بالثواني (10)
     * @param  EvaluationTimeBudget|null  $budget  ميزانية إجمالية (سقف 180s — T069)؛
     *                                    إذا غابت تُنشأ تلقائياً لكل run() عند انطلاقها
     */
    public function __construct(
        private readonly int $concurrency = 5,
        private readonly float $timeout = 45,
        private readonly float $connectTimeout = 10,
        ?ClientInterface $client = null,
        private readonly ?EvaluationTimeBudget $budget = null,
    ) {
        $this->client = $client ?? new GuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ]);
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * الميزانية المحقونة (إن وُجدت) — لاستعلام `shouldStopEscalation()`
     * من طبقة التصعيد. غيابها يعني أن run() يُنشئ ميزانية جديدة لكل استدعاء.
     */
    public function budget(): ?EvaluationTimeBudget
    {
        return $this->budget;
    }

    /**
     * عميل Guzzle المهرّأ بوقت المهلة المحدد — لبناء طلبات async حقيقية.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    /**
     * تشغيل مجموعة مهام عبر مجمّع Guzzle غير متزامن.
     *
     * @param  array<string, callable(): (PromiseInterface|mixed)>  $tasks  المفتاح = اسم البُعد
     *
     * @return array<string, mixed>  النتائج بنفس مفاتيح المهام (بالترتيب الأصلي)
     *
     * @throws Throwable عند فشل أي مهمة (يُرمى أول استثناء مع الحفاظ على نوعه)
     */
    public function run(array $tasks): array
    {
        // T069: ميزانية زمنية لكل تشغيل (سقف 180s) — تُنشأ عند الانطلاق إن لم تُحقن.
        $budget = $this->budget ?? EvaluationTimeBudget::start();

        $results = [];
        $failures = [];

        $pool = new EachPromise($this->promises($tasks, $budget), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (mixed $value, string $key) use (&$results): void {
                $results[$key] = $value;
            },
            'rejected' => function (mixed $reason, string $key) use (&$failures): void {
                $failures[$key] = $reason;
            },
        ]);

        $pool->promise()->wait();

        if ($failures !== []) {
            $firstReason = reset($failures);

            throw $firstReason instanceof Throwable
                ? $firstReason
                : new RuntimeException(sprintf('Concurrent task [%s] failed.', (string) key($failures)));
        }

        // حافظ على ترتيب المهام الأصلية.
        $ordered = [];

        foreach ($tasks as $key => $_) {
            $ordered[$key] = $results[$key] ?? null;
        }

        return $ordered;
    }

    /**
     * مولّد كسول للوعود — يُنشئ كل وعد عند طلبه من المجمّع،
     * فيفرض التزامن الفعلي (لا تُطلق الطلبات دفعة واحدة).
     *
     * T069: قبل إطلاق كل مهمة نتحقق من الميزانية — عند امتلائها نرفض المجمّع
     * بخطأ EvaluationTimeoutException (سقف 180s · SRS-AI-P02) ولا تُطلق مهام جديدة
     * (يتوقف التصعيد، والمجمّع يستقر على المهام المطلقة مسبقاً ثم يُترجم الخطأ إلى failed).
     *
     * @param  array<string, callable(): (PromiseInterface|mixed)>  $tasks
     *
     * @return \Generator<string, PromiseInterface>
     *
     * @throws EvaluationTimeoutException
     */
    private function promises(array $tasks, EvaluationTimeBudget $budget): \Generator
    {
        foreach ($tasks as $key => $task) {
            if (! $budget->canLaunch()) {
                throw new EvaluationTimeoutException($budget->ceilingSeconds(), $budget->elapsedSeconds());
            }

            yield $key => $this->toPromise($task);
        }
    }

    /**
     * يغلّف مهمة واحدة في وعد Guzzle (v2 — Create::promiseFor/rejectionFor):
     * القيمة المباشرة ← وعد مكتمل؛ الاستثناء ← وعد مرفوض؛ وعد سابق ← يُعتمد كما هو.
     *
     * @param  callable(): (PromiseInterface|mixed)  $task
     */
    private function toPromise(callable $task): PromiseInterface
    {
        try {
            $value = $task();

            return $value instanceof PromiseInterface
                ? $value
                : Create::promiseFor($value);
        } catch (Throwable $e) {
            return Create::rejectionFor($e);
        }
    }
}
