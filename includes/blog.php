<?php
/**
 * PATADOCS — blog / articles engine (Admin → Blog).
 *
 *   /blog/                     → latest posts (featured post first), ?page=N, ?q= search (noindex)
 *   /blog/<slug>               → a post
 *   /blog/category/<slug>      → posts in a blog category
 *   /blog/tag/<slug>           → posts with a tag (noindex unless enabled in Blog settings — tag pages are thin)
 *   /blog/author/<username>    → an author's profile + posts
 *   /blog/feed                 → RSS 2.0
 * Without clean URLs the same routes are blog.php?route=...
 *
 * Content is written in the admin's rich editor (TinyMCE) and ALWAYS passes blog_sanitize_html() before it is
 * stored: only a safe list of tags/attributes/styles survives, whoever wrote it. Rendering (headings anchors,
 * table of contents, lazy images, document cards, in-article ad) happens at display time.
 */

// ---- URLs ---------------------------------------------------------------------------------

function blog_enabled(): bool { return setting('blog_enabled', '1') === '1'; }

function blog_url(string $route = ''): string
{
    $route = trim($route, '/');
    if (setting('clean_urls', '1') === '1') { return url('blog/' . $route); }
    return $route === '' ? url('blog.php') : url('blog.php?route=' . str_replace('%2F', '/', rawurlencode($route)));
}
function post_url(array $p): string { return blog_url((string)$p['slug']); }
function blog_cat_url(array $c): string { return blog_url('category/' . $c['slug']); }
function blog_tag_url(array $t): string { return blog_url('tag/' . $t['slug']); }
function blog_author_url(array $a): string { return blog_url('author/' . rawurlencode(strtolower((string)$a['username']))); }

/** SQL condition for posts the public may see (scheduled posts appear by themselves when their time comes). */
function blog_live_sql(string $alias = 'p'): string { return "$alias.status = 'published' AND $alias.published_at <= NOW()"; }

