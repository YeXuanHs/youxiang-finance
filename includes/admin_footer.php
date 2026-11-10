    </div>
    <footer class="main-foot">
        <span><?= e(setting('site_name', config('app.name'))) ?> v<?= e(config('app.version')) ?></span>
        <span><?= e(YxEdition::name()) ?> · <?= e($licenseBadge['text']) ?></span>
        <span><?= e(setting('icp', '')) ?></span>
    </footer>
</main>
</body>
</html>
