<?php
/**
 * 优享财务系统 · 诉求性质判定（自助受理边界）
 *
 * 解决的问题
 *   站内有一批「自助受理」入口：工单举报、版权侵权举报与自助检测、漏洞提交、
 *   服务中心下单等。它们只适合处理平台自身范围内的请求（违规下架、退款申诉、
 *   漏洞反馈、备案材料代办…）。但用户经常把**已经进入公安/司法程序的诉求**
 *   （报案、立案、冻结、传唤、调证）也塞进这些表单，甚至期望「在线报案」。
 *
 *   平台不是公安/司法机关，无权受理报案、不能代替报案、也不该给出报案结论。
 *   这类诉求必须走人工核对身份与材料，再由人工导办至对应机关，不能自助流转。
 *
 * 判定口径
 *   只做**提示与分流**，不拦截用户表达诉求的权利：命中后仍允许提交（投诉平台
 *   自身服务问题的内容不该被堵死），但表单上必须显示「仅人工办理」提示，后台
 *   列表/详情必须标注，人工处理时给出导办口径。
 *
 * 关键词同时覆盖「报案类」与「易被误当作报案」的高危词，宁可比人工判断多提示，
 * 也不能让用户误以为填完表单就等于报案成功。
 */

class YxDispute
{
    /** 诉求性质 */
    const TYPE_TITLE = '报案 / 涉警诉求';
    const TYPE_KEY   = 'police';

    /**
     * 报案类强特征词：命中任意一个即判定为报案类诉求
     *
     * 只收「一旦出现就基本确定诉求性质」的词，避免误伤普通投诉
     * （例如只收「报案」而不是「报警」以外的泛化词，不收「网络」这类高频词）。
     */
    const KEYWORDS = [
        // 报案 / 立案
        '报案', '报警', '立案', '不予立案', '受案', '接警', '出警', '人民警察', '派出所',
        '公安机关', '公安局', '经侦', '刑警', '网警', '反诈中心',
        // 司法 / 调查
        '法院', '检察院', '起诉', '诉讼', '传唤', '笔录', '取证', '调证', '协查',
        '冻结账户', '账号冻结', '资金冻结', '涉案', '犯罪嫌疑人', '通缉',
        // 诈骗受害
        '被诈骗', '被骗', '诈骗受害人', '骗子账号',
        // 行政举报（同样由机关受理，平台不代受理）
        '举报至公安', '向公安举报', '网安大队',
    ];

    /** 人工导办口径（前台提示与后台处理说明共用，避免两处措辞不一致） */
    const NOTICE = [
        'title' => '报案 / 涉警诉求仅人工办理',
        'body'  => '平台不是公安机关，无权受理报案、立案、冻结账号或调取证据，也不能代为向机关报案。'
            . '涉及此类内容请通过本站客服人工通道（在线客服「转人工」、工单「投诉与违规」部门或客服邮箱）提交，'
            . '由人工核对身份与材料后协助导办；紧急或已发生财产损失，请直接拨打 110 或通过「国家反诈中心」App 报案。',
        'steps' => [
            '① 直接拨打 110（紧急）或到就近派出所报案，保留受案回执 / 立案编号；',
            '② 涉及诈骗资金，请同时联系开户银行或支付平台申请止付；',
            '③ 需要平台配合取证（订单、日志、实名信息）时，请由公安/司法机关出具调取证据通知书，本站依法配合。',
        ],
    ];

    /**
     * 判定文本是否属于报案类诉求
     *
     * @param string ...$texts 待判定的文本片段（标题、正文、摘要可一起传）
     * @return array ['hit'=>bool,'type'=>string,'type_name'=>string,'keywords'=>string[]]
     */
    public static function inspect(...$texts): array
    {
        $blob = '';
        foreach ($texts as $t) {
            $blob .= ' ' . (string)$t;
        }
        $hits = [];
        if ($blob !== ' ') {
            foreach (self::KEYWORDS as $kw) {
                if (mb_stripos($blob, $kw) !== false) {
                    $hits[] = $kw;
                }
            }
        }
        return [
            'hit'       => $hits !== [],
            'type'      => $hits !== [] ? self::TYPE_KEY : '',
            'type_name' => $hits !== [] ? self::TYPE_TITLE : '',
            'keywords'  => $hits,
        ];
    }