const BLOG_SELECT = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug, a.username AS author_username,
    COALESCE(NULLIF(a.full_name, \'\'), a.username) AS author_name FROM blog_posts p
    LEFT JOIN blog_categories c ON c.id = p.category_id LEFT JOIN admin_users a ON a.id = p.author_id';

function blog_posts(string $where, array $params, int $limit, int $offset = 0, string $order = 'p.published_at DESC, p.id DESC'): array
{
    return db_all(BLOG_SELECT . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (int)$offset . ', ' . (int)$limit, $params);
}
function blog_post_get(int $id): ?array { return db_row(BLOG_SELECT . ' WHERE p.id = ?', [$id]); }
function blog_categories(): array
{
    return cache_remember('blog_cats', 300, function () {
        return db_all('SELECT c.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id AND ' . blog_live_sql() . ') AS n FROM blog_categories c ORDER BY c.sort_order, c.name');
    });
}
function blog_post_tags(int $postId): array
{
    return db_all('SELECT t.* FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name', [$postId]);
}
function blog_tags_save(int $postId, string $csv): void
{
    db_exec('DELETE FROM blog_post_tags WHERE post_id = ?', [$postId]);
    $seen = [];
    foreach (preg_split('/[,;\n]+/', $csv) as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) { continue; }
        $slug = slugify($name, 90);
        if (isset($seen[$slug]) || count($seen) >= 15) { continue; }
        $seen[$slug] = true;
        $tid = (int)db_val('SELECT id FROM blog_tags WHERE slug = ?', [$slug]);
        if (!$tid) { $tid = db_insert('INSERT INTO blog_tags (name, slug) VALUES (?, ?)', [$name, $slug]); }
        db_exec('INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tid]);
    }
    db_exec('DELETE FROM blog_tags WHERE id NOT IN (SELECT tag_id FROM blog_post_tags)');
}

/** Can the signed-in admin edit this post? Publishers: any. Writers: their own, until it is published. */
function blog_can_edit(array $p): bool
{
    if (admin_can('blog.publish')) { return true; }
    return admin_can('blog.write') && (int)$p['author_id'] === (int)($_SESSION['admin']['id'] ?? 0) && $p['status'] !== 'published';
}

/** Old slug → current post (a renamed article keeps its links and rankings via a 301). */
function blog_slug_redirect(string $slug): ?array
{
    $id = (int)db_val('SELECT post_id FROM blog_slug_history WHERE old_slug = ?', [$slug]);
    return $id ? db_row('SELECT id, slug, status, published_at FROM blog_posts WHERE id = ?', [$id]) : null;
}

/** Signed page-load time for forms (comments): bots that post instantly or replay old forms are refused. */
function blog_form_ts(): string { $t = (string)time(); return $t . '.' . substr(hash_hmac('sha256', 'blogform' . $t, defined('APP_SECRET') ? APP_SECRET : 'x'), 0, 16); }
function blog_form_ts_age(string $v): int
{
    [$t, $sig] = array_pad(explode('.', $v, 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(substr(hash_hmac('sha256', 'blogform' . $t, defined('APP_SECRET') ? APP_SECRET : 'x'), 0, 16), $sig)) { return -1; }
    return time() - (int)$t;
}

function blog_reading_minutes(int $words): int { return max(1, (int)ceil($words / 200)); }

/** A resized copy for lists ("name-640.jpg") when the media library made one, else the original. */
function blog_thumb(?string $path): string
{
    $path = (string)$path;
    if ($path === '') { return ''; }
    $t = preg_replace('/\.(jpe?g|png|webp)$/i', '-640.$1', $path);
    return ($t !== $path && is_file(ROOT_DIR . '/' . $t)) ? $t : $path;
}
/** Absolute URL of a stored image path or a URL typed in the editor. */
function blog_abs(string $src): string
{
    if ($src === '' || preg_match('#^https?://#i', $src)) { return $src; }
    if ($src[0] === '/') { $b = parse_url(url('')); return ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '') . $src; }
    return url($src);
}

// ---- Sanitising (whitelist) ---------------------------------------------------------------

function blog_allowed(): array
{
    $g = ['class', 'id', 'title', 'style', 'dir', 'lang'];
    return [
        'p' => $g, 'br' => [], 'hr' => $g, 'h2' => $g, 'h3' => $g, 'h4' => $g, 'h5' => $g, 'h6' => $g,
        'strong' => $g, 'b' => $g, 'em' => $g, 'i' => $g, 'u' => $g, 's' => $g, 'del' => $g, 'ins' => $g, 'sub' => $g, 'sup' => $g,
        'mark' => $g, 'small' => $g, 'code' => $g, 'kbd' => $g, 'abbr' => $g, 'q' => array_merge($g, ['cite']), 'cite' => $g,
        'pre' => $g, 'blockquote' => array_merge($g, ['cite']), 'span' => $g, 'div' => $g, 'section' => $g,
        'ul' => $g, 'ol' => array_merge($g, ['start', 'type', 'reversed']), 'li' => $g, 'dl' => $g, 'dt' => $g, 'dd' => $g,
        'a' => array_merge($g, ['href', 'target', 'rel', 'name']),
        'img' => array_merge($g, ['src', 'alt', 'width', 'height', 'loading']),
        'figure' => $g, 'figcaption' => $g,
        'table' => array_merge($g, ['border', 'cellpadding', 'cellspacing']), 'caption' => $g, 'thead' => $g, 'tbody' => $g, 'tfoot' => $g,
        'tr' => $g, 'th' => array_merge($g, ['colspan', 'rowspan', 'scope']), 'td' => array_merge($g, ['colspan', 'rowspan']),
        'colgroup' => $g, 'col' => array_merge($g, ['span']),
        'details' => array_merge($g, ['open']), 'summary' => $g, 'time' => array_merge($g, ['datetime']),
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'style', 'class'],
        'video' => ['src', 'controls', 'poster', 'width', 'height', 'preload', 'class', 'style'], 'source' => ['src', 'type'],
    ];
}

function blog_safe_url(string $u, bool $image = false): string
{
    $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($u === '') { return ''; }
    $k = preg_replace('/[\x00-\x20\x7F]+/', '', $u);                          // "java\tscript:" tricks
    if (preg_match('#^(https?:)?//#i', $k) || $k[0] === '/' || $k[0] === '#' || $k[0] === '?') { return $u; }
    if (!$image && preg_match('#^(mailto|tel):#i', $k)) { return $u; }
    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $k) && strpos($k, ':') === false) { return $u; }   // relative path
    return '';                                                                // javascript:, data:, vbscript:, ...
}

