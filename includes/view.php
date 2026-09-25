<?php
/**
 * PATADOCS — HTML partials shared by pages and AJAX (they all reuse the design's classes).
 */

/** Friendly error page in the site design (404, 403, 419...). */
function abort_page(int $code, string $title, string $message, array $links = []): void
{
    if (wants_json()) { json_out(['ok' => false, 'code' => $code, 'message' => $message], $code); }
    if (!headers_sent()) { http_response_code($code); }
    $meta = ['title' => $title, 'robots' => 'noindex,nofollow', 'nav' => ''];
    include __DIR__ . '/header.php';
    $links = $links ?: [['🏠 GO HOME', url('')], ['🔍 SEARCH DOCUMENTS', page_url('search')]];
    ?>
    <section class="page-section active">
      <div class="panel">
        <div class="panel-header <?= $code >= 500 ? '' : 'orange' ?>">ERROR <?= (int)$code ?> — <?= e(strtoupper($title)) ?></div>
        <div class="panel-body" style="text-align:center; padding:36px 12px;">
          <div style="font-size:3rem; margin-bottom:10px;"><?= $code === 404 ? '🔍' : '⚠️' ?></div>
          <p style="font-size:1.15rem; font-family:Tahoma, sans-serif; max-width:640px; margin:0 auto 20px;"><?= e($message) ?></p>
          <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
            <?php foreach ($links as $l) { echo '<a class="btn-classic primary" href="' . e($l[1]) . '">' . e($l[0]) . '</a>'; } ?>
          </div>
        </div>
      </div>
    </section>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

// ---- SEO --------------------------------------------------------------------------

/** Robots directive for a page: defaults to indexable with the richest snippet/preview permissions. */
function seo_robots(array $m): string
{
    $r = strtolower(str_replace(' ', '', (string)($m['robots'] ?? 'index,follow')));
    if (strpos($r, 'noindex') === false && strpos($r, 'max-') === false) { $r .= ',max-snippet:-1,max-image-preview:large,max-video-preview:-1'; }
    return $r;
}

/**
 * <title>, description, canonical, robots, prev/next, Open Graph, Twitter and JSON-LD for the page.
 * $m keys: title, description, canonical, robots, keywords, og_type, og_image, og_image_alt,
 * published, modified (dates), prev, next (pagination URLs), schema[].
 */
function seo_head(array $m): string
{
    $site = setting('site_name', 'PATADOCS');
    $title = trim(preg_replace('/\s+/u', ' ', (string)($m['title'] ?? '')));
    $full = $title === '' ? setting('seo_default_title') : (stripos($title, $site) !== false ? $title : $title . setting('seo_title_suffix', ' | ' . $site));
    $desc = excerpt(trim(preg_replace('/\s+/u', ' ', (string)($m['description'] ?? ''))) !== '' ? (string)$m['description'] : setting('seo_default_description'), 160);
    $canon = (string)($m['canonical'] ?? '');
    $img = (string)($m['og_image'] ?? '') ?: (string)setting('og_image');
    if ($img === '') { $img = asset('images/og-default.png'); } elseif (!preg_match('#^https?://#i', $img)) { $img = url($img); }
    $ogTitle = $title !== '' ? $title : setting('seo_default_title');
    $h = '<title>' . e($full) . "</title>\n";
    $h .= '<meta name="description" content="' . e($desc) . "\">\n";
    if (!empty($m['keywords'])) { $h .= '<meta name="keywords" content="' . e($m['keywords']) . "\">\n"; }
    $h .= '<meta name="robots" content="' . e(seo_robots($m)) . "\">\n";
    if ($canon !== '' && setting('canonical_urls', '1') === '1') { $h .= '<link rel="canonical" href="' . e($canon) . "\">\n"; }
    if (!empty($m['prev'])) { $h .= '<link rel="prev" href="' . e($m['prev']) . "\">\n"; }
    if (!empty($m['next'])) { $h .= '<link rel="next" href="' . e($m['next']) . "\">\n"; }
    $h .= '<meta property="og:site_name" content="' . e($site) . "\">\n";
    $h .= '<meta property="og:type" content="' . e($m['og_type'] ?? 'website') . "\">\n";
    $h .= '<meta property="og:title" content="' . e($ogTitle) . "\">\n";
    $h .= '<meta property="og:description" content="' . e($desc) . "\">\n";
    if ($canon !== '') { $h .= '<meta property="og:url" content="' . e($canon) . "\">\n"; }
    $h .= '<meta property="og:image" content="' . e($img) . "\">\n";
    $h .= '<meta property="og:image:alt" content="' . e($m['og_image_alt'] ?? $ogTitle) . "\">\n<meta property=\"og:locale\" content=\"en_KE\">\n";
    if (($m['og_type'] ?? '') === 'article') {
        if (!empty($m['published'])) { $h .= '<meta property="article:published_time" content="' . e(date('c', strtotime($m['published']))) . "\">\n"; }
        if (!empty($m['modified'])) { $h .= '<meta property="article:modified_time" content="' . e(date('c', strtotime($m['modified']))) . "\">\n"; }
    }
    $h .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    $h .= '<meta name="twitter:title" content="' . e($ogTitle) . "\">\n";
    $h .= '<meta name="twitter:description" content="' . e($desc) . "\">\n";
    $h .= '<meta name="twitter:image" content="' . e($img) . "\">\n";
    foreach (($m['schema'] ?? []) as $s) {
        $h .= '<script type="application/ld+json">' . json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
    }
    return $h;
}

/** rel=prev / rel=next URLs for a paginate() result, matching pager_html()'s links. */
function seo_pagination(array $pg, string $base, array $params = []): array
{
    $u = function ($n) use ($base, $params) {
        $q = $params; if ($n > 1) { $q['page'] = $n; } else { unset($q['page']); }
        return rtrim($base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($q), '?&');
    };
    return ['prev' => $pg['page'] > 1 ? $u($pg['page'] - 1) : '', 'next' => $pg['page'] < $pg['pages'] ? $u($pg['page'] + 1) : ''];
}

/** robots.txt body for this installation (served by robots.php, shown in Admin → SEO audit). */
function robots_txt(): string
{
    $root = rtrim((string)parse_url(url(''), PHP_URL_PATH), '/');
    $lines = ['User-agent: *'];
    foreach (['admin/', 'ajax/', 'includes/', 'private_documents/', 'uploads/temporary/', 'install.php'] as $p) { $lines[] = 'Disallow: ' . $root . '/' . $p; }
    // Internal search with a query/filters: endless URL combinations, all noindex — don't waste crawl budget on them
    $lines[] = 'Disallow: ' . $root . '/search?';
    $lines[] = 'Disallow: ' . $root . '/search.php?';
    $lines[] = 'Allow: ' . $root . '/uploads/previews/';
    $lines[] = '';
    if (setting('sitemap_enabled', '1') === '1') { $lines[] = 'Sitemap: ' . url('sitemap.xml'); }
    return implode("\n", $lines) . "\n";
}

/** The site as a schema.org Organization (used as brand / seller / publisher). */
function org_schema(): array
{
    $o = ['@type' => 'Organization', 'name' => setting('site_name', 'PATADOCS'), 'url' => url('')];
    if (setting('site_logo')) { $o['logo'] = url(setting('site_logo')); }
    return $o;
}

/**
 * schema.org Product + Offer for something sold on the site (a document or a bundle), so search results can show
 * price and — once there are approved reviews — star ratings. $x: name, description, url, sku, images[], category,
 * price (float), free (bool), props [name => value], rating (['count','avg']), reviews (approved review rows).
 */
function product_schema(array $x): array
{
    $offer = ['@type' => 'Offer', 'url' => $x['url'], 'price' => number_format($x['free'] ? 0 : (float)$x['price'], 2, '.', ''),
        'priceCurrency' => setting('currency', 'KES'), 'availability' => 'https://schema.org/InStock',
        'priceValidUntil' => date('Y-12-31', strtotime('+1 year')), 'seller' => org_schema()];
    $p = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $x['name'], 'description' => excerpt((string)$x['description'], 5000),
        'url' => $x['url'], 'sku' => $x['sku'], 'brand' => ['@type' => 'Brand', 'name' => setting('site_name', 'PATADOCS')], 'offers' => $offer];
    $p['image'] = !empty($x['images']) ? array_values($x['images']) : [asset('images/og-default.png')];
    if (!empty($x['category'])) { $p['category'] = $x['category']; }
    foreach (($x['props'] ?? []) as $k => $v) { if ((string)$v !== '') { $p['additionalProperty'][] = ['@type' => 'PropertyValue', 'name' => $k, 'value' => (string)$v]; } }
    if (!empty($x['rating']['count'])) {
        $p['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float)$x['rating']['avg'], 1), 'reviewCount' => (int)$x['rating']['count'], 'bestRating' => 5, 'worstRating' => 1];
        foreach (array_slice($x['reviews'] ?? [], 0, 5) as $r) {
            $p['review'][] = ['@type' => 'Review', 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int)$r['rating'], 'bestRating' => 5, 'worstRating' => 1],
                'author' => ['@type' => 'Person', 'name' => $r['name']], 'datePublished' => date('Y-m-d', strtotime($r['created_at'])), 'reviewBody' => (string)$r['comment']];
        }
    }
    return $p;
}

