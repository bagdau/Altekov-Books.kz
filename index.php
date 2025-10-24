<?php

// ----------------------------- БАЗОВЫЕ НАСТРОЙКИ ------------------------------
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

load_env(__DIR__ . '/.env');

$adminPassword = getenv('ADM_PASS');
if ($adminPassword === false || $adminPassword === '') {
    http_response_code(500);
    exit('Admin password is not configured');
}
define('ADMIN_PASSWORD', $adminPassword);

const SITE_TITLE       = 'Публичная веб‑страница писателя Ғалымжана Алтекова';
const SITE_TAGLINE_RU  = 'Писатель · очерки · проза · исследования';
const SITE_TAGLINE_KK  = 'Жазушы · очерк · проза · зерттеулер';
const BASE_URL         = '';
const UPLOADS_DIR      = __DIR__ . '/uploads';
const DATA_DIR         = __DIR__ . '/data';
const IMAGES_DIR       = __DIR__ . '/images'; // <- локальные SVG

// Ограничения загрузки
const MAX_PDF_BYTES  = 60 * 1024 * 1024; // 60 МБ
const MAX_IMG_BYTES  = 15 * 1024 * 1024; // 15 МБ

// ------------------------------- УТИЛИТЫ --------------------------------------
function load_env(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;

        [$rawKey, $rawValue] = explode('=', $line, 2);
        $key = trim($rawKey);
        if ($key === '') continue;

        $value = trim($rawValue);
        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (!array_key_exists($key, $_SERVER)) {
            $_SERVER[$key] = $value;
        }
        putenv($key . '=' . $value);
    }
}

function ensure_dirs(): void {
    $dirs = [
        UPLOADS_DIR,
        UPLOADS_DIR . '/books',
        UPLOADS_DIR . '/covers',
        UPLOADS_DIR . '/photos',
        UPLOADS_DIR . '/photos/awards',
        UPLOADS_DIR . '/photos/family',
        UPLOADS_DIR . '/logo',
        DATA_DIR,
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) @mkdir($d, 0775, true);
    }
}
ensure_dirs();

// стало:
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Кэшобой для статики: строит относительный URL + ?v=<mtime>
if (!function_exists('asset')) {
    function asset(string $path): string {
        // URL пропускаем как есть
        if (preg_match('~^https?://~i', $path)) return $path;

        // Превратим в абсолютный путь, если он относительный
        $abs = $path;
        if (strpos($abs, __DIR__) !== 0) {
            $abs = __DIR__ . '/' . ltrim($abs, '/\\');
        }

        // Метка времени файла (если нет — текущее время)
        $ts = @filemtime($abs) ?: time();

        // Построим относительный путь от корня проекта
        $rel = ltrim(str_replace('\\', '/', str_replace(__DIR__, '', $abs)), '/');

        // BASE_URL может быть пустой строкой — учтём это
        $base = rtrim(BASE_URL, '/');

        // Всегда возвращаем с ведущим слэшем и версией
        return ($base !== '' ? $base : '') . '/' . $rel . '?v=' . $ts;
    }
}