function blog_safe_style(string $css): string
{
    $ok = ['text-align', 'color', 'background-color', 'width', 'height', 'max-width', 'float', 'margin', 'margin-left', 'margin-right',
        'margin-top', 'margin-bottom', 'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom', 'border', 'border-width',
        'border-style', 'border-color', 'border-collapse', 'list-style-type', 'text-decoration', 'vertical-align', 'font-weight', 'font-style',
        'aspect-ratio', 'display'];
    $out = [];
    foreach (explode(';', $css) as $decl) {
        if (strpos($decl, ':') === false) { continue; }
        [$k, $v] = array_map('trim', explode(':', $decl, 2));
        $k = strtolower($k);
        if (!in_array($k, $ok, true) || $v === '' || preg_match('/(url\s*\(|expression|javascript|@import|behavior|[<>{}\\\\])/i', $v)) { continue; }
        if ($k === 'display' && !in_array(strtolower($v), ['block', 'inline-block', 'none', 'table'], true)) { continue; }
        $out[] = $k . ': ' . $v;
    }
    return implode('; ', $out);
}

/** Video / map embeds are allowed only from these hosts. */
function blog_iframe_ok(string $src): bool
{
    $h = strtolower((string)parse_url(strpos($src, '//') === 0 ? 'https:' . $src : $src, PHP_URL_HOST));
    return (bool)preg_match('/^(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com|google\.com|docs\.google\.com|drive\.google\.com|maps\.google\.com|open\.spotify\.com|w\.soundcloud\.com|www\.facebook\.com)$/', $h)
        && preg_match('#^(https:)?//#i', $src);
}

