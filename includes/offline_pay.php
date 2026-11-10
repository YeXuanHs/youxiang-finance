<?php
/**
 * 优享财务系统 · 银联卡转账（线下付款）
 *
 * 面向「应用商店单独购买」等一次性付款场景：用户在订单页选择「银联卡转账」，
 * 页面展示后台配置的收款户名（公司 / 个人）、卡号、开户行，用户线下转账后
 * 由管理员在后台核对并确认收款，确认后走统一的订单交付流程。
 *
 * 配置项全部存放在 setting()，后台「系统设置」可随时修改，前台即时生效。
 */

/** 是否启用银联转账通道 */
function yx_offline_enabled(): bool
{
    return setting('offline_enabled', '0') === '1';
}

/** 收款资料：有实际填写内容时 hasContent 为真 */
function yx_offline_info(): array
{
    $info = [
        'type'    => setting('offline_type', 'company') === 'personal' ? 'personal' : 'company',
        'name'    => trim((string)setting('offline_name', '')),
        'card'    => trim((string)setting('offline_card', '')),
        'bank'    => trim((string)setting('offline_bank', '')),
        'branch'  => trim((string)setting('offline_branch', '')),
        'contact' => trim((string)setting('offline_contact', '')),
        'note'    => trim((string)setting('offline_note', '')),
    ];
    $info['type_name']   = $info['type'] === 'personal' ? '个人账户' : '公司账户';
    $info['hasContent']  = $info['name'] !== '' || $info['card'] !== '' || $info['bank'] !== '';
    return $info;
}

/** 通道可用：已启用且至少填了户名 + 卡号 */
function yx_offline_available(): bool
{
    $info = yx_offline_info();
    return yx_offline_enabled() && $info['name'] !== '' && $info['card'] !== '';
}

/** 该订单当前是否处于「待转账确认」 */
function yx_offline_pending(array $order): bool
{
    return (string)($order['status'] ?? '') === 'awaiting';
}

/**
 * 渲染前台收款信息卡片
 * @param array  $order 订单记录（含 sn / total）
 * @param bool   $compact 紧凑模式（用于列表内嵌）
 */
function yx_offline_render(array $order, bool $compact = false): string
{
    $info = yx_offline_info();
    if (!$info['hasContent']) {
        return '<p class="muted small">管理员尚未填写收款账户信息，请联系客服获取。</p>';
    }

    $rows = [];
    $rows[] = ['收款方类型', e($info['type_name'])];
    $rows[] = [$info['type'] === 'personal' ? '收款人姓名' : '收款单位', e($info['name'] !== '' ? $info['name'] : '未填写')];
    $rows[] = ['银行卡号', '<span class="mono offline-card">' . e($info['card'] !== '' ? $info['card'] : '未填写') . '</span>'];
    $rows[] = ['开户银行', e($info['bank'] !== '' ? $info['bank'] : '未填写')];
    if ($info['branch'] !== '') {
        $rows[] = ['开户支行', e($info['branch'])];
    }

    $html = '<div class="offline-pay' . ($compact ? ' offline-pay-compact' : '') . '">';
    $html .= '<table class="data kv offline-table">';
    foreach ($rows as $r) {
        $html .= '<tr><th>' . $r[0] . '</th><td>' . $r[1] . '</td></tr>';
    }
    $html .= '<tr><th>转账金额</th><td><strong class="offline-amount">' . e(money((float)$order['total'], true)) . '</strong></td></tr>';
    $html .= '<tr><th>转账备注</th><td><code>' . e((string)$order['sn']) . '</code> <span class="muted small">（务必填写，否则无法对账）</span></td></tr>';
    $html .= '</table>';

    if ($info['note'] !== '') {
        $html .= '<div class="offline-note pre-wrap">' . e($info['note']) . '</div>';
    }
    if ($info['contact'] !== '') {
        $html .= '<p class="muted small mt-2">已转账？请联系 ' . e($info['contact']) . ' 告知订单号，核对后即时开通。</p>';
    } else {
        $html .= '<p class="muted small mt-2">转账完成后请联系客服告知订单号，核对到账后即时开通。</p>';
    }
    $html .= '</div>';
    return $html;
}

/** 银行名称参考（用于后台下拉，允许自由填写） */
function yx_offline_banks(): array
{
    return ['中国工商银行', '中国农业银行', '中国银行', '中国建设银行', '交通银行', '招商银行',
        '中国邮政储蓄银行', '中信银行', '中国民生银行', '兴业银行', '浦发银行', '中国光大银行',
        '平安银行', '华夏银行', '广发银行', '北京银行', '上海银行', '农村商业银行'];
}
