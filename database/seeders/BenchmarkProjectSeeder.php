<?php

namespace Database\Seeders;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * بيانات قياس الأداء — US-030 (T119).
 *
 * 1000 مشروع منشور مختلط (عربي/إنجليزي) بأحجام متفاوتة: ~70% بتقييم (درجة)
 * و~30% بلا تقييم (لاختبار «غير المقيَّمة أخيراً» US-033-S5)، عبر تصنيفات
 * وحالات ووسوم وتواريخ ومشاهدات متنوعة — لاختبار بحث ≤ 200ms (P95) على 1000 وثيقة.
 *
 * ملاحظة: لا يشغّل الأحداث أثناء الإدراج، ثم يعيد فهرسة الدفعات دفعةً واحدة
 * (استدعاءات Meilisearch بالعدد الأدنى).
 */
class BenchmarkProjectSeeder extends Seeder
{
    public const COUNT = 1000;

    /** نسبة المشاريع التي تحمل تقييماً مكتملاً (درجة) */
    public const SCORED_RATIO = 0.7;

    private array $arabicTitles = [
        'منصة تشخيص طبي بالذكاء الاصطناعي',
        'تطبيق تعليم الرياضيات للأطفال بالواقع المعزز',
        'منصة تمويل المشاريع الناشئة في الخليج',
        'نظام إدارة المخزون الذكي للمتاجر الصغيرة',
        'تطبيق توصيل البقالة الطازجة خلال ساعة',
        'منصة الحجز الإلكتروني للعيادات الطبية',
        'تطبيق تعلم اللغة الإنجليزية بالمحادثة',
        'نظام متابعة المزارع الذكية بالاستشعار عن بعد',
        'منصة الربط بين المصممين والشركات',
        'تطبيق إدارة المهام للفرق عن بعد',
        'سوق رقمي للحرف اليدوية العربية',
        'منصة تحليل البيانات المالية للشركات الصغيرة',
        'تطبيق اللياقة البدنية بمدرب شخصي ذكي',
        'نظام الدفع الإلكتروني للتجار الصغار',
        'منصة التعليم التفاعلي للمدارس',
        'تطبيق حجز السيارات بالدقيقة',
        'منصة استشارات المحامين عبر الإنترنت',
        'تطبيق تنظيم الرحلات السياحية في السعودية',
        'نظام إدارة المطاعم السحابي',
        'منصة التوظيف للخريجين الجدد',
    ];

    private array $englishTitles = [
        'AI-Powered Medical Diagnosis Platform',
        'Augmented Reality Math Tutor for Kids',
        'Gulf Startup Funding Marketplace',
        'Smart Inventory System for Small Retailers',
        'Fresh Grocery Delivery in Under an Hour',
        'Online Clinic Booking Platform',
        'Conversational English Learning App',
        'Smart Farming Monitoring System',
        'Freelance Designer-Company Matchmaking',
        'Remote Team Task Management App',
        'Arabic Handicrafts Digital Marketplace',
        'Financial Data Analytics for SMBs',
        'Fitness App with AI Personal Trainer',
        'Mobile Payment System for Merchants',
        'Interactive School Learning Platform',
        'Pay-per-Minute Car Rental App',
        'Online Legal Consultation Platform',
        'Saudi Tourism Trip Planner',
        'Cloud-Based Restaurant Management',
        'Graduate Job Matching Platform',
    ];

