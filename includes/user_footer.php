    </div>
    <footer class="main-foot">
        <span><?= e(setting('site_name', config('app.name'))) ?> v<?= e(config('app.version')) ?></span>
        <span><?= e(YxEdition::name()) ?></span>
    </footer>
</main>
<script>
(function () {
    var body = document.body;
    var toggle = document.getElementById('yxMenuToggle');
    var mask = document.getElementById('yxSidebarMask');
    if (toggle) {
        toggle.addEventListener('click', function () { body.classList.toggle('sidebar-open'); });
    }
    if (mask) {
        mask.addEventListener('click', function () { body.classList.remove('sidebar-open'); });
    }
    // 移动端点击菜单项后自动收起抽屉
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('.sidebar-menu a') : null;
        if (a && window.matchMedia('(max-width: 960px)').matches) {
            body.classList.remove('sidebar-open');
        }
    });

    /* ---------------- 顶栏一级功能下拉 + 账号菜单 ---------------- */
    (function () {
        var navs = Array.prototype.slice.call(document.querySelectorAll('#yxNavbar .yx-nav'));
        var userbox = document.getElementById('yxUserbox');
        if (!navs.length && !userbox) return;

        function closeAll(except) {
            navs.forEach(function (n) {
                if (n === except) return;
                n.classList.remove('open');
                var b = n.querySelector('.yx-nav-btn');
                if (b) b.setAttribute('aria-expanded', 'false');
            });
            if (userbox && except !== userbox) {
                userbox.classList.remove('open');
                var ub = userbox.querySelector('.yx-userbox-btn');
                if (ub) ub.setAttribute('aria-expanded', 'false');
            }
        }

        navs.forEach(function (nav) {
            var btn = nav.querySelector('.yx-nav-btn');
            if (!btn) return;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var willOpen = !nav.classList.contains('open');
                closeAll(null);
                nav.classList.toggle('open', willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
            // 桌面端鼠标悬停即展开（点击对触屏仍然有效）
            nav.addEventListener('mouseenter', function () {
                if (!window.matchMedia('(hover: hover) and (min-width: 961px)').matches) return;
                closeAll(nav);
                nav.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            });
            nav.addEventListener('mouseleave', function () {
                if (!window.matchMedia('(hover: hover) and (min-width: 961px)').matches) return;
                nav.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            });
        });

        if (userbox) {
            var ubtn = userbox.querySelector('.yx-userbox-btn');
            if (ubtn) {
                ubtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var willOpen = !userbox.classList.contains('open');
                    closeAll(null);
                    userbox.classList.toggle('open', willOpen);
                    ubtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
            }
        }

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.closest && (t.closest('.yx-nav') || t.closest('#yxUserbox'))) return;
            closeAll(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll(null);
        });
    })();

    // 订单支付方式切换：页签与面板一一对应，仅显示 .active 面板
    (function () {
        var tabs = document.querySelectorAll('[data-pay-tab]');
        if (!tabs.length) return;
        var panels = document.querySelectorAll('[data-pay-panel]');
        function activate(key) {
            tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-pay-tab') === key); });
            panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-pay-panel') === key); });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () { activate(t.getAttribute('data-pay-tab')); });
        });
    })();
    // 业务登录密码：点击显示 / 隐藏
    document.querySelectorAll('.yx-pass-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var span = btn.parentNode.querySelector('.yx-pass');
            if (!span) return;
            var shown = btn.getAttribute('data-shown') === '1';
            span.textContent = shown ? '••••••' : (span.getAttribute('data-pass') || '');
            btn.textContent = shown ? '显示' : '隐藏';
            btn.setAttribute('data-shown', shown ? '0' : '1');
        });
    });
})();
</script>
<?php require __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
