<?php

namespace App\Services\DemoData;

use App\Enums\ConversationEventType;
use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 生成会话演示数据。
 */
class ConversationDemoGenerator
{
    /** 触发会话详情分页的阈值（前端默认 perPage=50）。 */
    private const PAGINATION_THRESHOLD = 50;

    /** 超过此阈值的长会话数量，用于验证时间线分页。 */
    private const LONG_CONVERSATION_COUNT = 3;

    /** @var list<string> */
    private const VISITOR_SCRIPTS = [
        '你好，请问你们的产品支持退款吗？',
        '我昨天下单之后一直没收到发货通知，能帮我查一下吗？',
        '我想升级一下我的套餐，有没有优惠活动？',
        '登录的时候提示验证码错误，我这边手机号没改过，怎么办？',
        '你们的企业版和专业版主要区别是什么？价格差多少？',
        '刚才支付的时候页面卡住了，但是扣款成功了，订单状态还没更新。',
        '发票信息在哪里下载？我需要补开一张上个月的。',
        '这个功能是不是要开通之后才能用？我在后台找了半天没找到入口。',
        '我们团队想申请一下批量授权，大概 20 个账号，请问怎么走流程？',
        '想问一下你们的 API 调用频率限制是多少？我担心并发高会被限流。',
        '可以把我的账号邮箱改一下吗？原来那个邮箱停用了。',
        '刚才被同事误删了数据，能不能帮忙恢复？紧急！',
        '我看到你们官网写的支持 SSO，请问 SAML 和 OIDC 都支持吗？',
        '合同续签的事情能不能找个专人对接一下？我们法务部门有一些条款问题。',
        '这个报表导出的格式可以自定义吗？我们需要对接到内部系统。',
        '我是新来的管理员，之前的同事已经离职了，能帮我把权限转移一下吗？',
        '试用期快结束了，续费有折扣吗？我们是老客户了。',
        '后台页面加载非常慢，尤其是数据量大的列表，有没有办法优化一下？',
    ];

    /** @var list<string> */
    private const AI_SCRIPTS = [
        '您好，欢迎咨询！我这边可以帮您处理，请问您的订单号是多少呢？',
        '稍等我帮您查询一下订单状态，请稍候片刻~',
        '根据我们的退款政策，7 天内且未使用的情况下支持全额退款。您是想申请退款吗？',
        '我已经为您查询到订单信息，目前物流状态显示为「已揽收」，预计 24 小时内会更新。',
        '这个问题可能是由于缓存导致的，建议您先清理浏览器缓存再重新登录试一下。',
        '我理解您的着急，这边优先为您升级处理，稍后会有同事跟进协助。',
        '您提到的功能在专业版以上套餐可用，具体的使用指南我发您一份文档链接。',
        '我已经为您提交了工单，工单号是 #20260421-8832，后续进度会同步到您的邮箱。',
        '关于合同和法务条款相关的问题，我这边已经转给对应的客户经理跟进了。',
        '为了更好地帮您定位问题，能否方便提供下报错的截图或者具体的操作步骤？',
        'API 调用频率默认是每分钟 1000 次，如果您有更高的并发需求，可以申请提高限额。',
        '已为您查询到账户信息，该账户当前处于正常状态，订阅将于 2026-07-15 到期。',
        '您要求的数据恢复操作需要管理员权限审批，我这边已经提交，预计 1 小时内完成。',
        '针对您反馈的慢的问题，我们工程团队之前也有同事反馈过，建议您先试下分页加载。',
    ];

