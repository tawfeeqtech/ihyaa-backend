# خارطة طريق الربط بين Laravel وFastAPI

## 1. الهدف

صاحب الفكرة يحفظ بيانات المشروع في Laravel. عند طلب التقييم، Laravel يقرأ بيانات المشروع ويرسلها إلى FastAPI. Laravel لا ينفذ التقييم ولا يستدعي `EvaluationOrchestrator` لهذا المسار؛ بل يعمل كوسيط:

```text
صاحب الفكرة → Laravel → FastAPI → Webhook إلى Laravel → لوحة التحكم
```

المسؤوليات:

| النظام | المسؤولية |
|---|---|
| Laravel | مصدر بيانات المشروع، التحقق من حالة المشروع، إرسال طلب التقييم إلى FastAPI، استقبال Webhook، حفظ النتيجة، توفير API للوحة التحكم |
| FastAPI | استقبال طلب التقييم القادم من Laravel، تنفيذ التقييم وAI، تجهيز التقرير، إرسال Webhook إلى Laravel |

التقييم الخارجي لا يستخدم في Laravel:

```text
EvaluateProjectJob
EvaluationOrchestrator
EvaluationEngineFactory
OpenAI/Claude providers داخل Laravel
```

يمكن إبقاء هذه المكونات لمسار التقييم الداخلي القديم، لكنها ليست جزءاً من تكامل FastAPI.

---

## 2. قرارات معتمدة

هذه القرارات نهائية للإصدار الأول:

1. `project_id` موجود داخل Request الذي يرسله Laravel إلى FastAPI.
2. Laravel هو مصدر البيانات الأساسي؛ FastAPI لا يجمع بيانات المشروع من صاحب الفكرة ولا يقرأ قاعدة بيانات Laravel.
3. Laravel لا يرسل طلب التقييم إلا إذا كانت حالة نشر المشروع `published`.
4. FastAPI مسؤول عن التقييم فقط، ولا يصبح مالكاً لبيانات المشروع.

## 3. بيانات المشروع المعتمدة

Laravel يقرأ الحقول التالية من نموذج `Project` ويرسلها إلى FastAPI ضمن طلب التقييم:

| الحقل | النوع | القاعدة |
|---|---|---|
| `project_id` | integer | معرف المشروع في Laravel |
| `title` | string | من 5 إلى 120 حرفاً |
| `description` | string | من 50 إلى 2000 حرفاً |
| `bio` | string/null | بحد أقصى 500 حرف |
| `category_id` | integer | موجود في `categories` |
| `status` | enum | `completed`, `needs_development`, `needs_funding` |
| `publication_status` | enum | `draft`, `published`, `archived` |
| `tags` | array<string> | حتى 10 وسوم |
| `team` | array<object> | `name` مطلوب و`role` اختياري |
| `github_url` | string/null | رابط GitHub صالح |
| `video_url` | string/null | رابط YouTube أو Vimeo |
| `video_provider` | string/null | `youtube` أو `vimeo` |
| `budget_min` | number/null | أكبر أو يساوي صفر |
| `budget_max` | number/null | أكبر أو يساوي `budget_min` |
| `visibility_level` | integer | من 1 إلى 3 |

لا نضيف إلى العقد الحالي حقولاً غير موجودة في `Project` مثل:

```text
github_readme
docs_meta
technologies
market
competitors
roadmap
video_description
```

ملاحظات:

- `github_url` رابط فقط؛ FastAPI لا يحصل على README إلا إذا أضيفت هذه الوظيفة لاحقاً.
- `docs_meta` ليست مدخلاً مستقلاً؛ ملفات المشروع الحالية تبقى داخل Laravel ولا تُرسل ضمن الإصدار الأول.
- `tags` هي الوسوم المخزنة فعلياً، ولا يوجد حقل `technologies` مستقل.
- التقييم يبدأ فقط عندما يكون `publication_status = published`.

---

## 4. دورة الطلب الكاملة

### 4.1 إنشاء طلب التقييم في Laravel

صاحب الفكرة يرسل بيانات المشروع إلى Laravel ضمن التدفق الطبيعي لإنشاء أو تعديل المشروع. عند ضغط طلب التقييم، Laravel يستخدم البيانات المخزنة لديه، ولا ينتظر Payload جديداً من FastAPI.

Laravel ينشئ سجل التقييم ثم يرسل طلباً داخلياً إلى FastAPI. صاحب المشروع يستخدم مصادقة Laravel الحالية، ولا يتعامل مباشرة مع مفتاح FastAPI.