function breadcrumb_html(array $crumbs): string
{
    $h = '<nav class="breadcrumb" aria-label="Breadcrumb">';
    foreach ($crumbs as $i => $c) {
        if ($i) { $h .= '<span class="sep">›</span>'; }
        $h .= !empty($c[1]) ? '<a href="' . e($c[1]) . '">' . e($c[0]) . '</a>' : '<span>' . e($c[0]) . '</span>';
    }
    return $h . '</nav>';
}
function breadcrumb_schema(array $crumbs): array
{
    $items = [];
    foreach ($crumbs as $i => $c) {
        $it = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0]];
        if (!empty($c[1])) { $it['item'] = $c[1]; }
        $items[] = $it;
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

// ---- Small UI pieces -------------------------------------------------------------

function alert_html(string $type, string $msg): string { return '<div class="alert alert-' . e($type) . '">' . e($msg) . '</div>'; }

/** Coloured status pill used in admin tables. */
function status_badge(string $s): string
{
    static $map = [
        'published' => 'green', 'paid' => 'green', 'active' => 'green', 'success' => 'green', 'approved' => 'green', 'resolved' => 'green',
        'found' => 'green', 'created' => 'green', 'ready' => 'green', 'verified' => 'green',
        'pending' => 'orange', 'pending_review' => 'orange', 'under_review' => 'orange', 'new' => 'orange', 'reviewing' => 'orange', 'initiated' => 'orange', 'received' => 'orange',
        'draft' => 'gray', 'archived' => 'gray', 'none' => 'gray', 'read' => 'gray', 'hidden' => 'gray', 'dismissed' => 'gray', 'expired' => 'gray',
        'failed' => 'red', 'rejected' => 'red', 'revoked' => 'red', 'refunded' => 'red', 'cancelled' => 'red', 'timeout' => 'red', 'disabled' => 'red',
    ];
    return '<span class="badge b-' . ($map[$s] ?? 'blue') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}

