<?php
/** PATADOCS — Popular documents (admin-pinned first, then by downloads + views). */
require __DIR__ . '/includes/init.php';

$per = 20; $page = max(1, get_int('page', 1));
$total = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published'");
$pg = paginate($total, $page, $per);
$rows = $total ? db_all("SELECT d.*, c.name AS category_name, c.path AS category_path FROM documents d LEFT JOIN categories c ON c.id = d.category_id
    WHERE d.status = 'published' ORDER BY d.popular DESC, (d.paid_downloads * 3 + d.free_downloads * 2 + d.view_count) DESC, d.id DESC
    LIMIT " . (int)$pg['offset'] . ', ' . (int)$per) : [];
$meta = [
    'title' => 'Popular Documents' . ($pg['page'] > 1 ? ' – Page ' . $pg['page'] : ''), 'nav' => 'popular',
    'canonical' => page_url('popular', $pg['page'] > 1 ? 'page=' . $pg['page'] : ''),
    'description' => 'The most viewed and downloaded documents on ' . setting('site_name') . ': schemes of work, exams, templates, business plans and more.',
    'schema' => [breadcrumb_schema([['Home', url('')], ['Popular documents', null]])],
] + seo_pagination($pg, page_url('popular'));
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-popular">
    <div class="panel">
        <div class="panel-header">POPULAR DOCUMENTS</div>
        <div class="panel-body">
            <?= doc_table_html($rows, 'No documents have been published yet.') ?>
            <?= pager_html($pg, page_url('popular')) ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
