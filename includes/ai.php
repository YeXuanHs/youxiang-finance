<?php
/**
 * 优享财务系统 · AI 客服引擎（可插拔驱动）
 *
 * 内置：
 *   - mock   ：规则式问答，免密钥即可跑通全流程（默认）
 *   - openai ：OpenAI 兼容接口（DeepSeek / 通义 / Kimi / 智谱 / OpenAI 等），后台填
 *              「接口地址 + API Key + 模型名」即接入真实大模型。
 * 密钥仅存于系统设置，不在前台回显。
 */

interface YxAiDriver
{
    public function code(): string;
    public function name(): string;

    /**
     * @param array $messages [['role'=>'system|user|assistant','content'=>string], ...]
     * @return array ['ok'=>bool,'reply'=>string,'msg'=>?,'raw'=>?]
     */
    public function chat(array $messages, array $opt = []): array;
}

/**
 * 规则式问答：无需外部服务，作为默认兜底
 *
 * 不依赖任何外部 API：按关键词打分选出最可能的意图，再用站内真实数据
 * （产品、应用商店条目、开发者保证金、分销比例、联系方式等）拼出答案，
 * 避免在未接入大模型时「什么都答不上来、句句转人工」。
 */
class YxAiMock implements YxAiDriver
{
    public function code(): string { return 'mock'; }
    public function name(): string { return '内置规则问答（免密钥）'; }

