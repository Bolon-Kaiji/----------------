<?php
session_start();
require_once __DIR__ . '/store.php';

$books = loadBooks();
$id = $_GET['id'] ?? '';
$paperIdx = $_GET['paper'] ?? 'default';
$chapIdx = isset($_GET['chap']) ? (int)$_GET['chap'] : 0;

// কোনো বই নির্বাচন করা না থাকলে হোমপেজে পাঠিয়ে দিন
if ($id === '') {
    header('Location: index.php');
    exit;
}

if (!isset($books[$id])) {
    die("বইটি খুঁজে পাওয়া যায়নি! <a href='index.php'>হোমপেজে ফিরে যান</a>");
}

$book = $books[$id];
$chapters = loadChapters($id);

// ---- পেমেন্ট ও অ্যাকসেস (সব ধরণের সংগ্রহ) ----
require_once __DIR__ . '/pay.php';
function priceToNumber($str) {
    $map = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
    $s = strtr((string)$str, $map);
    if (preg_match('/[0-9]+(?:\.[0-9]+)?/', $s, $m)) return (float)$m[0];
    return 0.0;
}
$priceNum = priceToNumber($book['price'] ?? '');
$accessType = $book['access'] ?? 'সাবস্ক্রিপশন';
$visitor = payVisitor();
$allDir = __DIR__;
$trustKey = 'paid_' . $id;
$trustAmount = isset($_SESSION[$trustKey]) ? (float)$_SESSION[$trustKey] : 0.0;
$approvedAmount = payApproved($allDir, $id, $visitor);
$pendingCount = payPendingCount($allDir, $id, $visitor);

// পেমেন্ট জমা
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $method = $_POST['method'] ?? 'trust';
    $amt = (float)($_POST['amount'] ?? 0);
    if ($amt > 0) {
        if ($method === 'trust') {
            if ($amt > $trustAmount) { $_SESSION[$trustKey] = $amt; $trustAmount = $amt; }
        } else {
            $rows = payLoad($allDir);
            $rows[] = [
                'id' => uniqid('pay_'),
                'book_id' => $id,
                'book_title' => $book['title'] ?? '',
                'visitor' => $visitor,
                'name' => substr($_POST['name'] ?? '', 0, 60),
                'contact' => substr($_POST['contact'] ?? '', 0, 60),
                'method' => $method,
                'amount' => $amt,
                'trxid' => substr($_POST['trxid'] ?? '', 0, 80),
                'status' => 'pending',
                'created' => date('Y-m-d H:i'),
            ];
            paySave($allDir, $rows);
        }
    }
    header('Location: ?id=' . urlencode($id) . '&paper=' . urlencode($paperIdx) . '&chap=' . $chapIdx);
    exit;
}

$isFreeBook = ($accessType === 'ফ্রি' || $priceNum <= 0);
$paidAmount = $trustAmount + $approvedAmount;
$paidFraction = $isFreeBook ? 1.0 : min(1.0, $paidAmount / $priceNum);
$fullAccess = $paidFraction >= 1.0;

// লোকাল ডেভেলপমেন্টে (localhost / 127.0.0.1) পূর্ণ অ্যাক্সেস — বই পড়া ও সম্পাদনার জন্য।
// cPanel-এ (প্রোডাকশন) REMOTE_ADDR কখনো localhost হয় না, তাই পেমেন্ট-প্রাচীর সেখানে অক্ষত থাকবে।
$isLocalDev = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if ($isLocalDev) $fullAccess = true;

// ডাউনলোড (আনলক করা অংশ পর্যন্ত)
if (isset($_GET['download'])) {
    $dl = $chapters[$chapIdx]['plain'] ?? strip_tags($chapters[$chapIdx]['content'] ?? '');
    if (!$fullAccess) $dl = mb_substr($dl, 0, (int)floor(mb_strlen($dl) * $paidFraction));
    $fname = preg_replace('/[^A-Za-z0-9 _-]/', '', $book['title']);
    if ($fname === '') $fname = 'book';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '.txt"');
    echo $dl;
    exit;
}

// লাইভ এডিট ও নতুন অধ্যায় সংরক্ষণ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_edit'])) {
        $chapters[$chapIdx]['title'] = $_POST['edited_title'];
        $chapters[$chapIdx]['content'] = $_POST['edited_content'];
        $chapters[$chapIdx]['plain'] = strip_tags($chapters[$chapIdx]['content']);
        saveChapters($id, $chapters);
    } elseif (isset($_POST['add_chapter'])) {
        $chapters[] = [
            'title' => htmlspecialchars($_POST['new_chap_title']),
            'content' => 'নতুন অধ্যায়ের লেখা এখানে এডিট করে যুক্ত করুন...',
            'plain' => ''
        ];
        saveChapters($id, $chapters);
        $chapIdx = count($chapters) - 1;
    }
}

// বাছাই করা কাগজের টেক্সচার সেট করা
$bgStyle = "background-color: #ffffff;";
$textColor = "#202122";
$currentPaperName = "সাধারণ কাগজ";

if ($paperIdx !== 'default' && isset($book['papers'][$paperIdx])) {
    $selectedPaper = $book['papers'][$paperIdx];
    $bgStyle = "background-image: url('" . $selectedPaper['image'] . "'); background-size: cover; background-attachment: fixed;";
    $textColor = $selectedPaper['textColor'];
    $currentPaperName = $selectedPaper['name'];
}

$currentChapter = $chapters[$chapIdx] ?? ($chapters[0] ?? ['title' => $book['title'] ?? 'প্রথম অধ্যায়', 'content' => '', 'plain' => '']);

// সূচিপত্র (বইয়ের প্রথম অধ্যায়ের ভেতর থেকে)
$plain0 = $chapters[0]['plain'] ?? strip_tags($chapters[0]['content'] ?? '');
$toc = parseBookToc($plain0);
$plainFull = $currentChapter['plain'] ?? strip_tags($currentChapter['content'] ?? '');
if ($fullAccess) {
    $bodyHtml = $currentChapter['content'];
    $isHtml = (strpos($bodyHtml, '<p') !== false || strpos($bodyHtml, '<table') !== false || strpos($bodyHtml, '<img') !== false);
    if ($chapIdx === 0 && !empty($toc)) {
        if ($isHtml) {
            // ফন্ট-ফ্যামিলি ইনলাইন স্টাইল সরানো (CSS ফন্ট স্ট্যাক কাজ করবে)
            $bodyHtml = preg_replace('/font-family:\s*[^;"]*;/u', '', $bodyHtml);
            // অধ্যায়ের শিরোনামগুলোকে নান্দনিক হেডিংয়ে রূপান্তর
            $bodyHtml = decorateChapterHeadings($bodyHtml, $toc);
        } else {
            $bodyHtml = injectBodyStartMarker($bodyHtml);
        }
    }
} else {
    // আংশিক আনলক — প্রথম অংশের পূর্বরূপ
    $isHtml = false;
    $showLen = (int)floor(mb_strlen($plainFull) * $paidFraction);
    $bodyHtml = mb_substr($plainFull, 0, $showLen);
}

// ক্যাটাগরি অনুযায়ী অটো-থিম (উইকিপিডিয়া টেমপ্লেটের সূক্ষ্ম রঙ)
$catAccents = [
    'স্বরবিজ্ঞান' => '#0369a1',
    'লালন' => '#15803d',
    'বলন' => '#be123c',
    'পৌরাণিক চরিত্র ও চার্ট' => '#b45309',
    'সংখ্যাতত্ত্ব' => '#6d28d9',
    'ধর্মীয় গ্রন্থ' => '#0f766e',
    'মহাকাব্য' => '#4338ca',
];
$accent = $catAccents[$book['category']] ?? '#3366cc';

// লেখার ভেতরের মার্কডাউন-ধাঁচের শিরোনাম থেকে উপ-অধ্যায়ের তালিকা
function extractHeadings($content) {
    $heads = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$content) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) { $heads[] = trim($m[1]); continue; }
        if (preg_match('/^\*\*(.+)\*\*$/u', $line, $m)) { $heads[] = trim($m[1]); continue; }
    }
    return $heads;
}

// বইয়ের ভেতরের "সূচিপত্র" অংশ থেকে অধ্যায় ও উপশিরোনাম বের করা
function parseBookToc($content) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    $n = count($lines);
    $first = -1; $last = -1;
    for ($i = 0; $i < $n; $i++) {
        if (strpos($lines[$i], '#') !== false) {
            if ($first === -1) $first = $i;
            $last = $i;
        }
    }
    if ($first === -1 || ($last - $first) < 3) return [];
    $start = $first;
    if ($start - 1 >= 0) {
        $h = trim($lines[$start - 1]);
        if ($h !== '' && strpos($h, '#') === false && mb_strlen($h) <= 40) $start--;
    }
    $chapters = [];
    $curIdx = -1;
    for ($i = $start; $i <= $last; $i++) {
        $t = trim($lines[$i]);
        if ($t === '') continue;
        if (mb_strpos($t, 'Contents') !== false) continue;
        $hasHash = strpos($t, '#') !== false;
        if (!$hasHash && mb_strpos($t, '(') === false && mb_strlen($t) <= 30) {
            $j = $i + 1;
            while ($j <= $last && trim($lines[$j]) === '') $j++;
            if ($j <= $last && strpos($lines[$j], '#') !== false) {
                $chapters[] = ['title' => $t, 'subs' => []];
                $curIdx = count($chapters) - 1;
                continue;
            }
        }
        if ($curIdx >= 0) {
            $sub = trim(preg_replace('/\s*#\s*\S+\s*$/u', '', $t));
            if ($sub !== '') $chapters[$curIdx]['subs'][] = $sub;
        }
    }
    return $chapters;
}

