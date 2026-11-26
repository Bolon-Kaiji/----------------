<?php
// ব্যবহার (টার্মিনালে):  php bulk-import.php
// import/ ফোল্ডারে .docx বইগুলো রাখুন। সাথে ঐচ্ছিক import/books.csv (UTF-8):
//   file,title,author,category,pages,price,access,cover,author_img
//   (cover / author_img = import/ ফোল্ডারের ছবির নাম; খালি রাখলে ফাঁকা থাকবে)
// CSV না থাকলে ফাইলের নামই বইয়ের নাম হবে এবং নিচের ডিফল্ট মান বসবে।
// import/papers/ ফোল্ডারে ছবি (সর্বোচ্চ ৫টি) থাকলে সব বইয়ে সেই কাগজ ব্যবহার হবে;
// না থাকলে প্রথম বইয়ের কাগজগুলোই সবার জন্য ব্যবহার হবে।
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('শুধু টার্মিনাল থেকে চালান।'); }
define('LIB_ONLY', true);
chdir(__DIR__);
require __DIR__ . '/index.php';

$DEFAULTS = ['author' => 'বলন কাইজি', 'category' => 'স্বরবিজ্ঞান', 'pages' => '', 'price' => '০ ৳', 'access' => 'সাবস্ক্রিপশন'];
$IMPORT = __DIR__ . '/import';
if (!is_dir($IMPORT)) { mkdir($IMPORT, 0777, true); exit("import/ ফোল্ডার বানানো হয়েছে। বইয়ের .docx ফাইল সেখানে রেখে আবার চালান।\n"); }

$rows = [];
$csv = $IMPORT . '/books.csv';
if (file_exists($csv) && ($h = fopen($csv, 'r'))) {
    $head = fgetcsv($h);
    if ($head) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    while (($r = fgetcsv($h)) !== false) {
        if (count($r) < 1 || trim($r[0]) === '') continue;
        $rows[trim($r[0])] = array_combine($head, array_pad($r, count($head), ''));
    }
    fclose($h);
}
foreach (glob($IMPORT . '/*.docx') as $f) {
    $n = basename($f);
    if (!isset($rows[$n])) $rows[$n] = ['file' => $n, 'title' => pathinfo($n, PATHINFO_FILENAME)];
}

$books = loadBooks();
$existingTitles = array_column($books, 'title');

$papers = [];
$pf = array_merge(glob($IMPORT . '/papers/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []);
sort($pf);
foreach (array_slice($pf, 0, 5) as $i => $p) {
    $dest = 'uploads/papers/paper' . ($i + 1) . '_shared.' . pathinfo($p, PATHINFO_EXTENSION);
    copy($p, $dest);
    $papers[] = ['name' => 'কাগজ ' . ($i + 1), 'image' => $dest, 'textColor' => '#111111'];
}
if (!$papers) { foreach ($books as $b) { if (!empty($b['papers'])) { $papers = $b['papers']; break; } } }

function importAsset($dir, $name, $folder, $prefix, $id) {
    if ($name === '' || !is_file("$dir/$name")) return '';
    $dest = "$folder/{$prefix}_$id." . strtolower(pathinfo($name, PATHINFO_EXTENSION));
    copy("$dir/$name", $dest);
    return $dest;
}

$ok = 0; $skip = 0;
foreach ($rows as $file => $r) {
    $src = $IMPORT . '/' . $file;
    $title = trim($r['title'] ?? '') ?: pathinfo($file, PATHINFO_FILENAME);
    if (!is_file($src)) { echo "[বাদ] ফাইল নেই: $file\n"; $skip++; continue; }
    if (in_array(htmlspecialchars($title), $existingTitles, true)) { echo "[বাদ] আগেই আছে: $title\n"; $skip++; continue; }

    $id = uniqid('book_');
    $fullBookPath = "uploads/books/fullbook_$id.docx";
    copy($src, $fullBookPath);
    $plain = docToText($fullBookPath, 'docx');

    $skipSet = [];
    $pl = preg_split('/\r\n|\r|\n/', $plain);
    $first = -1; $last = -1;
    for ($i = 0; $i < count($pl); $i++) { if (strpos($pl[$i], '#') !== false) { if ($first === -1) $first = $i; $last = $i; } }
    if ($first !== -1) {
        $s = $first;
        if ($s - 1 >= 0 && trim($pl[$s - 1]) !== '' && strpos($pl[$s - 1], '#') === false && mb_strlen(trim($pl[$s - 1])) <= 40) $s--;
        for ($i = $s; $i <= $last; $i++) { $tt = trim($pl[$i]); if ($tt !== '') $skipSet[$tt] = true; }
    }
    $html = docxToHtml($fullBookPath, "uploads/books/$id", "uploads/books/$id", $skipSet);
    if (trim(strip_tags($html)) === '') { echo "[বাদ] লেখা পড়া যায়নি: $file\n"; @unlink($fullBookPath); $skip++; continue; }

    $g = function ($k) use ($r, $DEFAULTS) { $v = trim($r[$k] ?? ''); return htmlspecialchars($v !== '' ? $v : ($DEFAULTS[$k] ?? '')); };
    addBook($id, [
        'id' => $id,
        'title' => htmlspecialchars($title),
        'author' => $g('author'),
        'category' => $g('category'),
        'pages' => $g('pages'),
        'price' => $g('price'),
        'access' => $g('access'),
        'cover' => importAsset($IMPORT, trim($r['cover'] ?? ''), 'uploads/covers', 'cover', $id),
        'author_img' => importAsset($IMPORT, trim($r['author_img'] ?? ''), 'uploads/authors', 'author', $id),
        'full_book_file' => $fullBookPath,
        'papers' => $papers,
    ], [['title' => 'প্রথম অধ্যায়', 'content' => $html, 'plain' => $plain]]);
    echo "[ঠিক] $title ($id)\n";
    $ok++;
}
echo "\nমোট যুক্ত: $ok, বাদ: $skip\n";
