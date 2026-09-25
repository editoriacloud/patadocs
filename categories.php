<?php
/** PATADOCS — Browse by category (top level tiles + full category tree for internal linking). */
require __DIR__ . '/includes/init.php';

$top = cat_children(null, true);
$counts = cat_counts();
$meta = [
    'title' => 'Browse Documents by Category', 'nav' => 'categories', 'canonical' => page_url('categories'),
    'description' => 'Browse all document categories: education, business, government, careers, agriculture, NGOs and more.',
    'schema' => [breadcrumb_schema([['Home', url('')], ['Categories', null]])],
];
$walk = function ($nodes) use (&$walk, $counts) {
    $h = '<ul>';
    foreach ($nodes as $n) {
        $h .= '<li><div class="node"><span class="nm"><a href="' . e(cat_url($n)) . '">' . e($n['icon'] ? $n['icon'] . ' ' : '') . e($n['name']) . '</a></span>'
            . '<span class="meta">' . num($counts[(int)$n['id']] ?? 0) . ' documents</span></div>';
        if ($n['children']) { $h .= $walk($n['children']); }
        $h .= '</li>';
    }
    return $h . '</ul>';
};
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-categories">
    <div class="panel">
        <div class="panel-header">BROWSE BY CATEGORY</div>
        <div class="panel-body">
            <?= $top ? cat_tiles_html($top) : '<div class="alert alert-info">No categories yet.</div>' ?>
        </div>
    </div>
    <?php if ($top) { $tree = cat_tree(null, true); ?>
    <div class="panel">
        <div class="panel-header">ALL CATEGORIES &amp; SUB-CATEGORIES</div>
        <div class="panel-body"><div class="tree"><?= $walk($tree) ?></div></div>
    </div>
    <?php } ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