    private array $arabicDescriptions = [
        'نظام متكامل يعتمد على الذكاء الاصطناعي لتحليل البيانات الطبية وتقديم توصيات دقيقة للأطباء والمرضى، مع دعم كامل للغة العربية وواجهة سهلة الاستخدام.',
        'تطبيق تفاعلي يستخدم تقنيات الواقع المعزز لتعليم الأطفال أساسيات الرياضيات بطريقة ممتعة، مع تقارير تقدم للوالدين وخطط تعلم مخصصة.',
        'منصة رقمية تربط رواد الأعمال في منطقة الخليج بالمستثمرين، مع أدوات تقييم المشاريع وإدارة جولات التمويل بشكل آمن وشفاف.',
        'نظام سحابي ذكي يساعد أصحاب المتاجر الصغيرة على تتبع المخزون والتنبؤ بالطلب، مع تنبيهات تلقائية وتقارير مبيعات لحظية.',
        'خدمة توصيل سريعة للمواد الغذائية الطازجة من المتاجر المحلية إلى باب العميل خلال ساعة واحدة، مع تتبع مباشر للطلب.',
        'منصة تتيح للمرضى حجز المواعيد مع الأطباء إلكترونياً وإدارة ملفاتهم الصحية ومتابعة الوصفات من مكان واحد.',
        'تطبيق مبتكر لتعلم اللغة الإنجليزية يركز على المحادثة الفعلية مع متحدثين أصليين، ويستخدم الذكاء الاصطناعي لتصحيح النطق.',
        'نظام متقدم لمراقبة المزارع باستخدام حساسات إنترنت الأشياء وتحليل البيانات لتحسين الإنتاجية وتقليل استهلاك المياه.',
        'منصة ذكية تربط الشركات بالمصممين المستقلين حسب احتياجات المشروع، مع نظام تقييم ودفع آمن وضمان حقوق الطرفين.',
        'تطبيق شامل لإدارة مهام الفرق البعيدة مع لوحات كانبان، ومحادثات مدمجة، وتقارير إنتاجية وتحليلات أداء.',
        'سوق إلكتروني لعرض وبيع الحرف اليدوية العربية الأصيلة من مختلف الدول، مع شحن دولي ودفع إلكتروني آمن.',
        'منصة تحليل مالي مبسطة تساعد الشركات الصغيرة على فهم بياناتها المالية واتخاذ قرارات مستنيرة بنماذج تنبؤ ذكية.',
        'تطبيق لياقة يوفر خطط تمارين مخصصة وتغذية ذكية مع مدرب افتراضي يعتمد على الذكاء الاصطناعي لتعديل الخطط لحظياً.',
        'نظام دفع إلكتروني متكامل يتيح للتجار الصغار قبول المدفوعات بسهولة عبر رمز QR والبطاقات والمحافظ الرقمية.',
        'منصة تعليمية تفاعلية للمدارس توفر فصولاً افتراضية وتقييمات فورية ومحتوى مخصصاً لكل طالب حسب مستواه.',
        'تطبيق حجز سيارات بالدقيقة يسمح للمستخدمين بالعثور على سيارة قريبة وحجزها وفتحها من الهاتف والدفع عند الاستخدام.',
        'منصة رقمية تربط المستخدمين بمحامين معتمدين للاستشارات القانونية عبر الفيديو والدردشة مع ضمان السرية التامة.',
        'تطبيق ذكي لتنظيم الرحلات السياحية في السعودية يقدم خططاً مخصصة وحجوزات وتوصيات محلية سياحية.',
        'نظام سحابي متكامل لإدارة المطاعم يغطي الطلبات والمخزون والتقارير المالية وتجربة العملاء في مكان واحد.',
        'منصة توظيف مخصصة للخريجين الجدد تربطهم بالفرص المناسبة بناءً على مهاراتهم واهتماماتهم مع نصائح مهنية.',
    ];

