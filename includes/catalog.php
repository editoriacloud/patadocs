<?php
/**
 * PATADOCS — catalog helpers: documents, dynamic metadata, tags, search,
 * related documents, homepage data and simple stats.
 */

// ============================================================================
// DOCUMENT BASICS
// ============================================================================

const PD_ALL_EXTS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];

function doc_ext_label(string $ext): string
{
    $map = ['pdf' => 'PDF', 'doc' => 'Word', 'docx' => 'Word', 'jpg' => 'JPG', 'jpeg' => 'JPG', 'png' => 'PNG', 'webp' => 'WEBP'];
    return $map[strtolower($ext)] ?? strtoupper($ext);
}
/** Emoji icons exactly like the design's getDocIcon(). */
function doc_icon(string $ext): string
{
    $e = strtolower($ext);
    if ($e === 'pdf') { return '📕'; }
    if ($e === 'doc' || $e === 'docx') { return '📘'; }
    if (in_array($e, ['jpg', 'jpeg', 'png', 'webp'], true)) { return '🖼️'; }
    return '📄';
}
function doc_mime(string $ext): string
{
    $map = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    return $map[strtolower($ext)] ?? 'application/octet-stream';
}
/** Extensions enabled in Settings (always a subset of the safe whitelist). */
function allowed_exts(): array
{
    $set = array_filter(array_map('trim', explode(',', strtolower(setting('allowed_types', implode(',', PD_ALL_EXTS))))));
    $ok = array_values(array_intersect($set, PD_ALL_EXTS));
    return $ok ?: PD_ALL_EXTS;
}

function doc_get(int $id): ?array
{
    return db_row('SELECT d.*, c.path AS category_path, c.name AS category_name FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE d.id = ?', [$id]);
}
function doc_get_by_slug(string $slug): ?array
{
    return db_row('SELECT d.*, c.path AS category_path, c.name AS category_name FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE d.slug = ?', [$slug]);
}
function doc_url(array $d): string
{
    $path = $d['category_path'] ?? null;
    if ($path === null && !empty($d['category_id'])) { $c = cat_get((int)$d['category_id']); $path = $c['path'] ?? null; }
    if (setting('clean_urls', '1') === '1') { return url(($path ? $path . '/' : '') . $d['slug']); }
    return url('document.php?slug=' . rawurlencode($d['slug']));
}
/** Absolute path of the ORIGINAL file (outside the web root when configured so). */
function doc_private_path(array $d): string { return rtrim(PRIVATE_DIR, '/\\') . '/' . basename((string)$d['file_name']); }
function doc_download_name(array $d): string { return slugify((string)$d['title'], 80) . '.' . strtolower((string)$d['file_ext']); }
function doc_preview_urls(array $d): array
{
    $out = [];
    if (($d['preview_status'] ?? '') === 'ready' && !empty($d['preview_dir'])) {
        for ($i = 1; $i <= (int)$d['preview_pages']; $i++) { $out[] = url('uploads/previews/' . rawurlencode($d['preview_dir']) . '/p' . $i . '.jpg'); }
    }
    return $out;
}

// ============================================================================
// DYNAMIC METADATA
// ============================================================================

function meta_options(array $f): array
{
    if (empty($f['options'])) { return []; }
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$f['options'])), 'strlen'));
}

/**
 * Fields that apply to a category: global fields + fields of every ancestor + its own.
 * When the same field_key is defined at several levels the deepest definition wins.
 */
function meta_fields_for(?int $catId, bool $activeOnly = true): array
{
    $ids = $catId ? cat_chain_ids($catId) : [];
    $sql = 'SELECT f.* FROM metadata_fields f LEFT JOIN categories c ON c.id = f.category_id WHERE (f.category_id IS NULL'
        . ($ids ? ' OR f.category_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . ')'
        . ($activeOnly ? " AND f.status = 'active'" : '')
        . ' ORDER BY COALESCE(c.depth, -1), f.sort_order, f.id';
    $byKey = [];
    foreach (db_all($sql) as $f) { $byKey[$f['field_key']] = $f; }
    return array_values($byKey);
}