function is_admin(): bool { return !empty($_SESSION['is_admin']); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(string $token): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403); exit('Bad CSRF');
    }
}
function slugify(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('~[\p{Z}\s]+~u', '-', $s);
    $ascii = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    if ($ascii === false) $ascii = $s;
    $s = preg_replace('~[^a-z0-9\-]+~u', '-', $ascii);
    $s = trim($s, '-');
    return $s ?: 'file';
}
function read_json(string $file, $fallback) {
    if (is_file($file)) {
        $j = json_decode((string)file_get_contents($file), true);
        if (is_array($j)) return $j;
    }
    return $fallback;
}
function write_json(string $file, $data): void {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Вставка локального SVG из /images как inline‑SVG, чтобы работал currentColor и темы.
 * Пример: svg('book'), svg('trash','icon sm')
 */
function svg(string $name, string $class = 'icon'): string {
    $safe = preg_replace('~[^a-z0-9\-]~i','', $name);
    $file = IMAGES_DIR . '/' . $safe . '.svg';
    if (!is_file($file)) return '';

    $svg = (string)file_get_contents($file);
    // убрать XML/doctype
    $svg = preg_replace('/<\?xml.*?\?>|<!DOCTYPE.*?>/si','', $svg);

    // добавить/дополнить class
    if (preg_match('/<svg[^>]*class=/i',$svg)) {
        $svg = preg_replace('/(<svg[^>]*class=")([^"]*)"/i','$1$2 '.str_replace('"','&quot;',$class).'"', $svg, 1);
    } else {
        $svg = preg_replace('/<svg(\s+)/i','<svg$1 class="'.h($class).'" ', $svg, 1);
    }

    // Перекрашиваем любые fill/stroke (кроме none) -> currentColor
    $svg = preg_replace('/\sfill\s*=\s*"(?!none)[^"]*"/i',   ' fill="currentColor"',  $svg);
    $svg = preg_replace('/\sstroke\s*=\s*"(?!none)[^"]*"/i', ' stroke="currentColor"', $svg);
    // И инлайновые стили:
    $svg = preg_replace('/fill:\s*(?!none)[#a-z0-9\(\)\.,\s%-]+/i',   'fill: currentColor',   $svg);
    $svg = preg_replace('/stroke:\s*(?!none)[#a-z0-9\(\)\.,\s%-]+/i', 'stroke: currentColor', $svg);

    // Убедимся, что на корневом теге стоят дефолты
    $svg = preg_replace_callback('/<svg\b[^>]*>/i', function($m){
        $tag = $m[0];
        if (!preg_match('/\sfill=/i',   $tag)) $tag = rtrim($tag, '>').' fill="currentColor">';
        if (!preg_match('/\sstroke=/i', $tag)) $tag = rtrim($tag, '>').' stroke="currentColor">';
        return $tag;
    }, $svg, 1);

    return $svg;
}


// ------------------------------- ЛОКАЛИ --------------------------------------
$langParam = $_GET['lang'] ?? null;
$lang = (is_string($langParam) && in_array($langParam, ['ru','kk','en'], true))
  ? $langParam
  : 'ru';

$i18n = [
  'ru' => [
    'nav_home' => 'Главная','nav_books' => 'Книги','nav_awards' => 'Награды','nav_family' => 'Семья','nav_about' => 'О писателе','nav_admin' => 'Админ‑панель',
    'search' => 'Поиск по книгам…','year' => 'Год','read' => 'Читать','download' => 'Скачать','all_books_zip' => 'Скачать все книги (ZIP)',
    'awards_title' => 'Награды и медали','family_title' => 'Семейные фотографии','about_title' => 'О писателе','footer' => '© ' . date('Y') . ' Ғалымжан Алтеков. Все права защищены.',
    'admin' => 'Админ‑панель','login' => 'Вход','password' => 'Пароль','logout' => 'Выйти','add_book' => 'Добавить книгу',
    'book_title_ru' => 'Название (RU)','book_title_kk' => 'Атауы (KZ)','book_year' => 'Год издания','book_desc_ru' => 'Описание (RU, необязательно)','book_desc_kk' => 'Сипаттамасы (KZ, міндетті емес)','book_pdf' => 'Файл книги (PDF)','book_cover' => 'Обложка (JPG/PNG/WebP, опционально)','save' => 'Сохранить',
    'add_photo' => 'Добавить фотографию','category' => 'Категория','awards' => 'Награды','family' => 'Семья','caption_ru' => 'Подпись (RU)','caption_kk' => 'Сурет мәтіні (KZ)','photo_file' => 'Файл фото (JPG/PNG/WebP)','delete' => 'Удалить',
    'theme' => 'Тема','light' => 'Светлая','dark' => 'Тёмная','subscribe' => 'Подписаться','donate' => 'Донат','settings' => 'Настройки','site_title_label' => 'Заголовок сайта',
    'tagline_ru_label' => 'Подзаголовок (RU)','tagline_kk_label' => 'Подзаголовок (KZ)','accent_color' => 'Акцентный цвет','default_theme' => 'Тема по умолчанию','subscribe_url' => 'Ссылка «Подписаться»','donate_url' => 'Ссылка «Донат»','logo' => 'Логотип (PNG/JPG/WebP)','save_config' => 'Сохранить настройки',
  ],
  'kk' => [
    'nav_home' => 'Басты','nav_books' => 'Кітаптар','nav_awards' => 'Марапаттар','nav_family' => 'Отбасы','nav_about' => 'Жазушы туралы','nav_admin' => 'Әкімші панелі',
    'search' => 'Кітаптардан іздеу…','year' => 'Жыл','read' => 'Оқу','download' => 'Жүктеу','all_books_zip' => 'Барлық кітапты ZIP түрінде жүктеу',
    'awards_title' => 'Марапаттар мен медальдар','family_title' => 'Отбасы фотолары','about_title' => 'Жазушы туралы','footer' => '© ' . date('Y') . ' Ғалымжан Алтеков. Барлық құқықтар қорғалған.',
    'admin' => 'Әкімші панелі','login' => 'Кіру','password' => 'Құпия сөз','logout' => 'Шығу','add_book' => 'Кітап қосу','book_title_ru' => 'Атауы (RU)','book_title_kk' => 'Атауы (KZ)','book_year' => 'Шыққан жылы','book_desc_ru' => 'Сипаттама (RU, міндетті емес)','book_desc_kk' => 'Сипаттама (KZ, міндетті емес)','book_pdf' => 'Кітап файлы (PDF)','book_cover' => 'Мұқаба (JPG/PNG/WebP, опциялы)','save' => 'Сақтау',
    'add_photo' => 'Фото қосу','category' => 'Санат','awards' => 'Марапаттар','family' => 'Отбасы','caption_ru' => 'Сипаттама (RU)','caption_kk' => 'Сипаттама (KZ)','photo_file' => 'Фото файлы (JPG/PNG/WebP)','delete' => 'Жою',
    'theme' => 'Тақырып','light' => 'Ашық','dark' => 'Қараңғы','subscribe' => 'Жазылу','donate' => 'Донат','settings' => 'Баптаулар','site_title_label' => 'Сайт тақырыбы','tagline_ru_label' => 'Астын тақырып (RU)','tagline_kk_label' => 'Астын тақырып (KZ)','accent_color' => 'Акцент түсі','default_theme' => 'Әдепкі тема','subscribe_url' => '«Жазылу» сілтемесі','donate_url' => '«Донат» сілтемесі','logo' => 'Логотип (PNG/JPG/WebP)','save_config' => 'Баптауларды сақтау',
  ],
  'en' => [
    'nav_home' => 'Home','nav_books'=>'Books','nav_awards'=>'Awards','nav_family'=>'Family','nav_about'=>'About','nav_admin'=>'Admin','search'=>'Search books…','year'=>'Year','read'=>'Read','download'=>'Download','all_books_zip'=>'Download all books (ZIP)','awards_title'=>'Awards & Medals','family_title'=>'Family Photos','about_title'=>'About the Author','footer'=>'© '.date('Y').' Galymzhan Altekov. All rights reserved.','admin'=>'Admin','login'=>'Login','password'=>'Password','logout'=>'Logout','add_book'=>'Add Book','book_title_ru'=>'Title (RU)','book_title_kk'=>'Title (KZ)','book_year'=>'Year','book_desc_ru'=>'Description (RU)','book_desc_kk'=>'Description (KZ)','book_pdf'=>'Book file (PDF)','book_cover'=>'Cover (JPG/PNG/WebP)','save'=>'Save','add_photo'=>'Add Photo','category'=>'Category','awards'=>'Awards','family'=>'Family','caption_ru'=>'Caption (RU)','caption_kk'=>'Caption (KZ)','photo_file'=>'Photo file (JPG/PNG/WebP)','delete'=>'Delete','theme'=>'Theme','light'=>'Light','dark'=>'Dark','subscribe'=>'Subscribe','donate'=>'Donate','settings'=>'Settings','site_title_label'=>'Site title','tagline_ru_label'=>'Tagline (RU)','tagline_kk_label'=>'Tagline (KZ)','accent_color'=>'Accent color','default_theme'=>'Default theme','subscribe_url'=>'Subscribe URL','donate_url'=>'Donate URL','logo'=>'Logo (PNG/JPG/WebP)','save_config'=>'Save settings'
  ],
];
$t = fn(string $k): string => $i18n[$lang][$k] ?? $i18n['ru'][$k] ?? $k;

// --------------------------- ДАННЫЕ (JSON/ФОЛБЭК) ----------------------------
$booksFile   = DATA_DIR . '/books.json';
$photosFile  = DATA_DIR . '/photos.json';
$configFile  = DATA_DIR . '/config.json';

$booksFallback = [
    
];
$photosFallback = [
    
];
$configFallback = [
    'site_title' => SITE_TITLE,'tagline_ru' => SITE_TAGLINE_RU,'tagline_kk' => SITE_TAGLINE_KK,'accent' => '#8b5e34','default_theme'=> 'light','subscribe_url'=> '','donate_url'=> '','logo'=> ''
];

$books   = read_json($booksFile,   $booksFallback);
$photos  = read_json($photosFile,  $photosFallback);
$config  = read_json($configFile,  $configFallback);

// ------------------------------- РОУТЫ ---------------------------------------
$action = $_GET['action'] ?? '';
if ($action === 'zip_books') {
    if (!class_exists('ZipArchive')) { http_response_code(500); echo 'ZipArchive недоступен на сервере'; exit; }
    $zipname = DATA_DIR . '/books_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'Не удалось создать ZIP'; exit; }
    foreach ($books as $b) { $path = __DIR__ . '/' . $b['pdf']; if (is_file($path)) $zip->addFile($path, basename($path)); }
    $zip->close(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . basename($zipname) . '"'); header('Content-Length: ' . filesize($zipname)); readfile($zipname); exit;
}

// ----------------------------- АДМИН‑ДЕЙСТВИЯ --------------------------------
if (($_POST['do'] ?? '') === 'login') { if (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) { $_SESSION['is_admin'] = true; } header('Location: ?lang='.$lang.'#admin'); exit; }
if (($_POST['do'] ?? '') === 'logout') { session_destroy(); header('Location: ?lang='.$lang.'#home'); exit; }

if (is_admin() && ($_POST['do'] ?? '') === 'save_config') {
    check_csrf($_POST['csrf'] ?? '');
    $config['site_title']    = trim((string)($_POST['site_title'] ?? $config['site_title']));
    $config['tagline_ru']    = trim((string)($_POST['tagline_ru'] ?? $config['tagline_ru']));
    $config['tagline_kk']    = trim((string)($_POST['tagline_kk'] ?? $config['tagline_kk']));
    $config['accent']        = preg_match('~^#[0-9a-fA-F]{6}$~', (string)($_POST['accent'] ?? '')) ? (string)$_POST['accent'] : $config['accent'];
    $defTheme                = ($_POST['default_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $config['default_theme'] = $defTheme;
    $config['subscribe_url'] = trim((string)($_POST['subscribe_url'] ?? $config['subscribe_url']));
    $config['donate_url']    = trim((string)($_POST['donate_url'] ?? $config['donate_url']));
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['logo']['size'] > MAX_IMG_BYTES) die('Logo too large');
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime  = finfo_file($finfo, $_FILES['logo']['tmp_name']); finfo_close($finfo);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) die('Bad logo type');
        $ext = $mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
        $name = 'logo-'.date('Ymd-His').'.'.$ext; $dest = UPLOADS_DIR . '/logo/' . $name;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) die('Move logo failed');
        $config['logo'] = 'uploads/logo/'.$name;
    }
    write_json($configFile, $config); header('Location: ?lang='.$lang.'#admin'); exit;
}

