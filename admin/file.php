<?php
/** Admin-only: open an ORIGINAL file for review (documents or community contributions). Never public. */
require __DIR__ . '/../includes/init.php';
$type = get_str('type', 10); $id = get_int('id');
if ($type === 'contrib') { require_admin('contributions.review'); $row = db_row('SELECT file_name, file_ext, title FROM contributions WHERE id = ?', [$id]); $which = get_str('f', 8); if ($which === 'preview') { $row = db_row('SELECT preview_image AS file_name, SUBSTRING_INDEX(preview_image, \'.\', -1) AS file_ext, title FROM contributions WHERE id = ?', [$id]); } }
else { require_admin('documents.view'); $row = db_row('SELECT file_name, file_ext, title FROM documents WHERE id = ?', [$id]); }
if (!$row || empty($row['file_name']) || !preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $row['file_name'])) { abort_page(404, 'File not found', 'There is no file for this record.'); }
$path = rtrim(PRIVATE_DIR, '/\\') . '/' . $row['file_name'];
if (!is_file($path)) { abort_page(404, 'File missing', 'The file is missing on the server.'); }
log_admin('file_opened', $type, $id);
$ext = strtolower($row['file_ext']); $inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
$name = slugify($row['title'], 60) . '.' . $ext;
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: ' . doc_mime($ext));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header_remove('Content-Security-Policy');   // lets the browser's built-in PDF viewer open inline files
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex, nofollow');
readfile($path);
exit;