    /** @var list<string> */
    private const TOOL_CALL_SCRIPTS = [
        ['tool' => 'search_docs', 'arguments' => ['query' => '退款流程']],
        ['tool' => 'search_docs', 'arguments' => ['query' => '发票申请指南']],
        ['tool' => 'search_docs', 'arguments' => ['query' => 'API 限流说明']],
        ['tool' => 'search_docs', 'arguments' => ['query' => 'SSO SAML 配置']],
        ['tool' => 'fetch_order', 'arguments' => ['order_id' => 'OD20260420-001']],
        ['tool' => 'fetch_user_profile', 'arguments' => ['user_email' => 'demo@example.com']],
        ['tool' => 'search_kb', 'arguments' => ['keyword' => '账号权限转移']],
        ['tool' => 'search_kb', 'arguments' => ['keyword' => '批量授权申请']],
    ];

    /** @var list<string> */
    private const TOOL_RESULT_SCRIPTS = [
        '已在知识库中找到 3 篇相关文档，最相关的是《退款处理规范 v2》。',
        '订单查询成功：状态=已发货，物流=顺丰 SF1234567890，预计 24 小时内签收。',
        'API 限流配置读取成功：当前额度 1000 次/分钟，可升级至 5000 次/分钟。',
        '未查询到匹配记录，建议请客户提供更详细的信息。',
        '已找到账号信息：套餐=专业版，到期时间=2026-07-15，状态=正常。',
    ];

    /** @var list<string> */
    private const SUBJECT_SCRIPTS = [
        '退款申请跟进',
        '订单物流咨询',
        '套餐升级 & 优惠',
        '登录异常排查',
        '企业版功能咨询',
        '发票补开申请',
        '账号权限转移',
        '数据恢复请求',
        'SSO 接入咨询',
        '合同续签对接',
        '报表导出定制',
        'API 限流配置',
        '批量授权申请',
        '慢查询优化反馈',
    ];

    /** @var list<string> */
    private const SUMMARY_SCRIPTS = [
        '客户咨询了退款相关的政策和操作流程，AI 先行答复，已转交对应同事跟进。',
        '访客反馈订单发货延迟，工单已创建，物流同步到客户邮箱。',
        '老客户咨询企业版升级，提供了报价和促销信息，等待客户确认。',
        '用户登录异常，排查为客户端缓存问题，指导清理后恢复正常。',
        '客户反馈 SSO 接入时的配置问题，已拉取技术团队介入。',
        '合同续签相关条款问题，已转给客户经理，预计明天跟进。',
        '客户反馈后台慢，已收集日志并同步给工程团队分析。',
    ];

    /**
     * 在事务中生成会话演示数据。
     *
     * @return array{conversations:int,messages:int,events:int}
     */
    public function generate(int $count): array
    {
        return DB::transaction(fn () => $this->run($count));
    }