// সূচিপত্রের শেষে একটি মার্কার বসানো (ক্লায়েন্ট-সাইড স্ক্রলের সূচনাবিন্দু)
function injectBodyStartMarker($content) {
    $nl = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    $last = -1;
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], '#') !== false) $last = $i;
    }
    if ($last === -1) return $content;
    $pos = mb_strlen(implode($nl, array_slice($lines, 0, $last + 1)));
    return mb_substr($content, 0, $pos) . '<a id="toc-body-start"></a>' . mb_substr($content, $pos);
}

// HTML লেখার ক্ষেত্রে সূচিপত্রের শেষে মার্কার বসানো
function injectBodyStartMarkerHtml($html, $plain) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$plain);
    $last = -1;
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], '#') !== false) $last = $i;
    }
    if ($last === -1) return $html;
    $t = trim(preg_replace('/\s*#\s*\S+\s*$/u', '', trim($lines[$last])));
    if ($t === '') return $html;
    $needle = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $pos = mb_strpos($html, $needle);
    if ($pos === false) return $html;
    $endP = mb_strpos($html, '</p>', $pos);
    if ($endP === false) return $html;
    $at = $endP + mb_strlen('</p>');
    return mb_substr($html, 0, $at) . '<a id="toc-body-start"></a>' . mb_substr($html, $at);
}

// বইয়ের ভেতরের সূচিপত্র ব্লক (সহ # পেজ নম্বর) সরিয়ে দেওয়া
function stripTocFromHtml($html, $plain) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$plain);
    $last = -1;
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], '#') !== false) $last = $i;
    }
    if ($last === -1) return injectBodyStartMarkerHtml($html, $plain);
    $lastText = trim(preg_replace('/\s*#\s*\S+\s*$/u', '', trim($lines[$last])));
    $cpos = mb_strpos($html, 'Contents');
    $pStart = ($cpos !== false) ? mb_strrpos(mb_substr($html, 0, $cpos), '<p') : false;
    $removeEnd = false;
    if ($lastText !== '') {
        $needle = htmlspecialchars($lastText, ENT_QUOTES, 'UTF-8');
        $tpos = mb_strpos($html, $needle);
        if ($tpos !== false) {
            $e = mb_strpos($html, '</p>', $tpos);
            if ($e !== false) $removeEnd = $e + mb_strlen('</p>');
        }
    }
    if ($pStart === false || $removeEnd === false || $removeEnd <= $pStart) {
        return injectBodyStartMarkerHtml($html, $plain);
    }
    return mb_substr($html, 0, $pStart) . '<a id="toc-body-start"></a>' . mb_substr($html, $removeEnd);
}

// অধ্যায়ের শুরুতে নান্দনিক হেডিং ব্যানার বসানো
function decorateChapterHeadings($html, $toc) {
    foreach ($toc as $ch) {
        $subs = $ch['subs'] ?? [];
        $anchor = !empty($subs) ? tocKey($subs[0]) : tocKey($ch['title']);
        if ($anchor === '') continue;
        $needle = htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8');
        $pos = mb_strpos($html, $needle);
        if ($pos === false) continue;
        $pStart = mb_strrpos(mb_substr($html, 0, $pos), '<p');
        if ($pStart === false) continue;
        $banner = '<h2 class="mw-chapter-title">' . htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') . '</h2>';
        $html = mb_substr($html, 0, $pStart) . $banner . mb_substr($html, $pStart);
    }
    return $html;
}

// সূচিপত্রের শিরোনাম থেকে লেখার ভেতরে খোঁজার উপযোগী কী
function tocKey($t) {
    $t = preg_replace('/^\s*[০-৯0-9]+\s*[\.\)]\s*/u', '', (string)$t);
    $t = str_replace("\t", ' ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    $p = mb_strpos($t, ' (');
    if ($p !== false && $p >= 3) $t = mb_substr($t, 0, $p);
    return trim($t);
}

$references = $book['references'] ?? [];

// বইয়ের লেখা থেকে গুরুত্বপূর্ণ টীকা, নোট ও ব্যাখ্যা সংগ্রহ
function extractBookNotes($plain, $book = []) {
    $notes = [];
    if (!empty($book['notes']) && is_array($book['notes'])) {
        foreach ($book['notes'] as $n) {
            if (!is_array($n)) continue;
            $t = trim((string)($n['title'] ?? 'টীকা'));
            $b = trim((string)($n['body'] ?? ($n['text'] ?? '')));
            $type = trim((string)($n['type'] ?? 'নোট'));
            if ($b !== '') $notes[] = ['type' => $type, 'title' => $t, 'body' => $b, 'needle' => $t];
        }
    }
    $lines = preg_split('/\r\n|\r|\n/', (string)$plain);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;
        $type = null;
        if (preg_match('/^টীকা(?:\s*[-\s]?[০-৯0-9]+)?\s*\(Annotation[^)]*\)$/u', $line)
            || preg_match('/^টীকা(?:\s*[-\s]?[০-৯0-9]+)?\s*[:\-–]/u', $line)) {
            $type = 'টীকা';
        } elseif (preg_match('/^ব্যাখ্যা(?:\s*[-\s]?[০-৯0-9]+)?\s*\(Explanation[^)]*\)$/u', $line)
            || preg_match('/^ব্যাখ্যা(?:\s*[-\s]?[০-৯0-9]+)?\s*[:\-–]/u', $line)) {
            $type = 'ব্যাখ্যা';
        } elseif (preg_match('/^নোট(?:\s*[-\s]?[০-৯0-9]+)?\s*\(Note[^)]*\)$/u', $line)
            || preg_match('/^নোট(?:\s*[-\s]?[০-৯0-9]+)?\s*[:\-–]/u', $line)) {
            $type = 'নোট';
        }
        if ($type === null) continue;
        $body = '';
        for ($j = $i + 1; $j < $n; $j++) {
            $nxt = trim($lines[$j]);
            if ($nxt !== '') { $body = $nxt; break; }
        }
        if ($body === '') continue;
        if (mb_strlen($body) > 260) {
            $body = mb_substr($body, 0, 257) . '…';
        }
        $notes[] = [
            'type' => $type,
            'title' => $line,
            'body' => $body,
            'needle' => mb_substr($body, 0, 45),
        ];
    }
    return $notes;
}
$bookNotes = extractBookNotes($fullAccess ? $plainFull : $bodyHtml, $book);

// সূচির নিচের পরিশিষ্ট শিরোনামসমূহ (বইয়ে থাকলে বাম পাশের সূচিতে যুক্ত হবে)
$appendixCandidates = [
    'ব্যবহৃত সঙ্কেতাদি',
    'পৌরাণিক ৪০ মূলক সংখ্যার সারণী',
    'শ্বরবিজ্ঞান বিষয়ক শব্দসংক্ষেপ',
    'ব্যাকরণ বিষয়ক শব্দসংক্ষেপ',
    'ভাষা বিষয়ক শব্দসংক্ষেপ',
    'বিষয় বিষয়ক শব্দসংক্ষেপ',
    'আরবি, ফার্সি, উর্দু ও হিন্দি ভাষার লিপ্যন্তর নিয়মাবলী',
    'লিপ্যন্তর- ১',
    'লিপ্যন্তর- ২',
    'প্রামাণ্য গ্রন্থপঞ্জি',
    'অন্যান্য গ্রন্থাদি',
    'কাঁই পরিবারের প্রামাণ্য গ্রন্থপঞ্জি (সন্নিবদ্ধ চলমান)',
    'কাঁই পরিবারের প্রকাশিত গ্রন্থসমূহ',
    'বলন বিষয়ক গ্রন্থ',
    'লালন বিষয়ক গ্রন্থ',
    'বৈদিক সঙ্কেত',
];
$appendixToc = [];
if ($plain0 !== '') {
    $normBn = function ($s) {
        if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C);
        return str_replace(["\xE0\xA6\xAF\xE0\xA6\xBC", "\xE0\xA6\xA1\xE0\xA6\xBC", "\xE0\xA6\xA2\xE0\xA6\xBC"], ["\xE0\xA7\x9F", "\xE0\xA7\x9C", "\xE0\xA7\x9D"], $s);
    };
    $plain0Norm = $normBn($plain0);
    foreach ($appendixCandidates as $cand) {
        if (mb_strpos($plain0Norm, $normBn(tocKey($cand))) !== false) {
            $appendixToc[] = $cand;
        }
    }
}