    public function chat(array $messages, array $opt = []): array
    {
        $q = '';
        $prevAi = '';
        foreach (array_reverse($messages) as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'user' && $q === '') {
                $q = (string)$m['content'];
            } elseif ($role === 'assistant' && $q !== '') {
                /* 紧挨着当前问题的上一条 AI 回复 */
                $prevAi = (string)$m['content'];
                break;
            }
        }
        /* 上一轮就没答上来，本轮仍未命中时直接转人工，避免反复兜圈 */
        $retry = $prevAi !== '' && mb_strpos($prevAi, self::FALLBACK_HEAD) === 0;
        return self::answer($q, null, $retry);
    }

    /** 兜底文案开头（用于识别「上一轮已经没答上来」） */
    const FALLBACK_HEAD = '抱歉，这个问题小优助手暂时没有把握回答。';

    /**
     * 生成规则答案
     * @param bool $retry 上一轮已兜底失败
     * @return array ['ok'=>bool,'reply'=>string,'handoff'=>bool,'intent'=>string]
     */
    public static function answer(string $question, ?array $customer = null, bool $retry = false): array
    {
        $text = self::normalize($question);
        $site = (string)setting('site_name', config('app.name'));

        if ($text === '') {
            return self::hit('empty', '您可以把问题直接发给我，例如：产品价格、如何购买、授权查询、开发者入驻、分销推广、发票与退款。', false);
        }

        /* 意图按优先级排列：越靠前的意图越先判定（避免「保证金多少钱」被价格意图抢先命中） */
        $rules = self::rules($site, $customer);
        $best  = null;
        $bestScore = 0;
        foreach ($rules as $rule) {
            $score = 0;
            foreach ($rule['kw'] as $kw => $weight) {
                if (mb_strpos($text, (string)$kw) !== false) {
                    $score += (int)$weight;
                }
            }
            if ($score <= 0) {
                continue;
            }
            /* 同分时优先级高（数组靠前）的意图胜出 */
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rule;
            }
        }

        if ($best === null) {
            return self::fallback($retry);
        }

        try {
            $reply = is_callable($best['a']) ? (string)call_user_func($best['a'], $site, $customer) : (string)$best['a'];
        } catch (Throwable $e) {
            $reply = '';
        }
        if (trim($reply) === '') {
            return self::fallback($retry);
        }
        return self::hit((string)$best['id'], $reply, !empty($best['handoff']));
    }

    private static function hit(string $intent, string $reply, bool $handoff): array
    {
        return ['ok' => true, 'reply' => $reply, 'handoff' => $handoff, 'intent' => $intent];
    }

    private static function fallback(bool $retry = false): array
    {
        $qq  = trim((string)setting('contact_qq', ''));
        $em  = trim((string)setting('contact_email', ''));
        $way = $qq !== '' ? ('客服 QQ ' . $qq) : ($em !== '' ? ('客服邮箱 ' . $em) : '');
        if ($retry) {
            return self::hit(
                'fallback_handoff',
                self::FALLBACK_HEAD . '已为您转接人工客服，请稍候…'
                . ($way !== '' ? '您也可以直接联系：' . $way . '。' : ''),
                true
            );
        }
        return self::hit(
            'fallback',
            self::FALLBACK_HEAD
            . '您可以换个说法问我（例如：产品价格、如何购买、授权查询、开发者入驻、分销推广、发票、退款、续费、转人工）；'
            . '也可以点击上方「转人工」由人工客服接手。'
            . ($way !== '' ? '如需自助联系：' . $way . '。' : ''),
            false
        );
    }

    /** 归一化：去空白标签、全角转半角、统一小写，便于关键词匹配 */
    private static function normalize(string $s): string
    {
        $s = trim(strip_tags($s));
        $s = str_replace(["\r", "\n", "\t", '　'], ' ', $s);
        /* 全角字符 → 半角（保留中文） */
        $s = mb_convert_kana($s, 'as', 'UTF-8');
        $s = preg_replace('/\s+/u', '', $s);
        return mb_strtolower((string)$s, 'UTF-8');
    }

    /**
     * 意图规则表
     * kw 为「关键词 => 权重」，权重用于同句命中多个意图时排序。
     */
    private static function rules(string $site, ?array $customer): array
    {
        return [
            /* ---------------- 转人工 / 联系方式 ---------------- */
            [
                'id'   => 'human',
                'kw'   => ['转人工' => 10, '人工客服' => 9, '人工' => 6, '真人' => 6, '客服电话' => 8, '电话' => 3, 'qq' => 4, '微信' => 3, '邮箱' => 3, '联系你们' => 6, '怎么联系' => 6],
                'handoff' => true,
                'a'    => function () {
                    $lines = ['好的，正在为您转接人工客服。'];
                    if (($qq = trim((string)setting('contact_qq', ''))) !== '')   { $lines[] = '客服 QQ：' . $qq; }
                    if (($em = trim((string)setting('contact_email', ''))) !== '') { $lines[] = '客服邮箱：' . $em; }
                    if (($ph = trim((string)setting('contact_phone', ''))) !== '') { $lines[] = '客服电话：' . $ph; }
                    $lines[] = '您也可以直接在上方选择「商务合作 / 售后咨询」留言，我们会生成工单跟进。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 开发者 / 保证金 ---------------- */
            [
                'id' => 'developer',
                'kw' => ['保证金' => 10, '开发者' => 8, '入驻' => 6, '上传插件' => 7, '上传模板' => 7, '发布插件' => 7, '成为开发者' => 8, '开发者中心' => 8, '稿费' => 4, '结算' => 4],
                'a'  => function () {
                    $plugin = (float)setting('developer_plugin_deposit_amount', setting('developer_deposit_amount', 600));
                    $tpl    = (float)setting('developer_template_deposit_amount', 0);
                    $app    = (float)setting('developer_deposit_amount', 600);
                    $lines  = ['开发者入驻流程：「实名核验 → 缴纳保证金 → 管理员审核」，通过后即可上传应用并在优享商店售卖。入口在「用户中心 → 开发者中心」。'];
                    $lines[] = '保证金按类型收取（按年计，用于保障买家权益，可申请退还）：';
                    $lines[] = '· 应用 / 插件类：' . money($app, true) . '/年（插件按年 ' . money($plugin, true) . '/年）';
                    $lines[] = '· 模板类：' . ($tpl > 0 ? money($tpl, true) . '/年' : '免保证金（0 元即可上传）');
                    $lines[] = '缴纳与审核都在「用户中心 → 开发者中心」完成，支持站内在线支付。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 开源版 / 社区版 ---------------- */
            [
                'id' => 'open',
                'kw' => ['开源版' => 12, '社区版' => 12, '免费版' => 9, '开源' => 9, '免费使用' => 8, '免费下载' => 8, '不花钱' => 7],
                'a'  => function (string $site, ?array $customer = null) {
                    $open = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE status = 'on' AND `key` = 'youxiang-finance-open' LIMIT 1");
                    $lines = [];
                    if ($open) {
                        $lines[] = '开源版（社区版）主程序包：' . (string)$open['name'] . '，价格 ' . YxBusiness::priceLabel($open) . '，在「应用商店 → 主程序」可直接获取。';
                    } else {
                        $lines[] = '开源版（社区版）主程序包可在「应用商店 → 主程序」免费获取。';
                    }
                    $lines[] = '开源版特点：完全免费、授权模块不参与官方校验；但功能固定为社区版形态，不提供正版后续升级与技术支持，该形态安装后不可切换回正式版。';
                    $lines[] = '如果需要完整功能与持续更新，可免费下载正版程序包，再按需单独购买授权包（' . self::officialPriceLabel() . '），凭安装授权码在安装向导第 4 步激活。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 正版程序包 / 授权包 / 授权码安装 ---------------- */
            [
                'id' => 'package',
                'kw' => ['正版程序包' => 12, '程序包' => 8, '授权包' => 12, '安装码' => 10, '授权码' => 9, '主程序' => 8, '怎么安装' => 8, '安装教程' => 9, '怎么部署' => 8, '安装' => 4, '部署' => 4, '源码' => 6],
                'a'  => function (string $site, ?array $customer = null) {
                    $lines = ['正版程序包免费下载，授权包（安装授权码）另计：' . self::officialPriceLabel() . ' / 次（按域名签发 1 个授权码），程序包与授权包分开收费。'];
                    $lines[] = '1) 到「应用商店」程序包条目直接免费下载（登录后即可，无需先买授权）；';
                    $lines[] = '2) 需要正版授权时，在同一页面单独购买授权包，支付成功后到「用户中心 → 我的授权」查看安装授权码；';
                    $lines[] = '3) 上传程序包到站点根目录，浏览器访问 /install/ 进入安装向导；';
                    $lines[] = '4) 填写数据库与站点信息；';
                    $lines[] = '5) 第 4 步「授权」处填写绑定域名 + 安装授权码（正版包必填）；';
                    $lines[] = '6) 完成安装，授权码与域名绑定，可在授权查询页复核。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 价格 / 套餐 / 产品 ---------------- */
            [
                'id' => 'price',
                'kw' => ['价格' => 9, '多少钱' => 9, '收费' => 7, '费用' => 6, '报价' => 8, '套餐' => 7, '什么产品' => 8, '有哪些产品' => 9, '产品' => 4, '云服务器' => 7, '虚拟主机' => 7, 'cdn' => 5, 'ssl' => 5, '证书' => 5, '便宜' => 5, '推荐' => 3, '配置' => 3],
                'a'  => function () {
                    $lines = [];
                    $rows  = self::cheapProducts(5);
                    if ($rows) {
                        $lines[] = '我们在售产品约 ' . self::productCount() . ' 款（云服务器 / 虚拟主机 / CDN / SSL 等），部分热门低价款：';
                        foreach ($rows as $r) {
                            $lines[] = '· ' . (string)$r['name'] . '：' . money($r['price'], true) . ' / ' . YxBusiness::cycleName((string)$r['cycle']) . ' 起';
                        }
                    }
                    $lines[] = '完整列表与配置可在「首页 → 产品服务」查看，支持月付 / 年付；具体规格与库存以页面为准。需要按业务量定制方案，我可以为您转接售前顾问。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 购买 / 下单 / 支付 ---------------- */
            [
                'id' => 'buy',
                'kw' => ['购买' => 8, '怎么买' => 9, '下单' => 8, '支付' => 7, '付款' => 7, '扫码' => 6, '怎么开通' => 8, '开通' => 5, '充值' => 5, '余额' => 4],
                'a'  => '购买流程：登录后进入「首页 → 产品服务」选择套餐 → 确认配置与计费周期 → 提交订单 → 扫码支付（支持码支付通道） → 支付成功后自动开通，可在「用户中心 → 我的业务」查看。也支持「用户中心 → 余额充值」后使用余额支付。',
            ],

            /* ---------------- 应用商店 / 授权 ---------------- */
            [
                'id' => 'store',
                'kw' => ['应用商店' => 9, '插件' => 7, '应用' => 5, '主题' => 5, '支付通道' => 7, '实名认证' => 6, '对接' => 3, '商店' => 6, '下载' => 5],
                'a'  => function () {
                    $lines = ['应用商店在「应用商店」导航进入，分类包括：'];
                    $cats = [];
                    foreach (YxDb::all('SELECT name FROM ' . YxDb::table('app_category') . ' WHERE status = 1 ORDER BY sort ASC, id ASC LIMIT 9') as $c) {
                        $cats[] = (string)$c['name'];
                    }
                    if ($cats) {
                        $lines[] = '· ' . implode('、', $cats);
                    }
                    $total  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . " WHERE status = 'on'");
                    $priced = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . " WHERE status = 'on' AND price > 0");
                    $lines[] = '当前上架 ' . $total . ' 个应用，其中 ' . $priced . ' 个为付费/一次性授权，其余免费；购买后在「用户中心 → 我的授权」安装，授权码与绑定域名一一对应。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 域名授权查询 ---------------- */
            [
                'id' => 'license',
                'kw' => ['授权查询' => 10, '查询授权' => 10, '域名授权' => 9, '授权' => 6, '正版' => 6, 'license' => 6, '被封' => 7, '过期了' => 6],
                'a'  => '域名授权可在「首页 → 授权查询」输入域名自助查询，结果包括：已正版授权 / 未在官方授权 / 已过期 / 已封禁 / 开源版。若结果与实际情况不符，请把域名发给我，我为您转人工核对。',
            ],

            /* ---------------- 分销 / 推广 ---------------- */
            [
                'id' => 'affiliate',
                'kw' => ['分销' => 10, '推广' => 8, '佣金' => 9, '返利' => 9, '邀请码' => 8, '邀请链接' => 9, 'aff' => 5, '提现' => 6, '推广计划' => 9, '推广商' => 7],
                'a'  => function (string $site, ?array $customer = null) {
                    $rate  = YxAffiliate::rate() * 100;
                    $min   = YxAffiliate::minPay();
                    $lines = [];
                    if (!YxAffiliate::enabled()) {
                        $lines[] = '当前站点未开启推广分销计划，如需了解可点击「转人工」咨询。';
                        return implode("\n", $lines);
                    }
                    $lines[] = '推广分销已开启：好友通过您的专属链接或二维码进入站点并注册后，系统会记录归属关系（有效期 30 天，首次来源优先）。';
                    $lines[] = '· 佣金比例：订单实付金额的 ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
                    $lines[] = '· 结算门槛：' . money($min, true) . '，达标后可在用户中心申请结算';
                    $lines[] = '· 自购订单不计算佣金';
                    $lines[] = '推广链接与二维码在「用户中心（账户概览）→ 推广返利」区域，可直接复制或扫码分享。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 发票 ---------------- */
            [
                'id' => 'invoice',
                'kw' => ['发票' => 10, '开票' => 10, '报销' => 8, '专票' => 9, '普票' => 9, '抬头' => 7],
                'a'  => '发票可在「用户中心 → 我的账单」发起申请：选择对应已支付订单、填写发票抬头与税号后提交，我们审核后开具。需要增值税专用发票或特殊抬头，请点击「转人工」说明。',
            ],

            /* ---------------- 退款 ---------------- */
            [
                'id' => 'refund',
                'kw' => ['退款' => 10, '退费' => 10, '退订' => 8, '退钱' => 9, '不满意' => 5],
                'handoff' => true,
                'a'  => '退款需按服务实际使用状态与购买时长核算，属于人工审核事项。请提供订单号或付款截图，我为您转接财务人工处理（虚拟类产品一经开通可能不支持无理由退款，以服务条款为准）。',
            ],

            /* ---------------- 续费 / 到期 ---------------- */
            [
                'id' => 'renew',
                'kw' => ['续费' => 10, '到期' => 8, '过期' => 8, '自动续费' => 9, '怎么续' => 8, '停机' => 6],
                'a'  => '续费：登录后在「用户中心 → 我的业务」找到对应实例，点击「续费」选择周期并支付即可。到期前系统会在站内与邮件提醒，建议提前续费避免业务中断；已停机的实例续费后会自动恢复。',
            ],

            /* ---------------- 售后 / 故障 ---------------- */
            [
                'id' => 'aftersale',
                'kw' => ['售后' => 10, '故障' => 8, '打不开' => 8, '无法访问' => 8, '宕机' => 8, '报错' => 7, '异常' => 5, '不能用' => 7, '无法使用' => 8, '连不上' => 8, '很慢' => 5, '重装' => 5, '重启' => 4],
                'handoff' => true,
                'a'  => '这台实例的问题建议走人工排查：请点击上方「售后咨询」留言，附上产品名称 / 订单号、故障现象与发生时间，技术同事会尽快处理。比较紧急可直接点「转人工」，我先把会话接过去并生成工单留档。',
            ],

            /* ---------------- 账号 / 登录 / 实名 ---------------- */
            [
                'id' => 'account',
                'kw' => ['注册' => 8, '登录' => 7, '密码' => 8, '忘记密码' => 9, '找回密码' => 9, '验证码' => 6, '实名' => 8, '实名认证' => 9, '账号' => 5, '绑定手机' => 7, '绑定邮箱' => 7, '解绑' => 6],
                'a'  => '账号相关：注册后可在「用户中心 → 账号绑定」绑定手机 / 邮箱 / 微信；忘记密码可用绑定手机或邮箱自助重置；实名认证在「用户中心 → 实名认证」，完成后方可购买需实名的产品。验证码收不到可稍后重试，仍不行请「转人工」。',
            ],

            /* ---------------- 签到 / 积分 ---------------- */
            [
                'id' => 'checkin',
                'kw' => ['签到' => 10, '积分' => 8, '兑换' => 6, '签到奖励' => 9],
                'a'  => '每日签到在「用户中心 → 每日签到」进行，签到获得的积分会累加到账户，可在积分商城兑换奖品或抵扣业务。连续签到奖励更高。',
            ],

            /* ---------------- 商务合作 ---------------- */
            [
                'id' => 'business',
                'kw' => ['商务合作' => 10, '合作' => 7, '代理' => 7, '渠道' => 7, '加盟' => 7, '采购' => 6, '批发' => 7, '量大' => 6, '大客户' => 7, 'api' => 4],
                'handoff' => true,
                'a'  => '商务合作（代理 / 渠道 / 批量采购 / API 对接）请点击上方「商务合作」留言，留下公司与联系方式、合作方向与预估规模，我们会安排商务同事尽快对接；也可直接点「转人工」。',
            ],

            /* ---------------- 公司 / 站点信息 ---------------- */
            [
                'id' => 'about',
                'kw' => ['你们是谁' => 8, '公司' => 6, '关于我们' => 8, '备案' => 7, 'icp' => 7, '资质' => 6, '地址' => 5, '工作时间' => 7, '上班时间' => 7, '地址在哪' => 6],
                'a'  => function (string $site, ?array $customer = null) {
                    $lines = ['我是「' . $site . '」的智能助手小优，本站提供 IDC 云服务与财务系统产品，7×24 小时在线。'];
                    if (($icp = trim((string)setting('icp', ''))) !== '') {
                        $lines[] = '备案号：' . $icp;
                    }
                    $lines[] = '公司资质与合作信息可在「关于我们」页面查看。';
                    return implode("\n", $lines);
                },
            ],

            /* ---------------- 打招呼 ---------------- */
            [
                'id' => 'greet',
                'kw' => ['你好' => 8, '您好' => 8, 'hi' => 5, 'hello' => 5, '在吗' => 7, '在么' => 7, '有人吗' => 8, '谢谢' => 6, '感谢' => 6],
                'a'  => function (string $site, ?array $customer = null) {
                    return "您好，我是「{$site}」的小优助手，7×24 小时在线为您解答。\n您可以直接问我：产品价格、如何购买与开通、授权码与安装、应用商店插件、开发者入驻与保证金、分销推广、发票与退款、续费、账号问题。解决不了的我帮您转人工。";
                },
            ],

            /* ---------------- 能力介绍 ---------------- */
            [
                'id' => 'help',
                'kw' => ['能做什么' => 9, '你会什么' => 9, '帮我做什么' => 8, '功能' => 6, '怎么用' => 5, 'help' => 5, '帮助' => 6],
                'a'  => '我可以帮您查：产品与价格、购买开通流程、授权查询与授权码安装、应用商店插件、开发者入驻与保证金规则、分销佣金规则、发票与退款政策、续费方式、账号与实名问题。直接描述您的问题即可，答不上的会自动为您转人工。',
            ],
        ];
    }

    /** 主程序包条目（商店「主程序」分类） */
    private static function mainPackage(): ?array
    {
        try {
            $sql = 'SELECT * FROM ' . YxDb::table('app') . " WHERE status = 'on' AND `key` IN ('youxiang-finance-official','youxiang-finance-open') ORDER BY price DESC LIMIT 1";
            $row = YxDb::one($sql);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 授权包价格文案（价格取商店条目，未上架时回退配置）；程序包本体免费下载，与授权包分开收费 */
    private static function officialPriceLabel(): string
    {
        try {
            $row = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE status = 'on' AND `key` = 'youxiang-finance-official' LIMIT 1");
            if ($row) {
                return YxBusiness::priceLabel($row);
            }
        } catch (Throwable $e) {
            /* 查询失败时回退配置价，不影响回答 */
        }
        return money((float)setting('official_package_price', 366), true) . ' 一次性';
    }

    private static function productCount(): int
    {
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product') . ' WHERE status = 1');
    }

    /** 低价在售产品（跳过 0 元条目，避免报价全是「免费」），用于报价回答 */
    private static function cheapProducts(int $limit = 5): array
    {
        return YxDb::all(
            'SELECT name, price, cycle FROM ' . YxDb::table('product') . ' WHERE status = 1 AND price > 0 ORDER BY price ASC, sort ASC, id ASC LIMIT ' . max(1, $limit)
        );
    }
}

/** OpenAI 兼容接口驱动 */
class YxAiOpenAI implements YxAiDriver
{
    public function code(): string { return 'openai'; }
    public function name(): string { return 'OpenAI 兼容接口（DeepSeek/通义/智谱等）'; }

    private function conf(): array
    {
        return [
            'endpoint' => rtrim((string)setting('ai_endpoint', ''), '/'),
            'key'      => trim((string)setting('ai_api_key', '')),
            'model'    => trim((string)setting('ai_model', 'deepseek-chat')) ?: 'deepseek-chat',
            'timeout'  => max(5, (int)setting('ai_timeout', 20)),
        ];
    }

    public function chat(array $messages, array $opt = []): array
    {
        $c = $this->conf();
        if ($c['endpoint'] === '' || $c['key'] === '') {
            return ['ok' => false, 'msg' => 'AI 服务未配置（后台 → 在线客服 → 设置）'];
        }
        $payload = [
            'model'       => $c['model'],
            'messages'    => array_values($messages),
            'temperature' => (float)setting('ai_temperature', '0.6'),
            'max_tokens'  => max(64, (int)setting('ai_max_tokens', 600)),
        ];
        $headers = [
            'Authorization: Bearer ' . $c['key'],
        ];
        [$ok, $code, $body] = http_post_json($c['endpoint'] . '/chat/completions', $payload, $headers, $c['timeout']);
        if (!$ok) {
            return ['ok' => false, 'msg' => 'AI 服务不可用：' . $body];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => 'AI 返回格式异常（HTTP ' . $code . '）'];
        }
        $reply = (string)array_dig($json, 'choices.0.message.content', '');
        if ($reply === '') {
            $err = (string)array_dig($json, 'error.message', '');
            return ['ok' => false, 'msg' => $err !== '' ? ('AI 返回错误：' . $err) : 'AI 未返回内容'];
        }
        $reply   = trim($reply);
        $handoff = false;
        foreach (['[[转人工]]', '[转人工]', '[[handoff]]'] as $mark) {
            if (mb_strpos($reply, $mark) !== false) {
                $handoff = true;
                $reply   = trim(str_replace($mark, '', $reply));
            }
        }
        return ['ok' => true, 'reply' => $reply, 'handoff' => $handoff, 'raw' => $json];
    }
}

class YxAi
{
    public static function drivers(): array
    {
        return [
            'mock'   => '内置规则问答（免密钥）',
            'openai' => 'OpenAI 兼容接口',
        ];
    }

    public static function enabled(): bool
    {
        return (string)setting('ai_enabled', '1') === '1';
    }

    public static function provider(): string
    {
        $p = (string)setting('ai_provider', 'mock');
        return array_key_exists($p, self::drivers()) ? $p : 'mock';
    }

    public static function driver(string $provider = null): YxAiDriver
    {
        $p = $provider ?? self::provider();
        return $p === 'openai' ? new YxAiOpenAI() : new YxAiMock();
    }

    /** 默认系统提示词 */
    public static function systemPrompt(): string
    {
        $custom = trim((string)setting('ai_system_prompt', ''));
        if ($custom !== '') {
            return $custom;
        }
        $site = (string)setting('site_name', config('app.name'));
        return "你是「{$site}」的小优助手，7×24 小时在线，负责解答 IDC 云服务与财务系统的常见问题，并受理商务合作、售后咨询留言。"
            . "回答要简洁、专业、口语化。当你无法确定答案，或问题涉及具体订单/退款/报价/账号操作等需要人工处理的事项时，"
            . "请在回复末尾单独加上标记 [[转人工]] ，系统会自动为用户转接人工客服，不要只是口头建议。"
            . "商务合作类问题引导用户点击「商务合作」留言，售后故障类问题引导点击「售后咨询」留言。"
            . "不要编造价格、库存或承诺，不要泄露任何密钥或内部信息。";
    }

    /** 组装业务上下文（供大模型参考） */
    public static function context(): string
    {
        $lines = [];
        $lines[] = '站点名称：' . setting('site_name', config('app.name'));
        if (setting('contact_qq', '')) {
            $lines[] = '客服QQ：' . setting('contact_qq');
        }
        if (setting('contact_email', '')) {
            $lines[] = '客服邮箱：' . setting('contact_email');
        }
        $lines[] = '主要能力：产品购买与续费、账单与发票、域名授权查询、应用商店（支付/实名/云对接/主题等）、开发者中心（实名核验+保证金后上传插件主题模板售卖）、每日签到。';
        return implode("\n", $lines);
    }

    /**
     * 提问并获取回答
     * @param string $question 用户问题
     * @param array  $history  历史消息 [['role'=>'user|assistant','content'=>..], ...]
     */
    public static function ask(string $question, array $history = []): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => 'AI 客服未开启'];
        }
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt() . "\n\n【业务背景】\n" . self::context()],
        ];
        foreach ($history as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string)($h['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        return self::driver()->chat($messages);
    }
}
