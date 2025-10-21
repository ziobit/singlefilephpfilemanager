<?php

session_start();
const AUTH_PASSWORD    = '12345678';  // Change this password as needed
const AUTH_SESSION_KEY = 'zip_manager_auth';
const BASE_ROOT        = __DIR__;
const TRASH_DIR        = '.trash';

function is_authenticated(): bool { return !empty($_SESSION[AUTH_SESSION_KEY]); }
function try_authenticate(): bool {
  if (!isset($_POST['password'])) return false;
  if ((string)$_POST['password'] === AUTH_PASSWORD) { $_SESSION[AUTH_SESSION_KEY] = time(); return true; }
  return false;
}
function logout_auth(): void { unset($_SESSION[AUTH_SESSION_KEY]); }

function flash_set(string $msg): void { $_SESSION['zip_manager_flash'] = $msg; }
function flash_get(): ?string { $m = $_SESSION['zip_manager_flash'] ?? null; unset($_SESSION['zip_manager_flash']); return $m; }

function secure_path(string $rel) {
  $rel = str_replace(["\0"], '', $rel);
  $parts = array_filter(explode('/', str_replace('\\','/',$rel)), fn($p)=> $p!=='' && $p!=='.' && $p!=='..');
  $clean = implode(DIRECTORY_SEPARATOR, $parts);
  $candidate = BASE_ROOT . DIRECTORY_SEPARATOR . $clean;
  $abs = realpath($candidate);
  if ($abs === false) $abs = $candidate;
  $rootReal = realpath(BASE_ROOT);
  if ($rootReal === false) return false;
  $absNorm = str_replace('\\','/',$abs);
  $rootNorm= str_replace('\\','/',$rootReal);
  if (strpos($absNorm, $rootNorm) !== 0) return false;
  return $abs;
}
function to_rel(string $abs): string {
  $root = str_replace('\\','/', realpath(BASE_ROOT));
  $abs  = str_replace('\\','/', $abs);
  if ($root && strpos($abs, $root) === 0) return ltrim(substr($abs, strlen($root)), '/');
  return '';
}
function trash_abs(): string {
  $p = secure_path(TRASH_DIR);
  if ($p && !is_dir($p)) @mkdir($p, 0755, true);
  return $p ?: (BASE_ROOT . DIRECTORY_SEPARATOR . TRASH_DIR);
}
function is_in_trash(string $absPath): bool {
  $t = realpath(trash_abs());
  $p = realpath($absPath) ?: $absPath;
  $t = $t ? str_replace('\\','/',$t) : '';
  $p = str_replace('\\','/',$p);
  return ($t !== '' && strpos($p, $t) === 0);
}

function size_human(int|float $bytes): string {
  $u = ['B','KB','MB','GB','TB']; $i=0; $val=max(0,(float)$bytes);
  while ($val>=1024 && $i<count($u)-1){ $val/=1024; $i++; }
  return number_format($val, ($i===0?0:2)).' '.$u[$i];
}
function dir_size_recursive(string $dir): int {
  $total=0; if (!is_dir($dir) || !is_readable($dir)) return 0;
  try {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach($it as $path=>$info){ if ($info->isLink()) continue; if ($info->isFile()){ $sz=@filesize($path); if ($sz!==false) $total+=$sz; } }
  } catch(Throwable $e){}
  return $total;
}
function list_directory(string $absDir): array {
  $out=[]; if(!is_dir($absDir)||!is_readable($absDir)) return $out;
  $dh=opendir($absDir); if(!$dh) return $out;
  while(($entry=readdir($dh))!==false){
    if($entry==='.'||$entry==='..') continue;
    $full=$absDir.DIRECTORY_SEPARATOR.$entry; $isDir=is_dir($full);
    $size=$isDir?dir_size_recursive($full):(@filesize($full)?:0);
    $mtime=@filemtime($full)?:0;
    $out[]=['name'=>$entry,'path'=>$full,'is_dir'=>$isDir,'size'=>(int)$size,'mtime'=>$mtime,'rel'=>to_rel($full)];
  }
  closedir($dh); return $out;
}
function sort_entries(array &$entries, string $sort, string $dir): void {
  $mul = (strtolower($dir)==='desc')?-1:1;
  if ($sort==='name'){
    usort($entries,function($a,$b)use($mul){ if($a['is_dir']!==$b['is_dir']) return $a['is_dir']?-1:1; return $mul*strcasecmp($a['name'],$b['name']);});
  } elseif ($sort==='size'){
    usort($entries,function($a,$b)use($mul){ $c=$a['size']<=>$b['size']; if($c===0) $c=strcasecmp($a['name'],$b['name']); return $mul*$c; });
  } else {
    usort($entries,function($a,$b)use($mul){ $c=$a['mtime']<=>$b['mtime']; if($c===0) $c=strcasecmp($a['name'],$b['name']); return $mul*$c; });
  }
}
function is_image_ext(string $name): bool {
  $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
  return in_array($ext,['jpg','jpeg','png','gif','webp','bmp','svg']);
}
function is_text_ext(string $name): bool {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === '') {
    $base = basename($name);
    return in_array($base, ['.htaccess', '.env', 'Dockerfile', 'Makefile']);
  }
  return in_array($ext, [
    'txt','log','md','markdown','csv','tsv','json','yaml','yml','xml','svg','ini','conf','config','env',
    'htm','html','css','js','mjs','ts','tsx','jsx',
    'php','phtml','inc','php3','php4','php5','phps',
    'c','h','cpp','hpp','cc','hh','cs','java','kt','go','rs','swift','m','mm',
    'py','rb','pl','sh','bash','zsh','ps1','sql','twig','vue','svelte','jade','ejs','handlebars','hbs'
  ]);
}
// Grep-allowed extensions (per request)
function is_grep_ext(string $name): bool {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return in_array($ext, ['txt','php','phtml','html','htm','css','js']);
}

// --- GD helpers for image batch ---
function gd_load(string $path, string $ext, ?string &$err) {
  $err=null; $ext=strtolower($ext);
  if ($ext==='jpg' || $ext==='jpeg') { return @imagecreatefromjpeg($path) ?: ($err='Cannot read JPEG.'); }
  if ($ext==='png') { return @imagecreatefrompng($path) ?: ($err='Cannot read PNG.'); }
  if ($ext==='gif') { return @imagecreatefromgif($path) ?: ($err='Cannot read GIF.'); }
  if ($ext==='webp') {
    if (!function_exists('imagecreatefromwebp')) { $err='WEBP unsupported on this PHP.'; return false; }
    return @imagecreatefromwebp($path) ?: ($err='Cannot read WEBP.');
  }
  $err='Unsupported input format: '.$ext; return false;
}
function gd_save($im, string $path, string $ext, int $quality, ?string &$err): bool {
  $err=null; $ext=strtolower($ext); $quality=max(1,min(100,$quality));
  if ($ext==='jpg' || $ext==='jpeg') { return @imagejpeg($im, $path, $quality) ?: ($err='Save JPEG failed.'); }
  if ($ext==='png') {
    $comp = (int)round((100 - $quality) * 9 / 100);
    imagesavealpha($im, true);
    return @imagepng($im, $path, $comp) ?: ($err='Save PNG failed.');
  }
  if ($ext==='webp') {
    if (!function_exists('imagewebp')) { $err='WEBP unsupported on this PHP.'; return false; }
    imagesavealpha($im, true);
    return @imagewebp($im, $path, $quality) ?: ($err='Save WEBP failed.');
  }
  $err='Unsupported output format: '.$ext; return false;
}
function ext_norm(string $fn): string {
  $e=strtolower(pathinfo($fn, PATHINFO_EXTENSION));
  if ($e==='jpeg') $e='jpg';
  return $e;
}

