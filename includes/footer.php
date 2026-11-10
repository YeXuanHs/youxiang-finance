<?php
/**
 * 优享财务系统 · 门户布局尾部（营销版式）
 * 与 header.php 配套：$pageFull 为 true 时首页未开 .portal-wrap，这里也不闭合
 */
if (empty($pageFull)) {
    echo '</div>'; // .portal-wrap
}

$footName  = setting('site_name', config('app.name'));
// 联系方式优先取「关于我们」中维护的字段，未填写时回退到系统全局设置
$footPhone = trim((string)setting('about_contact_phone', ''));
if ($footPhone === '') { $footPhone = trim((string)setting('contact_phone', '')); }
$footEmail = trim((string)setting('about_contact_email', ''));
if ($footEmail === '') { $footEmail = trim((string)setting('contact_email', '')); }
$footAddr  = trim((string)setting('about_contact_addr', ''));
if ($footAddr === '') { $footAddr = trim((string)setting('contact_addr', '')); }
$footQq    = trim((string)setting('about_contact_qq', ''));
if ($footQq === '') { $footQq = trim((string)setting('contact_qq', '')); }
$footIcp   = trim((string)setting('icp', ''));
$footGongan = trim((string)setting('gongan', ''));
$footLicenseImg = trim((string)setting('license_img', ''));
// 公安备案查询编码：仅保留数字，用于拼接官方查询链接
$footGonganCode = preg_replace('/\D+/', '', $footGongan);
?>
<div class="zd-foot-service">
    <div class="portal-container">
        <ul>
            <li><a href="<?= e(url('user/tickets.php')) ?>"><i>退</i><span><b>无忧退订</b><em>服务不满意可申请退订</em></span></a></li>
            <li><a href="<?= e(url('store/index.php')) ?>"><i>0元</i><span><b>免费应用</b><em>商店免费应用装完即用</em></span></a></li>
            <li><a href="<?= e(url('user/tickets.php')) ?>"><i>专</i><span><b>专业服务</b><em>业务全流程人工支持</em></span></a></li>
            <li><a href="<?= e(url('user/tickets.php')) ?>"><i>7×24</i><span><b>全天候</b><em>多渠道服务支持</em></span></a></li>
            <li><a href="<?= e(url('report.php')) ?>"><i>馈</i><span><b>建议反馈</b><em>优化改进建议直达官方</em></span></a></li>
        </ul>
    </div>
</div>

