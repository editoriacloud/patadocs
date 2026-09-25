<?php
/** Admin: search synonyms (two-way) + a tester that shows how a query is understood. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('synonyms.manage');
$back = url('admin/synonyms.php');
if (is_post()) {
    csrf_check(); $act = post_str('do', 8);
    if ($act === 'delete') { db_exec('DELETE FROM search_synonyms WHERE id = ?', [post_int('id')]); flash('success', 'Synonym removed.'); redirect($back); }
    if ($act === 'add') {
        $n = 0;
        $lines = post_str('term', 100) !== '' ? [post_str('term', 100) . '=' . post_str('canonical', 150)] : [];
        foreach (preg_split('/\R/u', (string)($_POST['bulk'] ?? '')) as $l) { if (trim($l) !== '') { $lines[] = $l; } }
        foreach ($lines as $l) {
            $parts = array_map('trim', explode('=', $l, 2));
            $t = mb_strtolower(mb_substr($parts[0] ?? '', 0, 100)); $c = mb_strtolower(mb_substr($parts[1] ?? '', 0, 150));
            if ($t !== '' && $c !== '' && $t !== $c) { $n += db_exec('INSERT IGNORE INTO search_synonyms (term, canonical) VALUES (?, ?)', [$t, $c]); }
        }
        flash($n ? 'success' : 'warn', $n . ' synonym(s) added.'); redirect($back);
    }
}
$rows = db_all('SELECT * FROM search_synonyms ORDER BY canonical, term');
$test = get_str('test', 150); $groups = $test !== '' ? search_groups($test) : [];
$adm = ['title' => 'Search Synonyms', 'active' => 'synonyms'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">SEARCH SYNONYMS</div><div class="panel-body">
    <p class="desc-text" style="margin-bottom:14px;">Synonyms work both ways: with <code>math = mathematics</code>, a search for “math” also finds “mathematics” documents and vice-versa. Examples: <code>cv = curriculum vitae</code>, <code>cbo = community based organization</code>, <code>exam = examinations</code>.</p>
    <div class="grid-2">
        <form method="post" class="pd-form"><?= csrf_field() ?><input type="hidden" name="do" value="add">
            <div class="form-grid"><div class="frow"><label>Word / abbreviation</label><input type="text" name="term" maxlength="100" placeholder="maths"></div><div class="frow"><label>Means the same as</label><input type="text" name="canonical" maxlength="150" placeholder="mathematics"></div>
            <div class="frow full"><label>…or add many (one per line: <code>word = meaning</code>)</label><textarea name="bulk" style="min-height:90px;" placeholder="kcpe = kenya certificate of primary education&#10;kcse = kenya certificate of secondary education"></textarea></div></div>
            <div class="form-actions"><button class="btn-classic success" type="submit">＋ ADD</button></div></form>
        <div><div class="section-title" style="margin-top:0;">TEST A SEARCH</div>
            <form method="get" class="pd-form flex"><input type="text" name="test" value="<?= e($test) ?>" placeholder="grade 7 maths schemes" style="flex:1; min-width:200px;"><button class="btn-classic primary" type="submit">TEST</button></form>
            <?php if ($groups) { echo '<div class="result-area" style="margin-top:10px;"><strong>Understood as (every group must match):</strong>'; foreach ($groups as $g) { echo '<div class="result-row">' . e(implode('  OR  ', $g)) . '</div>'; } echo '</div>'; } elseif ($test !== '') { echo '<div class="alert alert-info" style="margin-top:10px;">Only common words — nothing to search for.</div>'; } ?></div>
    </div>
    <div class="section-title">ALL SYNONYMS (<?= count($rows) ?>)</div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Word</th><th>=</th><th>Meaning</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($rows as $r) { echo '<tr><td class="doc-title">' . e($r['term']) . '</td><td>=</td><td>' . e($r['canonical']) . '</td><td><form method="post" style="display:inline;">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn-classic btn-sm danger" name="do" value="delete" data-confirm="Remove this synonym?">🗑</button></form></td></tr>'; }
    if (!$rows) { echo '<tr><td colspan="4" class="empty-cell">No synonyms yet.</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
