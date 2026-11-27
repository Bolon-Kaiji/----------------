<?php
require_once __DIR__ . '/store.php';

$books = loadBooks();
$id = 'book_6b0656ef422e3';
$book = $books[$id];
$chapters = loadChapters($id);
$plain = $chapters[0]['plain'] ?? '';

// Copy parseBookToc function
function parseBookToc($content, $indents = null) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    $n = count($lines);
    if (is_array($indents) && count($indents) !== $n) $indents = null;
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
        $isChapter = false;
        if (!$hasHash && mb_strpos($t, '(') === false && mb_strlen($t) <= 30) {
            $j = $i + 1;
            while ($j <= $last && trim($lines[$j]) === '') $j++;
            if ($j <= $last && strpos($lines[$j], '#') !== false) $isChapter = true;
        }
        if (!$hasHash && mb_strlen($t) <= 40 && preg_match('/^\S+\s*অধ্যা(?:\x{09DF}|\x{09AF}\x{09BC})$/u', $t)) $isChapter = true;
        if ($isChapter) {
            $chapters[] = ['title' => $t, 'subs' => [], 'starts' => []];
            $curIdx = count($chapters) - 1;
            continue;
        }
        if ($curIdx >= 0) {
            $sub = trim(preg_replace('/\s*#\s*\S+\s*$/u', '', $t));
            if ($sub !== '') {
                $chapters[$curIdx]['subs'][] = $sub;
                $chapters[$curIdx]['starts'][] = $indents[$i] ?? 0;
            }
        }
    }
    foreach ($chapters as $ci => $ch) {
        $chapters[$ci]['lv'] = tocAssignLevels($ch['subs'], $ch['starts']);
        unset($chapters[$ci]['starts']);
    }
    return $chapters;
}

function tocAssignLevels($subs, $starts) {
    $digits = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
    $levels = [];
    $stack = [];
    $ctx = [];
    $prevDepth = -1;
    foreach ($subs as $i => $text) {
        $s = (int)($starts[$i] ?? 0);
        if (preg_match('/^(প্রথম|দ্বিতী(?:\x{09DF}|\x{09AF}\x{09BC})|তৃতী(?:\x{09DF}|\x{09AF}\x{09BC})|চতুর্থ|পঞ্চম|ষষ্ঠ|সপ্তম|অষ্টম|নবম|দশম)\s+পরিচ্ছদ/u', $text)) {
            $stack = [[-1, 0]];
            $ctx = [];
            $levels[$i] = 0;
            $prevDepth = 0;
            continue;
        }
        while ($stack && $stack[count($stack) - 1][0] >= $s) array_pop($stack);
        $depth = $stack ? $stack[count($stack) - 1][1] + 1 : 0;
        $num = null;
        if (preg_match('/^\s*([০-৯0-9]{1,3})\s*[\.\)]\s*\S/u', $text, $m)) $num = (int)strtr($m[1], $digits);
        if ($num === null) {
            while ($ctx && $ctx[count($ctx) - 1][0] > $depth) array_pop($ctx);
        } elseif ($num === 1 && $prevDepth >= 0) {
            $depth = $prevDepth + 1;
            $ctx[] = [$depth, 1];
        } elseif ($num > 1) {
            for ($k = count($ctx) - 1; $k >= 0; $k--) {
                if ($ctx[$k][1] === $num - 1) {
                    $depth = $ctx[$k][0];
                    $ctx[$k][1] = $num;
                    $ctx = array_slice($ctx, 0, $k + 1);
                    break;
                }
            }
        }
        if ($num !== null) {
            while ($ctx && $ctx[count($ctx) - 1][0] > $depth) array_pop($ctx);
            $ctx[] = [$depth, $num];
        }
        $stack[] = [$s, $depth];
        $levels[$i] = $depth;
        $prevDepth = $depth;
    }
    return $levels;
}

$toc = parseBookToc($plain);
echo "Total chapters found: " . count($toc) . "\n\n";
foreach ($toc as $ci => $ch) {
    echo "Chapter $ci: {$ch['title']}\n";
    echo "  Subs count: " . count($ch['subs']) . "\n";
    if (count($ch['subs']) > 0) {
        foreach ($ch['subs'] as $si => $sub) {
            echo "    Sub $si: $sub (level: {$ch['lv'][$si]})\n";
        }
    }
    echo "\n";
}