function blog_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') { return ''; }
    if (!class_exists('DOMDocument')) {                                       // very rare hosts: fall back to a strict strip
        $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><h2><h3><h4><strong><b><em><i><u><s><ul><ol><li><a><img><blockquote><pre><code><table><thead><tbody><tr><th><td><figure><figcaption><hr>');
        return preg_replace(['/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '/(href|src)\s*=\s*(["\'])\s*(javascript|data|vbscript):[^"\']*\2/i'], ['', '$1="#"'], $html);
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="pd-root">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $dom->getElementById('pd-root');
    if (!$root) { return ''; }
    blog_clean_node($root, blog_allowed());
    $out = '';
    foreach ($root->childNodes as $n) { $out .= $dom->saveHTML($n); }
    return trim(preg_replace('/(<p>(&nbsp;|\s|<br>)*<\/p>\s*){2,}/i', '<p>&nbsp;</p>', $out));
}

function blog_clean_node(DOMNode $node, array $allowed): void
{
    $drop = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'frame', 'frameset', 'applet', 'head', 'title'];
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $c = $node->childNodes->item($i);
        if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction || $c instanceof DOMCdataSection) { $node->removeChild($c); continue; }
        if (!$c instanceof DOMElement) { continue; }
        $tag = strtolower($c->tagName);
        if ($tag === 'h1') { $c = blog_rename($c, 'h2'); $tag = 'h2'; }         // the post title is the page's only H1
        if (in_array($tag, $drop, true)) { $node->removeChild($c); continue; }
        if ($tag === 'iframe' && !blog_iframe_ok((string)$c->getAttribute('src'))) { $node->removeChild($c); continue; }
        blog_clean_node($c, $allowed);
        if (!isset($allowed[$tag])) {                                          // unknown wrapper (font, center, ...) → keep its content
            while ($c->firstChild) { $node->insertBefore($c->firstChild, $c); }
            $node->removeChild($c); continue;
        }
        for ($j = $c->attributes->length - 1; $j >= 0; $j--) {
            $a = $c->attributes->item($j); $name = strtolower($a->name); $val = (string)$a->value;
            if (!in_array($name, $allowed[$tag], true)) { $c->removeAttribute($a->name); continue; }
            if ($name === 'href' || $name === 'src' || $name === 'poster' || $name === 'cite') {
                $safe = blog_safe_url($val, $name !== 'href');
                if ($safe === '') { $c->removeAttribute($a->name); } elseif ($safe !== $val) { $c->setAttribute($a->name, $safe); }
            } elseif ($name === 'style') {
                $s = blog_safe_style($val); if ($s === '') { $c->removeAttribute('style'); } else { $c->setAttribute('style', $s); }
            } elseif ($name === 'class') {
                $s = trim(preg_replace('/[^A-Za-z0-9_\- ]/', '', $val)); if ($s === '') { $c->removeAttribute('class'); } else { $c->setAttribute('class', mb_substr($s, 0, 120)); }
            } elseif ($name === 'id' || $name === 'name') {
                $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $val); if ($s === '') { $c->removeAttribute($a->name); } else { $c->setAttribute($a->name, substr($s, 0, 80)); }
            } elseif ($name === 'target') {
                if ($val !== '_blank') { $c->removeAttribute('target'); }
            } elseif ($name === 'rel') {
                $c->setAttribute('rel', implode(' ', array_intersect(preg_split('/\s+/', strtolower($val)), ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'])));
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span', 'start', 'border', 'cellpadding', 'cellspacing'], true)) {
                if (!preg_match('/^\d{1,4}(%|px)?$/', trim($val))) { $c->removeAttribute($a->name); }
            }
        }
        if ($tag === 'a' && $c->getAttribute('target') === '_blank') {         // reverse-tabnabbing protection
            $rel = array_filter(preg_split('/\s+/', $c->getAttribute('rel')));
            if (!in_array('noopener', $rel, true)) { $rel[] = 'noopener'; }
            $c->setAttribute('rel', implode(' ', $rel));
        }
        if ($tag === 'iframe') { $c->setAttribute('loading', 'lazy'); if (!$c->hasAttribute('title')) { $c->setAttribute('title', 'Embedded media'); } }
    }
}

function blog_rename(DOMElement $el, string $tag): DOMElement
{
    $new = $el->ownerDocument->createElement($tag);
    foreach ($el->attributes as $a) { $new->setAttribute($a->name, $a->value); }
    while ($el->firstChild) { $new->appendChild($el->firstChild); }
    $el->parentNode->replaceChild($new, $el);
    return $new;
}

/** Plain text of stored HTML (search, word count, excerpts, SEO checks). */
function blog_plain(string $html): string
{
    $t = preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/div|/blockquote|/figcaption)[^>]*>#i', "$0\n", $html);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n"], $t));
}
function blog_word_count(string $text): int { return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)); }

// ---- Rendering ----------------------------------------------------------------------------

/** "[document id=12]" / "[document slug=...]" → a card linking to a document for sale / free download. */
function blog_doc_card(array $d): string
{
    return '<aside class="blog-doc-card"><span class="blog-doc-icon" aria-hidden="true">' . doc_icon((string)$d['file_ext']) . '</span><span class="blog-doc-main">'
        . '<a class="blog-doc-title" href="' . e(doc_url($d)) . '">' . e($d['title']) . '</a>'
        . '<span class="blog-doc-meta">' . e(doc_ext_label((string)$d['file_ext'])) . ($d['pages'] ? ' · ' . e(pages_label($d['pages'])) : '') . ' · <strong>' . e(price_label($d)) . '</strong></span></span>'
        . '<a class="btn-classic primary btn-sm" href="' . e(doc_url($d)) . '">' . (!empty($d['is_free']) ? 'DOWNLOAD' : 'GET IT') . '</a></aside>';
}

