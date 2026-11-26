<?php
// bulk-import-web.php — ব্রাউজার থেকে এক ক্লিকে ১০০+ বই আপলোড (অ্যাডমিন-একস חוק)
//陣: শুধু এই নতুন ফাইলটি। বিদ্যমান কোনো ফাইল/ডিজাইন/বাটন/রাউট/ভাষা-ফাইল পরিবর্তন করবে না।
// কোডের ভেতর কোনো মিডিয়া/ডেটা হার্ডকোড করবে না; শুধু আলাদা ফাইল/পাথ/ডেটা-কাঠামো ব্যবহার করবে।
define('LIB_ONLY', true);
require_once __DIR__ . '/index.php';

// কেবল অ্যাডমিন/লোকাল ডেভ accesses করতে পারবে
if (!isAdmin()) {
    http_response_code(403);
    exit('অনুমতি নেই। শুধু অ্যাডমিন এই পেজ ব্যবহার করতে পারবেন।');
}

$message = '';
$importDir = __DIR__ . '/import';
$report = []; // ['ok'=>int,'skip'=>int,'details'=>[...]]

if (!is_dir($importDir)) {
    @mkdir($importDir, 0777, true);
}

// ডিফল্ট মেটাডেটা (CSV-তে খালি থাকলে এই মান লাগবে)
$DEFAULTS = [
    'author' => 'বলন কাইজি',
    'category' => 'স্বরবিজ্ঞান',
    'pages' => '',
    'price' => '০ ৳',
    'access' => 'সাবস্ক্রিপশন',
];