/** The design's search component (works with JS live suggestions; degrades to a normal GET form). */
function search_hero_html(string $id, string $placeholder, string $value = '', string $style = '', string $action = ''): string
{
    $action = $action ?: page_url('search');
    return '<form class="search-form" action="' . e($action) . '" method="get" role="search">'
        . '<div class="search-hero" data-search-id="' . e($id) . '"' . ($style ? ' style="' . e($style) . '"' : '') . '>'
        . '<div class="search-wrap"><div class="search-icon-big">🔍</div>'
        . '<input type="text" class="search-input" name="q" value="' . e($value) . '" placeholder="' . e($placeholder) . '" autocomplete="off" spellcheck="false" aria-label="Search documents">'
        . '<button type="button" class="search-clear-btn' . ($value !== '' ? ' visible' : '') . '" title="Clear">✕</button>'
        . '<button type="submit" class="search-go-btn"><span class="go-text">SEARCH</span><span>🔍</span></button></div>'
        . '<div class="suggestions-box"></div></div></form>';
}

function price_label(array $d): string { return !empty($d['is_free']) ? 'FREE' : money($d['price']); }

// ---- Document table (columns exactly as in the design's "Browse Documents") -------

function doc_rows_html(array $docs): string
{
    $h = '';
    foreach ($docs as $d) {
        $u = doc_url($d);
        $h .= '<tr><td class="doc-title"><a href="' . e($u) . '">' . e($d['title']) . '</a></td>'
            . '<td>' . e($d['category_name'] ?? '') . '</td>'
            . '<td><span class="doc-format">' . e(doc_ext_label((string)$d['file_ext'])) . '</span></td>'
            . '<td>' . ($d['pages'] ? (int)$d['pages'] : '—') . '</td>'
            . '<td class="doc-price">' . e(price_label($d)) . '</td>'
            . '<td><a class="btn-classic primary btn-sm" href="' . e($u) . '">VIEW</a></td></tr>';
    }
    return $h;
}
function doc_table_html(array $docs, string $emptyMsg = 'No documents found.'): string
{
    return '<div class="gv-wrap"><table class="gv-table" id="documentsGrid"><thead><tr><th>Document Title</th><th>Category</th><th>Format</th><th>Pages</th><th>Price</th><th>Action</th></tr></thead><tbody id="documentsGridBody">'
        . ($docs ? doc_rows_html($docs) : '<tr><td colspan="6" class="empty-cell">' . e($emptyMsg) . '</td></tr>')
        . '</tbody></table></div>';
}

