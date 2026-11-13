/* 优享财务系统 · 门户交互（原生 JS，无依赖）
   轮播 / 产品页签 / 移动端菜单 / 悬浮栏 */
(function () {
    'use strict';

    /* ---- banner 轮播：手写版，够用就行 ---- */
    var banner = document.getElementById('zdBanner');
    if (banner) {
        var slides = banner.querySelectorAll('.zd-banner-slide');
        var dotsBox = banner.querySelector('.zd-banner-dots');
        var cur = 0, timer = null;

        // 按幻灯片数量生成指示点
        for (var i = 0; i < slides.length; i++) {
            var dot = document.createElement('i');
            dot.setAttribute('data-i', i);
            if (i === 0) dot.className = 'on';
            dotsBox.appendChild(dot);
        }
        var dots = dotsBox.querySelectorAll('i');

        function go(n) {
            slides[cur].classList.remove('is-active');
            dots[cur].classList.remove('on');
            cur = (n + slides.length) % slides.length;
            slides[cur].classList.add('is-active');
            dots[cur].classList.add('on');
        }
        function play() {
            stop();
            if (slides.length > 1) {
                timer = setInterval(function () { go(cur + 1); }, 5000);
            }
        }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }

        dotsBox.addEventListener('click', function (e) {
            var t = e.target;
            if (t.tagName === 'I') { go(parseInt(t.getAttribute('data-i'), 10)); play(); }
        });
        banner.addEventListener('mouseenter', stop);
        banner.addEventListener('mouseleave', play);
        play();
    }

    /**
     * 产品页签切换
     * 点击 .zd-tabs-item，按 data-tab 序号切换同名 .zd-market-panel
     */
    var tabItems = document.querySelectorAll('.zd-tabs-item');
    if (tabItems.length) {
        var panels = document.querySelectorAll('.zd-market-panel');
        Array.prototype.forEach.call(tabItems, function (item) {
            item.addEventListener('click', function () {
                var idx = item.getAttribute('data-tab');
                Array.prototype.forEach.call(tabItems, function (it) { it.classList.remove('on'); });
                item.classList.add('on');
                Array.prototype.forEach.call(panels, function (p) {
                    p.classList.toggle('show', p.getAttribute('data-panel') === idx);
                });
            });
        });
    }

    /**
     * 功能视频介绍：点击封面播放，并实时检测播放状态
     * 状态机：idle 未播放 → loading 加载中 → playing 播放中 → paused 已暂停 / ended 已播完
     * error 加载失败；缓冲时优先显示 playing，不把缓冲误报为暂停。
     */
    var videoPlay = document.querySelector('[data-video-play]');
    if (videoPlay) {
        var video  = videoPlay.querySelector('video');
        var cover  = videoPlay.querySelector('[data-video-cover]');
        var status = videoPlay.querySelector('[data-video-status]');

        var setStatus = function (state, text) {
            if (status) {
                status.setAttribute('data-video-status', state);
                status.innerHTML = '播放状态：<b></b>';
                status.querySelector('b').textContent = text;
            }
            videoPlay.setAttribute('data-video-state', state);
            if (cover) { cover.classList.toggle('is-hidden', state === 'playing' || state === 'paused' || state === 'ended'); }
        };

        var hideCover = function () { if (cover) { cover.classList.add('is-hidden'); } };

        var sync = function () {
            if (!video) { return; }
            if (video.error) { setStatus('error', '加载失败，请检查视频地址'); return; }
            if (!video.paused && !video.ended) {
                if (video.readyState < 3) {
                    setStatus('loading', '缓冲中…');
                } else {
                    setStatus('playing', '播放中 ' + formatTime(video.currentTime) + ' / ' + formatTime(video.duration));
                    hideCover();
                }
                return;
            }
            if (video.ended) { setStatus('ended', '已播放完毕'); return; }
            if (video.currentTime > 0) {
                setStatus('paused', '已暂停 ' + formatTime(video.currentTime) + ' / ' + formatTime(video.duration));
            } else if (video.readyState >= 1) {
                setStatus('idle', '未播放 · 时长 ' + formatTime(video.duration));
            } else {
                setStatus('idle', '未播放');
            }
        };

        var formatTime = function (sec) {
            if (!isFinite(sec) || sec < 0) { return '--:--'; }
            var m = Math.floor(sec / 60), s = Math.floor(sec % 60);
            return m + ':' + (s < 10 ? '0' : '') + s;
        };

        if (video) {
            ['loadedmetadata', 'play', 'playing', 'waiting', 'pause', 'ended', 'seeking', 'seeked', 'error', 'emptied']
                .forEach(function (ev) { video.addEventListener(ev, sync); });
            video.addEventListener('timeupdate', function () {
                // 只在播放中刷新时间，避免暂停/空闲时高频重排
                if (!video.paused && !video.ended) { sync(); }
            });
            if (video.readyState >= 1) { sync(); }
        }

        if (cover && video) {
            cover.addEventListener('click', function () {
                hideCover();
                setStatus('loading', '加载中…');
                var p = video.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(function () { setStatus('error', '浏览器阻止了播放，请点击视频上的播放按钮'); });
                }
            });
        }
    }

    /* ---- 移动端导航（抽屉） ---- */
    var menuBtn = document.getElementById('zdMenuBtn');
    if (menuBtn) {
        var navMask = document.getElementById('zdNavMask');
        var navClose = document.getElementById('zdNavClose');

        var setNav = function (open) {
            document.body.classList.toggle('zd-nav-open', open);
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        menuBtn.addEventListener('click', function () {
            setNav(!document.body.classList.contains('zd-nav-open'));
        });
        // 点遮罩或关闭按钮收起
        if (navMask) { navMask.addEventListener('click', function () { setNav(false); }); }
        if (navClose) { navClose.addEventListener('click', function () { setNav(false); }); }
        // ESC 关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('zd-nav-open')) { setNav(false); }
        });
        // 点导航链接后收起，避免挡住内容
        document.querySelectorAll('.zd-nav a').forEach(function (a) {
            a.addEventListener('click', function () { setNav(false); });
        });
        // 视口变宽回桌面时复位（与 CSS 抽屉断点 1200 保持一致）
        window.addEventListener('resize', function () {
            if (window.innerWidth > 1200 && document.body.classList.contains('zd-nav-open')) { setNav(false); }
        });
    }

    /* ---- 悬浮栏：回到顶部 + 在线客服 ---- */
    var floatBar = document.getElementById('zdFloat');
    var toTop = document.getElementById('zdToTop');
    if (floatBar && toTop) {
        var onScroll = function () {
            floatBar.classList.toggle('show-top', window.pageYOffset > 320);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
        toTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    var chatBtn = document.getElementById('zdFloatChat');
    if (chatBtn) {
        chatBtn.addEventListener('click', function () {
            // 优先唤起在线客服浮窗；未启用客服时退回工单页
            var fab = document.getElementById('yxChatFab');
            if (fab) { fab.click(); return; }
            var fallback = chatBtn.getAttribute('data-href');
            if (fallback) window.location.href = fallback;
        });
    }
})();