    /**
     * 后台列表用的轻量标注（不改变数据，只用于展示）
     *
     * @param array $row 举报单 / 漏洞单 / 侵权举报单
     * @return array 同 inspect()，额外带回 judge_note（命中词拼接）
     */
    public static function inspectRow(array $row): array
    {
        $res = self::inspect(
            (string)($row['subject'] ?? ''),
            (string)($row['title'] ?? ''),
            (string)($row['work_title'] ?? ''),
            (string)($row['content'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['target_text'] ?? '')
        );
        $res['judge_note'] = $res['hit'] ? ('命中：' . implode('、', $res['keywords'])) : '';
        return $res;
    }

    /**
     * 批量标注：给列表每行补 manual_only 判定结果（只读，不写库）
     *
     * @param array $rows 举报单 / 漏洞单 / 侵权举报单列表
     * @return array 每行追加 dispute_hit / dispute_note 两个键，便于模板直接展示
     */
    public static function decorate(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $res = self::inspectRow((array)$row);
            $rows[$i]['dispute_hit']  = $res['hit'];
            $rows[$i]['dispute_note'] = $res['judge_note'];
        }
        return $rows;
    }

    /** 后台列表徽标：仅人工办理（命中时才显示，未命中返回空串） */
    public static function badge(array $row): string
    {
        $hit = !empty($row['manual_only']) || !empty($row['dispute_hit'])
            || self::inspectRow($row)['hit'];
        if (!$hit) {
            return '';
        }
        $tip = (string)($row['dispute_note'] ?? '');
        return '<span class="badge badge-bad" title="' . e($tip !== '' ? $tip : self::TYPE_TITLE) . '">仅人工办理</span>';
    }

    /** 前台提示块（HTML），命中时调用 */
    public static function noticeHtml(): string
    {
        $n = self::NOTICE;
        $html  = '<div class="alert alert-warn"><strong>' . e($n['title']) . '</strong><br>' . e($n['body']);
        $html .= '<ul style="margin:8px 0 0 18px">';
        foreach ($n['steps'] as $s) {
            $html .= '<li>' . e($s) . '</li>';
        }
        return $html . '</ul></div>';
    }

    /**
     * 后台人工核查区块（HTML）
     *
     * 让处理人员在审核页就能看到「这条诉求为什么被判定为报案类」以及导办口径，
     * 不必回到原文逐字查找，避免把报案诉求当普通举报驳回。
     */
    public static function adminPanel(array $row): string
    {
        $res = self::inspectRow($row);
        $hit = !empty($row['manual_only']) || !empty($row['dispute_hit']) || $res['hit'];
        if (!$hit) {
            return '';
        }
        $n    = self::NOTICE;
        $note = (string)($row['dispute_note'] ?? '');
        if ($note === '' && $res['keywords']) {
            $note = '命中：' . implode('、', $res['keywords']);
        }
        $html  = '<div class="alert alert-warn" style="margin:12px 0">';
        $html .= '<strong>人工核查：' . e($n['title']) . '</strong>';
        if ($note !== '') {
            $html .= '<br><span class="small">判定依据 ' . e($note) . '（关键词提示，需人工复核原文）</span>';
        }
        $html .= '<br><span class="small">' . e($n['body']) . '</span>';
        $html .= '<ul style="margin:8px 0 0 18px">';
        foreach ($n['steps'] as $s) {
            $html .= '<li class="small">' . e($s) . '</li>';
        }
        return $html . '</ul></div>';
    }

    /** 人工处理时直接复制的导办说明（写进 handle_note / 回复） */
    public static function handoffText(): string
    {
        return '【报案类诉求 · 仅人工办理】' . self::NOTICE['body'];
    }
}