// বইয়ের ভেতরে সার্চের জন্য অধ্যায়ের লেখা (অ্যাক্সেস অনুযায়ী সীমিত)
$searchChapters = [];
if ($fullAccess) {
    foreach ($chapters as $ci => $ch) {
        $searchChapters[] = [
            'idx' => $ci,
            'title' => $ch['title'],
            'plain' => $ch['plain'] ?? strip_tags($ch['content'] ?? ''),
        ];
    }
} else {
    $showLen = (int)floor(mb_strlen($plainFull) * $paidFraction);
    $searchChapters[] = [
        'idx' => $chapIdx,
        'title' => $currentChapter['title'],
        'plain' => mb_substr($plainFull, 0, $showLen),
    ];
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($book['title']) ?> - Mythoscopedia</title>
    <style>
        :root {
            --accent: <?= $accent ?>;
            --wp-border: #a2a9b1;
            --wp-border-soft: #eaecf0;
            --wp-link: #3366cc;
            --wp-link-visited: #795cb2;
            --wp-redlink: #d73333;
            --wp-bg: #ffffff;
            --wp-bg-soft: #f8f9fa;
            --wp-ink: #202122;
            --wp-muted: #54595d;
            --wp-sidebar-w: clamp(210px, 20vw, 250px);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; width: 100%; max-width: 100%; overflow-x: clip; }
        body {
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: clip;
            background: var(--wp-bg-soft);
            color: var(--wp-ink);
            font-family: 'Times New Roman', 'SolaimanLipi', 'Al-Majid Quranic', 'Noto Sans Bengali', 'Noto Naskh Arabic', serif;
            font-size: 15px;
            line-height: 1.65;
        }
        a { color: var(--wp-link); text-decoration: none; }
        a:visited { color: var(--wp-link-visited); }
        a:hover { text-decoration: underline; }

        /* ---------- বাম ফিক্সড সাইডবার (উইকিপিডিয়া Vector) ---------- */
        .mw-app {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: flex-start;
            background: var(--wp-bg);
            min-height: 100vh;
        }
        @media (min-width: 1281px) {
            .mw-app {
                border-left: 1px solid var(--wp-border-soft);
                border-right: 1px solid var(--wp-border-soft);
            }
        }
        .mw-sidebar {
            position: sticky; top: 0;
            width: var(--wp-sidebar-w); height: 100vh; flex: none;
            background: var(--wp-bg-soft);
            border-right: 1px solid var(--wp-border-soft);
            overflow-y: auto; padding: 0 0 2rem;
            z-index: 20;
        }
        .mw-logo {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 16px; border-bottom: 1px solid var(--wp-border-soft);
        }
        .mw-logo img { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; }
        .mw-logo .mw-logo-text { font-weight: 700; line-height: 1.2; }
        .mw-logo .mw-logo-text small { display: block; color: var(--wp-muted); font-weight: 400; font-size: 11px; }
        .mw-nav { padding: 10px 8px; }
        .mw-nav .mw-nav-title {
            font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
            color: var(--wp-muted); font-weight: 700; padding: 8px 10px 4px;
        }
        .mw-nav .mw-nav-title .mw-nav-title-main {
            font-size: 16px;
            font-weight: 700;
            color: #e91e63;
        }
        .mw-nav a.mw-back {
            display: block; padding: 8px 10px; border-radius: 4px;
            background: var(--accent); color: #fff; font-weight: 700; text-align: center; margin-bottom: 12px;
        }
        .mw-nav a.mw-back:hover { text-decoration: none; filter: brightness(.95); }
        .mw-toc-list { list-style: none; margin: 0; padding: 0; }
        .mw-toc-list li { margin: 0; }
        .mw-toc-row { display: flex; align-items: center; flex-wrap: nowrap; gap: 2px; }
        .mw-toc-row .mw-toc-link {
            flex: 1 1 auto; min-width: 0;
            display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .mw-toc-link,
        .mw-toc-link:visited {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 8px; border-radius: 4px; color: #bf3c2c; font-size: 14px;
        }
        .mw-toc-link:hover { background: var(--wp-border-soft); text-decoration: none; }
        .mw-toc-link.active { background: var(--wp-border-soft); font-weight: 700; box-shadow: inset 3px 0 0 var(--accent); }
        .mw-toc-link.mw-toc-appendix,
        .mw-toc-link.mw-toc-appendix:visited {
            color: var(--wp-link);
            padding-left: 22px;
        }
        .mw-toc-link.mw-toc-appendix:hover {
            color: var(--wp-link);
            text-decoration: underline;
        }
        .mw-caret {
            flex: none; width: 1.2em; height: 1.2em; border: 0; background: none; cursor: pointer;
            color: var(--wp-muted); font-size: 15px; line-height: 1; padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mw-toc-chapter.collapsed > .mw-toc-row .mw-caret {
            font-size: 17px;
            width: 18px;
        }
        .mw-caret:hover { color: var(--accent); }
        .mw-sublist { list-style: none; margin: 2px 0 4px 22px; padding: 0; border-left: 1px solid var(--wp-border-soft); }
        .mw-sublist li a { display: block; padding: 3px 8px; color: var(--wp-muted); font-size: 12.5px; }
        .mw-sublist li a:hover { color: var(--accent); text-decoration: underline; }
        .mw-toc-list li.collapsed > .mw-sublist { display: none; }
        .mw-addchap { margin: 14px 12px 0; padding-top: 12px; border-top: 1px solid var(--wp-border-soft); }
        .mw-addchap input {
            width: 100%; padding: 7px 8px; border: 1px solid var(--wp-border); border-radius: 4px;
            font-size: 13px; margin-bottom: 8px; font-family: inherit;
        }
        .mw-addchap button {
            width: 100%; padding: 7px; border: 1px solid var(--accent); background: var(--accent); color: #fff;
            border-radius: 4px; cursor: pointer; font-weight: 700; font-size: 13px; font-family: inherit;
        }
        .mw-addchap button:hover { filter: brightness(.95); }

        /* ---------- মূল অংশ ---------- */
        .mw-main { margin-left: 0; flex: 1 1 auto; min-width: 0; overflow-x: clip; }
        .mw-topbar {
            position: sticky; top: 0; z-index: 15;
            background: var(--wp-bg); border-bottom: 1px solid var(--wp-border-soft);
            padding: 8px clamp(12px, 3vw, 32px);
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        }
        .mw-topbar .mw-tabs { display: flex; gap: 2px; margin-right: auto; }
        .mw-tab {
            padding: 6px 12px; border: 1px solid var(--wp-border-soft); border-bottom: none;
            border-radius: 4px 4px 0 0; background: var(--wp-bg-soft); color: var(--wp-ink);
            font-size: 13px; cursor: pointer; font-family: inherit;
        }
        .mw-tab.active { background: var(--wp-bg); font-weight: 700; border-color: var(--wp-border-soft); }
        .mw-tool {
            padding: 6px 10px; border: 1px solid var(--wp-border); border-radius: 4px;
            background: var(--wp-bg); color: var(--wp-ink); font-size: 13px; cursor: pointer; font-family: inherit;
        }
        .mw-tool:hover { background: var(--wp-bg-soft); }
        .mw-tool.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 700; }
        .mw-tool.primary:hover { filter: brightness(.95); }
        .mw-tool.danger { background: #d73333; color: #fff; border-color: #d73333; font-weight: 700; }
        .mw-tool.plain { background: var(--wp-bg-soft); }
        .mw-select {
            padding: 6px 8px; border: 1px solid var(--wp-border); border-radius: 4px;
            background: var(--wp-bg); color: var(--wp-ink); font-size: 13px; font-family: inherit;
        }
        .mw-color { width: 34px; height: 30px; padding: 0; border: 1px solid var(--wp-border); border-radius: 4px; background: none; cursor: pointer; }

        .mw-content-wrap { padding: clamp(10px, 1.5vw, 18px) 10px clamp(16px, 2vw, 24px) clamp(12px, 2vw, 22px); width: 100%; max-width: 100%; margin: 0 auto; }
        .mw-paypanel { width: calc(100% - clamp(24px, 4vw, 48px)); max-width: 100%; margin: 16px auto 0; padding: 16px 20px; border: 1px solid var(--wp-border); border-left: 5px solid var(--accent); background: var(--wp-bg-soft); border-radius: 6px; }
        .mw-paypanel h3 { margin: 0 0 8px; font-size: 1.1rem; color: var(--accent); }
        .mw-paypanel p { margin: .3rem 0; font-size: .9rem; }
        .mw-payform { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 10px 0; }
        .mw-payform input { padding: 8px 10px; border: 1px solid var(--wp-border); border-radius: 4px; font-size: 14px; min-width: 220px; }
        .mw-payform select { padding: 8px 10px; border: 1px solid var(--wp-border); border-radius: 4px; font-size: 14px; }
        .mw-paytarget { font-size: .85rem; background: var(--wp-bg); border: 1px solid var(--wp-border-soft); padding: 6px 10px; border-radius: 4px; margin: .4rem 0; }
        .mw-paynote { color: var(--wp-muted); font-size: .82rem !important; }
        .mw-article {
            background: var(--wp-bg);
            border: 1px solid var(--wp-border-soft);
            border-radius: 2px;
            padding: clamp(16px, 2.6vw, 32px) 12px clamp(18px, 3vw, 36px) clamp(16px, 3vw, 36px);
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            background-clip: padding-box;
            display: flow-root;
            max-width: 100%;
            overflow-x: clip;
        }
        .mw-article-main {
            min-width: 0;
        }
        /* নির্বাচিত কাগজের টেক্সচার মূল আর্টিকেল (বডি) অংশে */
        .mw-body.papered { <?= $bgStyle ?> color: <?= $textColor ?>; padding: 16px 20px; border-radius: 4px; }

        .mw-title {
            font-family: 'Times New Roman', 'SolaimanLipi', 'Al-Majid Quranic', 'Noto Sans Bengali', 'Noto Naskh Arabic', serif;
            font-size: 1.9rem; font-weight: 400; line-height: 1.25;
            margin: 0 0 .4rem; padding-bottom: .3rem; border-bottom: 1px solid var(--wp-border-soft);
        }
        .mw-subtitle { color: var(--wp-muted); font-size: .9rem; margin: 0 0 1.4rem; }

        /* ---------- ডান ইনফোবক্স (উইকিপিডিয়া-ধাঁচের ৩য় কলাম, কনটেন্ট অনুযায়ী উচ্চতা ও ডানে ভাসমান) ---------- */
        .mw-infobox {
            float: right;
            clear: right;
            position: relative;
            z-index: 1;
            width: clamp(260px, 25vw, 336px);
            max-width: 100%;
            min-width: 0;
            height: auto;
            margin: 0 0 16px clamp(14px, 2vw, 24px);
            display: flex; flex-direction: column; gap: 14px;
            font-size: .86rem; line-height: 1.5; overflow-wrap: anywhere;
        }
        .mw-ib-card {
            width: 100%;
            border: 1px solid #d5dbe3;
            border-radius: 14px;
            background: var(--wp-bg-soft);
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
        }
        .mw-ib-title {
            background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 82%, #0f172a));
            color: #fff; font-weight: 700; text-align: center;
            padding: 9px 12px; font-size: .96rem; letter-spacing: .01em;
        }
        .mw-ib-cover { padding: 10px; text-align: center; background: #fff; }
        .mw-ib-cover img {
            max-width: 100%; height: auto; display: block; margin: 0 auto;
            border-radius: 8px; border: 1px solid var(--wp-border-soft);
        }
        .mw-ib-author-card {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .mw-ib-author {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 8%, #ffffff), #f8fafc);
            border-bottom: 1px solid var(--wp-border-soft);
        }
        .mw-ib-author img {
            width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex: none;
            border: 2px solid color-mix(in srgb, var(--accent) 45%, #ffffff);
            box-shadow: 0 2px 6px rgba(15, 23, 42, .12);
        }
        .mw-ib-author .mw-ib-label { color: var(--wp-muted); font-size: .74rem; font-weight: 600; }
        .mw-ib-author .mw-ib-name { font-weight: 700; font-size: .96rem; color: var(--wp-ink); }
        .mw-ib-table { width: 100%; border-collapse: collapse; }
        .mw-ib-table th, .mw-ib-table td { border-top: 1px solid var(--wp-border-soft); padding: 6px 12px; text-align: left; vertical-align: top; }
        .mw-ib-table th { width: 38%; color: var(--wp-muted); font-weight: 700; }
        .mw-ib-table tr:first-child th, .mw-ib-table tr:first-child td { border-top: 0; }

        /* ---------- গ্রন্থপরিচিতি ও প্রকাশনা কার্ড (কলোফন) ---------- */
        .mw-ib-colophon {
            background: linear-gradient(180deg, #fffdf9 0%, #f8fafc 100%);
            border: 1px solid color-mix(in srgb, var(--accent) 28%, #d5dbe3);
            position: relative;
        }
        .mw-col-head {
            padding: 13px 14px 10px;
            text-align: center;
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 13%, #ffffff), color-mix(in srgb, var(--accent) 5%, #fff8eb));
            border-bottom: 1px solid color-mix(in srgb, var(--accent) 22%, #e2e8f0);
        }
        .mw-col-ornament {
            font-size: .72rem; font-weight: 700; letter-spacing: .08em;
            color: var(--accent); margin-bottom: 5px;
        }
        .mw-col-booktitle {
            font-size: .96rem; font-weight: 700; color: #0f172a; line-height: 1.3; margin: 0;
        }
        .mw-col-body { padding: 6px 10px 10px; }
        .mw-col-table {
            width: 100%; border-collapse: collapse; font-size: .81rem; line-height: 1.38;
        }
        .mw-col-table th, .mw-col-table td {
            padding: 5px 6px; vertical-align: top;
            border-top: 1px solid color-mix(in srgb, var(--accent) 14%, #e2e8f0);
        }
        .mw-col-table tr:first-child th, .mw-col-table tr:first-child td { border-top: 0; }
        .mw-col-table th {
            width: 28%; color: #0f172a; font-weight: 700; text-align: right;
            padding-right: 9px; white-space: nowrap;
            border-right: 1px solid color-mix(in srgb, var(--accent) 22%, #cbd5e1);
        }
        .mw-col-table td {
            color: #1e293b; text-align: left; padding-left: 9px;
        }
        .mw-col-line { margin: 0; line-height: 1.38; }
        .mw-col-cover-credit { color: #2563eb; font-weight: 600; }
        .mw-col-typeset-credit { color: #15803d; font-weight: 600; }
        .mw-col-isbn-label { color: #15803d; font-weight: 700; }

        /* ---------- ৪. গুরুত্বপূর্ণ টীকা, নোট ও ব্যাখ্যা বক্স (কনটেন্ট অনুযায়ী অটো-উচ্চতা) ---------- */
        .mw-ib-notes-card {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            background: linear-gradient(180deg, #fffdf9 0%, #f8fafc 100%);
            border: 1px solid color-mix(in srgb, var(--accent) 26%, #d5dbe3);
        }
        .mw-notes-head {
            padding: 11px 13px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 13%, #ffffff), color-mix(in srgb, var(--accent) 5%, #fff8eb));
            border-bottom: 1px solid color-mix(in srgb, var(--accent) 22%, #e2e8f0);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .mw-notes-title {
            font-size: .88rem; font-weight: 700; color: var(--accent); letter-spacing: .01em;
        }
        .mw-notes-count {
            font-size: .74rem; font-weight: 700; padding: 1px 8px; border-radius: 999px;
            background: #fff; color: var(--accent);
            border: 1px solid color-mix(in srgb, var(--accent) 30%, #cbd5e1);
        }
        .mw-notes-body {
            padding: 10px 11px;
            display: flex; flex-direction: column; gap: 9px;
        }
        .mw-note-item {
            background: rgba(255, 255, 255, .92);
            border: 1px solid #e6ebf1;
            border-left: 3px solid var(--accent);
            border-radius: 9px;
            padding: 8px 10px;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .mw-note-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15, 23, 42, .07);
            border-color: color-mix(in srgb, var(--accent) 45%, #cbd5e1);
        }
        .mw-note-item.is-byakhya { border-left-color: #b45309; }
        .mw-note-item.is-note { border-left-color: #0f766e; }
        .mw-note-meta {
            display: flex; align-items: center; gap: 6px; margin-bottom: 4px; flex-wrap: wrap;
        }
        .mw-note-badge {
            font-size: .69rem; font-weight: 700; padding: 1px 7px; border-radius: 999px;
            background: color-mix(in srgb, var(--accent) 12%, #ffffff);
            color: var(--accent);
            border: 1px solid color-mix(in srgb, var(--accent) 25%, #cbd5e1);
        }
        .mw-note-item.is-byakhya .mw-note-badge {
            background: #fef3c7; color: #92400e; border-color: #fcd34d;
        }
        .mw-note-item.is-note .mw-note-badge {
            background: #ccfbf1; color: #115e59; border-color: #5eead4;
        }
        .mw-note-heading {
            font-size: .8rem; font-weight: 700; color: #0f172a; line-height: 1.3;
        }
        .mw-note-text {
            font-size: .79rem; color: #334155; line-height: 1.5; text-align: justify;
        }
        .mw-notes-empty {
            padding: 14px 10px; text-align: center; color: var(--wp-muted);
            font-size: .81rem; line-height: 1.5;
        }
        .mw-notes-footer {
            margin-top: auto;
            padding: 8px 12px;
            text-align: center;
            font-size: .74rem;
            color: var(--wp-muted);
            border-top: 1px dashed color-mix(in srgb, var(--accent) 22%, #cbd5e1);
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 6%, #f8fafc), #f1f5f9);
        }
        .mw-note-flash {
            animation: mwNoteFlash 2s ease;
        }
        @keyframes mwNoteFlash {
            0%, 25% { background-color: #fef08a; }
            100% { background-color: transparent; }
        }

        /* ---------- মূল লেখা ---------- */
        .mw-body { min-height: 300px; font-size: 18px; line-height: 1.6; overflow: visible; overflow-wrap: anywhere; text-align: justify; text-justify: inter-word; }
        .mw-body table { max-width: 100%; font-size: inherit; text-align: initial; line-height: 1.6; }
        .mw-body td, .mw-body th { overflow-wrap: anywhere; word-break: break-word; line-height: 1.6; }
        .mw-body table p { line-height: 1.6; }
        .mw-body h1, .mw-body h2, .mw-body h3, .mw-body h4 {
            font-family: 'Times New Roman', 'SolaimanLipi', 'Al-Majid Quranic', 'Noto Sans Bengali', 'Noto Naskh Arabic', serif;
            font-weight: 400; line-height: 1.3; margin: 1.6rem 0 .6rem; scroll-margin-top: 4rem; text-align: left;
            overflow: hidden;
        }
        .mw-body h2 { font-size: 1.5rem; border-bottom: 1px solid var(--wp-border-soft); padding-bottom: .25rem; }
        .mw-body h2.mw-chapter-title {
            text-align: center; font-size: 2.1rem; font-weight: 700;
            color: var(--accent); margin: 3rem 0 1.6rem;
            padding: .8rem 1rem; border: 0;
            border-top: 3px double var(--accent); border-bottom: 3px double var(--accent);
            background: var(--wp-bg-soft); letter-spacing: .02em; line-height: 1.35;
        }
        .mw-body h2.mw-chapter-title::before { content: "\2766"; display: block; font-size: 1.4rem; margin-bottom: .3rem; color: var(--accent); }
        .mw-body h3 { font-size: 1.22rem; }
        .mw-body p { margin: 0 0 .5rem; line-height: 1.6; scroll-margin-top: 4rem; text-align: justify; }
        .mw-body ul, .mw-body ol { margin: 0 0 1rem; padding-left: 1.6rem; line-height: 1.6; }
        .mw-body a { color: var(--wp-link); }
        .mw-body a:visited { color: var(--wp-link-visited); }
        .mw-body a.mw-redlink { color: var(--wp-redlink); }
        .mw-body blockquote { margin: 1rem 0; padding: .5rem 1rem; border-left: 4px solid var(--accent); background: var(--wp-bg-soft); }
        .mw-body img { max-width: 100%; height: auto; }
        .mw-body[contenteditable="true"] { outline: 2px dashed var(--accent); outline-offset: 6px; }
        .mw-body:focus { outline: none; }

        /* ---------- রেফারেন্স ---------- */
        .mw-references { margin-top: 2.4rem; border-top: 1px solid var(--wp-border-soft); padding-top: .6rem; }
        .mw-references h2 { font-family: 'Times New Roman', 'SolaimanLipi', 'Al-Majid Quranic', 'Noto Sans Bengali', 'Noto Naskh Arabic', serif; font-weight: 400; font-size: 1.4rem; border-bottom: 1px solid var(--wp-border-soft); padding-bottom: .25rem; }
        .mw-references ol { padding-left: 1.6rem; font-size: .9rem; color: var(--wp-ink); }
        .mw-references .mw-ref-note { color: var(--wp-muted); font-size: .9rem; }

        /* ---------- মোবাইল ---------- */
        .mw-menu-btn { display: none; }
        @media (max-width: 900px) {
            .mw-app { display: block; }
            .mw-sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); transition: transform .2s; box-shadow: 2px 0 12px rgba(0,0,0,.15); }
            .mw-sidebar.open { transform: translateX(0); }
            .mw-main { margin-left: 0; }
            .mw-menu-btn { display: inline-block; }
            .mw-article { display: block; padding: 16px; }
            .mw-infobox { float: none; width: 100%; height: auto; margin: 0 0 1.2rem 0; }
        }
        @media print {
            .mw-app { display: block; }
            .mw-sidebar, .mw-topbar, .mw-menu-btn { display: none !important; }
            .mw-main { margin-left: 0; }
            .mw-article { display: block; border: 0; box-shadow: none; padding: 0; }
            .mw-infobox { float: none; width: 100%; height: auto; margin: 0 0 1.2rem 0; }
        }

        /* ---------- বইয়ের ভেতরে সার্চ মডাল ---------- */
        .bs-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; }
        .bs-box { background: var(--wp-bg); width: min(640px, 92vw); max-height: 82vh; display: flex; flex-direction: column; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.3); overflow: hidden; }
        .bs-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--wp-border-soft); }

        /* ---------- মোবাইল ভিউ প্রিভিউ মডাল ---------- */
        .mv-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); z-index: 1001; align-items: center; justify-content: center; padding: 16px; }
        .mv-box { background: var(--wp-bg); border-radius: 12px; box-shadow: 0 16px 48px rgba(0,0,0,.35); display: flex; flex-direction: column; align-items: center; padding: 12px 16px 16px; max-width: 96vw; max-height: 96vh; }
        .mv-head { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border-soft); }
        .mv-head h3 { margin: 0; font-size: 1rem; font-weight: 600; color: var(--wp-ink); }
        .mv-actions { display: flex; align-items: center; gap: 6px; }
        .mv-phone { width: 375px; max-width: 100%; height: min(812px, calc(100vh - 110px)); border: 10px solid #1e293b; border-radius: 28px; overflow: hidden; background: #fff; box-shadow: 0 12px 32px rgba(0,0,0,.25); }
        .mv-phone iframe { width: 100%; height: 100%; border: 0; display: block; }
        .bs-head h3 { margin: 0; font-size: 1.05rem; color: var(--accent); }
        .bs-close { border: 0; background: none; font-size: 24px; cursor: pointer; color: var(--wp-muted); line-height: 1; }
        .bs-searchbar { display: flex; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--wp-border-soft); }
        .bs-searchbar input { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid var(--wp-border); border-radius: 4px; font-size: 14px; font-family: inherit; }
        .bs-results { overflow-y: auto; padding: 8px 16px 16px; }
        .bs-empty, .bs-count { color: var(--wp-muted); font-size: .9rem; margin: 8px 0; }
        .bs-result { display: block; padding: 10px; border: 1px solid var(--wp-border-soft); border-radius: 6px; margin: 8px 0; color: var(--wp-ink); }
        .bs-result:hover { border-color: var(--accent); text-decoration: none; background: var(--wp-bg-soft); }
        .bs-rtitle { display: block; font-weight: 700; margin-bottom: 4px; }
        .bs-badge { background: var(--accent); color: #fff; border-radius: 10px; padding: 1px 8px; font-size: 12px; margin-left: 6px; }
        .bs-snippet { font-size: .88rem; color: var(--wp-muted); }
        .bs-snippet mark { background: #fde68a; color: inherit; padding: 0 2px; border-radius: 2px; }
    </style>
</head>
<body>

<div class="mw-app">

<!-- বাম ফিক্সড সাইডবার -->
<aside class="mw-sidebar" id="mwSidebar">
    <div class="mw-logo">
        <?php if (!empty($book['cover'])): ?>
            <img src="<?= htmlspecialchars($book['cover']) ?>" alt="লোগো">
        <?php endif; ?>
        <div class="mw-logo-text">মিথোস্কোপিডিয়া<small>মিথোলজি → থিওলজি</small></div>
    </div>
    <nav class="mw-nav">
        <a href="index.php" class="mw-back">← সব বইয়ের তালিকায় ফিরুন</a>
        <div class="mw-nav-title"><span class="mw-nav-title-main">সূচিপত্র</span> (অধ্যায়সমূহ)</div>
        <ul class="mw-toc-list">
            <?php if (!empty($toc)): ?>
                <?php foreach ($toc as $ci => $ch): ?>
                    <li class="mw-toc-chapter <?= $ci === 0 ? '' : 'collapsed' ?>">
                        <div class="mw-toc-row">
                            <button type="button" class="mw-caret" onclick="toggleSub(this)" aria-label="টগল"><?= $ci === 0 ? '▼' : '▸' ?></button>
                            <a href="#mwBody" class="mw-toc-link" data-key="<?= htmlspecialchars(tocKey($ch['title'])) ?>" data-firstsub="<?= htmlspecialchars(tocKey($ch['subs'][0] ?? $ch['title'])) ?>" onclick="return scrollToToc(this)"><?= htmlspecialchars($ch['title']) ?></a>
                        </div>
                        <?php if (!empty($ch['subs'])): ?>
                            <ul class="mw-sublist">
                                <?php foreach ($ch['subs'] as $si => $sh): ?>
                                    <li><a href="#mwBody" data-key="<?= htmlspecialchars(tocKey($sh)) ?>" onclick="return scrollToToc(this)"><?= htmlspecialchars($sh) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($chapters as $index => $chap): ?>
                    <li><a href="?id=<?= $id ?>&paper=<?= $paperIdx ?>&chap=<?= $index ?>" class="mw-toc-link"><?= htmlspecialchars($chap['title']) ?></a></li>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($appendixToc)): ?>
                <?php foreach ($appendixToc as $appTitle): ?>
                    <li class="mw-toc-appendix-item">
                        <a href="#mwBody" class="mw-toc-link mw-toc-appendix" data-appendix="1" data-key="<?= htmlspecialchars(tocKey($appTitle)) ?>" onclick="return scrollToToc(this)"><?= htmlspecialchars($appTitle) ?></a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <form method="POST" class="mw-addchap">
            <input type="text" name="new_chap_title" placeholder="নতুন অধ্যায়ের নাম..." required>
            <button type="submit" name="add_chapter">+ অধ্যায় যুক্ত করুন</button>
        </form>
    </nav>
</aside>

<!-- মূল অংশ -->
<div class="mw-main">
    <div class="mw-topbar">
        <button type="button" class="mw-tool plain mw-menu-btn" onclick="document.getElementById('mwSidebar').classList.toggle('open')">☰</button>
        <div class="mw-tabs">
            <button type="button" class="mw-tab" onclick="setEditMode(true, this)">✏️ সম্পাদনা</button>
            <button type="button" class="mw-tab active" onclick="setEditMode(false, this)">👁 দেখুন</button>
        </div>

        <select class="mw-select" onchange="window.location.href='?id=<?= $id ?>&chap=<?= $chapIdx ?>&paper=' + this.value">
            <option value="">কাগজ পরিবর্তন করুন...</option>
            <?php foreach (($book['papers'] ?? []) as $pIdx => $p): ?>
                <option value="<?= $pIdx ?>" <?= $paperIdx == $pIdx ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <a href="index.php#uploadPanel" class="mw-tool primary" style="margin-right: 8px;">⬆ আপলোডে যান</a>

        <div style="display:flex; gap:6px; align-items:center;">
            <select id="voiceSelector" class="mw-select" aria-label="কণ্ঠ নির্বাচন">
                <optgroup label="👩 নারী কণ্ঠসমূহ (Female Voices)">
                    <option value="f1">১. নারী কণ্ঠ - সুমধুর (স্বাভাবিক)</option>
                    <option value="f2">২. নারী কণ্ঠ - স্নিগ্ধ ও ধীরস্থির</option>
                    <option value="f3">৩. নারী কণ্ঠ - স্পষ্ট ও প্রাণবন্ত</option>
                    <option value="f4">৪. নারী কণ্ঠ - কোমল ও মায়াবী</option>
                    <option value="f5">৫. নারী কণ্ঠ - গম্ভীর ও আবৃত্তিমূলক</option>
                </optgroup>
                <optgroup label="👨 পুরুষ কণ্ঠসমূহ (Male Voices)">
                    <option value="m1">১. পুরুষ কণ্ঠ - ভরাট ও গম্ভীর</option>
                    <option value="m2">২. পুরুষ কণ্ঠ - স্বাভাবিক ও স্পষ্ট</option>
                    <option value="m3">৩. পুরুষ কণ্ঠ - ধীরস্থির ও শান্ত</option>
                    <option value="m4">৪. পুরুষ কণ্ঠ - দীপ্ত ও প্রাণবন্ত</option>
                    <option value="m5">৫. পুরুষ কণ্ঠ - প্রবীণ ও দার্শনিক</option>
                </optgroup>
            </select>
            <button type="button" class="mw-tool primary" onclick="startReadingBook()">🔊 শুনুন</button>
            <button type="button" class="mw-tool danger" onclick="stopReadingBook()">⏹ বন্ধ</button>
        </div>

        <button type="button" class="mw-tool" onclick="document.execCommand('bold')"><b>B</b></button>
        <button type="button" class="mw-tool" onclick="document.execCommand('italic')"><i>I</i></button>
        <button type="button" class="mw-tool" onclick="document.execCommand('underline')"><u>U</u></button>
        <input type="color" class="mw-color" onchange="document.execCommand('foreColor', false, this.value)" title="লেখার রঙ">

        <button type="button" class="mw-tool primary" onclick="savePage()">💾 সেভ করুন</button>
        <button type="button" class="mw-tool" onclick="window.print()">🖨️ প্রিন্ট / PDF</button>
        <a class="mw-tool" href="?id=<?= $id ?>&paper=<?= $paperIdx ?>&chap=<?= $chapIdx ?>&download=1">⬇️ ডাউনলোড<?= $fullAccess ? '' : ' (আনলক অংশ)' ?></a>
        <button type="button" class="mw-tool" onclick="openBookSearch()">🔍 বইয়ের ভিতরে খুঁজুন</button>
        <button type="button" class="mw-tool" onclick="openMobilePreview()">📱 মোবাইলে দেখুন</button>
    </div>

    <?php if (!$fullAccess): ?>
    <div class="mw-paypanel">
        <h3>টাকা পরিশোধ ও আনলক</h3>
        <p>বইয়ের মূল্য: <b><?= htmlspecialchars($book['price']) ?></b> · জমা: <b><?= (int)$paidAmount ?> ৳</b> · আনলক: <b><?= round($paidFraction * 100) ?>%</b><?= $pendingCount ? ' · যাচাইাধীন: ' . $pendingCount . 'টি' : '' ?></p>
        <form method="POST" class="mw-payform">
            <input type="hidden" name="action" value="pay">
            <select name="method" id="payMethod" onchange="payMethodChange()">
                <?php foreach (payMethods() as $m): ?>
                    <option value="<?= htmlspecialchars($m['key']) ?>"><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="amount" min="1" step="1" value="<?= (int)$paidAmount ?: '' ?>" placeholder="পরিমাণ (৳)" required>
            <input type="text" name="name" placeholder="আপনার নাম">
            <input type="text" name="contact" placeholder="মোবাইল/ইমেইল">
            <input type="text" name="trxid" id="payTrx" placeholder="ট্রানজেকশন আইডি (TrxID)">
            <button type="submit" class="mw-tool primary">জমা দিন</button>
        </form>
        <div id="payTargets">
            <?php foreach (payMethods() as $m): ?>
                <p class="mw-paytarget" data-key="<?= htmlspecialchars($m['key']) ?>"<?= $m['key'] === 'bkash' ? '' : ' style="display:none"' ?>>
                    <b><?= htmlspecialchars($m['name']) ?></b>: <?= htmlspecialchars($m['target'] !== '' ? $m['target'] : $m['note']) ?> — <span><?= htmlspecialchars($m['note']) ?></span>
                </p>
            <?php endforeach; ?>
        </div>
        <p class="mw-paynote">যে যতটুকু টাকা দিবেন, ততটুকুই পড়তে ও ডাউনলোড করতে পারবেন। ম্যানুয়াল পেমেন্টে অ্যাডমিন যাচাই করলে আনলক হবে; ট্রাস্টে তাৎক্ষণিক।</p>
    </div>
    <script>
    function payMethodChange() {
        var k = document.getElementById('payMethod').value;
        document.querySelectorAll('.mw-paytarget').forEach(function (p) {
            p.style.display = (p.getAttribute('data-key') === k) ? 'block' : 'none';
        });
        document.getElementById('payTrx').style.display = (k === 'trust') ? 'none' : 'inline-block';
    }
    </script>
    <?php endif; ?>

    <div class="mw-content-wrap">
        <article class="mw-article">
            <!-- ডান পাশের ইনফোবক্স (৩য় কলাম — float: right, তাই মূল লেখার আগে থাকবে) -->
            <aside class="mw-infobox">
                <!-- ১. প্রচ্ছদের বক্স -->
                <div class="mw-ib-card mw-ib-cover-card">
                    <div class="mw-ib-title"><?= htmlspecialchars($book['title']) ?></div>
                    <?php if (!empty($book['cover'])): ?>
                        <div class="mw-ib-cover"><img src="<?= htmlspecialchars($book['cover']) ?>" alt="প্রচ্ছদ"></div>
                    <?php endif; ?>
                </div>

                <!-- ২. লেখকের বক্স (প্রচ্ছদের বক্স থেকে আলাদা ও গোল কোনা) -->
                <div class="mw-ib-card mw-ib-author-card">
                    <div class="mw-ib-author">
                        <?php if (!empty($book['author_img'])): ?>
                            <img src="<?= htmlspecialchars($book['author_img']) ?>" alt="লেখক">
                        <?php endif; ?>
                        <div>
                            <div class="mw-ib-label">লেখক</div>
                            <div class="mw-ib-name"><?= htmlspecialchars($book['author']) ?></div>
                        </div>
                    </div>
                    <table class="mw-ib-table">
                        <tr><th>ক্যাটাগরি</th><td><?= htmlspecialchars($book['category']) ?></td></tr>
                        <tr><th>পৃষ্ঠা</th><td><?= htmlspecialchars($book['pages']) ?></td></tr>
                        <tr><th>মূল্য</th><td><?= htmlspecialchars($book['price']) ?></td></tr>
                        <tr><th>অ্যাক্সেস</th><td><?= htmlspecialchars($book['access']) ?></td></tr>
                    </table>
                </div>

                <!-- ৩. গ্রন্থপরিচিতি ও প্রকাশনা বক্স -->
                <?php if (!empty($book['colophon']) && is_array($book['colophon'])): $col = $book['colophon']; ?>
                <div class="mw-ib-card mw-ib-colophon">
                    <div class="mw-col-head">
                        <div class="mw-col-ornament">✦ গ্রন্থপরিচিতি ও প্রকাশনা ✦</div>
                        <div class="mw-col-booktitle"><?= htmlspecialchars($col['book_title'] ?? $book['title']) ?></div>
                        <?php if (!empty($col['author'])): ?>
                            <span class="mw-col-author-pill">লেখক: <?= htmlspecialchars($col['author']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="mw-col-body">
                        <div class="mw-col-section">
                            <div class="mw-col-sec-title">🏛️ প্রকাশনা</div>
                            <?php if (!empty($col['publisher'])): ?>
                                <div class="mw-col-line"><b>প্রকাশক:</b> <?= htmlspecialchars($col['publisher']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($col['publishing_house'])): ?>
                                <div class="mw-col-pub-house"><?= htmlspecialchars($col['publishing_house']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($col['address'])): ?>
                                <div class="mw-col-line"><?= htmlspecialchars($col['address']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($col['phones']) && is_array($col['phones'])): ?>
                                <div class="mw-col-phones">
                                    <?php foreach ($col['phones'] as $ph): ?>
                                        <span class="mw-col-phone">📞 <?= htmlspecialchars($ph) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($col['editions']) && is_array($col['editions'])): ?>
                        <div class="mw-col-section">
                            <div class="mw-col-sec-title">🕰️ প্রকাশকাল ও সংস্করণ</div>
                            <ul class="mw-col-timeline">
                                <?php foreach ($col['editions'] as $ed): ?>
                                    <li>
                                        <span class="mw-col-ed-label"><?= htmlspecialchars($ed['label'] ?? '') ?></span>,
                                        <?= htmlspecialchars($ed['date'] ?? '') ?>
                                        <?php if (!empty($ed['note'])): ?>
                                            <span class="mw-col-ed-note"><?= htmlspecialchars($ed['note']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($col['credits']) && is_array($col['credits'])): ?>
                        <div class="mw-col-section">
                            <div class="mw-col-sec-title">✒️ সৃজন ও মুদ্রণ</div>
                            <table class="mw-col-kv">
                                <?php foreach ($col['credits'] as $cr): ?>
                                    <tr>
                                        <th><?= htmlspecialchars($cr['label'] ?? '') ?></th>
                                        <td><?= htmlspecialchars($cr['value'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($col['distributors']) && is_array($col['distributors'])): ?>
                        <div class="mw-col-section">
                            <div class="mw-col-sec-title">🌐 পরিবেশক</div>
                            <div class="mw-col-dist">
                                <?php foreach ($col['distributors'] as $dist): ?>
                                    <div class="mw-col-dist-item">
                                        <b><?= htmlspecialchars($dist['label'] ?? '') ?>:</b> <?= htmlspecialchars($dist['value'] ?? '') ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mw-col-footer">
                        <div class="mw-col-price-row">
                            <?php if (!empty($col['price_bdt'])): ?>
                                <span class="mw-col-price-badge">মূল্য: <?= htmlspecialchars($col['price_bdt']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($col['price_usd'])): ?>
                                <span class="mw-col-usd-badge"><?= htmlspecialchars($col['price_usd']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($col['isbn'])): ?>
                            <div class="mw-col-isbn">ISBN : <?= htmlspecialchars($col['isbn']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ৪. গুরুত্বপূর্ণ টীকা, নোট ও ব্যাখ্যা বক্স (নিচ পর্যন্ত বিস্তৃত) -->
                <div class="mw-ib-card mw-ib-notes-card">
                    <div class="mw-notes-head">
                        <span class="mw-notes-title">✦ গুরুত্বপূর্ণ টীকা, নোট ও ব্যাখ্যা</span>
                        <span class="mw-notes-count"><?= count($bookNotes) ?></span>
                    </div>
                    <div class="mw-notes-body">
                        <?php if (!empty($bookNotes)): ?>
                            <?php foreach ($bookNotes as $ni => $bn):
                                $cls = $bn['type'] === 'ব্যাখ্যা' ? 'is-byakhya' : ($bn['type'] === 'নোট' ? 'is-note' : 'is-tika');
                            ?>
                                <div class="mw-note-item <?= $cls ?>" data-needle="<?= htmlspecialchars($bn['needle']) ?>" onclick="scrollToNote(this)" title="মূল বইয়ে দেখতে ক্লিক করুন">
                                    <div class="mw-note-meta">
                                        <span class="mw-note-badge"><?= htmlspecialchars($bn['type']) ?></span>
                                        <span class="mw-note-heading"><?= htmlspecialchars($bn['title']) ?></span>
                                    </div>
                                    <div class="mw-note-text"><?= htmlspecialchars($bn['body']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="mw-notes-empty">এই অধ্যায়ে আলাদা কোনো টীকা, নোট বা ব্যাখ্যা চিহ্নিত হয়নি।</div>
                        <?php endif; ?>
                    </div>
                    <div class="mw-notes-footer">✦ টীকা ও ব্যাখ্যা সমাপ্ত ✦</div>
                </div>
            </aside>

            <div class="mw-article-main">
                <div class="mw-body <?= $paperIdx !== 'default' ? 'papered' : '' ?>" id="editableContent" contenteditable="false" spellcheck="true">
                    <?= $isHtml ? $bodyHtml : nl2br($bodyHtml) ?>
                </div>

                <!-- তথ্যসূত্র -->
                <div class="mw-references">
                    <h2>তথ্যসূত্র</h2>
                    <?php if (!empty($references)): ?>
                        <ol>
                            <?php foreach ($references as $ref): ?>
                                <li><?= htmlspecialchars($ref) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="mw-ref-note">এই অধ্যায়ের জন্য এখনো কোনো তথ্যসূত্র যুক্ত করা হয়নি।</p>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    </div>
</div>
</div>

<!-- এডিট সেভ করার গোপন ফর্ম -->
<form id="saveForm" method="POST" style="display:none;">
    <input type="hidden" name="save_edit" value="1">
    <input type="hidden" name="edited_title" id="hiddenTitle">
    <input type="hidden" name="edited_content" id="hiddenContent">
</form>

<!-- বইয়ের ভেতরে সার্চ মডাল -->
<div class="bs-modal" id="bookSearchModal" onclick="if(event.target===this)closeBookSearch()">
    <div class="bs-box">
        <div class="bs-head">
            <h3>বইয়ের ভেতরে খুঁজুন</h3>
            <button type="button" class="bs-close" onclick="closeBookSearch()">&times;</button>
        </div>
        <div class="bs-searchbar">
            <input type="text" id="bookSearchInput" placeholder="শব্দ বা বাক্য লিখুন..." oninput="runBookSearch(this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();runBookSearch(this.value);openFirstSearchResult();}">
            <button type="button" class="mw-tool primary" onclick="runBookSearch(document.getElementById('bookSearchInput').value)">খুঁজুন</button>
        </div>
        <div class="bs-results" id="bookSearchResults"></div>
    </div>
</div>

<script>
/* ---------- বইয়ের ভেতরে সার্চ ---------- */
const BOOK_CHAPTERS = <?= json_encode($searchChapters, JSON_UNESCAPED_UNICODE) ?>;
const BOOK_ID = <?= json_encode((string)$id, JSON_UNESCAPED_UNICODE) ?>;
const BOOK_PAPER = <?= json_encode((string)$paperIdx, JSON_UNESCAPED_UNICODE) ?>;
let searchQuery = '';

function openBookSearch() {
    document.getElementById('bookSearchModal').style.display = 'flex';
    const inp = document.getElementById('bookSearchInput');
    inp.value = searchQuery;
    inp.focus();
    if (searchQuery) runBookSearch(searchQuery);
}

function closeBookSearch() {
    document.getElementById('bookSearchModal').style.display = 'none';
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function highlightSnippet(s) {
    const esc = escapeHtml(s);
    if (!searchQuery) return esc;
    const q = escapeHtml(searchQuery).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (!q) return esc;
    return esc.replace(new RegExp(q, 'gi'), '<mark>$&</mark>');
}

function runBookSearch(q) {
    searchQuery = String(q == null ? '' : q).trim();
    const resultsEl = document.getElementById('bookSearchResults');
    if (!searchQuery) { resultsEl.innerHTML = '<p class="bs-empty">খুঁজতে কিছু লিখুন।</p>'; return; }
    const needle = searchQuery.toLocaleLowerCase();
    const out = [];
    BOOK_CHAPTERS.forEach(function (ch) {
        const text = ch.plain || '';
        const lower = text.toLocaleLowerCase();
        const titleMatch = (ch.title || '').toLocaleLowerCase().indexOf(needle) !== -1;
        let from = 0, count = 0, snippet = '';
        while (count < 100) {
            const pos = lower.indexOf(needle, from);
            if (pos === -1) break;
            if (count === 0) {
                const start = Math.max(0, pos - 40);
                const end = Math.min(text.length, pos + searchQuery.length + 60);
                snippet = (start > 0 ? '…' : '') + text.slice(start, end).replace(/\s+/g, ' ').trim() + (end < text.length ? '…' : '');
            }
            count++;
            from = pos + searchQuery.length;
        }
        if (count > 0 || titleMatch) {
            if (!snippet) snippet = 'অধ্যায়ের শিরোনামে মিল পাওয়া গেছে।';
            out.push({ idx: ch.idx, title: ch.title, count: count, snippet: snippet });
        }
    });
    if (!out.length) { resultsEl.innerHTML = '<p class="bs-empty">কোনো ফলাফল পাওয়া যায়নি।</p>'; return; }
    let html = '<p class="bs-count">মোট ' + out.length + 'টি অধ্যায়ে পাওয়া গেছে:</p>';
    out.forEach(function (r) {
        const link = '?id=' + encodeURIComponent(BOOK_ID) + '&paper=' + encodeURIComponent(BOOK_PAPER) + '&chap=' + r.idx + '&q=' + encodeURIComponent(searchQuery);
        html += '<a class="bs-result" href="' + link + '">'
            + '<span class="bs-rtitle">' + escapeHtml(r.title) + ' <span class="bs-badge">' + (r.count || 'শিরোনাম') + '</span></span>'
            + '<span class="bs-snippet">' + highlightSnippet(r.snippet) + '</span></a>';
    });
    resultsEl.innerHTML = html;
}

function openFirstSearchResult() {
    const first = document.querySelector('#bookSearchResults .bs-result');
    if (first) window.location.href = first.getAttribute('href');
}

// অন্য অধ্যায়ে গিয়ে q প্যারামিটারের শব্দটি সিলেক্ট করে স্ক্রল করা
function selectQueryOnLoad() {
    const q = new URLSearchParams(window.location.search).get('q');
    if (!q) return;
    const body = document.getElementById('editableContent');
    if (!body) return;
    const needle = q.toLocaleLowerCase();
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, null);
    let node;
    while ((node = walker.nextNode())) {
        const t = node.nodeValue || '';
        const pos = t.toLocaleLowerCase().indexOf(needle);
        if (pos !== -1) {
            const range = document.createRange();
            range.setStart(node, pos);
            range.setEnd(node, pos + q.length);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
            break;
        }
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBookSearch();
});
window.addEventListener('load', selectQueryOnLoad);

function scrollToNote(el) {
    var needle = (el.getAttribute('data-needle') || '').replace(/\s+/g, ' ').trim();
    if (!needle) return;
    var body = document.getElementById('editableContent');
    if (!body) return;
    var shortNeedle = needle.slice(0, 32);
    var els = body.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li');
    for (var i = 0; i < els.length; i++) {
        var txt = (els[i].textContent || '').replace(/\s+/g, ' ').trim();
        if (txt.indexOf(shortNeedle) !== -1) {
            els[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
            els[i].classList.remove('mw-note-flash');
            void els[i].offsetWidth;
            els[i].classList.add('mw-note-flash');
            break;
        }
    }
}

function toggleSub(btn) {
    var li = btn.closest('li');
    li.classList.toggle('collapsed');
    btn.textContent = li.classList.contains('collapsed') ? '▸' : '▼';
}

// সূচিপত্রের যেকোনো শিরোনামে ক্লিক করলে লেখার ভেতরে সেই অংশে স্ক্রল করা।
// বইয়ের শুরুর front matter (বাণী ইত্যাদি) ও ছাপা সূচিপত্র বাদ দিয়ে,
// ২-ধাপে ম্যাচ করা হয়: (১) সব অধ্যায়ের সূচনা-শিরোনাম, (২) প্রতি অধ্যায়ের
// উপশিরোনাম নিজ নিজ অধ্যায়ের সীমার ভেতরে।
var tocTargets = {};

function normalizeKey(s) {
    return String(s == null ? '' : s).normalize('NFC')
        .replace(/^[০-৯0-9]+\s*[\.\)।\/]\s*/, '')
        .replace(/\s+/g, '');
}
function bengaliPrefix(s) {
    var m = String(s || '').normalize('NFC').match(/[\u0980-\u09FF]{5}/);
    return m ? m[0] : '';
}

function buildParaIndex() {
    var body = document.getElementById('editableContent');
    if (!body) return null;
    var els = body.querySelectorAll('h1,h2,h3,h4,h5,h6,p');
    var arr = [];
    for (var i = 0; i < els.length; i++) {
        var raw = (els[i].textContent || '').replace(/\s+/g, ' ').trim();
        if (raw) arr.push({ el: els[i], n: normalizeKey(raw), raw: raw });
    }
    return arr;
}

function findInIndex(idx, key, from, end) {
    var kn = normalizeKey(key);
    if (!kn || kn.length < 2) return -1;
    if (end == null) end = idx.length;
    var i, s;
    for (i = from; i < end; i++) if (idx[i].n === kn) return i;
    for (i = from; i < end; i++) {
        s = idx[i].n;
        if (idx[i].raw.length <= 100 && s.length >= 5 && (s.indexOf(kn) === 0 || kn.indexOf(s) === 0)) return i;
    }
    var bp = bengaliPrefix(key);
    if (bp) for (i = from; i < end; i++) if (idx[i].n.indexOf(bp) === 0) return i;
    return -1;
}

function precomputeTocTargets() {
    tocTargets = {};
    var idx = buildParaIndex();
    if (!idx || !idx.length) return;

    // কনটেন্টের সূচনা: "সূচিপত্র" শিরোনামের পরের অনুচ্ছেদ থেকে
    var cs = 0;
    for (var i = 0; i < idx.length; i++) if (idx[i].raw.indexOf('সূচিপত্র') !== -1) { cs = i + 1; break; }

    var links = Array.prototype.slice.call(document.querySelectorAll('.mw-toc-list a[data-key]'));
    if (!links.length) return;
    var chapLinks = links.filter(function (a) { return a.hasAttribute('data-firstsub'); });

    // ধাপ ১: অধ্যায়ের সূচনা-শিরোনাম (স্বতন্ত্র কার্সর)
    var cCur = cs - 1;
    var chapPos = chapLinks.map(function (a) {
        var key = a.getAttribute('data-firstsub') || a.getAttribute('data-key') || '';
        var p = findInIndex(idx, key, cCur + 1, idx.length);
        if (p >= 0) cCur = p;
        return p;
    });
    for (var c = 0; c < chapPos.length; c++) {
        if (chapPos[c] < 0) {
            for (var d = c + 1; d < chapPos.length; d++) if (chapPos[d] >= 0) { chapPos[c] = chapPos[d]; break; }
        }
    }
    chapLinks.forEach(function (a, ci) {
        if (chapPos[ci] >= 0) tocTargets[a.getAttribute('data-key')] = idx[chapPos[ci]].el;
    });

    // ধাপ ২: উপশিরোনাম — নিজ নিজ অধ্যায়ের সীমার ভেতরে ক্রমানুসারে
    var ci = -1, subCur = 0;
    links.forEach(function (a) {
        if (a.hasAttribute('data-appendix')) return;
        if (a.hasAttribute('data-firstsub')) { ci++; subCur = (chapPos[ci] >= 0 ? chapPos[ci] - 1 : 0); return; }
        if (ci < 0) return;
        var start = chapPos[ci];
        if (start < 0) return;
        var end = (ci + 1 < chapPos.length && chapPos[ci + 1] >= 0) ? chapPos[ci + 1] : idx.length;
        var key = a.getAttribute('data-key') || '';
        var p = findInIndex(idx, key, subCur + 1, end);
        if (p >= 0) { tocTargets[key] = idx[p].el; subCur = p; }
    });

    // যে উপশিরোনাম মেলেনি, তার লিংককে সংশ্লিষ্ট অধ্যায়ের সূচনায় পাঠানো হয়
    ci = -1;
    links.forEach(function (a) {
        if (a.hasAttribute('data-appendix')) return;
        if (a.hasAttribute('data-firstsub')) { ci++; return; }
        var key = a.getAttribute('data-key') || '';
        if (!tocTargets[key] && ci >= 0 && chapPos[ci] >= 0) tocTargets[key] = idx[chapPos[ci]].el;
    });

    // ধাপ ৩: সূচির নিচের পরিশিষ্ট শিরোনামসমূহ — শেষ অধ্যায়ের পর থেকে ক্রমানুসারে
    var appLinks = links.filter(function (a) { return a.hasAttribute('data-appendix'); });
    if (appLinks.length) {
        var lastChapPos = -1;
        for (var k = chapPos.length - 1; k >= 0; k--) {
            if (chapPos[k] >= 0) { lastChapPos = chapPos[k]; break; }
        }
        var appCur = lastChapPos >= 0 ? lastChapPos : cs - 1;
        appLinks.forEach(function (a) {
            var key = a.getAttribute('data-key') || '';
            var p = findInIndex(idx, key, appCur + 1, idx.length);
            if (p >= 0) {
                tocTargets[key] = idx[p].el;
                appCur = p;
            }
        });
    }
}

function scrollToToc(el) {
    var key = (el.getAttribute('data-key') || '').trim();
    if (!key) return true;
    var target = tocTargets[key];
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return false;
    }
    return true;
}

window.addEventListener('load', precomputeTocTargets);

function setEditMode(on, btn) {
    var titleEl = document.getElementById('editableTitle');
    if (titleEl) titleEl.setAttribute('contenteditable', on ? 'true' : 'false');
    document.getElementById('editableContent').setAttribute('contenteditable', on ? 'true' : 'false');
    document.querySelectorAll('.mw-tab').forEach(function (t) { t.classList.remove('active'); });
    if (btn) btn.classList.add('active');
}

function savePage() {
    var titleEl = document.getElementById('editableTitle');
    document.getElementById('hiddenTitle').value = titleEl ? titleEl.innerText : <?= json_encode($currentChapter['title'], JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('hiddenContent').value = document.getElementById('editableContent').innerHTML;
    document.getElementById('saveForm').submit();
}

/* ---------- টেক্সট-টু-স্পিচ (বাংলা ও ইংরেজি) ---------- */
let speechUtterance = null;
let availableVoices = [];

function loadVoices() {
    availableVoices = window.speechSynthesis.getVoices();
}
loadVoices();
if (window.speechSynthesis.onvoiceschanged !== undefined) {
    window.speechSynthesis.onvoiceschanged = loadVoices;
}

const voiceProfiles = {
    'f1': { gender: 'female', pitch: 1.15, rate: 0.95, voiceIndex: 0 },
    'f2': { gender: 'female', pitch: 1.08, rate: 0.85, voiceIndex: 1 },
    'f3': { gender: 'female', pitch: 1.25, rate: 1.02, voiceIndex: 2 },
    'f4': { gender: 'female', pitch: 1.32, rate: 0.90, voiceIndex: 3 },
    'f5': { gender: 'female', pitch: 1.02, rate: 0.88, voiceIndex: 4 },
    'm1': { gender: 'male',   pitch: 0.78, rate: 0.90, voiceIndex: 0 },
    'm2': { gender: 'male',   pitch: 0.92, rate: 0.96, voiceIndex: 1 },
    'm3': { gender: 'male',   pitch: 0.85, rate: 0.84, voiceIndex: 2 },
    'm4': { gender: 'male',   pitch: 0.98, rate: 1.04, voiceIndex: 3 },
    'm5': { gender: 'male',   pitch: 0.70, rate: 0.82, voiceIndex: 4 }
};

function getMatchingVoice(isBengali, gender, index) {
    if (availableVoices.length === 0) loadVoices();
    const langPrefix = isBengali ? 'bn' : 'en';
    let langVoices = availableVoices.filter(v => v.lang.toLowerCase().startsWith(langPrefix));
    if (langVoices.length === 0) langVoices = availableVoices;
    const femaleKeywords = ['female', 'woman', 'tanishaa', 'kalpana', 'heera', 'zira', 'samantha', 'victoria', 'google বাংলা', 'microsoft'];
    const maleKeywords = ['male', 'man', 'bashkar', 'hemant', 'david', 'mark', 'daniel', 'ravi', 'alex'];
    let filtered = langVoices.filter(v => {
        const name = v.name.toLowerCase();
        if (gender === 'female') return femaleKeywords.some(k => name.includes(k));
        return maleKeywords.some(k => name.includes(k));
    });
    const pool = filtered.length > 0 ? filtered : langVoices;
    return pool[index % pool.length] || null;
}

function startReadingBook() {
    window.speechSynthesis.cancel();
    var titleEl = document.getElementById('editableTitle');
    const titleText = titleEl ? titleEl.innerText : '';
    const bodyText = document.getElementById('editableContent').innerText;
    const fullText = (titleText ? titleText + "। " : '') + bodyText;
    if (!fullText.trim()) { alert("পড়ার জন্য কোনো লেখা পাওয়া যায়নি।"); return; }
    const sel = document.getElementById('voiceSelector');
    const selectedKey = sel ? sel.value : 'f1';
    const profile = voiceProfiles[selectedKey] || voiceProfiles['f1'];
    const sentences = fullText.match(/[^।\.!\?]+[।\.!\?]+/g) || [fullText];
    let currentIndex = 0;
    function speakNextSentence() {
        if (currentIndex < sentences.length) {
            const textChunk = sentences[currentIndex].trim();
            speechUtterance = new SpeechSynthesisUtterance(textChunk);
            const isBengali = /[\u0980-\u09FF]/.test(textChunk);
            speechUtterance.lang = isBengali ? 'bn-BD' : 'en-US';
            const chosenVoice = getMatchingVoice(isBengali, profile.gender, profile.voiceIndex);
            if (chosenVoice) speechUtterance.voice = chosenVoice;
            speechUtterance.pitch = profile.pitch;
            speechUtterance.rate = profile.rate;
            speechUtterance.onend = function () { currentIndex++; speakNextSentence(); };
            window.speechSynthesis.speak(speechUtterance);
        }
    }
    speakNextSentence();
}

function stopReadingBook() {
    window.speechSynthesis.cancel();
}

window.onbeforeunload = function () { window.speechSynthesis.cancel(); };

/* ---------- মোবাইল ভিউ প্রিভিউ (375×812) ---------- */
function openMobilePreview(targetUrl) {
    const modal = document.getElementById('mobilePreviewModal');
    const frame = document.getElementById('mobilePreviewFrame');
    const url = targetUrl || window.location.href;
    if (frame.getAttribute('data-src') !== url) {
        frame.src = url;
        frame.setAttribute('data-src', url);
    }
    modal.style.display = 'flex';
}

function closeMobilePreview() {
    document.getElementById('mobilePreviewModal').style.display = 'none';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const mv = document.getElementById('mobilePreviewModal');
        if (mv && mv.style.display === 'flex') closeMobilePreview();
    }
});
</script>

<!-- মোবাইল ভিউ প্রিভিউ মডাল -->
<div class="mv-modal" id="mobilePreviewModal" onclick="if(event.target===this)closeMobilePreview()">
    <div class="mv-box">
        <div class="mv-head">
            <h3>📱 মোবাইল ভিউ (375×812)</h3>
            <div class="mv-actions">
                <button type="button" class="mw-tool" onclick="openMobilePreview(window.location.href)">এই পেজ</button>
                <button type="button" class="mw-tool" onclick="openMobilePreview('index.php')">মূল পেজ</button>
                <button type="button" class="bs-close" onclick="closeMobilePreview()" aria-label="বন্ধ করুন">&times;</button>
            </div>
        </div>
        <div class="mv-phone">
            <iframe id="mobilePreviewFrame" title="মোবাইল ভিউ প্রিভিউ"></iframe>
        </div>
    </div>
</div>
</body>
</html>