/**
 * Stored (already sanitised) HTML → display HTML, table of contents and paragraph count.
 * $ad (optional) returns the ad HTML placed after paragraph N of the top level — called only when the article is long
 * enough, so a short article never uses up the page's ad budget.
 */
function blog_render_content(string $html, ?callable $ad = null, bool $lazyFirst = true): array
{
    $res = ['html' => $html, 'toc' => [], 'paragraphs' => 0, 'images' => []];
    if (trim($html) === '' || !class_exists('DOMDocument')) { return $res; }
    // document shortcodes (typed or inserted by the editor button) — only when alone in a paragraph
    $html = preg_replace_callback('#<p[^>]*>\s*\[document\s+(id|slug)\s*=\s*(?:"|&quot;|\x{201C}|\x{201D})?([A-Za-z0-9\-]{1,191})(?:"|&quot;|\x{201C}|\x{201D})?\s*\]\s*</p>#iu', function ($m) {
        $d = strtolower($m[1]) === 'id' ? doc_get((int)$m[2]) : doc_get_by_slug($m[2]);
        if (!$d || $d['status'] !== 'published') { return ''; }
        return blog_doc_card($d);
    }, $html);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="pd-root">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $dom->getElementById('pd-root');
    if (!$root) { return $res; }
    $xp = new DOMXPath($dom);
    $used = [];
    foreach ($xp->query('.//h2|.//h3', $root) as $h) {
        $text = trim(preg_replace('/\s+/u', ' ', $h->textContent));
        if ($text === '') { continue; }
        $id = $h->getAttribute('id') ?: slugify($text, 60);
        $base = $id; $n = 2; while (isset($used[$id])) { $id = $base . '-' . $n++; }
        $used[$id] = true; $h->setAttribute('id', $id);
        $res['toc'][] = ['id' => $id, 'text' => $text, 'level' => (int)substr($h->nodeName, 1)];
    }
    $first = true;
    foreach ($xp->query('.//img', $root) as $img) {
        $res['images'][] = blog_abs((string)$img->getAttribute('src'));
        if (!$img->hasAttribute('alt')) { $img->setAttribute('alt', ''); }
        if ($first && !$lazyFirst) { $img->setAttribute('fetchpriority', 'high'); } else { $img->setAttribute('loading', 'lazy'); }
        $img->setAttribute('decoding', 'async'); $first = false;
    }
    $host = (string)parse_url(url(''), PHP_URL_HOST);
    foreach ($xp->query('.//a[@href]', $root) as $a) {
        $h = strtolower((string)parse_url($a->getAttribute('href'), PHP_URL_HOST));
        if ($h !== '' && $h !== strtolower($host)) {                         // outbound links open safely
            $rel = array_filter(preg_split('/\s+/', $a->getAttribute('rel')));
            foreach (['noopener'] as $r) { if (!in_array($r, $rel, true)) { $rel[] = $r; } }
            $a->setAttribute('rel', implode(' ', $rel));
        }
    }
    foreach ($xp->query('.//table', $root) as $t) {                          // wide tables scroll instead of breaking the layout
        if ($t->parentNode instanceof DOMElement && strpos($t->parentNode->getAttribute('class'), 'table-wrap') !== false) { continue; }
        $w = $dom->createElement('div'); $w->setAttribute('class', 'table-wrap');
        $t->parentNode->replaceChild($w, $t); $w->appendChild($t);
    }
    $tops = [];
    foreach ($root->childNodes as $c) { if ($c instanceof DOMElement && $c->nodeName === 'p' && trim($c->textContent) !== '') { $tops[] = $c; } }
    $res['paragraphs'] = count($tops);
    $after = max(2, (int)setting('blog_ad_after_paragraph', '4'));
    $adMarker = null;
    $adHtml = '';
    if ($ad && count($tops) >= $after + 3 && ($adHtml = (string)$ad()) !== '') {   // only in long articles, never right before the end
        $adMarker = $dom->createComment('pd-ad');
        $ref = $tops[$after - 1];
        $ref->parentNode->insertBefore($adMarker, $ref->nextSibling);
    }
    $out = '';
    foreach ($root->childNodes as $n) { $out .= $dom->saveHTML($n); }
    $res['html'] = $adMarker ? str_replace('<!--pd-ad-->', $adHtml, $out) : $out;
    return $res;
}