if (is_admin() && ($_POST['do'] ?? '') === 'add_book') {
    check_csrf($_POST['csrf'] ?? '');
    $title_ru = trim((string)($_POST['title_ru'] ?? '')); $title_kk = trim((string)($_POST['title_kk'] ?? ''));
    $year     = (int)($_POST['year'] ?? 0); $desc_ru  = trim((string)($_POST['desc_ru'] ?? '')); $desc_kk  = trim((string)($_POST['desc_kk'] ?? ''));
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) die('PDF upload error');
    if ($_FILES['pdf']['size'] > MAX_PDF_BYTES) die('PDF too large');
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime  = finfo_file($finfo, $_FILES['pdf']['tmp_name']); finfo_close($finfo);
    if ($mime !== 'application/pdf') die('Not a PDF');
    $pdfName = slugify(($title_ru ?: 'book') . '-' . $year) . '.pdf'; $pdfPath = UPLOADS_DIR . '/books/' . $pdfName;
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) die('Move PDF failed');
    $coverRel = '';
    if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['cover']['size'] > MAX_IMG_BYTES) die('Image too large');
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime  = finfo_file($finfo, $_FILES['cover']['tmp_name']); finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) die('Bad image type');
        $ext = $mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
        $coverName = slugify(($title_ru ?: 'book') . '-' . $year) . '.' . $ext; $coverPath = UPLOADS_DIR . '/covers/' . $coverName;
        if (!move_uploaded_file($_FILES['cover']['tmp_name'], $coverPath)) die('Move cover failed');
        $coverRel = 'uploads/covers/' . $coverName;
    }
    $books[] = ['title_ru' => $title_ru,'title_kk' => $title_kk,'year' => $year,'desc_ru' => $desc_ru,'desc_kk' => $desc_kk,'pdf' => 'uploads/books/' . $pdfName,'cover' => $coverRel];
    usort($books, fn($a,$b) => ($b['year'] <=> $a['year']) ?: strcmp($a['title_ru'],$b['title_ru']));
    write_json($booksFile, $books); header('Location: ?lang='.$lang.'#books'); exit;
}