// প্যপার টেক্সটುರস: import/papers/ থেকে সর্বোচ্চ ৫টি; না থাকলে আগের বইয়ের কাগজ ব্যবহার করবে
$papers = [];
$pf = array_merge(glob($importDir . '/papers/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []);
sort($pf);
foreach (array_slice($pf, 0, 5) as $i => $p) {
    $dest = 'uploads/papers/paper' . ($i + 1) . '_shared.' . pathinfo($p, PATHINFO_EXTENSION);
    @copy($p, $dest);
    $papers[] = ['name' => 'কাগজ ' . ($i + 1), 'image' => $dest, 'textColor' => '#111111'];
}
if (!$papers) {
    $books = loadBooks();
    foreach ($books as $b) {
        if (!empty($b['papers'])) { $papers = $b['papers']; break; }
    }
}

// অ্যাসেট কপি হেল্পার (আলাদা পাথ, কোনো হার্ডকোডিং নেই)
function importAsset($dir, $name, $folder, $prefix, $id) {
    if ($name === '' || !is_file("$dir/$name")) return '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $dest = "$folder/{$prefix}_$id.$ext";
    @copy("$dir/$name", $dest);
    return $dest;
}

// CSV পড়া (ঐচ্ছিক)
$rows = [];
$csv = $importDir . '/books.csv';
if (isset($_POST['action']) && $_POST['action'] === 'do_import') {
    // আপলোড করা CSV (ঐচ্ছিক)
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        if (($h = fopen($tmp, 'r'))) {
            $head = fgetcsv($h);
            if ($head) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) < 1 || trim($r[0]) === '') continue;
                $rows[trim($r[0])] = array_combine($head, array_pad($r, count($head), ''));
            }
            fclose($h);
        }
    }

    // আপলোড করা .docx ফাইলগুলোর তালিকা
    $uploadedFiles = [];
    if (!empty($_FILES['docx_files']['name'][0])) {
        foreach ($_FILES['docx_files']['name'] as $idx => $name) {
            if ($_FILES['docx_files']['error'][$idx] === 0 && $name !== '') {
                $tmp = $_FILES['docx_files']['tmp_name'][$idx];
                $dest = $importDir . '/' . basename($name);
                @move_uploaded_file($tmp, $dest);
                $uploadedFiles[] = basename($name);
            }
        }
    }

    // import/ ফোল্ডারে আগে থেকে রাখা .docx ফাইলও ধরে নেবে
    foreach (glob($importDir . '/*.docx') as $f) {
        $n = basename($f);
        if (!isset($rows[$n])) $rows[$n] = ['file' => $n, 'title' => pathinfo($n, PATHINFO_FILENAME)];
    }
    foreach ($uploadedFiles as $n) {
        if (!isset($rows[$n])) $rows[$n] = ['file' => $n, 'title' => pathinfo($n, PATHINFO_FILENAME)];
    }

    $books = loadBooks();
    $existingTitles = array_column($books, 'title');

    $ok = 0; $skip = 0; $details = [];

    foreach ($rows as $file => $r) {
        $src = $importDir . '/' . $file;
        $title = trim($r['title'] ?? '') ?: pathinfo($file, PATHINFO_FILENAME);
        if (!is_file($src)) {
            $skip++; $details[] = "[বাদ] ফাইল নেই: $file"; continue;
        }
        if (in_array(htmlspecialchars($title), $existingTitles, true)) {
            $skip++; $details[] = "[বাদ] আগেই আছে: $title"; continue;
        }

        $id = uniqid('book_');
        $fullBookPath = "uploads/books/fullbook_$id.docx";
        @copy($src, $fullBookPath);
        $plain = docToText($fullBookPath, 'docx');

        // সূচিপত্র ব্লক বাদ দেওয়ার সেট
        $skipSet = [];
        $pl = preg_split('/\r\n|\r|\n/', $plain);
        $first = -1; $last = -1;
        for ($i = 0; $i < count($pl); $i++) {
            if (strpos($pl[$i], '#') !== false) {
                if ($first === -1) $first = $i;
                $last = $i;
            }
        }
        if ($first !== -1) {
            $s = $first;
            if ($s - 1 >= 0 && trim($pl[$s - 1]) !== '' && strpos($pl[$s - 1], '#') === false && mb_strlen(trim($pl[$s - 1])) <= 40) $s--;
            for ($i = $s; $i <= $last; $i++) {
                $tt = trim($pl[$i]);
                if ($tt !== '') $skipSet[$tt] = true;
            }
        }

        $html = docxToHtml($fullBookPath, "uploads/books/$id", "uploads/books/$id", $skipSet);
        if (trim(strip_tags($html)) === '') {
            @unlink($fullBookPath);
            $skip++; $details[] = "[বাদ] লেখা পড়া যায়নি: $file"; continue;
        }

        $g = function ($k) use ($r, $DEFAULTS) {
            $v = trim($r[$k] ?? '');
            return htmlspecialchars($v !== '' ? $v : ($DEFAULTS[$k] ?? ''));
        };
        addBook($id, [
            'id' => $id,
            'title' => htmlspecialchars($title),
            'author' => $g('author'),
            'category' => $g('category'),
            'pages' => $g('pages'),
            'price' => $g('price'),
            'access' => $g('access'),
            'cover' => importAsset($importDir, trim($r['cover'] ?? ''), 'uploads/covers', 'cover', $id),
            'author_img' => importAsset($importDir, trim($r['author_img'] ?? ''), 'uploads/authors', 'author', $id),
            'full_book_file' => $fullBookPath,
            'papers' => $papers,
        ], [['title' => 'প্রথম অধ্যায়', 'content' => $html, 'plain' => $plain]]);

        $existingTitles[] = htmlspecialchars($title);
        $ok++; $details[] = "[ঠিক] $title ($id)";
    }

    $report = ['ok' => $ok, 'skip' => $skip, 'details' => $details];
    $message = "মোট যুক্ত: $ok, বাদ: $skip";
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>বাল্ক বই আপলোড — টেমপ্লেট</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f1ea; margin: 0; padding: 20px; color: #222; }
        .max { max-width: 900px; margin: 0 auto; }
        .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        h1 { font-size: 22px; margin-top: 0; color: #1e293b; }
        label { display: block; margin: 10px 0 5px; font-weight: 600; font-size: 14px; }
        input[type=file], input[type=text] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; background: #f59e0b; color: #000; font-weight: bold; cursor: pointer; font-size: 15px; margin-top: 12px; }
        .btn:hover { background: #d97706; }
        .msg { background: #dcfce7; color: #166534; padding: 12px; border-radius: 5px; margin-bottom: 16px; border: 1px solid #86efac; }
        .warn { background: #fef3c7; color: #92400e; padding: 12px; border-radius: 5px; margin-bottom: 16px; border: 1px solid #fcd34d; }
        pre { background: #f8fafc; padding: 12px; border-radius: 5px; overflow-x: auto; font-size: 13px; line-height: 1.5; border: 1px solid #e2e8f0; }
        .ok { color: #166534; } .skip { color: #b45309; }
        .small { font-size: 12px; color: #64748b; margin-top: 4px; }
        a { color: #0369a1; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="max">
    <div class="card">
        <h1>বাল্ক বই আপলোড (টেমপ্লেট)</h1>
        <p class="small">এক ক্লিকে একাধিক .docx বই আপলোড করুন। সাথে ঐচ্ছিক books.csv দিলে প্রতিটি বইয়ের নাম/লেখক/ক্যাটাগরি/দাম ইত্যাদি সেট করা যাবে।</p>

        <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($report): ?>
        <pre>
<?php foreach ($report['details'] as $d): ?>
<span class="<?= strpos($d,'[ঠিক]')===0?'ok':'skip' ?>"><?= htmlspecialchars($d) ?></span>
<?php endforeach; ?>
        </pre>
        <?php endif; ?>

        <?php if (!is_dir($importDir) || count(glob($importDir.'/*.docx')) === 0 && !isset($_POST)): ?>
        <div class="warn">নোট: import/ ফোল্ডারে আপনার .docx বইগুলো রাখুন, অথবা নিচে আপলোড করুন। পেরে ফেলে চলে যাবে না যেসব বই আগে থেকেই books.json-এ আছে।</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="do_import">

            <label>বইয়ের .docx ফাইলগুলো (একাধিক elect করতে পারবেন)</label>
            <input type="file" name="docx_files[]" accept=".docx" multiple>

            <label>books.csv (ঐচ্ছিক — এখান দেখুন: file,title,author,category,pages,price,access,cover,author_img)</label>
            <input type="file" name="csv_file" accept=".csv,text/csv">

            <button type="submit" class="btn">এক ক্লিকে সব বই আপলোড করুন</button>
        </form>

        <p class="small" style="margin-top:24px;">অতিরিক্ত কোনো বিদ্যমান ফাইল বা ডিজাইন পরিবর্তন করা হয়নি। শুধু এই নতুন পেইজটি যোগ করা হয়েছে।</p>
    </div>
</div>
</body>
</html>