// Zip/unzip
function create_zip_from(string $srcAbs, string $zipAbs, ?string &$error = null): bool {
  $error=null; $za=new ZipArchive();
  if ($za->open($zipAbs, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){ $error='Cannot create zip.'; return false; }
  $srcAbs=rtrim($srcAbs,DIRECTORY_SEPARATOR); $baseName=basename($srcAbs);
  if (is_file($srcAbs)){
    if(!$za->addFile($srcAbs,$baseName)){ $za->close(); $error='Add file failed.'; return false; }
  } elseif (is_dir($srcAbs)){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcAbs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $path=>$info){
      $rel=$baseName.'/'.ltrim(str_replace($srcAbs,'',$path), DIRECTORY_SEPARATOR);
      $rel=str_replace('\\','/',$rel);
      if($info->isDir()) $za->addEmptyDir($rel);
      else{ if(!$za->addFile($path,$rel)){ $za->close(); $error='Add file failed.'; return false; } }
    }
  } else { $za->close(); $error='Source not found.'; return false; }
  $za->close(); return true;
}
function unzip_to_folder_and_delete(string $zipAbsPath, ?string &$error = null): bool {
  $error=null; if(!is_file($zipAbsPath)){ $error='Zip not found.'; return false; }
  $dir=dirname($zipAbsPath); $base=pathinfo($zipAbsPath,PATHINFO_FILENAME);
  $target=$dir.DIRECTORY_SEPARATOR.$base; if(file_exists($target)) $target.='_'.time();
  $za=new ZipArchive(); if($za->open($zipAbsPath)!==true){ $error='Cannot open zip.'; return false; }
  for($i=0;$i<$za->numFiles;$i++){ $entry=$za->getNameIndex($i);
    if($entry===null||strpos($entry,'..')!==false||preg_match('#(^/|\\\\)#',$entry)||strpos($entry,':')!==false){ $za->close(); $error='Invalid path inside zip.'; return false; } }
  if(!@mkdir($target,0755,true)){ $za->close(); $error='Cannot create target.'; return false; }
  if(!$za->extractTo($target)){ $za->close(); $error='Extraction failed.'; @rmdir($target); return false; }
  $za->close(); if(!@unlink($zipAbsPath)){ $error='Extracted, but cannot delete zip.'; return false; }
  return true;
}

function rrmdir(string $dir): bool {
  if (!is_dir($dir)) return @unlink($dir);
  $it=new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $path) {
    if ($path->isDir()) @rmdir($path->getRealPath());
    else @unlink($path->getRealPath());
  }
  return @rmdir($dir);
}
function delete_item(string $absPath, ?string &$error = null): bool {
  $error=null; if(!file_exists($absPath)){ $error='Item not found.'; return false; }
  if (is_file($absPath)) { if(!@unlink($absPath)){ $error='Cannot delete file.'; return false; } return true; }
  if (is_dir($absPath)) {
    if (is_in_trash($absPath)) return rrmdir($absPath) ?: (($error='Cannot permanently delete folder.') && false);
    $trash = trash_abs();
    $target=$trash.DIRECTORY_SEPARATOR.basename($absPath).'_'.date('Ymd_His');
    if(!@rename($absPath,$target)){ $error='Cannot move folder to trash.'; return false; }
    return true;
  }
  $error='Unsupported item.'; return false;
}
function rename_item(string $absPath, string $newName, ?string &$error = null): bool {
  $error=null; if(!file_exists($absPath)){ $error='Item not found.'; return false; }
  if ($newName==='' || strpos($newName,'/')!==false || strpos($newName,'\\')!==false){ $error='Invalid name.'; return false; }
  $dir=dirname($absPath); $dest=$dir.DIRECTORY_SEPARATOR.$newName;
  $destSec=secure_path(to_rel($dest)); if($destSec===false){ $error='Invalid destination.'; return false; }
  if(file_exists($dest)){ $error='Target already exists.'; return false; }
  if(!@rename($absPath,$dest)){ $error='Rename failed.'; return false; }
  return true;
}
function breadcrumbs(string $rel): string {
  $parts = $rel === '' ? [] : explode('/', str_replace('\\','/',$rel));
  $accum=''; $html=[];
  $html[]='<nav aria-label="breadcrumb"><ol class="breadcrumb mb-2">';
  $html[]='<li class="breadcrumb-item"><a data-folder-link href="?">root</a></li>';
  foreach($parts as $i=>$p){
    if($p==='') continue; $accum=($accum==='')?$p:($accum.'/'.$p);
    if($i===count($parts)-1) $html[]='<li class="breadcrumb-item active" aria-current="page">'.htmlspecialchars($p).'</li>';
    else $html[]='<li class="breadcrumb-item"><a data-folder-link href="?p='.rawurlencode($accum).'">'.htmlspecialchars($p).'</a></li>';
  }
  $html[]='</ol></nav>'; return implode('',$html);
}

// Image preview
if (isset($_GET['preview']) && isset($_GET['f'])) {
  $rel = (string)$_GET['f']; $abs = secure_path($rel);
  if ($abs && is_file($abs) && is_image_ext($abs)) {
    $mt = function_exists('mime_content_type') ? @mime_content_type($abs) : null;
    if (!$mt) {
      $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
      $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml'];
      $mt = $map[$ext] ?? 'application/octet-stream';
    }
    header('Content-Type: '.$mt);
    header('Content-Length: '.filesize($abs));
    header('Cache-Control: private, max-age=600');
    readfile($abs); exit;
  }
  http_response_code(404); exit;
}

// READ file for editor (AJAX JSON)
if (isset($_GET['read']) && isset($_GET['f']) && is_authenticated()) {
  $rel = (string)$_GET['f']; $abs = secure_path($rel);
  header('Content-Type: application/json; charset=UTF-8');
  if ($abs && is_file($abs) && is_text_ext($abs)) {
    $size = @filesize($abs);
    if ($size === false) { echo json_encode(['ok'=>false,'error'=>'Cannot stat file']); exit; }
    $limit = 2 * 1024 * 1024; // 2 MB limit for inline editor
    if ($size > $limit) { echo json_encode(['ok'=>false,'error'=>'File too large for inline editor (2MB limit).']); exit; }
    $content = @file_get_contents($abs);
    if ($content === false) { echo json_encode(['ok'=>false,'error'=>'Cannot read file']); exit; }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    echo json_encode([
      'ok'=>true,
      'name'=>basename($abs),
      'rel'=>to_rel($abs),
      'size'=>$size,
      'mtime'=>@filemtime($abs) ?: 0,
      'writable'=>is_writable($abs),
      'ext'=>$ext,
      'content'=>$content,
    ]);
    exit;
  }
  echo json_encode(['ok'=>false,'error'=>'Invalid file']); exit;
}

// GREP-style search (AJAX JSON): recurse from base, only certain text files, return matches with line numbers
if (isset($_GET['search']) && is_authenticated()) {
  header('Content-Type: application/json; charset=UTF-8');
  $q = trim((string)($_GET['q'] ?? ''));
  $baseRel = (string)($_GET['base'] ?? '');
  $caseSensitive = ((string)($_GET['cs'] ?? '0') === '1');
  if ($q === '') { echo json_encode(['ok'=>false,'error'=>'Empty query']); exit; }
  $baseAbs = secure_path($baseRel);
  if ($baseAbs === false || !is_dir($baseAbs)) { echo json_encode(['ok'=>false,'error'=>'Invalid base']); exit; }

  $trashReal = realpath(trash_abs()) ?: '';
  $trashReal = str_replace('\\','/',$trashReal);
  $results = [];
  $maxPerFile = 50;
  $maxTotal = 500;
  $maxFileSize = 2 * 1024 * 1024; // 2 MB
  $count = 0;

  try {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseAbs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $path => $info) {
      if ($info->isDir()) continue;
      $real = realpath($path) ?: $path;
      $realNorm = str_replace('\\','/',$real);
      if ($trashReal && str_starts_with($realNorm, $trashReal)) continue; // skip .trash
      $name = $info->getFilename();
      if (!is_grep_ext($name)) continue;
      $sz = @filesize($path);
      if ($sz === false || $sz > $maxFileSize) continue;

      $fh = @fopen($path, 'rb');
      if (!$fh) continue;
      $lineNo = 0; $matchesInFile = 0;
      while (!feof($fh)) {
        $line = fgets($fh);
        if ($line === false) break;
        $lineNo++;
        $hay = $caseSensitive ? $line : mb_strtolower($line, 'UTF-8');
        $needle = $caseSensitive ? $q : mb_strtolower($q, 'UTF-8');
        if ($needle === '' || mb_strpos($hay, $needle) === false) continue;

        $rel = to_rel($real);
        $trimmed = rtrim($line, "\r\n");
        $results[] = [
          'rel' => $rel,
          'name' => basename($real),
          'line_no' => $lineNo,
          'line' => mb_substr($trimmed, 0, 800, 'UTF-8'),
        ];
        $matchesInFile++;
        $count++;
        if ($matchesInFile >= $maxPerFile) break;
        if ($count >= $maxTotal) break 2;
      }
      fclose($fh);
    }
  } catch (Throwable $e) {}

  echo json_encode([
    'ok' => true,
    'query' => $q,
    'caseSensitive' => $caseSensitive,
    'base' => to_rel($baseAbs),
    'count' => $count,
    'truncated' => $count >= $maxTotal,
    'results' => $results
  ]);
  exit;
}