if (is_admin() && ($_POST['do'] ?? '') === 'add_photo') {
    check_csrf($_POST['csrf'] ?? '');
    $cat = ($_POST['category'] ?? 'awards') === 'family' ? 'family' : 'awards';
    $cap_ru = trim((string)($_POST['caption_ru'] ?? '')); $cap_kk = trim((string)($_POST['caption_kk'] ?? ''));
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) die('Photo upload error');
    if ($_FILES['photo']['size'] > MAX_IMG_BYTES) die('Image too large');
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime  = finfo_file($finfo, $_FILES['photo']['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) die('Bad image type');
    $ext = $mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
    $name = slugify(($cap_ru ?: 'photo') . '-' . date('Ymd-His')) . '.' . $ext; $dest = UPLOADS_DIR . '/photos/' . $cat . '/' . $name;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) die('Move photo failed');
    $photos[$cat][] = [ 'src' => 'uploads/photos/'.$cat.'/'.$name, 'caption_ru' => $cap_ru, 'caption_kk' => $cap_kk ];
    write_json($photosFile, $photos); header('Location: ?lang='.$lang.'#'.$cat); exit;
}

if (is_admin() && ($_POST['do'] ?? '') === 'delete_item') {
    check_csrf($_POST['csrf'] ?? '');
    $type = $_POST['type'] ?? ''; $idx  = (int)($_POST['idx'] ?? -1);
    if ($type === 'book' && isset($books[$idx])) {
        $b = $books[$idx]; @unlink(__DIR__ . '/' . $b['pdf']); if (!empty($b['cover'])) @unlink(__DIR__ . '/' . $b['cover']);
        array_splice($books, $idx, 1); write_json($booksFile, $books); header('Location: ?lang='.$lang.'#books'); exit;
    } elseif ($type === 'award' && isset($photos['awards'][$idx])) {
        $p = $photos['awards'][$idx]; @unlink(__DIR__ . '/' . $p['src']); array_splice($photos['awards'], $idx, 1); write_json($photosFile, $photos); header('Location: ?lang='.$lang.'#awards'); exit;
    } elseif ($type === 'family' && isset($photos['family'][$idx])) {
        $p = $photos['family'][$idx]; @unlink(__DIR__ . '/' . $p['src']); array_splice($photos['family'], $idx, 1); write_json($photosFile, $photos); header('Location: ?lang='.$lang.'#family'); exit;
    }
}