/** [field_id => raw value] for a document. */
function meta_values(int $docId): array
{
    $out = [];
    foreach (db_all('SELECT field_id, meta_value FROM document_meta WHERE document_id = ?', [$docId]) as $r) { $out[(int)$r['field_id']] = $r['meta_value']; }
    return $out;
}
function meta_pretty(array $f, string $raw): string
{
    if ($f['field_type'] === 'checkbox') { return implode(', ', array_filter(explode('|', $raw), 'strlen')); }
    return $raw;
}
/** Display list for the public document page. */
function meta_display(int $docId): array
{
    $rows = db_all('SELECT f.*, m.meta_value FROM document_meta m JOIN metadata_fields f ON f.id = m.field_id WHERE m.document_id = ? AND f.status = \'active\' ORDER BY f.sort_order, f.id', [$docId]);
    $out = [];
    foreach ($rows as $r) { $v = meta_pretty($r, (string)$r['meta_value']); if ($v !== '') { $out[] = ['label' => $r['label'], 'value' => $v, 'key' => $r['field_key']]; } }
    return $out;
}

/**
 * Cleans posted metadata (meta[fieldId] = value|array) for the fields that apply to a category.
 * Returns [rows => [[fieldId, value], ...], errors => [...]]. Nothing is written to the database here.
 */
function meta_sanitize(?int $catId, array $input, bool $enforceRequired = false): array
{
    $errors = []; $rows = [];
    foreach (meta_fields_for($catId) as $f) {
        $raw = $input[$f['id']] ?? ($input[(string)$f['id']] ?? '');
        $opts = meta_options($f);
        switch ($f['field_type']) {
            case 'checkbox':
                $picked = array_values(array_filter((array)$raw, function ($x) use ($opts) { return in_array($x, $opts, true); }));
                $val = $picked ? '|' . implode('|', $picked) . '|' : '';
                break;
            case 'dropdown': case 'radio':
                $val = (!is_array($raw) && in_array($raw, $opts, true)) ? (string)$raw : '';
                break;
            case 'number':
                $val = (!is_array($raw) && is_numeric($raw)) ? (string)(0 + $raw) : '';
                break;
            case 'year':
                $val = (!is_array($raw) && preg_match('/^(19|20|21)\d{2}$/', trim((string)$raw))) ? trim((string)$raw) : '';
                break;
            default:
                $val = is_array($raw) ? '' : mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$raw)), 0, 2000);
        }
        if ($val === '' && (int)$f['is_required'] && $enforceRequired) { $errors[] = $f['label'] . ' is required.'; }
        if ($val !== '') { $rows[] = [(int)$f['id'], $val]; }
    }
    return ['rows' => $rows, 'errors' => $errors];
}

/** Validates + stores metadata for a document. Returns error messages (empty = saved). */
function meta_save(int $docId, ?int $catId, array $input, bool $enforceRequired = false): array
{
    $r = meta_sanitize($catId, $input, $enforceRequired);
    if ($r['errors']) { return $r['errors']; }
    db_exec('DELETE FROM document_meta WHERE document_id = ?', [$docId]);
    foreach ($r['rows'] as $row) { db_exec('INSERT INTO document_meta (document_id, field_id, meta_value) VALUES (?, ?, ?)', [$docId, $row[0], $row[1]]); }
    return [];
}

// ============================================================================
// TAGS
// ============================================================================

function tags_parse(string $csv): array
{
    $out = [];
    foreach (preg_split('/[,\n;]+/u', $csv) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
        if ($t !== '' && mb_strlen($t) <= 60) { $out[mb_strtolower($t)] = $t; }
        if (count($out) >= 15) { break; }
    }
    return array_values($out);
}
function tags_get(int $docId): array
{
    return db_all('SELECT t.id, t.name, t.slug FROM tags t JOIN document_tags dt ON dt.tag_id = t.id WHERE dt.document_id = ? ORDER BY t.name', [$docId]);
}
function tags_save(int $docId, string $csv): void
{
    db_exec('DELETE FROM document_tags WHERE document_id = ?', [$docId]);
    foreach (tags_parse($csv) as $name) {
        $slug = slugify($name, 90);
        $id = db_val('SELECT id FROM tags WHERE slug = ?', [$slug]);
        if (!$id) { $id = db_insert('INSERT INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]); }
        db_exec('INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)', [$docId, $id]);
    }
}

/** Denormalised text used by the search (title + categories + tags + searchable metadata + description). */
function doc_rebuild_search(int $docId): void
{
    $d = doc_get($docId);
    if (!$d) { return; }
    $parts = [$d['title'], $d['doc_type'], $d['seo_keywords'], $d['author']];
    foreach (cat_breadcrumb($d['category_id'] ? (int)$d['category_id'] : null) as $c) { $parts[] = $c['name']; }
    foreach (tags_get($docId) as $t) { $parts[] = $t['name']; }
    foreach (db_all('SELECT m.meta_value FROM document_meta m JOIN metadata_fields f ON f.id = m.field_id WHERE m.document_id = ? AND f.is_searchable = 1', [$docId]) as $r) { $parts[] = str_replace('|', ' ', $r['meta_value']); }
    $parts[] = mb_substr(strip_tags((string)$d['description']), 0, 600);
    $text = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, function ($x) { return $x !== null && (string)$x !== ''; }))));
    db_exec('UPDATE documents SET search_text = ? WHERE id = ?', [$text, $docId]);
}
function docs_rebuild_all(): int
{
    $n = 0;
    foreach (db_all('SELECT id FROM documents') as $r) { doc_rebuild_search((int)$r['id']); $n++; }
    return $n;
}

