<?php
/** Admin: live preview of the editor's current (unsaved) content, rendered with the real article template. Never saved, never indexed. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/blog_admin.php';
$admin = require_admin('blog.write');
if (!is_post()) { redirect(url('admin/posts.php')); }
csrf_check();
$in = $_POST;
$cat = (int)($in['category_id'] ?? 0) ? db_row('SELECT name, slug FROM blog_categories WHERE id = ?', [(int)$in['category_id']]) : null;
$content = blog_sanitize_html((string)($in['content'] ?? ''));
$cover = trim((string)($in['cover_image'] ?? ''));
if ($cover !== '' && (strpos($cover, 'uploads/blog/') !== 0 || strpos($cover, '..') !== false || !is_file(ROOT_DIR . '/' . $cover))) { $cover = ''; }
$tags = [];
foreach (preg_split('/[,;\n]+/', (string)($in['tags'] ?? '')) as $t) { $t = trim($t); if ($t !== '') { $tags[] = ['name' => $t, 'slug' => slugify($t)]; } }
$authorId = admin_can('blog.publish') && (int)($in['author_id'] ?? 0) ? (int)$in['author_id'] : (int)$admin['id'];
$pub = blog_parse_dt((string)($in['published_at'] ?? '')) ?: date('Y-m-d H:i:s');
$post = [
    'id' => 0, 'title' => trim((string)($in['title'] ?? '')) ?: '(no title)', 'slug' => slugify((string)($in['slug'] ?? '') ?: (string)($in['title'] ?? 'preview')),
    'excerpt' => (string)($in['excerpt'] ?? ''), 'content' => $content, 'content_text' => blog_plain($content), 'cover_image' => $cover, 'cover_alt' => (string)($in['cover_alt'] ?? ''),
    'category_id' => $cat ? (int)$in['category_id'] : null, 'category_name' => $cat['name'] ?? null, 'category_slug' => $cat['slug'] ?? null, 'author_id' => $authorId,
    'status' => 'draft', 'published_at' => $pub, 'updated_at' => date('Y-m-d H:i:s'), 'seo_title' => (string)($in['seo_title'] ?? ''), 'meta_description' => (string)($in['meta_description'] ?? ''),
    'focus_keyword' => (string)($in['focus_keyword'] ?? ''), 'canonical_url' => '', 'robots_noindex' => 1, 'schema_type' => (string)($in['schema_type'] ?? 'BlogPosting'),
    'allow_comments' => 0, 'show_toc' => !empty($in['show_toc']) ? 1 : 0, 'word_count' => 0, 'doc_ids' => (string)($in['doc_ids'] ?? ''), '_tags' => $tags,
];
$preview = true;
$blogName = setting('blog_title', 'Blog');
include ROOT_DIR . '/includes/blog_post_view.php';
