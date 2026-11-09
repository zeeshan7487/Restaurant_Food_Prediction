<?php
/**
 * layout.php - the page frame (menu bar, messages, footer) shared by every page.
 *
 * Every piece of text that came from the database or the user (titles, names,
 * flash messages, option labels...) is printed through e() (helpers.php), which
 * escapes HTML special characters. Without this, a meal category or username
 * containing something like <script> could run in another user's browser
 * (cross-site scripting / XSS).
 */
declare(strict_types=1);

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $app = $GLOBALS['CONFIG']['app_name'];
    $items = [
        'dashboard'   => ['index.php', 'Dashboard'],
        'predict'     => ['predict.php', 'Predict'],
        'records'     => ['records.php', 'Records'],
        'predictions' => ['predictions.php', 'History'],
        'model'       => ['model.php', 'Results'],
    ];
    if ($user && $user['role'] === 'admin') {
        $items['users'] = ['users.php', 'Users'];
    }
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e($app) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="index.php"><?= e($app) ?></a>
        <?php if ($user): ?>
        <nav class="nav" aria-label="Main">
            <?php foreach ($items as $key => [$href, $label]): ?>
                <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="userbox">
            <a href="account.php"><?= e($user['full_name']) ?></a>
            <form method="post" action="logout.php" class="inline">
                <?= csrf_field() ?>
                <button class="link-btn" type="submit">Log out</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</header>
<main class="wrap">
    <?php foreach (flash_take() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="footer wrap">
    <span>BSCS final project - AI-Based Restaurant Food Demand and Waste Prediction System</span>
</footer>
<script src="assets/charts.js"></script>
</body>
</html>
    <?php
}

/** A small helper for <select> options. */
function options(array $rows, string $valueKey, callable $label, $selected = null, string $blank = ''): string
{
    $html = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
    foreach ($rows as $r) {
        $sel = ((string)$r[$valueKey] === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . e($r[$valueKey]) . '"' . $sel . '>' . e($label($r)) . '</option>';
    }
    return $html;
}