function cat_tiles_html(array $cats, string $gridClass = 'grid-5'): string
{
    $counts = cat_counts();
    $h = '<div class="' . e($gridClass) . '">';
    foreach ($cats as $c) {
        $h .= '<a class="cat-tile" href="' . e(cat_url($c)) . '"><span class="cat-icon">' . e($c['icon'] ?: '📁') . '</span>'
            . '<div class="cat-name">' . e($c['name']) . '</div><div class="cat-count">' . num($counts[(int)$c['id']] ?? 0) . ' Documents</div></a>';
    }
    return $h . '</div>';
}

function share_links_html(string $url, string $title): string
{
    $u = rawurlencode($url); $t = rawurlencode($title);
    return '<div class="share-row"><span class="share-label">SHARE:</span>'
        . '<a class="btn-classic btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=' . $t . '%20' . $u . '">WhatsApp</a>'
        . '<a class="btn-classic btn-sm" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '">Facebook</a>'
        . '<a class="btn-classic btn-sm" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u . '">X</a>'
        . '<a class="btn-classic btn-sm" href="mailto:?subject=' . $t . '&body=' . $u . '">Email</a>'
        . '<button type="button" class="btn-classic btn-sm" data-copy="' . e($url) . '">Copy link</button></div>';
}

// ---- Search results zone (used by search.php AND ajax/search.php) -----------------