// File download (keep simple; no token needed)
if (isset($_GET['download']) && isset($_GET['f']) && is_authenticated()) {
  $rel = (string)$_GET['f']; $abs = secure_path($rel);
  if ($abs && is_file($abs)) {
    $filename = basename($abs);
    $mt = function_exists('mime_content_type') ? @mime_content_type($abs) : 'application/octet-stream';
    header('Content-Type: '.$mt);
    header('Content-Length: '.filesize($abs));
    header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs); exit;
  }
  http_response_code(404); exit;
}

// Folder download as temp zip in .trash (Cookie token approach)
if (isset($_GET['download_zip_folder']) && isset($_GET['f']) && is_authenticated()) {
  $rel = (string)$_GET['f']; $abs = secure_path($rel);
  $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
  if ($token !== '') {
    setcookie('fileDownloadToken', $token, [
      'expires' => time() + 60,
      'path' => '/',
      'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
  if ($abs && is_dir($abs)) {
    $trash = trash_abs();
    $zipName = basename($abs) . '_' . date('Ymd_His') . '.zip';
    $zipAbs = $trash . DIRECTORY_SEPARATOR . $zipName;
    $err=null;
    if (create_zip_from($abs, $zipAbs, $err)) {
      header('Content-Type: application/zip');
      header('Content-Length: '.filesize($zipAbs));
      header('Content-Disposition: attachment; filename="'.rawurlencode($zipName).'"');
      header('Cache-Control: private, max-age=0, must-revalidate');
      readfile($zipAbs); exit;
    } else {
      echo "Error creating zip: ".htmlspecialchars($err ?? 'unknown'); exit;
    }
  }
  http_response_code(404); exit;
}

// Auth + actions
if (isset($_GET['action']) && $_GET['action'] === 'logout') { logout_auth(); header('Location: ' . basename(__FILE__)); exit; }
if (!is_authenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
  if (try_authenticate()) { header('Location: ' . basename(__FILE__) . (isset($_GET['p'])?('?p='.rawurlencode($_GET['p'])):'')); exit; }
  flash_set('Password incorrect.'); header('Location: ' . basename(__FILE__)); exit;
}

// Determine folder
$rel = isset($_GET['p']) ? (string)$_GET['p'] : '';
$abs = secure_path($rel); if ($abs===false || !is_dir($abs)) { $rel=''; $abs=BASE_ROOT; }

// Operations (rename/zip/unzip/delete/emptytrash/newfile/newfolder/upload/imgbatch/savefile)
if (is_authenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op'])) {
  $op = (string)$_POST['op'];

  // ---- Empty trash
  if ($op === 'emptytrash') {
    $trash = trash_abs(); if (is_dir($trash)) { rrmdir($trash); }
    $msg = 'Trash emptied.';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>$msg]); exit; }
    flash_set($msg);
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }

  // ---- Upload (AJAX friendly, no overwrite allowed)
  if ($op === 'upload') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['ajax'] ?? '')==='1';
    $baseRel = (string)$_POST['base'];
    $baseAbs = secure_path($baseRel);
    if ($baseAbs === false || !is_dir($baseAbs)) {
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Invalid destination']); exit; }
      flash_set('Invalid destination.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    if (!isset($_FILES['upload_file'])) {
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'No file payload']); exit; }
      flash_set('No file payload.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $errCode = $_FILES['upload_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK) {
      $map = [
        UPLOAD_ERR_INI_SIZE=>'Server limit exceeded',
        UPLOAD_ERR_FORM_SIZE=>'Form limit exceeded',
        UPLOAD_ERR_PARTIAL=>'Partial upload',
        UPLOAD_ERR_NO_FILE=>'No file',
        UPLOAD_ERR_NO_TMP_DIR=>'Missing temp dir',
        UPLOAD_ERR_CANT_WRITE=>'Disk write failed',
        UPLOAD_ERR_EXTENSION=>'Blocked by extension'
      ];
      $msg = $map[$errCode] ?? ('Upload error #'.$errCode);
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
      flash_set($msg); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $orig = $_FILES['upload_file']['name'] ?? 'file';
    $name = preg_replace('/[^\w\.\-\s]/u','_', basename($orig));
    $name = ltrim($name,'.'); // disallow dot-files by trimming leading dots
    if ($name==='') {
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Invalid filename']); exit; }
      flash_set('Invalid filename.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $dest = $baseAbs . DIRECTORY_SEPARATOR . $name;
    if (file_exists($dest)) {
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'A file with this name already exists. Upload aborted.']); exit; }
      flash_set('A file with this name already exists. Upload aborted.');
      header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $tmp = $_FILES['upload_file']['tmp_name'];
    $ok = @move_uploaded_file($tmp, $dest);
    if (!$ok) {
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Cannot save uploaded file']); exit; }
      flash_set('Cannot save uploaded file.');
      header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'saved'=>basename($dest)]); exit; }
    flash_set('Uploaded: '.$name);
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }

  // ---- Image batch (no "item" needed)
  if ($op === 'imgbatch') {
    if (!extension_loaded('gd')) {
      flash_set('PHP GD extension not available. Cannot process images.');
      header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    @set_time_limit(120);

    $srcType = strtolower((string)($_POST['src_type'] ?? 'all')); // all|jpg|png|webp
    $dstType = strtolower((string)($_POST['dst_type'] ?? 'keep')); // keep|jpg|png|webp
    $doResize = isset($_POST['do_resize']);
    $keepAR   = isset($_POST['keep_ar']);
    $newW     = max(0, (int)($_POST['new_w'] ?? 0));
    $newH     = max(0, (int)($_POST['new_h'] ?? 0));
    $quality  = max(1, min(100, (int)($_POST['quality'] ?? 85)));
    $overwrite= isset($_POST['overwrite']);

    $entries_now = list_directory($abs);
    $processed = 0; $skipped = 0; $errors = 0;
    $msgs = [];

    foreach ($entries_now as $e) {
      if ($e['is_dir']) continue;
      $name = $e['name'];
      if (!is_image_ext($name)) continue;

      $extIn = ext_norm($name);
      if ($srcType !== 'all' && $extIn !== $srcType) continue;

      $src = $e['path'];
      $size = @getimagesize($src);
      if (!$size) { $skipped++; $msgs[] = "$name: not a bitmap image"; continue; }
      [$w0, $h0] = $size;

      $extOut = ($dstType === 'keep') ? $extIn : $dstType;

      // Determine target size
      $tw = $w0; $th = $h0;
      if ($doResize) {
        $tw = $newW > 0 ? $newW : $w0;
        $th = $newH > 0 ? $newH : $h0;
        if ($keepAR) {
          if ($newW > 0 && $newH > 0) {
            $scale = min($newW / $w0, $newH / $h0);
            $tw = max(1, (int)round($w0 * $scale));
            $th = max(1, (int)round($h0 * $scale));
          } elseif ($newW > 0) {
            $tw = $newW; $th = max(1, (int)round($h0 * ($newW / $w0)));
          } elseif ($newH > 0) {
            $th = $newH; $tw = max(1, (int)round($w0 * ($newH / $h0)));
          } else {
            $tw = $w0; $th = $h0;
          }
        } else {
          if ($newW === 0) $tw = $w0;
          if ($newH === 0) $th = $h0;
        }
      }

      $changingType = ($extOut !== $extIn);
      $changingSize = ($tw !== $w0 || $th !== $h0);
      if (!$changingType && !$changingSize && !$overwrite) { $skipped++; $msgs[] = "$name: no changes"; continue; }

      $err = null;
      $im = gd_load($src, $extIn, $err);
      if ($im === false) { $errors++; $msgs[] = "$name: $err"; continue; }

      if ($changingSize) {
        $dst = imagecreatetruecolor($tw, $th);
        if ($extOut === 'png' || $extOut === 'webp') {
          imagealphablending($dst, false);
          $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
          imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
          imagesavealpha($dst, true);
        } else {
          $white = imagecolorallocate($dst, 255, 255, 255);
          imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        }
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, imagesx($im), imagesy($im));
        imagedestroy($im);
        $outIm = $dst;
      } else {
        $outIm = $im;
      }

      $dir = dirname($src);
      $base = pathinfo($src, PATHINFO_FILENAME);
      if ($overwrite) {
        $outPath = ($changingType) ? ($dir.DIRECTORY_SEPARATOR.$base.'.'.$extOut) : $src;
      } else {
        $candidate = $dir.DIRECTORY_SEPARATOR.$base.'_conv'.'.'.$extOut;
        if (file_exists($candidate)) $candidate = $dir.DIRECTORY_SEPARATOR.$base.'_conv_'.time().'.'.$extOut;
        $outPath = $candidate;
      }

      $saveErr = null;
      $ok = gd_save($outIm, $outPath, $extOut, $quality, $saveErr);
      imagedestroy($outIm);

      if (!$ok) { $errors++; $msgs[] = "$name: $saveErr"; continue; }

      if ($overwrite && $changingType && is_file($src) && $outPath !== $src) {
        @unlink($src);
      }

      $processed++;
    }

    $summary = "Images processed: $processed";
    if ($skipped) $summary .= " | Skipped: $skipped";
    if ($errors)  $summary .= " | Errors: $errors";
    if (!empty($msgs)) {
      $maxMsgs = 10;
      $preview = implode("; ", array_slice($msgs, 0, $maxMsgs));
      if (count($msgs) > $maxMsgs) $preview .= " ...";
      $summary .= " — " . $preview;
    }
    flash_set($summary);
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }

  // ---- Save edited text file
  if ($op === 'savefile') {
    $itemRel = (string)($_POST['item'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $itemAbs = secure_path($itemRel);
    if ($itemAbs === false || !is_file($itemAbs) || !is_text_ext($itemAbs)) {
      flash_set('Invalid file for save.');
      header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    if (!is_writable($itemAbs)) {
      flash_set('File is not writable.');
      header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $ok = @file_put_contents($itemAbs, $content);
    if ($ok === false) {
      flash_set('Save failed.');
    } else {
      @touch($itemAbs, time());
      flash_set('File saved: '.basename($itemAbs));
    }
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }

  // ---- Create file/folder
  if ($op === 'newfile' || $op === 'newfolder') {
    $baseRel = (string)($_POST['base'] ?? '');
    $newName = trim((string)($_POST['newname'] ?? ''));
    $baseAbs = secure_path($baseRel);
    if ($newName === '' || strpos($newName,'/')!==false || strpos($newName,'\\')!==false) {
      flash_set('Invalid name.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    if ($baseAbs === false || !is_dir($baseAbs)) {
      flash_set('Invalid destination.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
    }
    $dest = $baseAbs . DIRECTORY_SEPARATOR . $newName;
    if (file_exists($dest)) { flash_set('Target already exists.'); header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit; }
    if ($op === 'newfile') {
      $ok = @file_put_contents($dest, '') !== false;
      flash_set($ok ? ('Created file: '.$newName) : 'Cannot create file.');
    } else {
      $ok = @mkdir($dest, 0755, true);
      flash_set($ok ? ('Created folder: '.$newName) : 'Cannot create folder.');
    }
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }

  // ---- Remaining ops require an "item"
  if (!isset($_POST['item'])) {
    flash_set('No item specified.');
    header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
  }
  $itemRel = (string)$_POST['item']; $itemAbs = secure_path($itemRel);
  if ($itemAbs === false || !file_exists($itemAbs)) {
    flash_set('Invalid item.');
  } else {
    if ($op === 'unzip') {
      if (is_file($itemAbs) && strtolower(pathinfo($itemAbs, PATHINFO_EXTENSION))==='zip') {
        $err=null; $ok=unzip_to_folder_and_delete($itemAbs, $err);
        flash_set($ok ? 'Unzipped and deleted archive.' : ('Unzip error: '.($err??'unknown')));
      } else flash_set('Selected item is not a .zip file.');
    } elseif ($op === 'zip') {
      $zipName = trim((string)($_POST['zipname'] ?? ''));
      if ($zipName==='') $zipName = basename($itemAbs).'.zip';
      elseif (!preg_match('/^[A-Za-z0-9 _\-\.\(\)]+\.zip$/',$zipName)) { flash_set('Invalid zip file name.'); $zipName=''; }
      if ($zipName!==''){
        $destAbs = dirname($itemAbs).DIRECTORY_SEPARATOR.$zipName;
        if (file_exists($destAbs)) { $pi=pathinfo($destAbs); $destAbs=$pi['dirname'].DIRECTORY_SEPARATOR.$pi['filename'].'_'.time().'.zip'; }
        $err=null; $ok=create_zip_from($itemAbs,$destAbs,$err);
        flash_set($ok ? ('Created zip: '.basename($destAbs)) : ('Zip error: '.($err??'unknown')));
      }
    } elseif ($op === 'rename') {
      $newName = trim((string)($_POST['newname'] ?? ''));
      $err=null; $ok = ($newName!=='') ? rename_item($itemAbs,$newName,$err) : false;
      flash_set($ok ? 'Renamed successfully.' : ('Rename error: '.($err??'unknown')));
    } elseif ($op === 'delete') {
      $err=null; $ok=delete_item($itemAbs,$err);
      flash_set($ok ? (is_in_trash($itemAbs)?'Permanently deleted.':'Moved to trash.') : ('Delete error: '.($err??'unknown')));
    } else {
      if ($op !== 'savefile' && $op !== 'imgbatch' && $op !== 'newfile' && $op !== 'newfolder' && $op !== 'upload' && $op !== 'emptytrash') {
        flash_set('Unknown operation.');
      }
    }
  }
  header('Location: ' . basename(__FILE__) . ($rel!==''?'?p='.rawurlencode($rel):'')); exit;
}

// Sorting
$sort = $_GET['sort'] ?? 'name'; if(!in_array($sort,['name','size','date'])) $sort='name';
$dir  = $_GET['dir']  ?? 'asc';  if(!in_array($dir, ['asc','desc'])) $dir='asc';

// Data + totals (for usage bar)
$entries = list_directory($abs);
sort_entries($entries,$sort,$dir);
$total_bytes = 0;
$has_images = false;
foreach ($entries as $en) {
  $total_bytes += max(0, (int)$en['size']);
  if (!$en['is_dir'] && is_image_ext($en['name'])) $has_images = true;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Zip Manager & File Browser</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .table td, .table th { vertical-align: middle; }
    #pageOverlay { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.45); display: none; }
    #pageOverlay.show { display: block; }
    body.no-scroll { overflow: hidden; }
    #imgOverlay { position: fixed; inset: 0; z-index: 2100; background: rgba(0,0,0,.9); display: none; align-items: center; justify-content: center; }
    #imgOverlay.show { display: flex; }
    #imgOverlay img { max-width: 95vw; max-height: 95vh; box-shadow: 0 0 32px rgba(0,0,0,.8); }
    .thumb { width: 42px; height: 42px; object-fit: cover; border-radius: .25rem; border: 1px solid rgba(0,0,0,.1); cursor: pointer; }
    .name-cell { display:flex; align-items:center; gap:.4rem; }
    .icon-btn { border: 0; background: transparent; padding: .08rem .14rem; line-height: 1; }
    .icon-row { display:flex; align-items:center; gap:.15rem; }
    .usage-wrap { width: 100%; height: .6rem; background: rgba(0,0,0,.075); border-radius: .25rem; overflow: hidden; }
    .usage-bar { height: 100%; background: #0d6efd; }
    .editor-wrap { height: 70vh; border: 1px solid rgba(0,0,0,.125); border-radius: .25rem; }
    #aceEditor { width: 100%; height: 100%; }
    /* Grep results */
    #grepResultsWrap { display:none; }
    .grep-line { white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .grep-item .badge { font-weight: 500; }
  </style>
</head>
<body class="bg-light">
  <div id="pageOverlay" aria-hidden="true">
    <div class="d-flex w-100 h-100 align-items-center justify-content-center">
      <div class="text-center">
        <div class="spinner-border" role="status" style="width:4rem;height:4rem;"></div>
        <div class="mt-3 text-white fw-semibold">Please wait…</div>
      </div>
    </div>
  </div>

  <div id="imgOverlay" aria-hidden="true">
    <img id="imgOverlayImg" src="" alt="preview">
  </div>

  <!-- Upload Modal -->
  <div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="uploadForm" enctype="multipart/form-data" method="post">
        <input type="hidden" name="op" value="upload">
        <input type="hidden" name="base" value="<?=htmlspecialchars(to_rel($abs))?>">
        <input type="hidden" name="ajax" value="1">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-upload"></i> Upload a file</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Choose file</label>
            <input type="file" class="form-control" name="upload_file" id="uploadFile" required>
            <div class="form-text">If a file with the same name exists, the upload will be rejected.</div>
          </div>
          <div class="progress" style="height: 10px; display:none;" id="uploadProgressWrap">
            <div class="progress-bar" id="uploadProgressBar" style="width:0%"></div>
          </div>
          <div class="small mt-2 text-muted" id="uploadStatus" style="display:none;">0%</div>
          <div class="alert alert-danger mt-2 py-2 px-3" id="uploadError" style="display:none;"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="uploadStartBtn"><i class="bi bi-cloud-arrow-up"></i> Start upload</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Editor Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <form class="modal-content" id="editForm" method="post">
        <input type="hidden" name="op" value="savefile">
        <input type="hidden" name="item" id="editItem" value="">
        <textarea name="content" id="editContent" style="display:none;"></textarea>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-code-slash"></i> Edit: <span id="editTitle" class="mono"></span></h5>
          <div class="ms-auto d-flex align-items-center gap-2">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="toggleWrap">
              <label class="form-check-label" for="toggleWrap">Wrap</label>
            </div>
            <button type="button" class="btn btn-light btn-sm" id="btnEditorReload" title="Reload from disk"><i class="bi bi-arrow-clockwise"></i></button>
            <button type="submit" class="btn btn-primary btn-sm" id="btnEditorSave"><i class="bi bi-save"></i> Save (Ctrl+S)</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
        <div class="modal-body">
          <div class="editor-wrap"><div id="aceEditor"></div></div>
          <div class="small text-muted mt-2">
            Tip: Use Ctrl/Cmd+S to save. Some very large files are not supported by the inline editor.
          </div>
          <div class="alert alert-danger mt-2 py-2 px-3 d-none" id="editError"></div>
        </div>
      </form>
    </div>
  </div>

  <!-- hidden iframe (still used for folder zip downloads) -->
  <iframe id="dlFrame" style="display:none;"></iframe>

  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Zip Manager & File Browser</h1>
      <?php if (is_authenticated()): ?>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-secondary" data-folder-link href="?">Root</a>
          <form method="post" class="d-inline" id="emptyTrashForm">
            <input type="hidden" name="op" value="emptytrash">
            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash3"></i> Empty Trash</button>
          </form>
          <a class="btn btn-sm btn-outline-secondary" href="?action=logout">Logout</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($m = flash_get()): ?>
      <div class="alert alert-info"><?=htmlspecialchars($m)?></div>
    <?php endif; ?>

    <?php if (!is_authenticated()): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <form method="post" class="row g-3" id="loginForm">
            <div class="col-12">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-auto">
              <button class="btn btn-primary" id="loginBtn">Login</button>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <?php
        $relNow = to_rel($abs); $upRel = '';
        if ($relNow !== '') { $parts = explode('/', $relNow); array_pop($parts); $upRel = implode('/', $parts); }
      ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <?=breadcrumbs($relNow)?>

          <!-- Grep search row -->
          <form class="row g-2 align-items-center mb-3" id="grepForm">
            <div class="col-12 col-md">
              <input type="text" class="form-control" id="grepInput" placeholder="Search recursively from current folder (txt/php/html/css/js)…" autocomplete="off">
            </div>
            <div class="col-auto">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="grepCS">
                <label class="form-check-label" for="grepCS">Case sensitive</label>
              </div>
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
            </div>
            <div class="col-auto">
              <button type="button" class="btn btn-outline-secondary" id="grepClear"><i class="bi bi-x-circle"></i> Clear</button>
            </div>
          </form>

          <div id="grepResultsWrap" class="card border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <div><i class="bi bi-list-check"></i> Search results</div>
              <div class="small text-muted"><span id="grepCount">0</span> matches</div>
            </div>
            <div class="list-group list-group-flush" id="grepList"></div>
            <div class="card-footer small text-muted d-none" id="grepTrunc">Results truncated.</div>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div><strong>Current:</strong> <span class="mono"><?=htmlspecialchars($abs)?></span></div>
            <div class="d-flex gap-2">
              <!-- Upload (comes BEFORE new file/new folder) -->
              <button class="btn btn-sm btn-success" type="button" id="btnUpload"><i class="bi bi-upload"></i> Upload</button>

              <!-- New file / New folder buttons -->
              <form method="post" class="d-inline" data-opform="newfile">
                <input type="hidden" name="op" value="newfile">
                <input type="hidden" name="base" value="<?=htmlspecialchars($relNow)?>">
                <input type="hidden" name="newname" value="">
                <button class="btn btn-sm btn-outline-primary" type="submit">
                  <i class="bi bi-file-earmark-plus"></i> New file
                </button>
              </form>
              <form method="post" class="d-inline" data-opform="newfolder">
                <input type="hidden" name="op" value="newfolder">
                <input type="hidden" name="base" value="<?=htmlspecialchars($relNow)?>">
                <input type="hidden" name="newname" value="">
                <button class="btn btn-sm btn-outline-primary" type="submit">
                  <i class="bi bi-folder-plus"></i> New folder
                </button>
              </form>

              <!-- Up button -->
              <?php if ($relNow !== ''): ?>
                <a class="btn btn-sm btn-outline-secondary" data-folder-link href="?p=<?=rawurlencode($upRel)?>">⬆️ Up</a>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary" disabled>⬆️ Up</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Folders & Files</span>
          <span class="text-muted small"><?=count($entries)?> items</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:18%">Actions</th>
                  <th style="width:42%"><a href="?<?= 'sort=name&dir='.(($sort==='name'&&$dir==='asc')?'desc':'asc').($rel!==''?'&p='.rawurlencode($rel):'') ?>" class="text-decoration-none" data-folder-link>Name <?= $sort==='name' ? ($dir==='asc'?'▲':'▼') : '' ?></a></th>
                  <th style="width:20%"><a href="?<?= 'sort=date&dir='.(($sort==='date'&&$dir==='asc')?'desc':'asc').($rel!==''?'&p='.rawurlencode($rel):'') ?>" class="text-decoration-none" data-folder-link>Modified <?= $sort==='date' ? ($dir==='asc'?'▲':'▼') : '' ?></a></th>
                  <th style="width:15%"><a href="?<?= 'sort=size&dir='.(($sort==='size'&&$dir==='asc')?'desc':'asc').($rel!==''?'&p='.rawurlencode($rel):'') ?>" class="text-decoration-none" data-folder-link>Size <?= $sort==='size' ? ($dir==='asc'?'▲':'▼') : '' ?></a></th>
                  <th style="width:5%">Usage</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($entries)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">Empty folder</td></tr>
                <?php else: foreach ($entries as $e):
                  $name   = $e['name'];
                  $isDir  = $e['is_dir'];
                  $mtime  = $e['mtime'] ? date('Y-m-d H:i:s', $e['mtime']) : '-';
                  $size   = (int)$e['size'];
                  $size_h = size_human($size);
                  $nextRel= $e['rel'];
                  $isImg  = (!$isDir && is_image_ext($name));
                  $isText = (!$isDir && is_text_ext($name));
                  $previewUrl = '?preview=1&f='.rawurlencode($nextRel);
                  $isZip  = (!$isDir && strtolower(pathinfo($name, PATHINFO_EXTENSION))==='zip');
                  $pct    = ($total_bytes>0) ? max(0, min(100, round($size*100/$total_bytes))) : 0;
                ?>
                  <tr>
                    <td class="mono">
                      <div class="icon-row">
                        <?php if (!$isDir): ?>
                          <a class="icon-btn" title="Download" href="?download=1&f=<?=rawurlencode($nextRel)?>" data-download>
                            <i class="bi bi-download"></i>
                          </a>
                        <?php else: ?>
                          <a class="icon-btn" title="Download (zip)" href="#" data-download-folder data-rel="<?=htmlspecialchars($nextRel)?>" data-name="<?=htmlspecialchars($name)?>">
                            <i class="bi bi-download"></i>
                          </a>
                        <?php endif; ?>

                        <?php if ($isText): ?>
                          <button type="button" class="icon-btn" title="Edit" data-edit data-rel="<?=htmlspecialchars($nextRel)?>" data-name="<?=htmlspecialchars($name)?>">
                            <i class="bi bi-code-slash"></i>
                          </button>
                        <?php endif; ?>

                        <form method="post" class="d-inline" data-opform="rename">
                          <input type="hidden" name="op" value="rename">
                          <input type="hidden" name="item" value="<?=htmlspecialchars($nextRel)?>">
                          <input type="hidden" name="newname" value="">
                          <button type="submit" class="icon-btn" title="Rename"><i class="bi bi-pencil-square"></i></button>
                        </form>

                        <form method="post" class="d-inline" data-opform="zip">
                          <input type="hidden" name="op" value="zip">
                          <input type="hidden" name="item" value="<?=htmlspecialchars($nextRel)?>">
                          <input type="hidden" name="zipname" value="">
                          <button type="submit" class="icon-btn" title="Zip"><i class="bi bi-file-earmark-zip"></i></button>
                        </form>

                        <form method="post" class="d-inline" data-opform="unzip">
                          <input type="hidden" name="op" value="unzip">
                          <input type="hidden" name="item" value="<?=htmlspecialchars($nextRel)?>">
                          <button type="submit" class="icon-btn" title="Unzip" <?= $isZip ? '' : 'disabled' ?>>
                            <i class="bi bi-archive"></i>
                          </button>
                        </form>

                        <form method="post" class="d-inline" data-opform="delete" data-isdir="<?=$isDir?'1':'0'?>" data-name="<?=htmlspecialchars($name)?>" data-intrash="<?=is_in_trash($e['path'])?'1':'0'?>">
                          <input type="hidden" name="op" value="delete">
                          <input type="hidden" name="item" value="<?=htmlspecialchars($nextRel)?>">
                          <button type="submit" class="icon-btn" title="Delete">
                            <i class="bi bi-trash text-danger"></i>
                          </button>
                        </form>
                      </div>
                    </td>

                    <td class="mono">
                      <div class="name-cell">
                        <?php if ($isDir): ?>
                          <span>📁</span>
                          <a data-folder-link href="?p=<?=rawurlencode($nextRel)?>"><?=htmlspecialchars($name)?></a>
                        <?php else: ?>
                          <?php if ($isImg): ?>
                            <img class="thumb" src="<?=$previewUrl?>" alt="" data-img="<?=$previewUrl?>">
                          <?php else: ?>
                            <span>📄</span>
                          <?php endif; ?>
                          <span><?=htmlspecialchars($name)?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><span class="mono"><?=$mtime?></span></td>
                    <td><span class="mono"><?=htmlspecialchars($size_h)?></span></td>
                    <td>
                      <div class="usage-wrap" title="<?=$pct?>%">
                        <div class="usage-bar" style="width: <?=$pct?>%;"></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-footer">
          <div class="small text-muted d-flex flex-wrap gap-3">
            <span><i class="bi bi-download"></i> download</span>
            <span><i class="bi bi-code-slash"></i> edit</span>
            <span><i class="bi bi-pencil-square"></i> rename</span>
            <span><i class="bi bi-file-earmark-zip"></i> zip</span>
            <span><i class="bi bi-archive"></i> unzip</span>
            <span><i class="bi bi-trash text-danger"></i> delete</span>
          </div>
        </div>
      </div>

      <?php if ($has_images): ?>
      <!-- Image batch form -->
      <div class="card shadow-sm mt-3">
        <div class="card-header">Image tools</div>
        <div class="card-body">
          <form method="post" class="row gy-3 align-items-end" data-opform="imgbatch" id="imgBatchForm">
            <input type="hidden" name="op" value="imgbatch">
            <div class="col-12 col-md-3">
              <label class="form-label">Source file type</label>
              <select name="src_type" class="form-select">
                <option value="all">All</option>
                <option value="jpg">JPG</option>
                <option value="png">PNG</option>
                <option value="webp">WebP</option>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Destination file type</label>
              <select name="dst_type" class="form-select">
                <option value="keep">Don't change</option>
                <option value="jpg">JPG</option>
                <option value="png">PNG</option>
                <option value="webp">WebP</option>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="doResize" name="do_resize">
                <label class="form-check-label" for="doResize">Resize</label>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="keepAR" name="keep_ar" checked>
                <label class="form-check-label" for="keepAR">Keep Aspect Ratio</label>
              </div>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">New Width</label>
              <input type="number" class="form-control" name="new_w" min="1" placeholder="e.g. 1200">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">New Height</label>
              <input type="number" class="form-control" name="new_h" min="1" placeholder="e.g. 800">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Save quality</label>
              <input type="number" class="form-control" name="quality" min="1" max="100" value="85">
              <div class="form-text">1–100 (PNG maps to compression)</div>
            </div>

            <div class="col-6 col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite">
                <label class="form-check-label" for="overwrite">Rewrite original files</label>
              </div>
            </div>

            <div class="col-12 col-md-3 text-md-end">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-magic"></i> Run
              </button>
            </div>
          </form>
          <div class="small text-muted mt-2">
            Supported inputs: JPG/PNG/GIF/WebP. Outputs: JPG/PNG/WebP (requires PHP GD).
          </div>
        </div>
      </div>
      <?php endif; ?>

    <?php endif; ?>

    <footer class="mt-4 text-muted small"><?=htmlspecialchars(date('Y-m-d H:i'))?></footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Single-CDN editor (Ace) -->
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.32.9/src-min-noconflict/ace.js"></script>
  <script>
    (function(){
      const overlay = document.getElementById('pageOverlay');
      const imgOverlay = document.getElementById('imgOverlay');
      const imgOverlayImg = document.getElementById('imgOverlayImg');
      const dlFrame = document.getElementById('dlFrame');

      function showOverlay(){ overlay.classList.add('show'); document.body.classList.add('no-scroll'); }
      function hideOverlay(){ overlay.classList.remove('show'); document.body.classList.remove('no-scroll'); }

      function getCookie(name){
        const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\\]\\\\])/g, '\\\\$1') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
      }
      function makeToken(){ return Date.now().toString(36) + Math.random().toString(36).slice(2); }

      // Empty trash confirmation
      const emptyTrashForm = document.getElementById('emptyTrashForm');
      if (emptyTrashForm) {
        emptyTrashForm.addEventListener('submit', function(e){
          if (!confirm('Empty trash and delete the .trash folder?')) { e.preventDefault(); return false; }
          showOverlay();
        });
      }

      // Folder navigation and sort headers
      document.querySelectorAll('[data-folder-link]').forEach(a=>{
        a.addEventListener('click', ()=>{ showOverlay(); });
      });

      // File download: keep simple (no overlay)
      document.querySelectorAll('[data-download]').forEach(a=>{
        a.addEventListener('click', ()=>{ /* no overlay to avoid any stuck UI */ });
      });

      // Folder download with cookie token detection
      document.querySelectorAll('[data-download-folder]').forEach(a=>{
        a.addEventListener('click', (ev)=>{
          ev.preventDefault();
          const rel = a.getAttribute('data-rel');
          const name = a.getAttribute('data-name') || 'folder';
          if (!confirm('Create a temporary ZIP in .trash for "'+name+'" and download it?')) return;

          const token = makeToken();
          showOverlay();
          const start = Date.now();
          const timer = setInterval(()=>{
            const v = getCookie('fileDownloadToken');
            if (v === token) {
              clearInterval(timer);
              document.cookie = 'fileDownloadToken=; Max-Age=0; Path=/';
              hideOverlay();
            } else {
              if (Date.now() - start > 120000) {
                clearInterval(timer);
                hideOverlay();
              }
            }
          }, 400);

          const url = '?download_zip_folder=1&f=' + encodeURIComponent(rel) + '&token=' + encodeURIComponent(token);
          dlFrame.src = url;
        });
      });

      // Image modal
      document.querySelectorAll('img.thumb').forEach(img=>{
        img.addEventListener('click', ()=>{
          const src = img.getAttribute('data-img') || img.src;
          imgOverlayImg.src = src;
          imgOverlay.classList.add('show'); document.body.classList.add('no-scroll');
        });
      });
      imgOverlay.addEventListener('click', ()=>{ imgOverlay.classList.remove('show'); imgOverlayImg.src=''; document.body.classList.remove('no-scroll'); });
      document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && imgOverlay.classList.contains('show')) imgOverlay.click(); });

      // Upload button -> open modal
      const btnUpload = document.getElementById('btnUpload');
      const uploadModalEl = document.getElementById('uploadModal');
      const uploadModal = uploadModalEl ? new bootstrap.Modal(uploadModalEl) : null;
      if (btnUpload && uploadModal) {
        btnUpload.addEventListener('click', ()=> {
          const bar = document.getElementById('uploadProgressBar');
          const wrap = document.getElementById('uploadProgressWrap');
          const status = document.getElementById('uploadStatus');
          const errBox = document.getElementById('uploadError');
          if (bar) bar.style.width = '0%';
          if (wrap) wrap.style.display = 'none';
          if (status) { status.style.display = 'none'; status.textContent = '0%'; }
          if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
          const fileInput = document.getElementById('uploadFile');
          if (fileInput) fileInput.value = '';
          uploadModal.show();
        });
      }

      // AJAX upload with progress; rejects overwrites server-side
      const uploadForm = document.getElementById('uploadForm');
      if (uploadForm) {
        uploadForm.addEventListener('submit', function(e){
          e.preventDefault();
          const fileInput = document.getElementById('uploadFile');
          if (!fileInput || !fileInput.files || fileInput.files.length === 0) return;

          const formData = new FormData(uploadForm);
          const xhr = new XMLHttpRequest();
          const bar = document.getElementById('uploadProgressBar');
          const wrap = document.getElementById('uploadProgressWrap');
          const status = document.getElementById('uploadStatus');
          const errBox = document.getElementById('uploadError');
          const btn = document.getElementById('uploadStartBtn');

          if (wrap) wrap.style.display = 'block';
          if (status) { status.style.display = 'block'; status.textContent = '0%'; }
          if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading…'; }

          xhr.upload.addEventListener('progress', function(ev){
            if (ev.lengthComputable) {
              const pct = Math.round((ev.loaded / ev.total) * 100);
              if (bar) bar.style.width = pct + '%';
              if (status) status.textContent = pct + '%';
            }
          });
          xhr.onreadystatechange = function(){
            if (xhr.readyState === 4) {
              if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-arrow-up"></i> Start upload'; }
              try {
                const res = JSON.parse(xhr.responseText || '{}');
                if (xhr.status === 200 && res.ok) {
                  uploadModal.hide();
                  showOverlay();
                  location.reload();
                } else {
                  const msg = (res && res.error) ? res.error : ('HTTP ' + xhr.status + ' error');
                  if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
                }
              } catch(ex) {
                if (errBox) { errBox.textContent = 'Upload error.'; errBox.style.display = 'block'; }
              }
            }
          };
          xhr.open('POST', window.location.pathname + window.location.search);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.send(formData);
        });
      }

      // -------- Inline editor (Ace) ----------
      let aceEditor = null;
      const aceBase = 'https://cdn.jsdelivr.net/npm/ace-builds@1.32.9/src-min-noconflict/';
      if (window.ace) {
        window.ace.config.set('basePath', aceBase);
      }
      const editModalEl = document.getElementById('editModal');
      const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;
      const editTitle = document.getElementById('editTitle');
      const editItem  = document.getElementById('editItem');
      const editError = document.getElementById('editError');
      const btnReload = document.getElementById('btnEditorReload');
      const btnSave   = document.getElementById('btnEditorSave');
      const toggleWrap= document.getElementById('toggleWrap');
      const hiddenContent = document.getElementById('editContent');
      let pendingGotoLine = null;

      function modeFromExt(ext){
        ext = (ext||'').toLowerCase();
        const map = {
          'php':'php','phtml':'php','inc':'php',
          'htm':'html','html':'html',
          'css':'css',
          'js':'javascript','mjs':'javascript','jsx':'jsx','ts':'typescript','tsx':'tsx',
          'json':'json',
          'md':'markdown','markdown':'markdown',
          'xml':'xml','svg':'xml',
          'yml':'yaml','yaml':'yaml',
          'ini':'ini','conf':'ini','config':'ini','env':'ini',
          'sh':'sh','bash':'sh','zsh':'sh',
          'py':'python','rb':'ruby','pl':'perl',
          'c':'c_cpp','h':'c_cpp','cpp':'c_cpp','hpp':'c_cpp','cc':'c_cpp','hh':'c_cpp',
          'java':'java','cs':'csharp',
          'sql':'sql',
          'txt':'text','log':'text','csv':'text','tsv':'text','vue':'vue','svelte':'svelte'
        };
        if (!ext) return 'text';
        return map[ext] || 'text';
      }

      function ensureAceMode(mode, cb){
        try {
          if (ace.require && ace.require('ace/mode/'+mode)) { cb && cb(); return; }
        } catch(e){}
        const id = 'ace-mode-'+mode;
        if (document.getElementById(id)) { document.getElementById(id).addEventListener('load', ()=>cb && cb()); return; }
        const s = document.createElement('script');
        s.src = aceBase + 'mode-' + mode + '.js';
        s.id = id;
        s.onload = ()=> cb && cb();
        s.onerror = ()=> cb && cb();
        document.head.appendChild(s);
      }

      function openEditor(rel, name, line){
        if (!editModal) return;
        if (editError) { editError.classList.add('d-none'); editError.textContent=''; }
        if (editTitle) editTitle.textContent = name || rel || '';
        if (editItem) editItem.value = rel || '';
        pendingGotoLine = Number.isInteger(line) ? line : null;

        showOverlay();
        fetch('?search=0&read=1&f=' + encodeURIComponent(rel), {headers:{'Accept':'application/json'}})
          .then(r => r.json())
          .then(data => {
            if (!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Read error');
            if (!aceEditor) {
              aceEditor = ace.edit('aceEditor');
              aceEditor.setOptions({ fontSize: '13px', showPrintMargin: false, useSoftTabs: true, tabSize: 2 });
              aceEditor.session.setUseWrapMode(false);
            }
            aceEditor.setValue(data.content || '', -1);
            const mode = modeFromExt(data.ext || '');
            ensureAceMode(mode, ()=> {
              try { aceEditor.session.setMode('ace/mode/' + mode); } catch(ex){}
              if (pendingGotoLine) {
                try { aceEditor.gotoLine(pendingGotoLine, 0, true); } catch(e){}
              }
            });
            if (toggleWrap) toggleWrap.checked = false;
            if (btnSave) btnSave.disabled = !data.writable;
            editModal.show();
          })
          .catch(err => {
            if (editError) { editError.textContent = err.message || 'Error opening file'; editError.classList.remove('d-none'); }
          })
          .finally(()=> hideOverlay());
      }

      document.querySelectorAll('[data-edit]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const rel = btn.getAttribute('data-rel');
          const name= btn.getAttribute('data-name') || rel.split('/').pop();
          openEditor(rel, name);
        });
      });

      if (toggleWrap) {
        toggleWrap.addEventListener('change', ()=>{
          if (aceEditor) aceEditor.session.setUseWrapMode(!!toggleWrap.checked);
        });
      }

      if (btnReload) {
        btnReload.addEventListener('click', ()=>{
          const rel = editItem.value;
          const name = editTitle.textContent || rel.split('/').pop();
          openEditor(rel, name, pendingGotoLine || null);
        });
      }

      // Submit save: push editor content into hidden textarea
      const editForm = document.getElementById('editForm');
      if (editForm) {
        editForm.addEventListener('submit', (e)=>{
          if (!aceEditor) return;
          hiddenContent.value = aceEditor.getValue();
          showOverlay();
        });
      }

      // Ctrl/Cmd+S to save
      document.addEventListener('keydown', function(e){
        const isMac = navigator.platform.toUpperCase().indexOf('MAC')>=0;
        if ((isMac ? e.metaKey : e.ctrlKey) && e.key.toLowerCase() === 's') {
          if (editModalEl && editModalEl.classList.contains('show')) {
            e.preventDefault();
            if (editForm) editForm.requestSubmit();
          }
        }
      });

      // ---------- GREP UI ----------
      const grepForm = document.getElementById('grepForm');
      const grepInput = document.getElementById('grepInput');
      const grepCS = document.getElementById('grepCS');
      const grepClear = document.getElementById('grepClear');
      const grepWrap = document.getElementById('grepResultsWrap');
      const grepList = document.getElementById('grepList');
      const grepCount = document.getElementById('grepCount');
      const grepTrunc = document.getElementById('grepTrunc');

      function esc(s){
        return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
      }
      function regexEscape(s){ return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
      function highlight(line, q, cs){
        if (!q) return esc(line);
        try {
          const re = new RegExp(regexEscape(q), cs ? 'g' : 'gi');
          return esc(line).replace(re, m => `<mark>${esc(m)}</mark>`);
        } catch(e){ return esc(line); }
      }

      function renderGrep(data, q){
        if (!grepWrap || !grepList || !grepCount) return;
        grepList.innerHTML = '';
        if (!data || !data.ok) {
          grepWrap.style.display = 'none';
          return;
        }
        const res = data.results || [];
        grepCount.textContent = data.count || res.length || 0;
        if (grepTrunc) grepTrunc.classList.toggle('d-none', !data.truncated);
        if (res.length === 0) {
          grepList.innerHTML = `<div class="list-group-item text-muted">No matches.</div>`;
          grepWrap.style.display = 'block';
          return;
        }
        const frag = document.createDocumentFragment();
        res.forEach(r=>{
          const item = document.createElement('div');
          item.className = 'list-group-item grep-item';
          item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
              <div class="mono">
                <span class="badge bg-light text-dark border">L${r.line_no}</span>
                <span class="text-secondary">/</span>
                <span class="text-primary">${esc(r.rel || r.name || '')}</span>
              </div>
              <div>
                <button class="btn btn-sm btn-outline-primary" data-edit-rel="${esc(r.rel)}" data-edit-name="${esc(r.name)}" data-edit-line="${r.line_no}">
                  <i class="bi bi-pencil-square"></i> Edit
                </button>
              </div>
            </div>
            <div class="grep-line mt-2">${highlight(r.line || '', q, !!data.caseSensitive)}</div>
          `;
          frag.appendChild(item);
        });
        grepList.appendChild(frag);
        grepWrap.style.display = 'block';
      }

      if (grepForm) {
        grepForm.addEventListener('submit', (e)=>{
          e.preventDefault();
          const q = (grepInput && grepInput.value) ? grepInput.value.trim() : '';
          if (!q) return;
          const cs = grepCS && grepCS.checked ? 1 : 0;
          const params = new URLSearchParams({search:'1', q, base:'<?=htmlspecialchars(to_rel($abs))?>', cs:String(cs)});
          showOverlay();
          fetch('?' + params.toString(), {headers:{'Accept':'application/json'}})
            .then(r=>r.json())
            .then(data=> renderGrep(data, q))
            .catch(()=> { renderGrep({ok:false}, q); })
            .finally(()=> hideOverlay());
        });
      }
      if (grepClear) {
        grepClear.addEventListener('click', ()=>{
          if (grepInput) grepInput.value = '';
          if (grepWrap) grepWrap.style.display = 'none';
          if (grepList) grepList.innerHTML = '';
        });
      }
      if (grepList) {
        grepList.addEventListener('click', (e)=>{
          const btn = e.target.closest('button[data-edit-rel]');
          if (!btn) return;
          const rel = btn.getAttribute('data-edit-rel');
          const name = btn.getAttribute('data-edit-name') || (rel ? rel.split('/').pop() : '');
          const line = parseInt(btn.getAttribute('data-edit-line') || '0', 10) || null;
          openEditor(rel, name, line);
        });
      }

      // Operation forms: rename/zip/unzip/delete/newfile/newfolder/imgbatch
      document.querySelectorAll('form[data-opform]').forEach(f=>{
        f.addEventListener('submit', (ev)=>{
          const type = f.getAttribute('data-opform');

          if (type === 'delete') {
            const isDir   = f.getAttribute('data-isdir') === '1';
            const inTrash = f.getAttribute('data-intrash') === '1';
            const name    = f.getAttribute('data-name') || '';
            const isTrashFolder = (name === '.trash') && isDir;

            if (isTrashFolder) {
              if (!confirm('Empty trash and delete the .trash folder?')) { ev.preventDefault(); return false; }
              const opInput = f.querySelector('input[name="op"]');
              if (opInput) opInput.value = 'emptytrash';
              const itemInput = f.querySelector('input[name="item"]');
              if (itemInput) itemInput.remove();
            } else {
              if (isDir) {
                const msg = inTrash
                  ? 'Permanently delete folder "'+name+'" from .trash? This cannot be undone.'
                  : 'Move folder "'+name+'" to root/.trash ?';
                if (!confirm(msg)) { ev.preventDefault(); return false; }
              } else {
                if (!confirm('Delete file "'+name+'"?')) { ev.preventDefault(); return false; }
              }
            }
          }

          if (type === 'rename') {
            const cur = (f.querySelector('input[name="item"]')?.value || '').split('/').pop();
            const nn = prompt('New name:', cur || '');
            if (!nn) { ev.preventDefault(); return false; }
            f.querySelector('input[name="newname"]').value = nn;
          }

          if (type === 'zip') {
            const item = f.querySelector('input[name="item"]').value;
            const base = item.split('/').pop();
            const suggestion = base + '.zip';
            const zn = prompt('Zip filename (.zip):', suggestion);
            if (zn === null) { ev.preventDefault(); return false; }
            if (zn.trim() !== '') f.querySelector('input[name="zipname"]').value = zn.trim();
          }

          if (type === 'newfile' || type === 'newfolder') {
            const label = (type === 'newfile') ? 'file' : 'folder';
            const nn = prompt('New ' + label + ' name:');
            if (!nn) { ev.preventDefault(); return false; }
            f.querySelector('input[name="newname"]').value = nn.trim();
          }

          if (type === 'imgbatch') {
            if (!confirm('Run image batch on current folder?')) { ev.preventDefault(); return false; }
          }

          const btn = f.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…'; }
          showOverlay();
        });
      });

      // Login spinner
      const loginForm = document.getElementById('loginForm');
      if (loginForm) {
        loginForm.addEventListener('submit', ()=>{
          const loginBtn = document.getElementById('loginBtn');
          if (loginBtn) {
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Logging in…';
          }
          showOverlay();
        });
      }
    })();
  </script>
</body>
</html>