    private array $englishDescriptions = [
        'An integrated AI system that analyzes medical data and provides accurate recommendations for doctors and patients, with full Arabic support and an easy-to-use interface.',
        'An interactive app using augmented reality to teach kids math fundamentals in a fun way, with progress reports for parents and personalized learning plans.',
        'A digital platform connecting Gulf entrepreneurs with investors, featuring project evaluation tools and secure, transparent funding round management.',
        'A smart cloud system helping small retailers track inventory and forecast demand, with automatic alerts and real-time sales reports.',
        'A fast delivery service bringing fresh groceries from local stores to your door within one hour, with live order tracking.',
        'A platform letting patients book doctor appointments online and manage their health records and prescriptions in one place.',
        'An innovative English learning app focused on real conversation with native speakers, using AI to correct pronunciation.',
        'An advanced farm monitoring system using IoT sensors and data analytics to improve productivity and reduce water usage.',
        'A smart platform connecting companies with freelance designers based on project needs, with ratings, secure payments, and rights protection.',
        'A comprehensive remote team task management app with kanban boards, integrated chat, productivity reports, and performance analytics.',
        'An e-commerce marketplace showcasing and selling authentic Arabic handicrafts from various countries, with international shipping and secure payments.',
        'A simplified financial analytics platform helping small businesses understand their financial data and make informed decisions with smart forecasting models.',
        'A fitness app providing personalized workout plans and smart nutrition with an AI virtual coach that adjusts plans in real time.',
        'An integrated digital payment system enabling small merchants to accept payments easily via QR codes, cards, and digital wallets.',
        'An interactive school learning platform offering virtual classrooms, instant assessments, and content personalized to each student.',
        'A pay-per-minute car rental app letting users find a nearby car, book and unlock it from their phone, and pay as they go.',
        'A digital platform connecting users with licensed lawyers for video and chat legal consultations with full confidentiality.',
        'A smart trip planning app for Saudi tourism offering personalized itineraries, bookings, and local recommendations.',
        'A cloud-based restaurant management system covering orders, inventory, financial reporting, and customer experience in one place.',
        'A job matching platform for new graduates connecting them to suitable opportunities based on their skills and interests, with career advice.',
    ];

    private array $tagPool = ['ai', 'react', 'laravel', 'flutter', 'nodejs', 'saas', 'mobile', 'fintech', 'health', 'education', 'ecommerce', 'blockchain', 'iot', 'cloud', 'data'];

    /**
     * إنشاء 1000 مشروع مختلط عربي/إنجليزي (مع/بدون درجات).
     */
    public function run(): void
    {
        $owners = $this->owners();
        $categoryIds = $this->categories();

        if (empty($categoryIds)) {
            $this->command?->warn('لا توجد تصنيفات — شغّل CategorySeeder أولاً.');

            return;
        }

        $projects = [];

        Project::withoutEvents(function () use (&$projects, $owners, $categoryIds) {
            for ($i = 0; $i < self::COUNT; $i++) {
                $isArabic = $i % 2 === 0;

                $projects[] = Project::create([
                    'user_id' => $owners[array_rand($owners)],
                    'category_id' => $categoryIds[array_rand($categoryIds)],
                    'title' => $isArabic
                        ? $this->arabicTitles[array_rand($this->arabicTitles)].' '.($i + 1)
                        : $this->englishTitles[array_rand($this->englishTitles)].' '.($i + 1),
                    'description' => $isArabic
                        ? $this->arabicDescriptions[array_rand($this->arabicDescriptions)]
                        : $this->englishDescriptions[array_rand($this->englishDescriptions)],
                    'status' => $this->randomState(),
                    'publication_status' => ProjectStatus::PUBLISHED,
                    'tags' => $this->randomTags(),
                    'budget_min' => rand(10000, 500000),
                    'budget_max' => rand(500000, 2000000),
                    'view_count' => rand(0, 10000),
                    'visibility_level' => 2,
                    'created_at' => now()->subDays(rand(1, 600)),
                    'updated_at' => now()->subDays(rand(0, 30)),
                ]);
            }
        });

        // تقييمات مكتملة لنسبة 70% — مع إيقاف الأحداث ثم إعادة مزامنة الفهرس
        $scored = 0;

        Evaluation::withoutEvents(function () use ($projects, &$scored) {
            foreach ($projects as $project) {
                if (mt_rand(1, 100) > self::SCORED_RATIO * 100) {
                    continue; // بدون تقييم — اختبار «غير المقيَّمة أخيراً»
                }

                $score = round(mt_rand(35, 98) + mt_rand(0, 99) / 100, 1);

                Evaluation::create([
                    'project_id' => $project->id,
                    'version' => 1,
                    'status' => EvaluationStatus::COMPLETED,
                    'overall_score' => $score,
                    'confidence_score' => round(mt_rand(60, 95) + mt_rand(0, 99) / 100, 1),
                    'result' => $this->evaluationResult($score),
                    'model_used' => 'claude',
                    'model_name' => 'claude-3-5-haiku',
                    'provider_used' => 'claude',
                    'consensus_rounds' => 1,
                    'retry_count' => 0,
                    'processing_time_ms' => rand(8000, 45000),
                    'started_at' => now()->subDays(rand(1, 60)),
                    'completed_at' => now()->subDays(rand(0, 59)),
                ]);

                // مزامنة ai_score مع آخر تقييم مكتمل (اتساق عرض البطاقات)
                $project->update([
                    'ai_score' => $score,
                    'last_evaluation_at' => now()->subDays(rand(0, 59)),
                ]);

                $scored++;
            }
        });

        // إعادة فهرسة كل المنشور دفعةً واحدة (أحداث مشغَّلة خارجياً)
        Project::query()
            ->published()
            ->orderBy('id')
            ->chunkById(500, fn ($batch) => $batch->searchable());

        $this->command?->info("تم إنشاء ".self::COUNT." مشروعاً منشوراً ({$scored} بتقييم) وفهرستها.");
    }