function search_filters_html(array $o, array $facetFields): string
{
    $sel = function ($name, $label, $opts, $cur, $attr = '') {
        $h = '<div class="frow"><label>' . e($label) . '</label><select name="' . e($name) . '" data-filter ' . $attr . '>';
        foreach ($opts as $v => $t) { $h .= '<option value="' . e($v) . '"' . ((string)$v === (string)$cur ? ' selected' : '') . '>' . e($t) . '</option>'; }
        return $h . '</select></div>';
    };
    $h = '<div class="result-area filters" id="filterBox"><div class="filters-title">FILTERS</div><div class="filter-grid">';
    $h .= '<div class="frow"><label>Category</label><select name="cat" data-filter>' . cat_options_html((int)($o['cat'] ?? 0), 0, true, 'All categories') . '</select></div>';
    foreach ($facetFields as $f) {
        $opts = ['' => 'All'];
        foreach ($f['values'] as $v => $n) { $opts[(string)$v] = $v . ' (' . $n . ')'; }
        $h .= $sel('f[' . $f['key'] . ']', $f['label'], $opts, $o['filters'][$f['key']] ?? '');
    }
    $h .= $sel('format', 'Format', ['' => 'All formats', 'pdf' => 'PDF', 'word' => 'Word', 'image' => 'Image'], $o['format'] ?? '');
    $h .= $sel('price', 'Free / Paid', ['' => 'Free & paid', 'free' => 'Free only', 'paid' => 'Paid only'], $o['price'] ?? '');
    $h .= '<div class="frow"><label>Price range (KSh)</label><div class="range-row"><input type="number" name="min" min="0" placeholder="Min" value="' . e($o['min'] ?? '') . '" data-filter><input type="number" name="max" min="0" placeholder="Max" value="' . e($o['max'] ?? '') . '" data-filter></div></div>';
    $h .= $sel('sort', 'Sort by', ['' => 'Best match', 'new' => 'Newest', 'popular' => 'Most popular', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'title' => 'Title A–Z'], $o['sort'] ?? '');
    $h .= '</div><div class="filter-actions"><button type="button" class="btn-classic btn-sm" id="filterReset">✕ RESET FILTERS</button></div></div>';
    return $h;
}

/**
 * The whole dynamic area of the browse page: filters + result count + table + pager.
 * $o = search options, $res = search_documents() result.
 */
function search_zone_html(array $o, array $res): string
{
    $q = trim((string)($o['q'] ?? ''));
    $facetCat = (int)($o['cat'] ?? 0);
    if (!$facetCat && $res['total'] > 0) { $facetCat = search_dominant_category($res['build']); }
    $h = search_filters_html($o, search_facet_fields($facetCat));
    $total = $res['total'];
    if ($q === '' && $total === 0) { $msg = 'No documents match these filters.'; }
    elseif ($total === 0) { $msg = 'No documents found for "' . $q . '".'; }
    else { $msg = ''; }
    $h .= '<div class="result-count">';
    if ($total > 0) {
        $h .= '<strong>' . num($total) . '</strong> document' . ($total === 1 ? '' : 's') . ' found' . ($q !== '' ? ' for "<em>' . e($q) . '</em>"' : '');
        if ($res['relaxed']) { $h .= ' <span class="relaxed-note">— no exact match, showing the closest results</span>'; }
    }
    $h .= '</div>';
    $empty = $msg;
    $h .= doc_table_html($res['rows'], $empty ?: 'No documents found.');
    if ($total === 0) {
        $h .= '<div class="alert alert-info" style="margin-top:14px;">Can\'t find it? <a href="' . e(page_url('request-document', 'q=' . rawurlencode($q))) . '"><strong>Request this document</strong></a> and we will source it for you.</div>';
    }
    $params = [];
    foreach (['q', 'cat', 'format', 'price', 'min', 'max', 'sort', 'tag'] as $k) { if (isset($o[$k]) && $o[$k] !== '' && $o[$k] !== 0) { $params[$k] = $o[$k]; } }
    foreach (($o['filters'] ?? []) as $k => $v) { if ($v !== '') { $params['f'][$k] = $v; } }
    $h .= pager_html($res['pg'], page_url('search'), $params);
    return $h;
}