function blog_toc_html(array $toc): string
{
    if (count($toc) < 3) { return ''; }
    $h = '<nav class="blog-toc" aria-label="Table of contents"><details open><summary>Contents</summary><ol>';
    foreach ($toc as $t) { $h .= '<li class="lvl-' . (int)$t['level'] . '"><a href="#' . e($t['id']) . '">' . e($t['text']) . '</a></li>'; }
    return $h . '</ol></details></nav>';
}

/** Excerpt for lists and meta tags: the hand-written one, else the first words of the article. */
function blog_excerpt(array $p, int $len = 160): string
{
    $x = trim((string)($p['excerpt'] ?? ''));
    return excerpt($x !== '' ? $x : (string)($p['content_text'] ?? blog_plain((string)($p['content'] ?? ''))), $len);
}

function blog_card_html(array $p, bool $big = false): string
{
    $img = blog_thumb($p['cover_image'] ?? '');
    $h = '<article class="blog-card' . ($big ? ' blog-card-big' : '') . '">';
    if ($img !== '') { $h .= '<a class="blog-card-img" href="' . e(post_url($p)) . '" tabindex="-1" aria-hidden="true"><img src="' . e(url($img)) . '" alt="" loading="lazy" decoding="async"></a>'; }
    $h .= '<div class="blog-card-body">';
    if (!empty($p['category_name'])) { $h .= '<a class="blog-cat-chip" href="' . e(blog_cat_url(['slug' => $p['category_slug']])) . '">' . e($p['category_name']) . '</a>'; }
    $h .= '<h' . ($big ? '2' : '3') . ' class="blog-card-title"><a href="' . e(post_url($p)) . '">' . e($p['title']) . '</a></h' . ($big ? '2' : '3') . '>'
        . '<p class="blog-card-excerpt">' . e(blog_excerpt($p, $big ? 260 : 150)) . '</p>'
        . '<div class="blog-meta"><time datetime="' . e(date('c', strtotime((string)$p['published_at']))) . '">' . e(fmt_date($p['published_at'])) . '</time> · '
        . blog_reading_minutes((int)$p['word_count']) . ' min read</div></div></article>';
    return $h;
}

// ---- Related content ----------------------------------------------------------------------

function blog_related_posts(array $p, int $limit = 3): array
{
    $tagIds = array_map('intval', array_column(db_all('SELECT tag_id FROM blog_post_tags WHERE post_id = ?', [$p['id']]), 'tag_id'));
    $score = '(CASE WHEN p.category_id = ? THEN 2 ELSE 0 END)' . ($tagIds ? ' + (SELECT COUNT(*) FROM blog_post_tags x WHERE x.post_id = p.id AND x.tag_id IN (' . implode(',', $tagIds) . ')) * 3' : '');
    return db_all(str_replace('SELECT p.*', 'SELECT p.*, ' . $score . ' AS rel', BLOG_SELECT) . ' WHERE ' . blog_live_sql() . ' AND p.id <> ? ORDER BY rel DESC, p.published_at DESC LIMIT ' . (int)$limit,
        [(int)$p['category_id'], (int)$p['id']]);
}