    /**
     * @return list<int>
     */
    private function owners(): array
    {
        $owners = [];

        for ($i = 1; $i <= 20; $i++) {
            $owners[] = User::firstOrCreate(
                ['email' => "bench-owner-{$i}@ihyaa.test"],
                [
                    'name' => "Benchmark Owner {$i}",
                    'password' => 'password',
                    'role' => UserRole::IDEA_OWNER,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            )->id;
        }

        return $owners;
    }

    /**
     * @return list<int>
     */
    private function categories(): array
    {
        $ids = Category::query()->pluck('id')->all();

        if (empty($ids)) {
            $this->call(CategorySeeder::class);
            $ids = Category::query()->pluck('id')->all();
        }

        return array_values($ids);
    }

    private function randomState(): ProjectState
    {
        return ProjectState::from(match (rand(1, 3)) {
            1 => 'needs_funding',
            2 => 'needs_development',
            default => 'completed',
        });
    }

    /**
     * @return list<string>
     */
    private function randomTags(): array
    {
        $count = rand(2, 4);
        $keys = array_rand($this->tagPool, min($count, count($this->tagPool)));
        $keys = is_array($keys) ? $keys : [$keys];

        return array_values(array_intersect_key($this->tagPool, array_flip($keys)));
    }

    /**
     * مخطط نتيجة تقييم مصغّر (data-model §2 / SRS §5.4.6.3).
     *
     * @return array<string, mixed>
     */
    private function evaluationResult(float $score): array
    {
        return [
            'dimensions' => [
                'market' => ['label' => 'فرص السوق', 'score' => min(100, max(0, $score + rand(-10, 10))), 'summary' => 'سوق قابل للنمو'],
                'technical' => ['label' => 'الجدوى التقنية', 'score' => min(100, max(0, $score + rand(-10, 10))), 'summary' => 'خطة تقنية واضحة'],
                'team' => ['label' => 'الفريق', 'score' => min(100, max(0, $score + rand(-10, 10))), 'summary' => 'فريق متنوع المهارات'],
                'financial' => ['label' => 'الجدوى المالية', 'score' => min(100, max(0, $score + rand(-10, 10))), 'summary' => 'نموذج إيرادات منطقي'],
                'competitiveness' => ['label' => 'التنافسية', 'score' => min(100, max(0, $score + rand(-10, 10))), 'summary' => 'ميزة تنافسية واضحة'],
            ],
            'overall_summary' => 'ملخص تقييم آلي للمشروع.',
            'overall_score' => $score,
        ];
    }
}