<footer class="zd-footer">
    <div class="portal-container">
        <div class="zd-footer-cols">
            <div class="zd-footer-brand">
                <h4><?= e($footName) ?></h4>
                <p><?= e(setting('site_sub', '')) ?>：产品目录、订单、客户、账单、工单、续费与经营分析，业务与财务一套系统跑通。</p>
            </div>
            <div>
                <h4>产品</h4>
                <a href="<?= e(url('index.php#products')) ?>">产品服务</a>
                <a href="<?= e(url('store/index.php')) ?>">应用商店</a>
                <a href="<?= e(url('index.php#editions')) ?>">版本选择</a>
                <a href="<?= e(url('index.php#license')) ?>">授权查询</a>
                <?php if (wechat_enabled()): ?><a href="<?= e(url('index.php#wechat')) ?>">公众号</a><?php endif; ?>
            </div>
            <div>
                <h4>支持</h4>
                <a href="<?= e(url('docs/')) ?>">帮助文档</a>
                <a href="<?= e(url('user/tickets.php')) ?>">提交工单</a>
                <a href="<?= e(url('report.php')) ?>">举报投诉</a>
                <a href="<?= e(url('infringe.php')) ?>">版权侵权处理</a>
                <?php if (YxNews::isEnabled()): ?><a href="<?= e(url('news.php')) ?>">新闻公告</a><?php endif; ?>
                <a href="<?= e(url('career.php')) ?>">加入我们 / 招聘</a>
                <?php if (YxAbout::hasContent()): ?><a href="<?= e(url('about.php')) ?>">关于我们</a><?php endif; ?>
            </div>
            <div class="zd-footer-contact">
                <h4>联系我们</h4>
                <span class="zd-contact-line">
                    <span class="zd-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.61 21 3 13.39 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2z"/></svg></span>
                    <span class="zd-contact-txt"><em>电话</em>
                        <?php if ($footPhone !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $footPhone)) ?>"><?= e($footPhone) ?></a><?php else: ?><i class="zd-contact-empty">暂未配置</i><?php endif; ?>
                    </span>
                </span>
                <span class="zd-contact-line">
                    <span class="zd-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M2 5.5A2.5 2.5 0 0 1 4.5 3h15A2.5 2.5 0 0 1 22 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-15A2.5 2.5 0 0 1 2 18.5v-13zm2.2-.5 7.8 5.6 7.8-5.6H4.2zM4 6.9V18a.5.5 0 0 0 .5.5h15A.5.5 0 0 0 20 18V6.9l-7.4 5.3a1 1 0 0 1-1.2 0L4 6.9z"/></svg></span>
                    <span class="zd-contact-txt"><em>邮箱</em>
                        <?php if ($footEmail !== ''): ?><a href="mailto:<?= e($footEmail) ?>"><?= e($footEmail) ?></a><?php else: ?><i class="zd-contact-empty">暂未配置</i><?php endif; ?>
                    </span>
                </span>
                <span class="zd-contact-line">
                    <span class="zd-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg></span>
                    <span class="zd-contact-txt"><em>地址</em>
                        <?php if ($footAddr !== ''): ?><b><?= e($footAddr) ?></b><?php else: ?><i class="zd-contact-empty">暂未配置</i><?php endif; ?>
                    </span>
                </span>
                <?php if ($footQq !== ''): ?>
                <span class="zd-contact-line">
                    <span class="zd-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg></span>
                    <span class="zd-contact-txt"><em>客服 QQ</em><b><?= e($footQq) ?></b></span>
                </span>
                <?php endif; ?>
                <span class="zd-contact-line">
                    <span class="zd-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M12 3a9 9 0 0 0-9 9v6a2 2 0 0 0 2 2h2v-8H5v-1a7 7 0 0 1 14 0v1h-2v8h2a2 2 0 0 0 2-2v-6a9 9 0 0 0-9-9z"/></svg></span>
                    <span class="zd-contact-txt"><em>在线支持</em><a href="<?= e(url('user/tickets.php')) ?>">提交工单 →</a></span>
                </span>
            </div>
        </div>
        <?php if ($footLicenseImg !== ''): ?>
        <div class="zd-footer-license">
            <a href="<?= e(yx_upload_url($footLicenseImg)) ?>" target="_blank" rel="noopener" title="点击查看营业执照大图">
                <img src="<?= e(yx_upload_url($footLicenseImg)) ?>" alt="营业执照" loading="lazy">
            </a>
            <span>营业执照</span>
        </div>
        <?php endif; ?>
        <div class="zd-footer-copy">
            <span>Copyright © <?= date('Y') ?> <?= e($footName) ?> · <?= e(YxEdition::name()) ?> · v<?= e(config('app.version')) ?></span>
            <span>
                <?php if ($footIcp !== ''): ?><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener"><?= e($footIcp) ?></a><?php endif; ?>
                <?php if ($footGongan !== ''): ?><a href="https://beian.mps.gov.cn/#/query/webSearch?code=<?= e($footGonganCode) ?>" target="_blank" rel="nofollow noopener" class="zd-gongan"><svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true" style="vertical-align:-2px;margin-right:3px"><path d="M12 1 3 5v6c0 5.25 3.84 10.17 9 11 5.16-.83 9-5.75 9-11V5l-9-4zm0 2.18 7 3.11V11c0 4.15-3 8.24-7 9.13-4-.89-7-4.98-7-9.13V6.29l7-3.11z"/></svg><?= e($footGongan) ?></a><?php endif; ?>
                <a href="<?= e(url('infringe.php')) ?>">版权侵权</a>
                <a href="<?= e(url('report.php')) ?>">工单举报</a>
            </span>
        </div>
    </div>
</footer>

<div class="zd-float" id="zdFloat">
    <button type="button" class="zd-totop" id="zdToTop" title="回到顶部">↑</button>
</div>
<?php require __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