// ----------------------------- ШАБЛОН/ОТРИСОВКА -------------------------------
$siteTitle = trim((string)($config['site_title'] ?? SITE_TITLE)) ?: SITE_TITLE;
$taglineRU = trim((string)($config['tagline_ru'] ?? SITE_TAGLINE_RU)) ?: SITE_TAGLINE_RU;
$taglineKK = trim((string)($config['tagline_kk'] ?? SITE_TAGLINE_KK)) ?: SITE_TAGLINE_KK;
$accent    = preg_match('~^#[0-9a-fA-F]{6}$~', (string)($config['accent'] ?? '')) ? $config['accent'] : '#8b5e34';
$defTheme  = ($config['default_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
$subscribe = (string)($config['subscribe_url'] ?? '');
$donate    = (string)($config['donate_url'] ?? '');
$logoPath  = (string)($config['logo'] ?? '');

$tagline = $lang==='kk' ? $taglineKK : $taglineRU;
$absUrl  = (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<!doctype html>
<html lang="<?= h(in_array($lang, ['ru','kk','en'], true) ? $lang : 'ru') ?>" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($siteTitle)?></title>
  <meta name="description" content="<?=h($tagline)?>">
  <meta property="og:title" content="<?=h($siteTitle)?>">
  <meta property="og:description" content="<?=h($tagline)?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?=h($absUrl)?>">
  <meta name="theme-color" content="<?=h($accent)?>">
  <style>
  :root { --font-body: Georgia, 'Times New Roman', Times, serif; --font-heading: Georgia, 'Times New Roman', Times, serif;
    --bg:#111318; --bg2:#141720; --text:#e6e0d6; --muted:#a8a29e; --card:#161a22; --accent: <?=h($accent)?>; --ring:rgba(202,165,106,.28); --border:#2a2e36; --paper:#111318; --ink:#e8e2d8; --btn-bg:#1b2030; --btn-bg-hover:#212638; --btn-fg:#e6e0d6; }
  [data-theme='light'] { --bg:#fbf7ee; --bg2:#ffffff; --text:#1f2937; --muted:#6b7280; --card:#ffffff; --accent: <?=h($accent)?>; --ring:rgba(139,94,52,.22); --border:#e6e1d9; --paper:#ffffff; --ink:#111827; --btn-bg:#f5efe6; --btn-bg-hover:#efe8de; --btn-fg:#111827; }
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:var(--font-body);font-size:17px;line-height:1.7;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
  h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);font-weight:700;letter-spacing:.2px;margin:0 0 .6em}
  a{color:var(--accent);text-decoration:none;text-underline-offset:2px} a:hover{text-decoration:underline}
  .wrap{max-width:1100px;margin:0 auto;padding:24px}
  header{position:sticky;top:0;background:var(--bg2);border-bottom:1px solid var(--border);z-index:50}
  header .row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:14px 24px}
  .brand{display:flex;align-items:center;gap:14px}
  .brand .logo{width:48px;height:48px;border-radius:8px;overflow:hidden;display:grid;place-items:center;border:1px solid var(--border);background:linear-gradient(#e6e1d9,#d6d0c4)}
  .brand .logo img{display:block;max-width:100%;max-height:100%}
  .brand .title{display:flex;flex-direction:column}
  .brand .title .site{font-size:20px;font-weight:700}
  .brand .title .tag{font-size:13px;color:var(--muted)}
  nav .tabs{display:flex;gap:6px;flex-wrap:wrap}
  nav .tabs a{margin:0;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg2)}
  nav .tabs a.active{outline:2px solid var(--ring); box-shadow:0 0 0 2px var(--ring) inset}
  .lang-theme{display:flex;gap:8px;align-items:center}
  .chip{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border:1px solid var(--border);border-radius:999px;background:var(--bg2)}
  .btn{display:inline-flex;align-items:center;gap:8px;background:var(--btn-bg);color:var(--btn-fg);border:1px solid var(--border);border-radius:8px;padding:10px 14px;cursor:pointer;box-shadow:none;text-decoration:none}
  .btn:hover{background:var(--btn-bg-hover)} .btn.secondary{background:transparent}
  .icon{ width:18px; height:18px; vertical-align:-2px; }

    /* Кнопки */
    .btn { color: var(--btn-fg); }           /* иконка унаследует этот цвет */
    .btn.secondary { color: var(--text); }   /* вторичная — текстовый цвет */

    /* Табы/чипы в навигации */
    nav .tabs a { color: var(--text); }
    nav .tabs a.active { color: var(--accent); }

    /* Чипы */
    .chip { color: var(--text); }

  .btn .icon{margin-right:8px} .chip .icon{margin-right:6px} h2 .icon{margin-right:8px}
  .grid{display:grid;gap:16px}
  .hero{display:grid;grid-template-columns:1.2fr .8fr;gap:24px;align-items:center;padding:48px 24px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px}
  .books{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}
  .book .cover{width:100%;aspect-ratio:3/4;background:linear-gradient(135deg,#d6d0c4,#f3ede0);border-radius:8px;overflow:hidden;display:block}
  .book h3{margin:10px 0 6px}
  .muted{color:var(--muted)}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  /* PDF modal */
.modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);z-index:120}
.modal.open{display:flex}
.modal .inner{width:min(1000px,95vw);height:min(85vh,95vh);background:var(--paper);color:var(--ink);
  border:1px solid var(--border);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.45);overflow:hidden;display:flex;flex-direction:column}
.modal .bar{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--paper)}
.modal object{width:100%;height:100%;border:0}

  .lightbox {position:fixed; inset:0; background:rgba(0,0,0,.6); display:none;
  align-items:center; justify-content:center; z-index:100}
.lightbox.open{display:flex}

.lightbox img{max-width:92vw; max-height:92vh; border-radius:8px;
  box-shadow:0 10px 40px rgba(0,0,0,.5)}

.lightbox-close{
  position:absolute; top:16px; right:16px; cursor:pointer;
  background:var(--bg2); color:var(--text);
  border:1px solid var(--border); border-radius:999px; padding:8px;
  display:grid; place-items:center; box-shadow:0 6px 24px rgba(0,0,0,.35)
}
.lightbox-close .icon{width:20px; height:20px}

  input[type='text'],input[type='number'],input[type='password'],select,textarea{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);color:var(--text)}
  .admin{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .gallery{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
  .gallery img{width:100%;height:220px;object-fit:cover;border-radius:8px;border:1px solid var(--border);cursor:pointer}
  footer{margin-top:32px;padding:24px;border-top:1px solid var(--border);text-align:center;color:var(--muted)}
  section[data-tab]{display:none} section[data-tab].active{display:block}
  .animate-in{animation:fadeSlide .35s ease both}
  @keyframes fadeSlide{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .display{font-size:clamp(36px, 4.2vw, 56px); line-height:1.1; margin:0 0 10px}
  @media (max-width:1000px){ header .row{padding:12px 16px} .hero{grid-template-columns:1fr;padding:28px 16px} }
  @media (max-width:640px){ nav .tabs{overflow:auto} .brand .title .site{font-size:18px} .brand .logo{width:40px;height:40px} }
  </style>
  <script>const applyTheme=t=>document.documentElement.setAttribute('data-theme',t);(function(){const def='<?=h($defTheme)?>';applyTheme(localStorage.getItem('theme')||def)})();</script>
  <script type="application/ld+json">{"@context":"https://schema.org","@type":"Person","name":"Ғалымжан Алтеков","url":"<?=h($absUrl)?>","jobTitle":"Writer","nationality":"KZ","worksFor":{"@type":"Organization","name":"Independent"},"hasPart":[<?php foreach ($books as $i=>$b): ?>{"@type":"Book","name":"<?=h($b['title_ru']?:$b['title_kk'])?>","inLanguage":["ru","kk"],"datePublished":"<?=h((string)$b['year'])?>","url":"<?=h($b['pdf'])?>"}<?= $i<count($books)-1?',':'' ?><?php endforeach; ?>]}</script>
</head>
<body>
<header>
  <div class="row">
    <div class="brand">
      <div class="logo">
        <?php if ($logoPath && is_file(__DIR__.'/'.$logoPath)): ?>
          <img src="<?=h(asset(__DIR__.'/'.$logoPath))?>" alt="logo">
        <?php else: ?>
          <!-- Фолбэк‑иконка книги -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="icon" aria-hidden="true"><path d="M4 19a2 2 0 0 0 2 2h12" stroke="currentColor" fill="none"/><path d="M6 3h10a2 2 0 0 1 2 2v16" stroke="currentColor" fill="none"/><path d="M6 7h10" stroke="currentColor" fill="none"/></svg>
        <?php endif; ?>
      </div>
      <div class="title">
        <div class="site"><?=h($siteTitle)?></div>
        <div class="tag"><?=h($tagline)?></div>
      </div>
    </div>
    <nav>
      <div class="tabs">
        <a href="#home"   data-goto="home"   class="chip"><?=svg('home')?> <?=$t('nav_home')?></a>
        <a href="#books"  data-goto="books"  class="chip"><?=svg('book')?> <?=$t('nav_books')?></a>
        <a href="#awards" data-goto="awards" class="chip"><?=svg('award')?> <?=$t('nav_awards')?></a>
        <a href="#family" data-goto="family" class="chip"><?=svg('users')?> <?=$t('nav_family')?></a>
        <a href="#about"  data-goto="about"  class="chip"><?=svg('info')?> <?=$t('nav_about')?></a>
        <a href="#admin"  data-goto="admin"  class="chip"><?=svg('settings')?> <?=$t('nav_admin')?></a>
      </div>
    </nav>
    <div class="lang-theme">
      <a class="chip" href="?lang=ru#home">RU</a>
      <a class="chip" href="?lang=kk#home">KZ</a>
      <button id="btnTheme" class="chip js-toggle-icon"
              data-icon="moon" data-icon-alt="sun" type="button">
        <?= svg('moon') ?> <?=$t('theme')?>
      </button>

    </div>
  </div>
</header>

<main class="wrap">
  <section id="home" data-tab="home" class="active animate-in">
    <div class="hero">
      <div>
        <h1 class="display">Ғалымжан Алтеков</h1>
        <p class="muted" style="font-size:18px;margin:0 0 18px;"><?=h($tagline)?></p>
        <div class="row">
          <!-- Книги: book ↔ book2 -->
            <a class="btn js-toggle-icon"
               href="#books" data-goto="books"
               data-icon="book" data-icon-alt="book2">
              <?= svg('book') ?> <?=$t('nav_books')?>
            </a>


            <!-- Подписаться: star ↔ sun -->
            <a class="btn js-toggle-icon"
               href="<?= h($subscribe ?: '#') ?>" target="_blank" rel="noopener"
               data-icon="star" data-icon-alt="star2">
              <?= svg('star') ?> <?=$t('subscribe')?>
            </a>

            <!-- Донат: heart ↔ heart2 -->
            <a class="btn js-toggle-icon"
               href="<?= h($donate ?: '#') ?>" target="_blank" rel="noopener"
               data-icon="heart" data-icon-alt="heart2">
              <?= svg('heart') ?> <?=$t('donate')?>
            </a>

        </div>
      </div>
      <div class="card">
        <div class="row" style="justify-content:space-between;">
          <div class="chip"><?=svg('book')?> <?=$t('nav_books')?>: <?=count($books)?></div>
          <div class="chip"><?=svg('award')?> <?=$t('nav_awards')?>: <?=count($photos['awards']??[])?></div>
        </div>
        <p class="muted" style="margin-top:12px;">Altekov.books.kz Ашық беті</p>
      </div>
    </div>
  </section>

  <section id="books" data-tab="books">
    <div class="row" style="justify-content:space-between;align-items:center;gap:16px;margin-bottom:12px;">
      <h2 style="margin:0;"><?=svg('book')?> <?=$t('nav_books')?></h2>
      <input id="bookSearch" type="text" placeholder="<?=$t('search')?>" style="max-width:360px;">
    </div>
    <div class="grid books">
    <?php foreach ($books as $idx=>$b): $title = $lang==='kk' && ($b['title_kk']??'') ? $b['title_kk'] : ($b['title_ru']??''); ?>
      <article class="card book animate-in" data-title="<?=h(mb_strtolower($title))?>">
        <a class="cover" href="<?=h($b['pdf'])?>" data-pdf="<?=h($b['pdf'])?>" onclick="return openPdf(this)">
          <?php if (!empty($b['cover']) && is_file(__DIR__.'/'.$b['cover'])): ?>
            <img src="<?=h(asset(__DIR__.'/'.$b['cover']))?>" alt="cover" style="width:100%;height:100%;object-fit:cover;display:block">
          <?php endif; ?></a>
        <h3><?=h($title)?></h3>
        <div class="muted"><?=$t('year')?>: <?=h((string)($b['year']??''))?></div>
        <?php $desc = $lang==='kk' ? ($b['desc_kk']??'') : ($b['desc_ru']??''); if ($desc): ?>
          <p style="margin:8px 0 12px;"><?=h($desc)?></p>
        <?php endif; ?>
        <div class="row">
          <a class="btn" href="<?=h($b['pdf'])?>" onclick="return openPdf(this)"><?=svg('book-open')?> <?=$t('read')?></a>
          <a class="btn secondary" href="<?=h($b['pdf'])?>" download><?=svg('download')?> <?=$t('download')?></a>
        </div>
        <?php if (is_admin()): ?>
          <form method="post" class="row" style="margin-top:10px;" onsubmit="return confirm('Delete book?');">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="delete_item">
            <input type="hidden" name="type" value="book">
            <input type="hidden" name="idx" value="<?=$idx?>">
            <button class="btn secondary" type="submit"><?=svg('trash')?> <?=$t('delete')?></button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    </div>
  </section>

  <section id="awards" data-tab="awards">
    <h2><?=svg('award')?> <?=$t('awards_title')?></h2>
    <div class="grid gallery">
      <?php foreach(($photos['awards']??[]) as $i=>$p): $cap = $lang==='kk' ? ($p['caption_kk']??'') : ($p['caption_ru']??''); ?>
        <figure class="animate-in">
          <img src="<?=h($p['src'])?>" alt="<?=h($cap ?: 'award')?>" onclick="openLightbox(this.src,this.alt)">
          <?php if ($cap): ?><figcaption class="muted" style="margin-top:6px;"><?=h($cap)?></figcaption><?php endif; ?>
          <?php if (is_admin()): ?>
            <form method="post" class="row" onsubmit="return confirm('Delete photo?');">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="delete_item">
              <input type="hidden" name="type" value="award">
              <input type="hidden" name="idx" value="<?=$i?>">
              <button class="btn secondary" type="submit"><?=svg('trash')?> <?=$t('delete')?></button>
            </form>
          <?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="family" data-tab="family">
    <h2><?=svg('users')?> <?=$t('family_title')?></h2>
    <div class="grid gallery">
      <?php foreach(($photos['family']??[]) as $i=>$p): $cap = $lang==='kk' ? ($p['caption_kk']??'') : ($p['caption_ru']??''); ?>
        <figure class="animate-in">
          <img src="<?=h($p['src'])?>" alt="<?=h($cap ?: 'family')?>" onclick="openLightbox(this.src,this.alt)">
          <?php if ($cap): ?><figcaption class="muted" style="margin-top:6px;"><?=h($cap)?></figcaption><?php endif; ?>
          <?php if (is_admin()): ?>
            <form method="post" class="row" onsubmit="return confirm('Delete photo?');">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="delete_item">
              <input type="hidden" name="type" value="family">
              <input type="hidden" name="idx" value="<?=$i?>">
              <button class="btn secondary" type="submit"><?=svg('trash')?> <?=$t('delete')?></button>
            </form>
          <?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="about" data-tab="about">
    <h2><?=svg('info')?> <?=$t('about_title')?></h2>
    <div class="card animate-in">
      <p>Бұл — жазушы Ғалымжан Алтековтың ресми қоғамдық парақшасы. Здесь собраны его книги в формате PDF, награды и семейные фотографии. Контент пополняется и поддерживается лично автором / его представителем.</p>
      <p class="muted">Контакты для связи, презентаций, встреч можно разместить здесь (email/телефон/соцсети).</p>
    </div>
  </section>

  <section id="admin" data-tab="admin">
    <h2><?=svg('settings')?> <?=$t('admin')?></h2>
    <?php if (!is_admin()): ?>
      <form method="post" class="card" style="max-width:420px;">
        <input type="hidden" name="do" value="login">
        <label><?=$t('password')?>:<br><input type="password" name="password" required></label>
        <div class="row" style="margin-top:12px;">
          <button class="btn" type="submit"><?=svg('log-in')?> <?=$t('login')?></button>
        </div>
      </form>
    <?php else: ?>
      <form method="post" class="row">
        <input type="hidden" name="do" value="logout">
        <button class="btn secondary"><?=svg('log-out')?> <?=$t('logout')?></button>
      </form>

      <form class="card" method="post" enctype="multipart/form-data" style="margin-top:16px;">
        <h3 style="margin-top:0;"><?=svg('settings')?> <?=$t('settings')?></h3>
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="save_config">
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:12px;">
          <label><?=$t('site_title_label')?><br><input name="site_title" value="<?=h($siteTitle)?>"></label>
          <label><?=$t('accent_color')?><br><input type="color" name="accent" value="<?=h($accent)?>"></label>
          <label><?=$t('tagline_ru_label')?><br><input name="tagline_ru" value="<?=h($taglineRU)?>"></label>
          <label><?=$t('tagline_kk_label')?><br><input name="tagline_kk" value="<?=h($taglineKK)?>"></label>
          <label><?=$t('subscribe_url')?><br><input name="subscribe_url" value="<?=h($subscribe)?>"></label>
          <label><?=$t('donate_url')?><br><input name="donate_url" value="<?=h($donate)?>"></label>
          <label><?=$t('default_theme')?><br>
            <select name="default_theme">
              <option value="light" <?= $defTheme==='light'?'selected':'' ?>><?=$t('light')?></option>
              <option value="dark"  <?= $defTheme==='dark'?'selected':'' ?>><?=$t('dark')?></option>
            </select>
          </label>
          <label><?=$t('logo')?><br><input type="file" name="logo" accept="image/*"></label>
        </div>
        <div class="row" style="margin-top:12px;"><button class="btn"><?=svg('check')?> <?=$t('save_config')?></button></div>
      </form>

      <div class="admin" style="margin-top:16px;">
        <form class="card" method="post" enctype="multipart/form-data" id="formBook">
          <h3 style="margin-top:0;"><?=svg('book-open')?> <?=$t('add_book')?></h3>
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="add_book">
          <label><?=$t('book_title_ru')?><br><input name="title_ru" required></label>
          <label><?=$t('book_title_kk')?><br><input name="title_kk"></label>
          <label><?=$t('book_year')?><br><input type="number" name="year" min="1900" max="<?=date('Y')?>" value="<?=date('Y')?>" required></label>
          <label><?=$t('book_desc_ru')?><br><textarea name="desc_ru" rows="3"></textarea></label>
          <label><?=$t('book_desc_kk')?><br><textarea name="desc_kk" rows="3"></textarea></label>
          <label><?=$t('book_pdf')?><br><input type="file" name="pdf" accept="application/pdf" required></label>
          <label><?=$t('book_cover')?><br><input type="file" name="cover" accept="image/*"></label>
          <div class="row" style="margin-top:12px;"><button class="btn"><?=svg('check')?> <?=$t('save')?></button></div>
        </form>

        <form class="card" method="post" enctype="multipart/form-data" id="formPhoto">
          <h3 style="margin-top:0;"><?=svg('image')?> <?=$t('add_photo')?></h3>
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="add_photo">
          <label><?=$t('category')?><br>
            <select name="category">
              <option value="awards"><?=$t('awards')?></option>
              <option value="family"><?=$t('family')?></option>
            </select>
          </label>
          <label><?=$t('caption_ru')?><br><input name="caption_ru"></label>
          <label><?=$t('caption_kk')?><br><input name="caption_kk"></label>
          <label><?=$t('photo_file')?><br><input type="file" name="photo" accept="image/*" required></label>
          <div class="row" style="margin-top:12px;"><button class="btn"><?=svg('check')?> <?=$t('save')?></button></div>
        </form>
      </div>
    <?php endif; ?>
  </section>
</main>

<footer><?=$t('footer')?></footer>

<div class="modal" id="pdfModal">
  <div class="inner">
    <header class="bar">
      <div class="row"><strong><?=svg('file-text')?> PDF</strong></div>
      <div class="row">
        <a id="pdfDownload" class="btn secondary" href="#" download><?=svg('download')?> <?=$t('download')?></a>
        <button class="btn" type="button" data-close-pdf><?=svg('x')?></button>
      </div>
    </header>
    <object id="pdfObject" data="" type="application/pdf"></object>
  </div>
</div>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Фото в увеличении">
  <button type="button" class="lightbox-close" id="lbClose" aria-label="Закрыть"><?=svg('x')?></button>
  <img alt="">
</div>



<script>
// ===== SPA-вкладки =====
(function () {
  const tabs = document.querySelectorAll('section[data-tab]');
  const navLinks = document.querySelectorAll('[data-goto]');

  function showTab(tab) {
    const id = (tab || 'home').replace('#', '');
    tabs.forEach(s => {
      const on = s.dataset.tab === id;
      s.classList.toggle('active', on);
      if (on) {
        s.classList.remove('animate-in');
        void s.offsetWidth; // reflow
        s.classList.add('animate-in');
      }
    });
    navLinks.forEach(a => a.classList.toggle('active', a.dataset.goto === id));
    if (location.hash !== '#' + id) history.replaceState(null, '', '#' + id);
  }

  // Навигация по клику без перезагрузки
  navLinks.forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      showTab(a.dataset.goto);
    });
  });

  (function(){
  const btn = document.getElementById('btnTheme');
  if (!btn) return;
  const def = '<?=h($defTheme)?>' || 'light';

  const loadIcon = (name) => {
    fetch('images/' + name + '.svg')
      .then(r => r.ok ? r.text() : '')
      .then(svg => { if (!svg) return; const old = btn.querySelector('svg'); if (old) old.outerHTML = svg; })
      .catch(() => {});
  };

  const setTheme = (theme) => {
    try { if (window.applyTheme) applyTheme(theme); else document.documentElement.setAttribute('data-theme', theme); } catch(e) { document.documentElement.setAttribute('data-theme', theme); }
    localStorage.setItem('theme', theme);
    const icon = theme === 'dark' ? 'moon' : 'sun';
    btn.setAttribute('data-current', icon);
    loadIcon(icon);
  };

  // Инициализация по сохранённому или дефолтному значению
  const initial = localStorage.getItem('theme') || def || 'light';
  setTheme(initial);

  // По клику — переключаем тему и иконку
  btn.addEventListener('click', (e) => {
    const cur = document.documentElement.getAttribute('data-theme') || 'light';
    const next = cur === 'dark' ? 'light' : 'dark';
    setTheme(next);
  });
})();

  window.addEventListener('hashchange', () => showTab(location.hash || 'home'));
  showTab(location.hash || 'home');
})();

