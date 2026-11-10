    </div>
    <footer class="main-foot">
        <span><?= e(setting('site_name', config('app.name'))) ?> v<?= e(config('app.version')) ?></span>
        <span>用户后台</span>
    </footer>
</main>
<?php require __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