Body المرسل من Laravel إلى FastAPI:

```json
{
  "project_id": 17,
  "title": "عنوان المشروع",
  "description": "وصف المشروع، ويجب أن يكون بين 50 و2000 حرف.",
  "bio": "وصف مختصر للمشروع",
  "category_id": 1,
  "status": "needs_development",
  "publication_status": "published",
  "tags": ["laravel", "ai"],
  "team": [
    {
      "name": "اسم العضو",
      "role": "Backend Developer"
    }
  ],
  "github_url": "https://github.com/example/project",
  "video_url": null,
  "video_provider": null,
  "budget_min": 0,
  "budget_max": 10000,
  "visibility_level": 1
}
```

Laravel يقوم بالآتي:

1. يتحقق من هوية صاحب المشروع وصلاحية الوصول إلى المشروع عبر مصادقة Laravel الحالية.
2. يتحقق من Payload بنفس قواعد `Project`.
3. يتحقق من أن المشروع منشور.
4. ينشئ `Idempotency-Key` للطلب أو يعيد استخدامه عند إعادة المحاولة.
5. ينشئ Evaluation بحالة `pending` إذا كان الطلب جديداً.
6. يحفظ Snapshot من Payload.
7. يرسل Payload إلى FastAPI.
8. يحفظ `python_evaluation_id` وحالة FastAPI.
9. يعيد النتيجة الأولية فوراً.

Response:

```json
{
  "data": {
    "evaluation_id": 42,
    "project_id": 17,
    "python_evaluation_id": "py-eval-123",
    "status": "pending"
  }
}
```

Laravel لا يشغل Job لتقييم المشروع في هذه المرحلة.

### 4.2 استقبال طلب التقييم في FastAPI

FastAPI يستقبل الطلب المرسل من Laravel على:

```http
POST /api/v1/evaluations
X-API-Key: <fastapi-api-key>
Idempotency-Key: <same-key>
Content-Type: application/json
```

Body:

```json
{
  "project_id": 17,
  "callback_id": "laravel-evaluation-42",
  "idempotency_key": "project-17-evaluation-001",
  "callback_url": "https://laravel.example.com/api/webhooks/evaluations",
  "project": {
    "title": "عنوان المشروع",
    "description": "وصف المشروع، ويجب أن يكون بين 50 و2000 حرف.",
    "bio": "وصف مختصر للمشروع",
    "category_id": 1,
    "status": "needs_development",
    "publication_status": "published",
    "tags": ["laravel", "ai"],
    "team": [
      {
        "name": "اسم العضو",
        "role": "Backend Developer"
      }
    ],
    "github_url": "https://github.com/example/project",
    "video_url": null,
    "video_provider": null,
    "budget_min": 0,
    "budget_max": 10000,
    "visibility_level": 1
  }
}
```

FastAPI يتحقق من الطلب ويرد بسرعة:

```json
{
  "evaluation_id": "py-eval-123",
  "callback_id": "laravel-evaluation-42",
  "status": "queued"
}
```

### 4.3 تنفيذ التقييم في FastAPI

FastAPI هو المسؤول عن:

1. استلام بيانات المشروع.
2. إنشاء مهمة تقييم في Worker/Queue.
3. تنفيذ وكلاء التقييم الخمسة.
4. حساب الدرجات.
5. إنشاء الفجوات والتوصيات والمهارات والتحذيرات.
6. تحديد `completed` أو `partial` أو `failed`.
7. إرسال Webhook إلى Laravel.

لا يبقى طلب HTTP مفتوحاً أثناء تنفيذ AI.

### 4.4 Webhook من FastAPI إلى Laravel

عند انتهاء التقييم، FastAPI يرسل:

```http
POST /api/webhooks/evaluations
X-Webhook-Id: webhook-987
X-Webhook-Timestamp: 1789043400
X-Webhook-Signature: sha256=<signature>
Content-Type: application/json
```

عند النجاح:

```json
{
  "event_id": "webhook-987",
  "callback_id": "laravel-evaluation-42",
  "python_evaluation_id": "py-eval-123",
  "project_id": 17,
  "status": "completed",
  "overall_score": 78.4,
  "confidence_score": 91.2,
  "report": {
    "schema_version": "1.0",
    "overall_score": 78.4,
    "dimensions": {},
    "gap_analysis": {},
    "recommendations": {},
    "required_skills": [],
    "warnings": [],
    "partial_dimensions": []
  },
  "completed_at": "2026-09-10T12:30:00Z"
}
```

عند الفشل:

```json
{
  "event_id": "webhook-988",
  "callback_id": "laravel-evaluation-42",
  "python_evaluation_id": "py-eval-123",
  "project_id": 17,
  "status": "failed",
  "error_code": "EVALUATION_FAILED",
  "message": "Evaluation failed"
}
```

Laravel يقوم عند استلام Webhook بالآتي:

1. يتحقق من التوقيع والوقت.
2. يتحقق من `event_id` لمنع التكرار.
3. يبحث عن Evaluation عبر `callback_id` أو `python_evaluation_id`.
4. يتحقق من `project_id`.
5. يحفظ التقرير في `evaluations.result`.
6. يحدّث الحالة والدرجات ووقت الإكمال.
7. يحدّث `projects.ai_score` عند نجاح التقييم.
8. يعيد `200`.

إذا وصل نفس Webhook مرة أخرى، يعيد Laravel `200` دون معالجة ثانية.

### 4.5 قراءة النتيجة من لوحة التحكم

تبقى لوحة التحكم تقرأ من Laravel:

```http
GET /api/integrations/evaluations/42/status
Authorization: Bearer <sanctum-token>
```

```http
GET /api/integrations/evaluations/42
Authorization: Bearer <sanctum-token>
```

الحالات:

```text
pending
processing
completed
partial
failed
```

---

# 5. خطة تنفيذ Laravel Backend

## L1: تعديل نموذج التقييم

إضافة Migration إلى جدول `evaluations`:

```text
python_evaluation_id nullable string
webhook_event_id nullable string unique
external_idempotency_key nullable string unique
```

يُستخدم:

- `external_idempotency_key`: منع إنشاء نفس الطلب مرتين.
- `python_evaluation_id`: ربط Evaluation المحلي بتقييم Python.
- `webhook_event_id`: منع معالجة Webhook مرتين.

تعديل:

```text
app/Models/Evaluation.php
```

لإضافة الحقول إلى `fillable` و`casts` عند الحاجة.

## L2: قراءة بيانات المشروع من Laravel

الإبقاء على:

```text
app/Http/Requests/ExternalEvaluationRequest.php
```

ويجب أن يطابق قواعد `StoreProjectRequest` وحقول `Project` فقط. هذا الطلب يستخدمه Laravel للتحقق من البيانات قبل إرسالها إلى FastAPI؛ Python لا يرسل بيانات المشروع إلى Laravel.

الإبقاء على:

```text
app/Http/Middleware/AuthenticateExternalApi.php
```

لاستخدام `X-API-Key` من العميل أو لوحة التحكم.

## L3: Client للاتصال بـ FastAPI

إنشاء:

```text
app/Services/External/PythonEvaluationClient.php
```

مسؤولياته:

- إرسال Payload إلى FastAPI.
- إرسال `X-API-Key` الخاص بـ FastAPI.
- إرسال `Idempotency-Key` نفسه.
- ضبط timeout للاتصال القصير فقط.
- تحويل أخطاء FastAPI إلى Exceptions واضحة.
- عدم تشغيل التقييم داخل Laravel.

الإعدادات في `.env`:

```env
PYTHON_EVALUATION_URL=http://fastapi:8000
PYTHON_EVALUATION_API_KEY=...
EVALUATION_WEBHOOK_URL=https://laravel.example.com/api/webhooks/evaluations
EVALUATION_WEBHOOK_SECRET=...
```

الإعدادات في `config/services.php`:

```php
'python_evaluation' => [
    'url' => env('PYTHON_EVALUATION_URL'),
    'api_key' => env('PYTHON_EVALUATION_API_KEY'),
    'webhook_url' => env('EVALUATION_WEBHOOK_URL'),
    'webhook_secret' => env('EVALUATION_WEBHOOK_SECRET'),
],
```

## L4: تعديل `ExternalEvaluationController`

المسار:

```text
POST /api/integrations/evaluations
```

الترتيب:

1. قراءة المشروع بواسطة `project_id` من Laravel.
2. التأكد من أن `publication_status = published`.
3. بناء Payload من بيانات Laravel الحالية.
4. فحص `Idempotency-Key`.
5. إنشاء Evaluation محلي بحالة `pending`.
6. حفظ Snapshot لبيانات المشروع التي أرسلها Laravel.
7. استدعاء `PythonEvaluationClient` لإرسال طلب التقييم إلى FastAPI.
8. حفظ `python_evaluation_id` وحالة FastAPI.
9. إرجاع `201`.

عند فشل الاتصال بـ FastAPI:

- لا ترجع Laravel نجاحاً كاذباً.
- حدّث Evaluation إلى `failed` أو احذف السجل غير المكتمل حسب القرار المتفق عليه.
- أرجع خطأ واضحاً للعميل.

## L5: Webhook Controller

إنشاء:

```text
app/Http/Controllers/Api/EvaluationWebhookController.php
```

Route:

```php
Route::post('/webhooks/evaluations', [EvaluationWebhookController::class, 'store']);
```

هذا المسار لا يستخدم `X-API-Key` الخاص بالعميل. يستخدم توقيع Webhook:

```text
X-Webhook-Id
X-Webhook-Timestamp
X-Webhook-Signature
```

التحقق:

```text
signature = HMAC-SHA256(secret, timestamp + "." + raw_body)
```

يجب رفض:

- التوقيع الخاطئ.
- Timestamp أقدم من 5 دقائق.
- `event_id` مكرر مع Payload مختلف.
- Evaluation غير موجود.
- `project_id` غير مطابق.
- حالة غير معروفة.

## L6: قراءة الحالة والتقرير

الإبقاء على:

```text
GET /api/integrations/evaluations/{evaluation}/status
GET /api/integrations/evaluations/{evaluation}
```

لكن `status` يقرأ من Evaluation المحلي الذي حدثه Webhook، وليس من نتائج AI داخل Laravel.

## L7: Laravel Tests

إضافة اختبارات لـ:

```text
tests/Feature/Integration/ExternalEvaluationApiTest.php
tests/Feature/Integration/EvaluationWebhookTest.php
tests/Unit/Services/PythonEvaluationClientTest.php
```

السيناريوهات:

- Payload صحيح ينشئ Evaluation محلياً.
- Laravel يرسل Payload الصحيح إلى FastAPI.
- Laravel لا يشغل `EvaluateProjectJob` للتكامل الخارجي.
- حفظ `python_evaluation_id`.
- Webhook صحيح يحدث التقرير.
- Webhook مكرر لا يعالج مرتين.
- توقيع خاطئ يرفض.
- Timestamp قديم يرفض.
- `project_id` غير مطابق يرفض.
- حالة `partial` تحفظ بشكل صحيح.
- حالة `failed` تحفظ بشكل صحيح.
- إعادة نفس `Idempotency-Key` تعيد نفس Evaluation.

---

# 6. خطة تنفيذ Python FastAPI

## P1: هيكل المشروع

```text
python-evaluation-service/
├── app/
│   ├── main.py
│   ├── config.py
│   ├── routers/
│   │   └── evaluations.py
│   ├── schemas/
│   │   └── evaluation.py
│   ├── services/
│   │   ├── evaluation_service.py
│   │   └── webhook_service.py
│   ├── workers/
│   │   └── evaluation_worker.py
│   └── engine/
│       └── evaluation_engine.py
├── tests/
│   ├── test_evaluations.py
│   └── test_webhooks.py
├── requirements.txt
├── .env.example
└── README.md
```

المكونات المطلوبة:

```text
fastapi
uvicorn
httpx
pydantic
pydantic-settings
pytest
pytest-asyncio
```

يُضاف نظام Queue/Worker حسب بيئة التشغيل، مثل Celery أو RQ، قبل تشغيل تقييمات فعلية طويلة.

## P2: Endpoint استقبال طلب التقييم

```http
POST /api/v1/evaluations
X-API-Key: <fastapi-api-key>
Idempotency-Key: <same-key>
```

FastAPI يستقبل طلب التقييم من Laravel، ولا يستقبل بيانات المشروع من صاحب الفكرة مباشرة:

1. يتحقق من المفتاح.
2. يتحقق من Payload.
3. يبحث عن `Idempotency-Key`.
4. ينشئ تقييم Python إذا كان الطلب جديداً.
5. يضع المهمة في Worker.
6. يعيد `202` مع `python_evaluation_id`.

Response:

```json
{
  "evaluation_id": "py-eval-123",
  "callback_id": "laravel-evaluation-42",
  "status": "queued"
}
```

إعادة نفس `Idempotency-Key` تعيد نفس `evaluation_id` ولا تنشئ مهمة ثانية.

## P3: تنفيذ محرك التقييم

يستقبل Worker بيانات `project` كما هي، ثم ينفذ:

```text
التقييم التقني
تقييم الابتكار
تقييم الجدوى السوقية
تقييم الفريق
تقييم التوثيق
حساب overall_score
حساب confidence_score
إنتاج gap_analysis
إنتاج recommendations
إنتاج required_skills
إنتاج warnings
```

مخرجات FastAPI يجب أن تطابق Schema التقرير المتفق عليه:

```text
overall_score
dimensions
gap_analysis
recommendations
required_skills
warnings
partial_dimensions
confidence_score
```

## P4: إرسال Webhook

إنشاء:

```text
app/services/webhook_service.py
```

عند انتهاء Worker:

1. يبني Payload النتيجة.
2. يضيف `event_id` فريداً.
3. يضيف timestamp.
4. يوقع Body بتوقيع HMAC.
5. يرسل POST إلى `callback_url`.
6. يعيد الإرسال عند timeout أو `5xx` فقط.
7. يستخدم نفس `event_id` في كل محاولات إعادة الإرسال.
8. يتوقف بعد عدد محاولات محدد ويسجل الفشل.

لا يعيد الإرسال عند `4xx` الناتج عن Payload أو توقيع غير مقبول.

## P5: FastAPI Tests

إضافة اختبارات لـ:

```text
tests/test_evaluations.py
tests/test_webhooks.py
tests/test_engine.py
```

السيناريوهات:

- Payload صحيح يقبل.
- Payload غير صحيح يرفض.
- نفس Idempotency-Key لا ينشئ تقييماً ثانياً.
- Worker ينتج تقريراً صحيحاً.
- Webhook يحتوي الحقول المطلوبة.
- توقيع Webhook صحيح.
- إعادة الإرسال تستخدم نفس `event_id`.
- نجاح Webhook يوقف الإعادة.
- `partial` و`failed` يرسلان بشكل صحيح.

---

# 7. ترتيب التنفيذ

## المرحلة 1: Laravel

1. إضافة `python_evaluation_id` و`webhook_event_id` إلى `evaluations`.
2. إنشاء `PythonEvaluationClient`.
3. إضافة إعدادات FastAPI والـ Webhook.
4. تعديل `ExternalEvaluationController` ليرسل إلى FastAPI.
5. منع `EvaluateProjectJob` في المسار الخارجي.
6. إنشاء `EvaluationWebhookController`.
7. إضافة التحقق من HMAC وIdempotency للـ Webhook.
8. إضافة اختبارات Laravel.

## المرحلة 2: FastAPI

1. إنشاء FastAPI service.
2. تعريف Pydantic schemas المطابقة لبيانات `Project`.
3. إضافة endpoint استقبال التقييم.
4. إضافة Worker/Queue.
5. نقل محرك التقييم إلى Python.
6. إضافة توقيع وإرسال Webhook.
7. إضافة retry للـ Webhook.
8. إضافة اختبارات Python.

## المرحلة 3: التكامل الكامل

1. تشغيل Laravel وFastAPI.
2. إرسال مشروع منشور من الواجهة.
3. التأكد من وصول Payload إلى FastAPI.
4. التأكد أن Laravel لا يشغل AI داخلياً.
5. التأكد من تنفيذ Worker في Python.
6. التأكد من وصول Webhook.
7. التأكد من حفظ التقرير في Laravel.
8. التأكد من ظهور التقرير في لوحة التحكم.
9. اختبار Webhook مكرر وفشل الشبكة.

---

# 8. معايير القبول

يعتبر التنفيذ صحيحاً عندما:

- Laravel لا ينفذ التقييم الخارجي بنفسه.
- FastAPI هو المكان الوحيد الذي يشغل AI والتقييم الخارجي.
- Payload بين النظامين يطابق حقول `Project` الحالية.
- Laravel يعيد `202/201` بسرعة ولا ينتظر التقييم داخل نفس الطلب.
- كل طلب يملك `Idempotency-Key`.
- FastAPI لا ينشئ مهمة ثانية عند تكرار المفتاح.
- كل Webhook موقّع ومتحقق منه.
- Webhook المكرر لا يكرر تحديث التقرير.
- النتيجة تحفظ في `evaluations.result`.
- لوحة التحكم تقرأ الحالة والتقرير من Laravel.
- حالات `completed`, `partial`, و`failed` تعمل.
- أخطاء الاتصال أو التوقيع لا تنتج تقريراً وهمياً.

---

# 9. خارج النطاق الحالي

لا يتم تنفيذ الآتي ضمن الإصدار الأول:

- جلب README من GitHub.
- تحليل ملفات PDF أو الصور.
- إضافة `market` أو `competitors` أو `roadmap` إلى نموذج المشروع.
- إنشاء حقول مشروع جديدة غير مطلوبة للربط.
- تشغيل AI داخل Laravel بالتوازي مع FastAPI.
- إبقاء HTTP request مفتوحاً حتى نهاية التقييم.