// ============================================================================
// SEARCH
// ============================================================================

function like_escape(string $s): string { return addcslashes($s, '%_\\'); }

function search_norm(string $q): string
{
    $q = mb_strtolower(trim($q), 'UTF-8');
    $q = preg_replace('/[^\p{L}\p{N}\s\-\/&\'.]+/u', ' ', $q);
    return trim(preg_replace('/\s+/u', ' ', $q));
}

/** Two-way synonym map: word/phrase => [equivalents]. Managed in Admin → Search Synonyms. */
function syn_map(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = [];
    try {
        foreach (db_all('SELECT term, canonical FROM search_synonyms') as $r) {
            $t = mb_strtolower(trim($r['term'])); $c = mb_strtolower(trim($r['canonical']));
            if ($t !== '' && $c !== '') { $m[$t][$c] = true; $m[$c][$t] = true; }
        }
    } catch (Throwable $e) { /* table not there yet */ }
    foreach ($m as $k => $v) { $m[$k] = array_keys($v); }
    return $m;
}

/**
 * Turns a query into token groups. Every group must match (AND); inside a group any alternative
 * (the word itself, its synonyms, a singular form, "grade7" → "grade 7") may match (OR).
 */
function search_groups(string $q): array
{
    $words = ($n = search_norm($q)) === '' ? [] : explode(' ', $n);
    $syn = syn_map();
    $stop = ['the', 'a', 'an', 'of', 'for', 'and', 'in', 'on', 'to', 'with', 'from', 'at', 'by', 'or', 'pdf', 'docx', 'doc', 'download', 'free', 'kenya', 'kenyan', 'document', 'documents'];
    $groups = []; $i = 0; $cnt = count($words);
    while ($i < $cnt) {
        $matched = false;
        for ($len = min(4, $cnt - $i); $len >= 1; $len--) {
            $phrase = implode(' ', array_slice($words, $i, $len));
            if (isset($syn[$phrase])) {
                $groups[] = array_values(array_unique(array_merge([$phrase], $syn[$phrase])));
                $i += $len; $matched = true; break;
            }
        }
        if ($matched) { continue; }
        $w = $words[$i++];
        if (in_array($w, $stop, true)) { continue; }
        $alts = [$w];
        if (mb_strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') { $alts[] = substr($w, 0, -1); }
        if (preg_match('/^([a-z]+)(\d+)$/', $w, $mm)) { $alts[] = $mm[1] . ' ' . $mm[2]; }
        $groups[] = array_values(array_unique($alts));
    }
    if (!$groups && $words) { foreach ($words as $w) { $groups[] = [$w]; } }
    return array_slice($groups, 0, 8);
}

/** Builds WHERE / params / relevance score for search_documents(). */
function search_build(array $o, bool $relaxed = false): array
{
    $where = ["d.status = 'published'"]; $params = [];
    if (!empty($o['cat'])) {
        $ids = cat_descendant_ids((int)$o['cat']);
        $where[] = $ids ? 'd.category_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '0 = 1';
    }
    if (!empty($o['format'])) {
        $map = ['pdf' => ['pdf'], 'word' => ['doc', 'docx'], 'image' => ['jpg', 'jpeg', 'png', 'webp']];
        $f = strtolower((string)$o['format']);
        if (isset($map[$f])) { $where[] = 'd.file_ext IN (' . db_in($map[$f]) . ')'; $params = array_merge($params, $map[$f]); }
    }
    $price = $o['price'] ?? '';
    if ($price === 'free') { $where[] = 'd.is_free = 1'; } elseif ($price === 'paid') { $where[] = 'd.is_free = 0'; }
    if (isset($o['min']) && $o['min'] !== '' && is_numeric($o['min'])) { $where[] = 'd.is_free = 0 AND d.price >= ?'; $params[] = (float)$o['min']; }
    if (isset($o['max']) && $o['max'] !== '' && is_numeric($o['max'])) { $where[] = 'd.is_free = 0 AND d.price <= ?'; $params[] = (float)$o['max']; }
    if (!empty($o['tag'])) {
        $where[] = 'EXISTS (SELECT 1 FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dt.document_id = d.id AND t.slug = ?)';
        $params[] = (string)$o['tag'];
    }
    foreach (($o['filters'] ?? []) as $key => $val) {
        if ($val === '' || !is_scalar($val) || !preg_match('/^[a-z0-9_]{1,60}$/', (string)$key)) { continue; }
        $where[] = 'EXISTS (SELECT 1 FROM document_meta m JOIN metadata_fields f ON f.id = m.field_id WHERE m.document_id = d.id AND f.field_key = ? AND (m.meta_value = ? OR m.meta_value LIKE ?))';
        array_push($params, (string)$key, (string)$val, '%|' . like_escape((string)$val) . '|%');
    }
    // Text groups
    $groups = $o['_groups'] ?? []; $score = '0'; $sp = [];
    if ($groups) {
        $conds = [];
        foreach ($groups as $g) {
            $alts = [];
            foreach ($g as $alt) {
                $alts[] = 'd.search_text LIKE ?'; $params[] = '%' . like_escape($alt) . '%';
            }
            $conds[] = '(' . implode(' OR ', $alts) . ')';
        }
        // NOTE: params for text conditions were appended in placeholder order above.
        if ($relaxed && count($conds) >= 2) { $where[] = '(' . implode(' + ', $conds) . ') >= ' . (count($conds) - 1); }
        else { $where[] = implode(' AND ', $conds); }
        foreach ($groups as $g) { foreach ($g as $alt) { $score .= ' + (CASE WHEN d.title LIKE ? THEN 10 ELSE 0 END)'; $sp[] = '%' . like_escape($alt) . '%'; } }
        $full = search_norm((string)($o['q'] ?? ''));
        $score .= ' + (CASE WHEN d.title LIKE ? THEN 25 ELSE 0 END) + (CASE WHEN d.title LIKE ? THEN 15 ELSE 0 END)';
        array_push($sp, '%' . like_escape($full) . '%', like_escape($full) . '%');
        $score .= ' + d.featured * 3 + LEAST(d.view_count / 200, 5)';
    }
    return ['where' => implode(' AND ', $where), 'params' => $params, 'score' => $score, 'sp' => $sp];
}

function search_order(string $sort, bool $hasQuery): string
{
    switch ($sort) {
        case 'new':        return 'd.published_at DESC, d.id DESC';
        case 'popular':    return '(d.paid_downloads * 3 + d.free_downloads * 2 + d.view_count) DESC, d.id DESC';
        case 'price_asc':  return 'd.is_free DESC, d.price ASC, d.title ASC';
        case 'price_desc': return 'd.is_free ASC, d.price DESC, d.title ASC';
        case 'title':      return 'd.title ASC';
        default:           return $hasQuery ? 'score DESC, d.featured DESC, d.view_count DESC, d.id DESC' : 'd.featured DESC, d.published_at DESC, d.id DESC';
    }
}

/**
 * Main search. $o keys: q, cat, format(pdf|word|image), price(free|paid), min, max, tag, filters[key=>value],
 * sort, page, per. Returns rows + pagination + strict_total (exact matches) + relaxed flag.
 */
function search_documents(array $o): array
{
    $q = trim((string)($o['q'] ?? ''));
    $page = max(1, (int)($o['page'] ?? 1)); $per = max(1, min(60, (int)($o['per'] ?? 15)));
    $o['_groups'] = $q !== '' ? search_groups($q) : [];
    $b = search_build($o, false);
    $total = (int)db_val('SELECT COUNT(*) FROM documents d WHERE ' . $b['where'], $b['params']);
    $strict = $total; $relaxed = false;
    if ($total === 0 && count($o['_groups']) >= 2) {
        $b2 = search_build($o, true);
        $t2 = (int)db_val('SELECT COUNT(*) FROM documents d WHERE ' . $b2['where'], $b2['params']);
        if ($t2 > 0) { $b = $b2; $total = $t2; $relaxed = true; }
    }
    $pg = paginate($total, $page, $per);
    $sql = 'SELECT d.*, c.name AS category_name, c.path AS category_path, (' . $b['score'] . ') AS score '
         . 'FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE ' . $b['where']
         . ' ORDER BY ' . search_order((string)($o['sort'] ?? ''), $o['_groups'] !== [])
         . ' LIMIT ' . (int)$pg['offset'] . ', ' . (int)$per;
    $rows = $total ? db_all($sql, array_merge($b['sp'], $b['params'])) : [];
    return ['rows' => $rows, 'total' => $total, 'strict_total' => $strict, 'relaxed' => $relaxed, 'pg' => $pg, 'build' => $b];
}

/** The category most of the current results live in (used to pick which dynamic filters to show). */
function search_dominant_category(array $b): int
{
    $row = db_row('SELECT d.category_id, COUNT(*) n FROM documents d WHERE ' . $b['where'] . ' AND d.category_id IS NOT NULL GROUP BY d.category_id ORDER BY n DESC LIMIT 1', $b['params']);
    return $row ? (int)$row['category_id'] : 0;
}

/**
 * Filterable metadata fields for a category (own + inherited + global) with the values that
 * actually exist on published documents in scope. Nothing is hardcoded to "Education".
 */
function search_facet_fields(int $catId): array
{
    $fields = array_filter(meta_fields_for($catId ?: null), function ($f) { return (int)$f['is_filterable'] === 1; });
    $scope = [];
    if ($catId) { $chain = cat_breadcrumb($catId); $root = $chain ? (int)$chain[0]['id'] : $catId; $scope = cat_descendant_ids($root); }
    $out = [];
    foreach ($fields as $f) {
        $sql = "SELECT m.meta_value v, COUNT(*) n FROM document_meta m JOIN documents d ON d.id = m.document_id "
             . "WHERE d.status = 'published' AND m.meta_value <> '' AND m.field_id IN (SELECT id FROM metadata_fields WHERE field_key = ?)"
             . ($scope ? ' AND d.category_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '')
             . ' GROUP BY m.meta_value ORDER BY n DESC LIMIT 300';
        $vals = [];
        foreach (db_all($sql, [$f['field_key']]) as $r) {
            foreach (explode('|', trim($r['v'], '|')) as $piece) { $piece = trim($piece); if ($piece !== '') { $vals[$piece] = ($vals[$piece] ?? 0) + (int)$r['n']; } }
        }
        if (!$vals) { continue; }
        $opts = meta_options($f); $ordered = [];
        if ($opts) { foreach ($opts as $o) { if (isset($vals[$o])) { $ordered[$o] = $vals[$o]; unset($vals[$o]); } } }
        if ($f['field_type'] === 'year') { krsort($vals); } elseif ($f['field_type'] === 'number') { ksort($vals, SORT_NUMERIC); } else { ksort($vals, SORT_NATURAL | SORT_FLAG_CASE); }
        $ordered += $vals;
        $out[] = ['key' => $f['field_key'], 'label' => $f['label'], 'type' => $f['field_type'], 'values' => array_slice($ordered, 0, 80, true)];
    }
    return $out;
}

/** Logs an anonymous search; typing refinements within 20 s update the same row. Returns the log id. */
function search_log(string $q, int $results, array $filters = []): int
{
    $norm = search_norm($q);
    if ($norm === '' || mb_strlen($norm) < 2 || is_bot() || session_status() !== PHP_SESSION_ACTIVE) { return 0; }
    $last = $_SESSION['last_search'] ?? null;
    if ($last && time() - $last['t'] < 20 && ($norm === $last['norm'] || str_starts_with($norm, $last['norm']) || str_starts_with($last['norm'], $norm))) {
        if ($norm !== $last['norm']) {
            db_exec('UPDATE search_logs SET query = ?, norm_query = ?, results = ? WHERE id = ?', [mb_substr($q, 0, 190), mb_substr($norm, 0, 190), $results, $last['id']]);
        }
        $_SESSION['last_search'] = ['norm' => $norm, 't' => time(), 'id' => $last['id']];
        return (int)$last['id'];
    }
    $id = db_insert('INSERT INTO search_logs (query, norm_query, results, filters, session_hash, ip_hash) VALUES (?, ?, ?, ?, ?, ?)', [
        mb_substr($q, 0, 190), mb_substr($norm, 0, 190), $results,
        $filters ? mb_substr(json_encode($filters, JSON_UNESCAPED_UNICODE), 0, 500) : null,
        substr(hash('sha256', session_id()), 0, 16), ip_hash(client_ip()),
    ]);
    $_SESSION['last_search'] = ['norm' => $norm, 't' => time(), 'id' => $id];
    if (mt_rand(1, 500) === 1) { db_exec('DELETE FROM search_logs WHERE created_at < (NOW() - INTERVAL 400 DAY)'); }
    return $id;
}

// ============================================================================
// RELATED DOCUMENTS / COLLECTIONS / HOMEPAGE DATA
// ============================================================================

/** Same category → siblings → shared tags → similar metadata → same type. No duplicates. */
function related_docs(array $doc, int $limit = 6): array
{
    $id = (int)$doc['id']; $cat = (int)($doc['category_id'] ?? 0);
    $catIds = [];
    if ($cat && ($c = cat_get($cat))) {
        $catIds[] = $cat;
        if ($c['parent_id']) { $catIds[] = (int)$c['parent_id']; foreach (cat_children((int)$c['parent_id'], false) as $s) { $catIds[] = (int)$s['id']; } }
    }
    $tagIds = array_map('intval', array_column(db_all('SELECT tag_id FROM document_tags WHERE document_id = ?', [$id]), 'tag_id'));
    $conds = [];
    if ($catIds) { $conds[] = 'd.category_id IN (' . implode(',', array_unique($catIds)) . ')'; }
    if ($tagIds) { $conds[] = 'd.id IN (SELECT document_id FROM document_tags WHERE tag_id IN (' . implode(',', $tagIds) . '))'; }
    if (!$conds) { $conds[] = '1 = 1'; }
    $sql = "SELECT d.*, c.name AS category_name, c.path AS category_path,
              (CASE WHEN d.category_id = ? THEN 10 ELSE 0 END)
            + (SELECT COUNT(*) FROM document_tags a JOIN document_tags b ON a.tag_id = b.tag_id WHERE a.document_id = d.id AND b.document_id = ?) * 3
            + (SELECT COUNT(*) FROM document_meta m1 JOIN metadata_fields f1 ON f1.id = m1.field_id
                 JOIN document_meta m2 ON m2.document_id = ? JOIN metadata_fields f2 ON f2.id = m2.field_id
                WHERE m1.document_id = d.id AND f1.field_key = f2.field_key AND m1.meta_value = m2.meta_value AND m1.meta_value <> '') * 2
            + (CASE WHEN d.doc_type IS NOT NULL AND d.doc_type <> '' AND d.doc_type = ? THEN 2 ELSE 0 END) AS rel
            FROM documents d LEFT JOIN categories c ON c.id = d.category_id
            WHERE d.status = 'published' AND d.id <> ? AND (" . implode(' OR ', $conds) . ")
            ORDER BY rel DESC, d.view_count DESC, d.id DESC LIMIT " . (int)$limit;
    return db_all($sql, [$cat, $id, $id, (string)($doc['doc_type'] ?? ''), $id]);
}

function doc_collections(int $docId): array
{
    return db_all("SELECT c.* FROM collections c JOIN collection_documents cd ON cd.collection_id = c.id WHERE cd.document_id = ? AND c.status = 'published' ORDER BY c.featured DESC, c.title", [$docId]);
}

/** Homepage lists: popular | latest | free | premium | recent | featured. */
function home_docs(string $kind, int $limit = 8): array
{
    $cond = "d.status = 'published'"; $order = 'd.published_at DESC, d.id DESC';
    switch ($kind) {
        case 'popular':  $order = 'd.popular DESC, (d.paid_downloads * 3 + d.free_downloads * 2 + d.view_count) DESC, d.id DESC'; break;
        case 'free':     $cond .= ' AND d.is_free = 1'; break;
        case 'premium':  $cond .= ' AND d.is_free = 0'; break;
        case 'recent':   $order = 'd.updated_at DESC, d.id DESC'; break;
        case 'featured': $cond .= ' AND d.featured = 1'; $order = 'd.published_at DESC'; break;
    }
    return db_all("SELECT d.*, c.name AS category_name, c.path AS category_path FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE $cond ORDER BY $order LIMIT " . (int)$limit);
}
function featured_categories(int $limit = 10): array
{
    $out = [];
    foreach (cats_all() as $c) { if ($c['status'] === 'active' && (int)$c['featured'] === 1) { $out[] = $c; } }
    usort($out, function ($a, $b) { return [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]; });
    return array_slice($out, 0, $limit);
}
function featured_collections(int $limit = 6): array
{
    return db_all("SELECT c.*, (SELECT COUNT(*) FROM collection_documents cd WHERE cd.collection_id = c.id) AS doc_count FROM collections c WHERE c.status = 'published' ORDER BY c.featured DESC, c.sort_order, c.id DESC LIMIT " . (int)$limit);
}
function collection_url(array $c): string
{
    return setting('clean_urls', '1') === '1' ? url('collection/' . $c['slug']) : url('collection.php?slug=' . rawurlencode($c['slug']));
}
/** Admin-defined pills first, topped up with the most frequent real searches. */
function popular_searches(int $limit = 6): array
{
    $out = [];
    foreach (preg_split('/\R/u', (string)setting('home_popular_searches', '')) as $l) { $l = trim($l); if ($l !== '') { $out[mb_strtolower($l)] = $l; } }
    if (count($out) < $limit) {
        try {
            $top = cache_remember('popular_searches', 600, function () {
                return db_all("SELECT MAX(query) q, COUNT(*) n FROM search_logs WHERE created_at > (NOW() - INTERVAL 30 DAY) AND results > 0 GROUP BY norm_query HAVING n >= 2 ORDER BY n DESC LIMIT 10");
            });
            foreach ($top as $r) { $out[mb_strtolower($r['q'])] = $r['q']; }
        } catch (Throwable $e) { }
    }
    return array_slice(array_values($out), 0, $limit);
}
function site_stats(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $s = cache_remember('site_stats', 300, function () {
        $r = db_row("SELECT COUNT(*) docs, COALESCE(SUM(free_downloads + paid_downloads), 0) downloads FROM documents WHERE status = 'published'");
        return ['docs' => (int)$r['docs'], 'downloads' => (int)$r['downloads'], 'categories' => count(cat_children(null, true))];
    });
    return $s;
}

// ============================================================================
// STATS + SEO SUGGESTIONS
// ============================================================================

/** Adds 1 to today's daily counter ('views' or 'preview_views'). */
function stat_bump(int $docId, string $field): void
{
    if (!in_array($field, ['views', 'preview_views'], true)) { return; }
    db_exec("INSERT INTO document_stats_daily (stat_date, document_id, $field) VALUES (CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE $field = $field + 1", [$docId]);
}

/** Suggested SEO title / slug / description / keywords (admin can always edit). */
function seo_suggest(string $title, ?int $catId, array $metaPairs, string $docType, bool $free): array
{
    $title = trim($title);
    $crumb = array_map(function ($c) { return $c['name']; }, cat_breadcrumb($catId));
    $seoTitle = $title;
    if ($title !== '' && stripos($title, 'kenya') === false && mb_strlen($title) < 48) { $seoTitle .= ' – Kenya'; }
    $seoTitle = mb_substr($seoTitle, 0, 62);
    $bits = [];
    foreach ($metaPairs as $label => $val) { if ($val !== '') { $bits[] = $val; } }
    $desc = ($free ? 'Free download: ' : 'Preview and download: ') . $title . '.';
    $ctx = array_filter(array_merge($crumb, $bits));
    if ($ctx) { $desc .= ' ' . implode(' · ', array_slice(array_unique($ctx), 0, 5)) . '.'; }
    $desc .= $free ? ' Instant download, no account needed.' : ' Pay with M-Pesa and download instantly.';
    $kw = [];
    foreach (array_merge([$title, $docType], $crumb, $bits) as $piece) {
        $piece = mb_strtolower(trim((string)$piece));
        if ($piece !== '') { $kw[$piece] = true; }
    }
    $kw['kenya'] = true;
    return ['seo_title' => $seoTitle, 'slug' => slugify($title), 'meta_description' => mb_substr($desc, 0, 158), 'keywords' => implode(', ', array_slice(array_keys($kw), 0, 10))];
}