/** Documents from the store for an article: the ones the author picked, then the best search matches. */
function blog_related_docs(array $p, int $limit = 4): array
{
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$p['doc_ids']))));
    $rows = [];
    if ($ids) {
        $found = db_all("SELECT d.*, c.name AS category_name, c.path AS category_path FROM documents d LEFT JOIN categories c ON c.id = d.category_id
                         WHERE d.status = 'published' AND d.id IN (" . implode(',', $ids) . ')');
        $byId = []; foreach ($found as $d) { $byId[(int)$d['id']] = $d; }
        foreach ($ids as $i) { if (isset($byId[$i])) { $rows[$i] = $byId[$i]; } }
    }
    if (count($rows) < $limit && setting('blog_auto_related_docs', '1') === '1') {
        $q = trim((string)$p['focus_keyword']) !== '' ? (string)$p['focus_keyword'] : (string)$p['title'];
        try {
            $r = search_documents(['q' => $q, 'per' => $limit + count($rows), '_nospell' => true]);
            foreach ($r['rows'] as $d) { if (count($rows) >= $limit) { break; } $rows[(int)$d['id']] = $rows[(int)$d['id']] ?? $d; }
        } catch (Throwable $e) { }
    }
    return array_slice(array_values($rows), 0, $limit);
}

// ---- Structured data ----------------------------------------------------------------------

function blog_author_schema(array $a): array
{
    $s = ['@type' => 'Person', 'name' => $a['full_name'] ?: $a['username'], 'url' => blog_author_url($a)];
    if (!empty($a['author_title'])) { $s['jobTitle'] = $a['author_title']; }
    if (!empty($a['bio'])) { $s['description'] = excerpt((string)$a['bio'], 300); }
    $links = array_values(array_filter(preg_split('/\s+/', (string)($a['author_links'] ?? '')), function ($u) { return (bool)preg_match('#^https?://#i', $u); }));
    if ($links) { $s['sameAs'] = $links; }
    return $s;
}

function blog_post_schema(array $p, ?array $author, array $tags, array $render, int $commentCount): array
{
    $type = in_array($p['schema_type'], ['BlogPosting', 'Article', 'NewsArticle'], true) ? $p['schema_type'] : 'BlogPosting';
    $url = post_url($p);
    $images = [];
    if (!empty($p['cover_image'])) { $images[] = url($p['cover_image']); }
    foreach (array_slice($render['images'], 0, 3) as $i) { if ($i !== '' && !in_array($i, $images, true)) { $images[] = $i; } }
    $pub = org_schema();
    $s = ['@context' => 'https://schema.org', '@type' => $type, 'headline' => mb_substr((string)$p['title'], 0, 110),
        'description' => blog_excerpt($p, 300), 'url' => $url, 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'datePublished' => date('c', strtotime((string)$p['published_at'])), 'dateModified' => date('c', strtotime((string)max($p['updated_at'], $p['published_at']))),
        'author' => $author ? blog_author_schema($author) : $pub, 'publisher' => $pub, 'inLanguage' => 'en-KE',
        'wordCount' => (int)$p['word_count'], 'isAccessibleForFree' => true, 'commentCount' => $commentCount];
    if ($images) { $s['image'] = $images; }
    if (!empty($p['category_name'])) { $s['articleSection'] = $p['category_name']; }
    $kw = array_column($tags, 'name');
    if (trim((string)$p['focus_keyword']) !== '') { array_unshift($kw, $p['focus_keyword']); }
    if ($kw) { $s['keywords'] = implode(', ', array_unique($kw)); }
    return $s;
}

// ---- Media --------------------------------------------------------------------------------

/**
 * Stores an uploaded image in uploads/blog/YYYY/MM/, re-encoded with GD when available (strips metadata and
 * anything hidden in the file), scaled to at most 1600px wide, plus a 640px copy for lists. Returns the
 * blog_media row or ['error' => ...].
 */
function blog_media_store(array $file, string $alt = ''): array
{
    $chk = upload_check($file, ['jpg', 'jpeg', 'png', 'webp'], max(1, (int)setting('blog_image_mb', '5')) * 1048576, true);
    if (!$chk['ok']) { return ['error' => $chk['error']]; }
    if ($chk['ext'] === 'jpeg') { $chk['ext'] = 'jpg'; }
    $rel = 'uploads/blog/' . date('Y/m');
    $dir = ROOT_DIR . '/' . $rel;
    $name = upload_save($file['tmp_name'], $chk['ext'], $dir);
    if (!$name) { return ['error' => 'The image could not be saved. Check that the uploads folder is writable.']; }
    $path = $dir . '/' . $name;
    @chmod($path, 0644);
    [$w, $h] = @getimagesize($path) ?: [0, 0];
    $thumb = null;
    if (function_exists('imagecreatetruecolor')) {
        $load = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$chk['ext']] ?? '';
        $src = ($load !== '' && function_exists($load)) ? @$load($path) : false;
        if ($src) {
            $save = function ($im, string $to) use ($chk) {
                if ($chk['ext'] === 'png') { imagesavealpha($im, true); return @imagepng($im, $to, 7); }
                if ($chk['ext'] === 'webp') { return @imagewebp($im, $to, 82); }
                imageinterlace($im, true); return @imagejpeg($im, $to, 82);
            };
            $scale = function ($im, int $maxW) use ($w, $h, $chk) {
                $nw = min($w, $maxW); $nh = (int)round($h * $nw / max(1, $w));
                $dst = imagecreatetruecolor($nw, max(1, $nh));
                if ($chk['ext'] !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, max(1, $nh), $w, $h);
                return $dst;
            };
            $big = $scale($src, 1600);                                         // re-encode always (clean file), downscale if wider
            if ($save($big, $path)) { $w = imagesx($big); $h = imagesy($big); }
            if ($w > 700) {
                $t = $scale($big, 640); $tn = preg_replace('/\.(\w+)$/', '-640.$1', $name);
                if ($save($t, $dir . '/' . $tn)) { @chmod($dir . '/' . $tn, 0644); $thumb = $rel . '/' . $tn; }
                imagedestroy($t);
            }
            imagedestroy($big); imagedestroy($src);
        }
    }
    clearstatcache();
    $id = db_insert('INSERT INTO blog_media (file_path, thumb_path, file_name, width, height, size, alt, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$rel . '/' . $name, $thumb, $chk['name'], (int)$w, (int)$h, (int)@filesize($path), $alt !== '' ? mb_substr($alt, 0, 255) : null, $_SESSION['admin']['id'] ?? null]);
    return db_row('SELECT * FROM blog_media WHERE id = ?', [$id]) ?: ['error' => 'Saved, but not recorded.'];
}

// ---- Revisions ----------------------------------------------------------------------------

function blog_revision_add(int $postId, array $data, string $kind = 'revision'): void
{
    if ($kind === 'autosave') { db_exec("DELETE FROM blog_revisions WHERE post_id = ? AND kind = 'autosave'", [$postId]); }
    else {
        $last = db_row("SELECT title, content, excerpt FROM blog_revisions WHERE post_id = ? AND kind = 'revision' ORDER BY id DESC LIMIT 1", [$postId]);
        if ($last && $last['title'] === $data['title'] && (string)$last['content'] === (string)$data['content'] && (string)$last['excerpt'] === (string)$data['excerpt']) { return; }
    }
    db_exec('INSERT INTO blog_revisions (post_id, author_id, kind, title, excerpt, content) VALUES (?, ?, ?, ?, ?, ?)',
        [$postId, $_SESSION['admin']['id'] ?? null, $kind, mb_substr((string)$data['title'], 0, 255), $data['excerpt'] ?? null, $data['content'] ?? null]);
    $keep = max(5, (int)setting('blog_revisions_keep', '25'));
    $old = db_all("SELECT id FROM blog_revisions WHERE post_id = ? AND kind = 'revision' ORDER BY id DESC LIMIT 1000 OFFSET $keep", [$postId]);
    if ($old) { db_exec('DELETE FROM blog_revisions WHERE id IN (' . implode(',', array_map('intval', array_column($old, 'id'))) . ')'); }
}

// ---- Comments -----------------------------------------------------------------------------

function blog_comments_tree(int $postId): array
{
    $rows = db_all("SELECT * FROM blog_comments WHERE post_id = ? AND status = 'approved' ORDER BY created_at, id", [$postId]);
    $top = []; $kids = [];
    foreach ($rows as $r) { if ($r['parent_id']) { $kids[(int)$r['parent_id']][] = $r; } else { $top[] = $r; } }
    foreach ($top as &$t) { $t['replies'] = $kids[(int)$t['id']] ?? []; } unset($t);
    return ['list' => $top, 'count' => count($rows)];
}
