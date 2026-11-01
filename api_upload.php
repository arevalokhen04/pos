<?php
/**
 * MARINA'S HARDWARE POS - Product image upload
 * POST multipart/form-data with field "image" (jpg/png/webp, max 3MB).
 * Returns { success, path } — the path is saved on the product record.
 */
require __DIR__ . '/db.php';

$user = require_role('admin', 'super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'POST only'], 405);
}
if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_out(['success' => false, 'message' => 'No image uploaded.']);
}
$file = $_FILES['image'];
if ($file['size'] > 3 * 1024 * 1024) {
    json_out(['success' => false, 'message' => 'Image is too large (max 3MB).']);
}

/* totoong image type — hindi nakikialam sa extension lang */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$map   = [
    'image/jpeg'  => 'jpg',
    'image/png'   => 'png',
    'image/webp'  => 'webp',
];
if (!isset($map[$mime])) {
    json_out(['success' => false, 'message' => 'Only JPG, PNG or WEBP images are allowed.']);
}

$dir = __DIR__ . '/Assets/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$name = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    json_out(['success' => false, 'message' => 'Failed to save the image.'], 500);
}

audit_log($user, 'image_upload', 'product', null, $name);
json_out(['success' => true, 'path' => 'Assets/uploads/' . $name]);