/** Reads search options from a query-string array (GET). */
function search_options_from(array $src): array
{
    $filters = [];
    if (!empty($src['f']) && is_array($src['f'])) {
        foreach ($src['f'] as $k => $v) { if (is_scalar($v) && preg_match('/^[a-z0-9_]{1,60}$/', (string)$k) && $v !== '') { $filters[(string)$k] = mb_substr((string)$v, 0, 120); } }
    }
    $fmt = input_str($src, 'format', 10); $price = input_str($src, 'price', 5);
    return [
        'q' => input_str($src, 'q', 150), 'cat' => input_int($src, 'cat', 0),
        'format' => in_array($fmt, ['pdf', 'word', 'image'], true) ? $fmt : '',
        'price' => in_array($price, ['free', 'paid'], true) ? $price : '',
        'min' => is_numeric($src['min'] ?? '') ? (string)(0 + $src['min']) : '', 'max' => is_numeric($src['max'] ?? '') ? (string)(0 + $src['max']) : '',
        'sort' => in_array(input_str($src, 'sort', 12), ['new', 'popular', 'price_asc', 'price_desc', 'title'], true) ? input_str($src, 'sort', 12) : '',
        'tag' => preg_match('/^[a-z0-9\-]{1,100}$/', input_str($src, 'tag', 100)) ? input_str($src, 'tag', 100) : '',
        'filters' => $filters, 'page' => max(1, input_int($src, 'page', 1)), 'per' => 15,
    ];
}

// ---- Dynamic metadata inputs (contribute form + admin document form) -----------------

/** Renders inputs for metadata fields. $values = [fieldId => raw stored value]. */
function meta_fields_html(array $fields, array $values = []): string
{
    $h = '';
    foreach ($fields as $f) {
        $id = (int)$f['id']; $name = 'meta[' . $id . ']'; $val = (string)($values[$id] ?? '');
        $req = (int)$f['is_required'] ? ' <span class="req">*</span>' : '';
        $opts = meta_options($f); $cls = 'frow'; $label = '<label for="meta' . $id . '">' . e($f['label']) . $req . '</label>';
        switch ($f['field_type']) {
            case 'textarea':
                $in = '<textarea id="meta' . $id . '" name="' . $name . '">' . e($val) . '</textarea>'; $cls .= ' full'; break;
            case 'number':
                $in = '<input type="number" step="any" id="meta' . $id . '" name="' . $name . '" value="' . e($val) . '">'; break;
            case 'year':
                $in = '<input type="number" min="1900" max="2100" id="meta' . $id . '" name="' . $name . '" value="' . e($val) . '" placeholder="' . date('Y') . '">'; break;
            case 'dropdown':
                $in = '<select id="meta' . $id . '" name="' . $name . '"><option value="">— Select —</option>';
                foreach ($opts as $o) { $in .= '<option value="' . e($o) . '"' . ($o === $val ? ' selected' : '') . '>' . e($o) . '</option>'; }
                $in .= '</select>'; break;
            case 'radio':
                $in = '<div class="chk-group">';
                foreach ($opts as $o) { $in .= '<label class="chk"><input type="radio" name="' . $name . '" value="' . e($o) . '"' . ($o === $val ? ' checked' : '') . '><span>' . e($o) . '</span></label>'; }
                $in .= '</div>'; $label = '<span class="flabel">' . e($f['label']) . $req . '</span>'; break;
            case 'checkbox':
                $in = '<div class="chk-group">';
                foreach ($opts as $o) { $in .= '<label class="chk"><input type="checkbox" name="' . $name . '[]" value="' . e($o) . '"' . (strpos($val, '|' . $o . '|') !== false ? ' checked' : '') . '><span>' . e($o) . '</span></label>'; }
                $in .= '</div>'; $label = '<span class="flabel">' . e($f['label']) . $req . '</span>'; break;
            default:
                $in = '<input type="text" id="meta' . $id . '" name="' . $name . '" maxlength="500" value="' . e($val) . '">';
        }
        $h .= '<div class="' . $cls . '">' . $label . $in . '</div>';
    }
    return $h === '' ? '' : '<div class="section-title" style="margin-top:6px;">DETAILS</div><div class="form-grid">' . $h . '</div>';
}