// ===== Поиск по книгам (легкий) =====
(function () {
  const q = document.getElementById('bookSearch');
  if (!q) return;
  let t = null;
  q.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => {
      const v = q.value.trim().toLowerCase();
      document.querySelectorAll('.book').forEach(card => {
        const hay = (card.dataset.title || '').toLowerCase();
        card.style.display = hay.includes(v) ? 'block' : 'none';
      });
    }, 60);
  });
})();

// ===== PDF-просмотр (safe) =====
(function () {
  const modal = document.getElementById('pdfModal');       // может отсутствовать
  const pdfObject = document.getElementById('pdfObject');  // может отсутствовать
  const pdfDownload = document.getElementById('pdfDownload');

  // Глобальные функции — используются из markup через onclick
  window.openPdf = function (a) {
    const url = a.getAttribute('data-pdf') || a.getAttribute('href') || a.href;

    // Если модалки нет — просто откроем в этом же окне (без новой вкладки)
    if (!modal || !pdfObject || !pdfDownload) {
      location.href = url;
      return false;
    }

    // Сброс, потом установка — стабильнее для некоторых браузеров
    pdfObject.removeAttribute('data');
    requestAnimationFrame(() => {
      pdfObject.setAttribute('data', url + '#toolbar=1&navpanes=0');
    });

    pdfDownload.setAttribute('href', url);

    modal.classList.add('open');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    return false; // не пускаем ссылку открываться отдельно
  };

  window.closePdf = function () {
    if (!modal) return;
    modal.classList.remove('open');
    modal.style.display = 'none';
    if (pdfObject) pdfObject.removeAttribute('data'); // выгрузим PDF из памяти
    document.body.style.overflow = '';
  };

  // Закрытие по клику на фон / Esc / кнопке
  modal?.addEventListener('click', e => {
    if (e.target === modal) window.closePdf();
  });
  document.addEventListener('click', e => {
    if (e.target.matches('[data-close-pdf]')) window.closePdf();
  });
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') window.closePdf();
  });
})();