    /**
     * @return array{conversations:int,messages:int,events:int}
     */
    private function run(int $count): array
    {
        $contacts = Contact::query()->limit(max(10, min($count, 40)))->get();
        if ($contacts->isEmpty()) {
            $contacts = Contact::factory()
                ->count(max(10, min($count, 20)))
                ->state(fn () => [
                    'name' => fake('zh_CN')->name(),
                    'city' => fake('zh_CN')->optional()->city(),
                    'country' => fake('zh_CN')->optional()->country(),
                ])
                ->create([
                ]);
        }

        $users = User::query()->get();

        $messageCount = 0;
        $eventCount = 0;

        for ($index = 0; $index < $count; $index++) {
            $createdAt = CarbonImmutable::now()
                ->subDays(random_int(0, 20))
                ->subMinutes(random_int(0, 1200));

            $contact = random_int(1, 100) <= 80 ? $contacts->random() : null;
            $assignedUser = $users->isNotEmpty() && random_int(1, 100) <= 60 ? $users->random() : null;
            $isClosed = random_int(1, 100) <= 30;

            $isLongConversation = $index < self::LONG_CONVERSATION_COUNT;
            $messageTimelineCount = $isLongConversation
                ? random_int(self::PAGINATION_THRESHOLD + 30, self::PAGINATION_THRESHOLD + 80)
                : random_int(6, 12);

            $conversation = Conversation::query()->create([
                'contact_id' => $contact?->id,
                'assigned_user_id' => $assignedUser?->id,
                'source' => ConversationSource::Channel,
                'status' => $isClosed ? ConversationStatus::Closed : ConversationStatus::Open,
                'inbox_status' => fake()->randomElement(ConversationInboxStatus::cases()),
                'waiting_for_visitor_reply' => ! $isClosed && fake()->boolean(35),
                'subject' => random_int(1, 100) <= 80 ? $this->pickRandom(self::SUBJECT_SCRIPTS) : null,
                'summary' => random_int(1, 100) <= 60 ? $this->pickRandom(self::SUMMARY_SCRIPTS) : null,
                'ai_context' => ['sentiment' => fake()->randomElement(['positive', 'neutral', 'negative'])],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $timelineAt = $createdAt;
            $latestMessage = null;

            for ($messageIndex = 0; $messageIndex < $messageTimelineCount; $messageIndex++) {
                $timelineAt = $timelineAt->addMinutes(random_int(2, 20));

                $message = match ($messageIndex % 5) {
                    0 => ConversationMessage::factory()->forConversation($conversation)->visitorText()->create([
                        'content' => $this->pickRandom(self::VISITOR_SCRIPTS),
                        'created_at' => $timelineAt,
                        'updated_at' => $timelineAt,
                    ]),
                    1 => ConversationMessage::factory()->forConversation($conversation)->aiText()->create([
                        'content' => $this->pickRandom(self::AI_SCRIPTS),
                        'created_at' => $timelineAt,
                        'updated_at' => $timelineAt,
                    ]),
                    2 => ConversationMessage::factory()->forConversation($conversation)->toolCall()->create([
                        'payload' => $this->pickRandom(self::TOOL_CALL_SCRIPTS),
                        'created_at' => $timelineAt,
                        'updated_at' => $timelineAt,
                    ]),
                    3 => ConversationMessage::factory()->forConversation($conversation)->toolResult()->create([
                        'payload' => [
                            'status' => 'ok',
                            'result' => $this->pickRandom(self::TOOL_RESULT_SCRIPTS),
                        ],
                        'created_at' => $timelineAt,
                        'updated_at' => $timelineAt,
                    ]),
                    default => ConversationMessage::factory()->forConversation($conversation)->create([
                        'sender_user_id' => $assignedUser?->id,
                        'content' => $this->pickRandom(self::AI_SCRIPTS),
                        'created_at' => $timelineAt,
                        'updated_at' => $timelineAt,
                    ]),
                };

                $messageCount++;

                if ($message->content !== null) {
                    $latestMessage = $message;
                }

                if ($messageIndex === 0) {
                    ConversationEvent::factory()->forConversation($conversation)->create([
                        'type' => ConversationEventType::Created,
                        'actor_user_id' => $assignedUser?->id,
                        'payload' => ['source' => 'manual'],
                        'created_at' => $timelineAt->subMinute(),
                    ]);
                    $eventCount++;
                }

                if ($messageIndex === 3 && $assignedUser !== null) {
                    ConversationEvent::factory()->forConversation($conversation)->handoffRequested()->create([
                        'created_at' => $timelineAt->addMinute(),
                    ]);
                    $eventCount++;
                }
            }

            $lastMessageAt = $latestMessage?->created_at ?? $timelineAt;
            $conversation->update([
                'last_message_at' => $lastMessageAt,
                'last_message_preview' => $latestMessage?->content ?? $this->pickRandom(self::AI_SCRIPTS),
                'closed_at' => $isClosed ? $lastMessageAt : null,
            ]);
        }

        return [
            'conversations' => $count,
            'messages' => $messageCount,
            'events' => $eventCount,
        ];
    }

    /**
     * @template T
     *
     * @param  list<T>  $pool
     * @return T
     */
    private function pickRandom(array $pool): mixed
    {
        return $pool[array_rand($pool)];
    }
}