// ===== Лайтбокс (safe) =====
(function () {
  const lb = document.getElementById('lightbox');
  const img = lb?.querySelector('img');
  const btnClose = document.getElementById('lbClose');

  window.openLightbox = function (src, alt) {
    if (!lb || !img) return;
    img.src = src;
    img.alt = alt || '';
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
  };

  function closeLightbox() {
    if (!lb || !img) return;
    lb.classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { img.src = ''; }, 150);
  }

  lb?.addEventListener('click', e => { if (e.target.id === 'lightbox') closeLightbox(); });
  btnClose?.addEventListener('click', e => { e.stopPropagation(); closeLightbox(); });
  window.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
})();

// ===== Переключатель SVG-иконок без лишних вкладок =====
// Блокируем переход только для внутренних ссылок (#… или data-goto).
// Внешние (подписка/донат) — работают как обычные ссылки.
(function () {
  document.querySelectorAll('.js-toggle-icon').forEach(btn => {
    btn.addEventListener('click', e => {
      const href = btn.getAttribute('href') || '';
      const isInternal = href.startsWith('#') || btn.hasAttribute('data-goto');
      if (isInternal) e.preventDefault();

      const icon = btn.getAttribute('data-icon');
      const alt  = btn.getAttribute('data-icon-alt');
      if (!icon || !alt) return;

      const current = btn.getAttribute('data-current') || icon;
      const next = current === icon ? alt : icon;
      btn.setAttribute('data-current', next);

      fetch('images/' + next + '.svg')
        .then(r => r.text())
        .then(svg => {
          const old = btn.querySelector('svg');
          if (old) old.outerHTML = svg;
        })
        .catch(err => console.error('SVG load error:', err));
    });
  });
})();
</script>


</body>
</html>
