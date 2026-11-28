<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/pay.php';
// স্বয়ংক্রিয়ভাবে ফোল্ডার এবং JSON ডাটাবেস তৈরি
$dirs = ['uploads', 'uploads/covers', 'uploads/authors', 'uploads/papers', 'uploads/books'];
foreach ($dirs as $dir) {
    $absDir = __DIR__ . '/' . $dir;
    if (!is_dir($absDir)) mkdir($absDir, 0777, true);
}
$books = loadBooks();
$message = '';

// .doc / .docx ফাইল থেকে লেখা পড়ার হেল্পার ফাংশন
function docToText($path, $ext) {
    $ext = strtolower($ext);
    // .docx আসলে একটি zip — ZipArchive দিয়ে word/document.xml পড়া হয়
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>', '<w:br/>'], ["\n", "\n", "\t", "\n"], $xml);
                $text = strip_tags($xml);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                return trim($text);
            }
        }
    }
    // .doc (বাইনারি) — হিউরিস্টিক পদ্ধতিতে পড়ারযোগ্য লেখা বের করা হয়
    $data = file_get_contents($path);
    $candidates = [];
    $u = @iconv('UTF-16LE', 'UTF-8//IGNORE', $data);
    if ($u !== false) $candidates[] = $u;
    $c = @iconv('WINDOWS-1252', 'UTF-8//IGNORE', $data);
    if ($c !== false) $candidates[] = $c;

    $best = '';
    $bestScore = 0;
    foreach ($candidates as $text) {
        // শুধু বাংলা, আরবি, ল্যাটিন, সংখ্যা ও সাধারণ চিহ্ন রাখা হয়
        $text = preg_replace('/[^\p{Bengali}\p{Arabic}\x{0020}-\x{007E}\x{200C}\x{200D}\n\r\t]/u', ' ', $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match_all('/[\p{Bengali}\p{Arabic}A-Za-z]/u', $line, $mm) >= 3) {
                $lines[] = $line;
            }
        }
        $res = implode("\n", $lines);
        // "I n t" ধাঁচের একক-অক্ষরের মাঝের ফাঁক মুছে আসল শব্দ জোড়া লাগানো হয়
        $res = preg_replace('/(?<=[A-Za-z0-9]) (?=[A-Za-z0-9])/', '', $res);
        preg_match_all('/[\p{Bengali}\p{Arabic}A-Za-z0-9]{2,}/u', $res, $wm);
        $score = count($wm[0]);
        if ($score > $bestScore) { $bestScore = $score; $best = $res; }
    }
    return $best;
}

// .docx -> HTML (বোল্ড, ইটালিক, আনডারলাইন, রং, টেবিল ও ছবি ধরে রাখে)
function docxToHtml($path, $mediaDir, $srcPrefix, $skipSet = []) {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) { $zip->close(); return ''; }
    $rels = [];
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml !== false) {
        $rx = new DOMDocument();
        @$rx->loadXML($relsXml);
        foreach ($rx->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') === 'External') continue;
            $rels[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }
    }
    if (!is_dir($mediaDir)) @mkdir($mediaDir, 0777, true);
    $dom = new DOMDocument();
    @$dom->loadXML($xml);
    $NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $body = $dom->getElementsByTagNameNS($NS_W, 'body')->item(0);
    if (!$body) { $zip->close(); return ''; }
    $imgCount = 0;

    $renderRun = function ($run) use (&$renderRun, $NS_W, $NS_A, $NS_R, $rels, $zip, $mediaDir, $srcPrefix, &$imgCount) {
        $rpr = $run->getElementsByTagNameNS($NS_W, 'rPr')->item(0);
        $bold = $italic = $underline = false; $color = ''; $fontSize = ''; $fontFamily = '';
        if ($rpr) {
            $bold = $rpr->getElementsByTagNameNS($NS_W, 'b')->length > 0;
            $italic = $rpr->getElementsByTagNameNS($NS_W, 'i')->length > 0;
            $u = $rpr->getElementsByTagNameNS($NS_W, 'u')->item(0);
            if ($u) { $uv = $u->getAttributeNS($NS_W, 'val'); $underline = ($uv === '' || $uv !== 'none'); }
            $c = $rpr->getElementsByTagNameNS($NS_W, 'color')->item(0);
            if ($c) $color = $c->getAttributeNS($NS_W, 'val');
            $sz = $rpr->getElementsByTagNameNS($NS_W, 'sz')->item(0);
            if ($sz) $fontSize = $sz->getAttributeNS($NS_W, 'val');
            $rf = $rpr->getElementsByTagNameNS($NS_W, 'rFonts')->item(0);
            if ($rf) $fontFamily = $rf->getAttributeNS($NS_W, 'ascii');
        }
        $inner = '';
        foreach ($run->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $local = $child->localName;
            if ($local === 't') $inner .= htmlspecialchars($child->textContent, ENT_QUOTES, 'UTF-8');
            elseif ($local === 'tab') $inner .= "\t";
            elseif ($local === 'br' || $local === 'cr') $inner .= '<br>';
            elseif ($local === 'drawing' || $local === 'pict' || $local === 'object') {
                $blips = $child->getElementsByTagNameNS($NS_A, 'blip');
                if ($blips->length) {
                    $embed = $blips->item(0)->getAttributeNS($NS_R, 'embed');
                    if ($embed && isset($rels[$embed])) {
                        $target = $rels[$embed];
                        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                        if ($ext === '') $ext = 'png';
                        $newName = 'img_' . (++$imgCount) . '.' . $ext;
                        $data = $zip->getFromName('word/' . $target);
                        if ($data !== false) {
                            @file_put_contents($mediaDir . '/' . $newName, $data);
                            $inner .= '<img src="' . htmlspecialchars($srcPrefix . '/' . $newName) . '" style="max-width:100%;height:auto;display:block;margin:.4em 0;">';
                        }
                    }
                }
            }
        }
        if ($inner === '') return '';
        $style = '';
        if ($bold) $style .= 'font-weight:700;';
        if ($italic) $style .= 'font-style:italic;';
        if ($underline) $style .= 'text-decoration:underline;';
        if ($color && preg_match('/^[0-9A-Fa-f]{6}$/', $color)) $style .= 'color:#' . $color . ';';
        if ($fontSize) $style .= 'font-size:' . (intval($fontSize) / 2) . 'pt;';
        if ($style !== '') return '<span style="' . $style . '">' . $inner . '</span>';
        return $inner;
    };

    $renderRunsOf = function ($p) use ($renderRun, $NS_W) {
        $s = '';
        foreach ($p->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            if ($c->localName === 'r') $s .= $renderRun($c);
            elseif ($c->localName === 'hyperlink' || $c->localName === 'ins' || $c->localName === 'smartTag') {
                foreach ($c->childNodes as $hc) {
                    if ($hc->nodeType === XML_ELEMENT_NODE && $hc->localName === 'r') $s .= $renderRun($hc);
                }
            }
        }
        return $s;
    };

    $renderTable = function ($tbl) use (&$renderTable, $renderRunsOf, $NS_W) {
        $html = '<table style="border-collapse:collapse;width:100%;margin:1em 0;font-size:.95em;">';
        $rows = [];
        foreach ($tbl->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'tr') $rows[] = $c;
        }
        foreach ($rows as $tr) {
            $html .= '<tr>';
            foreach ($tr->childNodes as $tc) {
                if ($tc->nodeType !== XML_ELEMENT_NODE || $tc->localName !== 'tc') continue;
                $cell = '';
                foreach ($tc->childNodes as $cc) {
                    if ($cc->nodeType !== XML_ELEMENT_NODE) continue;
                    if ($cc->localName === 'p') $cell .= $renderRunsOf($cc);
                    elseif ($cc->localName === 'tbl') $cell .= $renderTable($cc);
                }
                $html .= '<td style="border:1px solid #999;padding:4px 7px;vertical-align:top;">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    };

    $out = '';
    $renderBlocks = function ($container) use (&$renderBlocks, $renderRunsOf, $renderTable, $NS_W, $skipSet, &$out) {
        foreach ($container->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) continue;
            $local = $node->localName;
            if ($local === 'p') {
                $inner = $renderRunsOf($node);
                if (!empty($skipSet)) {
                    $pt = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));
                    if ($pt !== '' && isset($skipSet[$pt])) continue;
                }
                if (trim(strip_tags($inner)) === '' && strpos($inner, '<img') === false) {
                    $out .= '<p style="margin:0;height:.6em;"></p>';
                    continue;
                }
                $jc = '';
                $ppr = $node->getElementsByTagNameNS($NS_W, 'pPr')->item(0);
                if ($ppr) {
                    $j = $ppr->getElementsByTagNameNS($NS_W, 'jc')->item(0);
                    if ($j) $jc = $j->getAttributeNS($NS_W, 'val');
                }
                $align = '';
                if ($jc === 'center') $align = 'text-align:center;';
                elseif ($jc === 'right') $align = 'text-align:right;';
                $out .= '<p style="margin:0 0 .6em;' . $align . '">' . $inner . '</p>';
            } elseif ($local === 'tbl') {
                $out .= $renderTable($node);
            } elseif (in_array($local, ['sdt', 'sdtContent', 'customXml', 'ins', 'smartTag', 'body', 'del'], true)) {
                $renderBlocks($node);
            }
        }
    };
    $renderBlocks($body);
    $zip->close();
    return $out;
}

if (defined('LIB_ONLY')) {
    return;
}

// নতুন বই আপলোড প্রসেসিং
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_book') {
    $id = uniqid('book_');
    $title = htmlspecialchars($_POST['title']);
    $author = htmlspecialchars($_POST['author']);
    $category = htmlspecialchars($_POST['category']);
    $pages = htmlspecialchars($_POST['pages']);
    $price = htmlspecialchars($_POST['price']);
    $access = htmlspecialchars($_POST['access']);
    $chapter_title = !empty($_POST['chapter_title']) ? htmlspecialchars($_POST['chapter_title']) : 'প্রথম অধ্যায়';
    $content = isset($_POST['content']) ? $_POST['content'] : '';

    // ফাইল আপলোড হেল্পার ফাংশন
    function uploadFile($fileInput, $folder, $prefix) {
        if (isset($_FILES[$fileInput]) && $_FILES[$fileInput]['error'] === 0 && is_uploaded_file($_FILES[$fileInput]['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES[$fileInput]['name'], PATHINFO_EXTENSION));
            $path = $folder . '/' . $prefix . '_' . uniqid() . ($ext !== '' ? '.' . $ext : '');
            $dest = __DIR__ . '/' . $path;
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0777, true);
            if (move_uploaded_file($_FILES[$fileInput]['tmp_name'], $dest)) {
                return $path;
            }
        }
        return '';
    }

    $coverPath = uploadFile('cover', 'uploads/covers', 'cover');
    $authorPath = uploadFile('author_img', 'uploads/authors', 'author');
    $fullBookPath = uploadFile('full_book_file', 'uploads/books', 'fullbook');

    // যদি পুরো বইয়ের .doc/.docx/.txt/.md ফাইল আপলোড করা হয়, তবে স্বয়ংক্রিয়ভাবে লেখা পড়ে নেবে
    $plainContent = '';
    if ($fullBookPath) {
        $fullAbsPath = __DIR__ . '/' . $fullBookPath;
        $fullExt = strtolower(pathinfo($fullBookPath, PATHINFO_EXTENSION));
        if ($fullExt === 'docx') {
            $plainContent = docToText($fullAbsPath, 'docx');
            // সূচিপত্র ব্লক বাদ দেওয়ার সেট
            $skipSet = [];
            $pl = preg_split('/\r\n|\r|\n/', $plainContent);
            $first = -1; $last = -1;
            for ($i = 0; $i < count($pl); $i++) { if (strpos($pl[$i], '#') !== false) { if ($first === -1) $first = $i; $last = $i; } }
            if ($first !== -1) {
                $s = $first;
                if ($s - 1 >= 0 && trim($pl[$s - 1]) !== '' && strpos($pl[$s - 1], '#') === false && mb_strlen(trim($pl[$s - 1])) <= 40) $s--;
                for ($i = $s; $i <= $last; $i++) { $tt = trim($pl[$i]); if ($tt !== '') $skipSet[$tt] = true; }
            }
            $html = docxToHtml($fullAbsPath, __DIR__ . "/uploads/books/$id", "uploads/books/$id", $skipSet);
            if (trim(strip_tags($html)) !== '') {
                $content = $html;
            }
        } elseif ($fullExt === 'doc') {
            $fileText = docToText($fullAbsPath, $fullExt);
            if (!empty(trim($fileText))) {
                $content = $fileText;
                $plainContent = $fileText;
            }
        } elseif (in_array($fullExt, ['txt', 'md', 'markdown'], true)) {
            $fileText = (string)@file_get_contents($fullAbsPath);
            if (trim($fileText) !== '') {
                $content = htmlspecialchars($fileText, ENT_QUOTES, 'UTF-8');
                $plainContent = $fileText;
            }
        }
    }
    if ($plainContent === '' && trim($content) !== '') {
        $plainContent = strip_tags($content);
    }

    // ৫টি পড়ার কাগজের টেক্সচার আপলোড
    $papers = [];
    for ($i = 1; $i <= 5; $i++) {
        $pPath = uploadFile("paper_$i", 'uploads/papers', "paper{$i}");
        $pName = !empty($_POST["paper_name_$i"]) ? htmlspecialchars($_POST["paper_name_$i"]) : "কাগজ $i";
        $pFontColor = !empty($_POST["paper_color_$i"]) ? htmlspecialchars($_POST["paper_color_$i"]) : "#111111";
        if ($pPath) {
            $papers[] = ['name' => $pName, 'image' => $pPath, 'textColor' => $pFontColor];
        }
    }

    if (trim($content) === '') {
        $message = "বই আপলোড হয়নি — বিষয়বস্তু খালি। অনুগ্রহ করে লেখা লিখুন অথবা সম্পূর্ণ বইয়ের ফাইল (.doc/.docx) আপলোড করুন।";
    } else {
        addBook($id, [
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'category' => $category,
            'pages' => $pages,
            'price' => $price,
            'access' => $access,
            'cover' => $coverPath,
            'author_img' => $authorPath,
            'full_book_file' => $fullBookPath,
            'papers' => $papers,
        ], [
            ['title' => $chapter_title, 'content' => $content, 'plain' => $plainContent]
        ]);
        $books = loadBooks();
        $message = "সফলভাবে '$title' সম্পূর্ণ বইটি ও কাগজের টেক্সচারগুলো আপলোড হয়েছে!";
    }
}

// কভারের দাম ও তথ্য এডিট প্রসেসিং
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_price') {
    $id = $_POST['book_id'];
    if (isset($books[$id])) {
        $books[$id]['price'] = htmlspecialchars($_POST['new_price']);
        $books[$id]['access'] = htmlspecialchars($_POST['new_access']);
        saveBooks($books);
        $message = "বইয়ের মূল্য ও অ্যাক্সেস আপডেট করা হয়েছে!";
    }
}

// পেমেন্ট যাচাই (অ্যাডমিন)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'review_payment') {
    $rows = payLoad(__DIR__);
    $pid = $_POST['pay_id'] ?? '';
    $newStatus = ($_POST['new_status'] ?? '') === 'approved' ? 'approved' : 'rejected';
    foreach ($rows as &$r) {
        if (($r['id'] ?? '') === $pid) { $r['status'] = $newStatus; $r['reviewed'] = date('Y-m-d H:i'); }
    }
    unset($r);
    paySave(__DIR__, $rows);
    $message = "পেমেন্টটি " . ($newStatus === 'approved' ? 'অনুমোদন' : 'প্রত্যাখ্যান') . " করা হয়েছে!";
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mythoscopia - ১০০ বইয়ের মহাপরিকল্পনা</title>
    <link rel="stylesheet" href="https://fonts.maateen.me/solaiman-lipi/font.css">
    <style>
        @font-face {
            font-family: 'SolaimanLipi';
            src: local('SolaimanLipi'),
                 url('https://cdn.jsdelivr.net/gh/potatosalad/solaimanlipi@master/SolaimanLipi.woff') format('woff'),
                 url('https://fonts.maateen.me/solaiman-lipi/font/SolaimanLipi.woff') format('woff');
            font-display: swap;
        }
        body { font-family: 'Times New Roman', 'SolaimanLipi', Times, serif; background: #f4f1ea; margin: 0; padding: 0; color: #222; }
        button, input, select, textarea { font-family: inherit; }
        header { background: #1e293b; color: #fff; padding: 15px 0; }
        .header-inner { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        header h1 { margin: 0; font-size: 24px; color: #f59e0b; }
        .btn { padding: 10px 18px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .btn-primary { background: #f59e0b; color: #000; }
        .btn-dark { background: #334155; color: #fff; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .alert { background: #dcfce7; color: #166534; padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #86efac; }
        
        /* আপলোড ড্যাশবোর্ড */
        #uploadPanel { display: block; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: bold; margin-bottom: 5px; font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .highlight-upload { background: #fef3c7; border: 2px dashed #d97706; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .paper-box { background: #f8fafc; padding: 12px; border: 1px dashed #94a3b8; border-radius: 6px; margin-top: 15px; }
        
        /* বইয়ের গ্রিড */
        .book-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 25px; }
        .book-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.08); position: relative; transition: transform 0.2s; }
        .book-card:hover { transform: translateY(-5px); }
        .cover-wrap { position: relative; height: 290px; background: #cbd5e1; cursor: pointer; }
        .cover-wrap img { width: 100%; height: 100%; object-fit: cover; }
        .price-badge { position: absolute; top: 10px; right: 10px; background: #dc2626; color: #fff; padding: 5px 10px; border-radius: 20px; font-weight: bold; font-size: 13px; box-shadow: 0 2px 5px rgba(0,0,0,0.3); }
        .edit-price-btn { position: absolute; bottom: 10px; right: 10px; background: rgba(0,0,0,0.75); color: #fff; border: none; padding: 5px 8px; border-radius: 4px; font-size: 11px; cursor: pointer; }
        .book-info { padding: 15px; }
        .book-info h3 { margin: 0 0 5px; font-size: 17px; }
        .book-info p { margin: 3px 0; font-size: 13px; color: #555; }
        .author-mini { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .author-mini img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }

        /* পপআপ মডাল */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: #fff; padding: 25px; border-radius: 10px; max-width: 650px; width: 90%; text-align: center; position: relative; }
        .close-modal { position: absolute; top: 10px; right: 15px; font-size: 24px; cursor: pointer; }
        .paper-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px; margin-top: 20px; }
        .paper-choice { border: 2px solid #cbd5e1; border-radius: 8px; padding: 8px; cursor: pointer; transition: 0.2s; }
        .paper-choice:hover { border-color: #f59e0b; transform: scale(1.05); }
        .paper-choice img { width: 100%; height: 90px; object-fit: cover; border-radius: 4px; }
        .paper-choice span { display: block; margin-top: 6px; font-size: 12px; font-weight: bold; }
        .empty-msg { grid-column: 1 / -1; color: #64748b; padding: 40px; text-align: center; }
        .file-badge { position: absolute; top: 10px; left: 10px; background: #0ea5e9; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .audio-btn-listen, .audio-btn-stop { padding: 10px 16px; border: 1px solid #a2a9b1; border-radius: 5px; color: #fff; font-size: 14px; font-weight: bold; cursor: pointer; }
        .audio-btn-listen { background: #22c55e; }
        .audio-btn-stop { background: #dc2626; }
        .audio-btn-listen:hover { background: #16a34a; }
        .audio-btn-stop:hover { background: #b91c1c; }

        /* Word (.docx) মেটাডেটা কালেক্টর */
        #metadataCollectorPanel { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 30px; }
        #metadataCollectorPanel h2 { margin: 0 0 6px; font-size: 22px; color: #1f2937; }
        .mc-subtitle { color: #4b5563; margin: 0 0 18px; font-size: 14px; }
        .mc-upload-box { background: #ffffff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px; }
        .mc-upload-label { display: block; margin-bottom: 8px; font-weight: bold; color: #374151; font-size: 14px; }
        .mc-file-input { display: block; width: 100%; font-size: 14px; color: #6b7280; margin-bottom: 14px; }
        .mc-file-input::file-selector-button { margin-right: 14px; padding: 8px 16px; border-radius: 9999px; border: 0; font-size: 13px; font-weight: 600; background: #eff6ff; color: #1d4ed8; cursor: pointer; }
        .mc-file-input::file-selector-button:hover { background: #dbeafe; }
        .mc-pattern-box { margin-bottom: 14px; padding: 12px; background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; font-size: 13px; color: #854d0e; line-height: 1.6; }
        .mc-pattern-box code { background: rgba(0,0,0,0.05); padding: 1px 5px; border-radius: 4px; font-family: Consolas, monospace; }
        .mc-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .mc-btn-reset { padding: 8px 16px; background: #e5e7eb; color: #374151; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .mc-btn-reset:hover { background: #d1d5db; }
        .mc-btn-export { padding: 8px 16px; background: #16a34a; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .mc-btn-export:hover:not(:disabled) { background: #15803d; }
        .mc-btn-export:disabled { opacity: 0.5; cursor: not-allowed; }
        .mc-table-wrap { background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; overflow-x: auto; }
        .mc-table { width: 100%; text-align: left; border-collapse: collapse; font-size: 14px; }
        .mc-table thead { background: #f3f4f6; }
        .mc-table th { padding: 12px; border-bottom: 1px solid #e5e7eb; font-weight: bold; color: #1f2937; white-space: nowrap; }
        .mc-table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; vertical-align: middle; }
        .mc-table td:last-child { border-right: none; }
        .mc-table tbody tr:hover { background: #f9fafb; }
        .mc-filename-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mc-cell-input { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 8px; font-size: 13px; }
        .mc-cell-input:focus { outline: 2px solid #3b82f6; background: #ffffff; border-color: transparent; }
        .mc-remove-btn { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 13px; font-weight: bold; padding: 4px 6px; }
        .mc-remove-btn:hover { color: #b91c1c; }
    </style>
</head>
<body>

<header>
    <div class="header-inner">
        <div>
            <h1>MYTHOSCOPIA</h1>
            <small>মিথোলজি ও থিওলজি আর্কাইভ (১০০ বইয়ের প্রজেক্ট)</small>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php $firstBookId = !empty($books) ? (array_key_first($books) ?: 'book_6b0656ef422e3') : 'book_6b0656ef422e3'; ?>
            <a href="read.php?id=<?= urlencode($firstBookId) ?>&paper=default&view=public" class="btn btn-primary" style="text-decoration: none; background: #2563eb; color: #fff;">🌐 পাবলিক বুক ভিউয়ার</a>
            <button class="btn btn-primary" onclick="toggleUpload()">▲/▼ আপলোড ফর্ম দেখুন বা লুকান</button>
        </div>

        <!-- নিরক্ষর পাঠকদের জন্য ভয়েস রিডিং বাটন -->
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="audio-btn-listen" onclick="startReadingBook()">🔊 শুনুন</button>
            <button type="button" class="audio-btn-stop" onclick="stopReadingBook()">⏹ বন্ধ করুন</button>
        </div>
    </div>
</header>

<div class="container">
    <?php if ($message): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>

    <!-- অ্যাডমিন বই আপলোড প্যানেল (এখন সরাসরি খোলা থাকবে) -->
    <div id="uploadPanel">
        <h2>নতুন বই, সম্পূর্ণ ফাইল ও ৫টি পড়ার কাগজ আপলোড ড্যাশবোর্ড</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_book">
            <div style="position:sticky; top:0; background:#fff; padding:10px 0; z-index:5; border-bottom:1px solid #e5e7eb; margin-bottom:15px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">✓ এখনই আপলোড / সেভ করুন</button>
                <span style="color:#64748b; font-size:13px;">ফর্ম পূরণ করে এই সবুজ বাটনে ক্লিক করুন — আপলোড হলে উপরে সবুজ বার্তা দেখাবে এবং নিচে বইয়ের তালিকায় যুক্ত হবে।</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>বইয়ের নাম *</label>
                    <input type="text" name="title" required placeholder="যেমন: স্বরবিজ্ঞান রহস্য">
                </div>
                <div class="form-group">
                    <label>লেখকের নাম *</label>
                    <input type="text" name="author" value="বলন কাইজি" required>
                </div>
                <div class="form-group">
                    <label>ক্যাটাগরি *</label>
                    <select name="category">
                        <option value="স্বরবিজ্ঞান">স্বরবিজ্ঞান</option>
                        <option value="লালন">লালন</option>
                        <option value="বলন">বলন</option>
                        <option value="পৌরাণিক চরিত্র ও চার্ট">পৌরাণিক চরিত্র ও চার্ট</option>
                        <option value="সংখ্যাতত্ত্ব">সংখ্যাতত্ত্ব</option>
                        <option value="ধর্মীয় গ্রন্থ">ধর্মীয় গ্রন্থ</option>
                        <option value="মহাকাব্য">মহাকাব্য</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>পৃষ্ঠা সংখ্যা (ফোল্ডার সাইজ)</label>
                    <select name="pages">
                        <option value="২০০ পৃষ্ঠা">২০০ পৃষ্ঠা</option>
                        <option value="৪০০ পৃষ্ঠা">৪০০ পৃষ্ঠা</option>
                        <option value="৬০০ পৃষ্ঠা">৬০০ পৃষ্ঠা</option>
                        <option value="৮০০ পৃষ্ঠা">৮০০ পৃষ্ঠা</option>
                        <option value="১০০০ পৃষ্ঠা">১০০০ পৃষ্ঠা</option>
                        <option value="১২০০+ পৃষ্ঠা">১২০০+ পৃষ্ঠা</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>বইয়ের মূল্য (টাকায়)</label>
                    <input type="text" name="price" value="৫০০ ৳" required>
                </div>
                <div class="form-group">
                    <label>সাবস্ক্রিপশন ধরন</label>
                    <select name="access">
                        <option value="সাবস্ক্রিপশন">সাবস্ক্রিপশন প্রয়োজন</option>
                        <option value="ফ্রি">ফ্রি (সবার জন্য)</option>
                        <option value="প্রিমিয়াম">প্রিমিয়াম</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>বইয়ের কভার ছবি</label>
                    <input type="file" name="cover" accept="image/*">
                </div>
                <div class="form-group">
                    <label>লেখকের ছবি</label>
                    <input type="file" name="author_img" accept="image/*">
                </div>
                <div class="form-group">
                    <label>প্রথম অধ্যায়ের নাম</label>
                    <input type="text" name="chapter_title" placeholder="যেমন: প্রথম পরিচ্ছেদ">
                </div>
            </div>

            <!-- সম্পূর্ণ বইয়ের ফাইল (.txt) -->
            <div class="highlight-upload">
                <div class="form-group">
                    <label>সম্পূর্ণ বইয়ের ফাইল (.doc / .docx) — আপলোড করলে লেখা স্বয়ংক্রিয়ভাবে যুক্ত হবে</label>
                    <input type="file" name="full_book_file" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                </div>
            </div>

            <div class="form-group" style="margin-top:15px;">
                <label>প্রথম অধ্যায়ের বিষয়বস্তু (ফাইল না দিলে এখানে লিখুন)</label>
                <textarea name="content" rows="6" placeholder="বইয়ের প্রথম অধ্যায়ের লেখা এখানে লিখুন..."></textarea>
            </div>

            <!-- ৫টি পড়ার কাগজের টেক্সচার -->
            <h3 style="margin-top:25px;">৫টি পড়ার কাগজের টেক্সচার (ঐচ্ছিক)</h3>
            <div class="form-grid">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="paper-box">
                    <div class="form-group">
                        <label>কাগজ <?= $i ?> এর নাম</label>
                        <input type="text" name="paper_name_<?= $i ?>" placeholder="কাগজ <?= $i ?>">
                    </div>
                    <div class="form-group" style="margin-top:8px;">
                        <label>ছবি আপলোড</label>
                        <input type="file" name="paper_<?= $i ?>" accept="image/*">
                    </div>
                    <div class="form-group" style="margin-top:8px;">
                        <label>লেখার রঙ</label>
                        <input type="color" name="paper_color_<?= $i ?>" value="#111111">
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:20px;">✓ বই ও কাগজ আপলোড করুন</button>
        </form>
    </div>

    <!-- Word (.docx) মেটাডেটা কালেক্টর -->
    <div id="metadataCollectorPanel">
        <h2>Word (.docx) মেটাডেটা কালেক্টর</h2>
        <p class="mc-subtitle">একসাথে ফাইল সিলেক্ট করুন → ফাইলের নাম থেকে অটো-শিরোনাম/লেখক/বছর পাবেন → ম্যানুয়াল এডিট → CSV ডাউনলোড করুন</p>

        <div class="mc-upload-box">
            <label class="mc-upload-label" for="fileInput">১. Word ফাইল সিলেক্ট করুন (.docx)</label>
            <input type="file" id="fileInput" multiple accept=".docx" class="mc-file-input">

            <div class="mc-pattern-box">
                <strong>ফাইলের নামের প্যাটার্ন:</strong> <code>শিরোনাম_লেখক_বছর.docx</code><br>
                উদাহরণ: <code>আত্মতত্ত্ব_রবীন্দ্রনাথ_২০২৩.docx</code> ⇒ শিরোনাম=আত্মতত্ত্ব, লেখক=রবীন্দ্রনাথ, বছর=২০২৩
            </div>

            <div class="mc-actions">
                <button type="button" onclick="resetAll()" class="mc-btn-reset">রিসেট</button>
                <button type="button" onclick="exportCSV()" class="mc-btn-export" id="exportBtn" disabled>CSV ডাউনলোড</button>
            </div>
        </div>

        <div class="mc-table-wrap">
            <table class="mc-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ফাইলের নাম</th>
                        <th>শিরোনাম (শিরোনাম)</th>
                        <th>লেখক</th>
                        <th>বছর</th>
                        <th>ক্যাটাগরি</th>
                        <th>নোট (ম্যানুয়াল)</th>
                        <th>অ্যাকশন</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
    </div>

    <!-- পেমেন্ট যাচাই (অ্যাডমিন) -->
    <div id="paymentsPanel" style="background:#fff; padding:20px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); margin-bottom:30px;">
        <h2 style="margin-top:0;">পেমেন্ট যাচাই ড্যাশবোর্ড</h2>
        <?php $payRows = payLoad(__DIR__); ?>
        <?php if (empty($payRows)): ?>
            <div class="empty-msg">এখনো কোনো পেমেন্ট জমা পড়েনি।</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">তারিখ</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">বই</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">পাঠক</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">মাধ্যম</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">পরিমাণ</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">TrxID</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">অবস্থা</th>
                        <th style="border:1px solid #e2e8f0; padding:6px; text-align:left;">অ্যাকশন</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_reverse($payRows) as $r): ?>
                    <tr>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars($r['created'] ?? '') ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars($r['book_title'] ?? '') ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars(($r['name'] ?? '') . ' ' . ($r['contact'] ?? '')) ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars(payMethodName($r['method'] ?? '')) ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars((string)($r['amount'] ?? '')) ?> ৳</td>
                        <td style="border:1px solid #e2e8f0; padding:6px;"><?= htmlspecialchars($r['trxid'] ?? '') ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px; font-weight:bold; color:<?= ($r['status'] ?? '') === 'approved' ? '#15803d' : (($r['status'] ?? '') === 'rejected' ? '#b91c1c' : '#b45309') ?>;"><?= htmlspecialchars($r['status'] ?? '') ?></td>
                        <td style="border:1px solid #e2e8f0; padding:6px; white-space:nowrap;">
                            <?php if (($r['status'] ?? '') !== 'approved'): ?>
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="review_payment"><input type="hidden" name="pay_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>"><input type="hidden" name="new_status" value="approved"><button class="btn btn-primary" style="padding:4px 8px; font-size:12px;">অনুমোদন</button></form>
                            <?php endif; ?>
                            <?php if (($r['status'] ?? '') !== 'rejected'): ?>
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="review_payment"><input type="hidden" name="pay_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>"><input type="hidden" name="new_status" value="rejected"><button class="btn btn-dark" style="padding:4px 8px; font-size:12px;">প্রত্যাখ্যান</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- বইয়ের গ্রিড -->
    <h2 id="bookList" style="margin-top:10px; scroll-margin-top: 16px;">বইয়ের তালিকা (মোট <?= count($books) ?>টি)</h2>
    <div class="book-grid">
        <?php if (empty($books)): ?>
            <div class="empty-msg">এখনো কোনো বই যুক্ত করা হয়নি। উপরের ফর্ম থেকে প্রথম বই যুক্ত করুন।</div>
        <?php else: ?>
            <?php foreach ($books as $bid => $book): ?>
                <div class="book-card">
                    <div class="cover-wrap" onclick="openBook('<?= htmlspecialchars($bid) ?>')">
                        <?php if (!empty($book['cover'])): ?>
                            <img src="<?= htmlspecialchars($book['cover']) ?>" alt="কভার">
                        <?php else: ?>
                            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#475569;font-weight:bold;">কভার নেই</div>
                        <?php endif; ?>
                        <?php if (!empty($book['full_book_file'])): ?>
                            <span class="file-badge">📄 সম্পূর্ণ ফাইল</span>
                        <?php endif; ?>
                        <span class="price-badge"><?= htmlspecialchars($book['price']) ?></span>
                        <button class="edit-price-btn" onclick="event.stopPropagation(); editPrice('<?= htmlspecialchars($bid) ?>')">✏️ দাম সম্পাদনা</button>
                    </div>
                    <div class="book-info">
                        <h3><?= htmlspecialchars($book['title']) ?></h3>
                        <p><?= htmlspecialchars($book['category']) ?> · <?= htmlspecialchars($book['pages']) ?></p>
                        <p>অ্যাক্সেস: <?= htmlspecialchars($book['access']) ?></p>
                        <div class="author-mini">
                            <?php if (!empty($book['author_img'])): ?>
                                <img src="<?= htmlspecialchars($book['author_img']) ?>" alt="লেখক">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($book['author']) ?></span>
                        </div>
                        <button class="btn btn-primary" style="width:100%; margin-top:10px;" onclick="goRead('<?= htmlspecialchars($bid) ?>','default')">📖 পড়ুন / পুরো বই দেখুন</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- কাগজ নির্বাচনের মডাল -->
<div class="modal" id="paperModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">পড়ার কাগজ নির্বাচন করুন</h2>
        <div class="paper-options" id="paperOptions"></div>
    </div>
</div>

<!-- দাম সম্পাদনার গোপন ফর্ম -->
<form id="editForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="edit_price">
    <input type="hidden" name="book_id" id="editBookId">
    <input type="hidden" name="new_price" id="editNewPrice">
    <input type="hidden" name="new_access" id="editNewAccess">
</form>

<script>
const BOOKS = <?= json_encode(array_map(function ($b) {
    return ['title' => $b['title'], 'price' => $b['price'], 'access' => $b['access'], 'papers' => $b['papers'] ?? []];
}, $books), JSON_UNESCAPED_UNICODE) ?>;

function toggleUpload() {
    const p = document.getElementById('uploadPanel');
    p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none';
    if (p.style.display === 'block') p.scrollIntoView({behavior: 'smooth'});
}

function openBook(id) {
    const book = BOOKS[id];
    if (!book) { window.location.href = 'read.php?id=' + encodeURIComponent(id) + '&paper=default'; return; }
    const modal = document.getElementById('paperModal');
    const opts = document.getElementById('paperOptions');
    document.getElementById('modalTitle').textContent = book.title + ' — কাগজ নির্বাচন করুন';
    opts.innerHTML = '';

    const def = document.createElement('div');
    def.className = 'paper-choice';
    def.innerHTML = '<div style="height:90px;background:#fdfbf7;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#64748b;">সাধারণ</div><span>সাধারণ কাগজ</span>';
    def.onclick = () => goRead(id, 'default');
    opts.appendChild(def);

    (book.papers || []).forEach((p, idx) => {
        const el = document.createElement('div');
        el.className = 'paper-choice';
        const img = document.createElement('img');
        img.src = p.image || '';
        img.alt = '';
        const span = document.createElement('span');
        span.textContent = p.name || '';
        el.appendChild(img);
        el.appendChild(span);
        el.onclick = () => goRead(id, idx);
        opts.appendChild(el);
    });

    modal.style.display = 'flex';
}

function goRead(id, paper) {
    window.location.href = 'read.php?id=' + encodeURIComponent(id) + '&paper=' + encodeURIComponent(paper);
}

function closeModal() {
    document.getElementById('paperModal').style.display = 'none';
}

function editPrice(id) {
    const book = BOOKS[id];
    if (!book) return;
    const np = prompt('নতুন মূল্য লিখুন:', book.price);
    if (np === null) return;
    const na = prompt('অ্যাক্সেস ধরন (সাবস্ক্রিপশন / ফ্রি / প্রিমিয়াম):', book.access);
    if (na === null) return;
    document.getElementById('editBookId').value = id;
    document.getElementById('editNewPrice').value = np;
    document.getElementById('editNewAccess').value = na;
    document.getElementById('editForm').submit();
}

document.getElementById('paperModal').addEventListener('click', (e) => {
    if (e.target.id === 'paperModal') closeModal();
});
</script>
<script>
window.addEventListener('load', function () {
    var a = document.querySelector('.alert');
    if (a) { a.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
});
</script>

<!-- পুরো বই পড়ে শোনানোর জাভাস্ক্রিপ্ট কোড (বাংলা ও ইংরেজি সাপোর্টসহ) -->
<script>
let speechUtterance = null;
let availableVoices = [];

// ব্রাউজার থেকে উপলব্ধ ভয়েসগুলো লোড করা
function loadVoices() {
    availableVoices = window.speechSynthesis.getVoices();
}
loadVoices();
if (window.speechSynthesis.onvoiceschanged !== undefined) {
    window.speechSynthesis.onvoiceschanged = loadVoices;
}

// ১০টি কণ্ঠের আলাদা বৈশিষ্ট্য (পিচ, গতি ও ভয়েস ম্যাচিং)
const voiceProfiles = {
    // ৫টি নারী কণ্ঠ
    'f1': { gender: 'female', pitch: 1.15, rate: 0.95, voiceIndex: 0 },
    'f2': { gender: 'female', pitch: 1.08, rate: 0.85, voiceIndex: 1 },
    'f3': { gender: 'female', pitch: 1.25, rate: 1.02, voiceIndex: 2 },
    'f4': { gender: 'female', pitch: 1.32, rate: 0.90, voiceIndex: 3 },
    'f5': { gender: 'female', pitch: 1.02, rate: 0.88, voiceIndex: 4 },

    // ৫টি পুরুষ কণ্ঠ
    'm1': { gender: 'male',   pitch: 0.78, rate: 0.90, voiceIndex: 0 },
    'm2': { gender: 'male',   pitch: 0.92, rate: 0.96, voiceIndex: 1 },
    'm3': { gender: 'male',   pitch: 0.85, rate: 0.84, voiceIndex: 2 },
    'm4': { gender: 'male',   pitch: 0.98, rate: 1.04, voiceIndex: 3 },
    'm5': { gender: 'male',   pitch: 0.70, rate: 0.82, voiceIndex: 4 }
};

function getMatchingVoice(isBengali, gender, index) {
    if (availableVoices.length === 0) loadVoices();
    const langPrefix = isBengali ? 'bn' : 'en';

    // ভাষা অনুযায়ী ভয়েস ফিল্টার
    let langVoices = availableVoices.filter(v => v.lang.toLowerCase().startsWith(langPrefix));
    if (langVoices.length === 0) langVoices = availableVoices;

    // নারী বা পুরুষ নামের কিওয়ার্ড দিয়ে আলাদা করা
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

    // হোমপেজে বইয়ের তালিকা, রিডার পাতায় অধ্যায়ের লেখা — দুটোতেই কাজ করবে
    const titleEl = document.getElementById('editableTitle') || document.querySelector('header h1');
    const bodyEl = document.getElementById('editableContent') || document.querySelector('.book-grid') || document.querySelector('.container');
    const titleText = titleEl ? titleEl.innerText : '';
    const bodyText = bodyEl ? bodyEl.innerText : '';
    const fullText = titleText + "। " + bodyText;

    if (!fullText.trim()) {
        alert("পড়ার জন্য কোনো লেখা পাওয়া যায়নি।");
        return;
    }

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

            // নির্বাচিত কণ্ঠের ভয়েস, পিচ (কণ্ঠের গাম্ভীর্য/তীক্ষ্ণতা) ও গতি সেট করা
            const chosenVoice = getMatchingVoice(isBengali, profile.gender, profile.voiceIndex);
            if (chosenVoice) {
                speechUtterance.voice = chosenVoice;
            }
            speechUtterance.pitch = profile.pitch;
            speechUtterance.rate = profile.rate;

            speechUtterance.onend = function() {
                currentIndex++;
                speakNextSentence();
            };

            window.speechSynthesis.speak(speechUtterance);
        }
    }

    speakNextSentence();
}

function stopReadingBook() {
    window.speechSynthesis.cancel();
}

window.onbeforeunload = function() {
    window.speechSynthesis.cancel();
};
</script>

<!-- Word (.docx) মেটাডেটা কালেক্টর স্ক্রিপ্ট -->
<script>
let docs = [];

// ফাইলের নাম থেকে মেটা এক্সট্রাক্ট (শিরোনাম_লেখক_বছর.docx)
function extractMeta(filename) {
    const name = filename.replace(/\.docx$/i, '');
    const parts = name.split('_');
    return {
        title: parts[0] || '',
        author: parts[1] || '',
        year: parts[2] || '',
        category: '',
        notes: ''
    };
}

document.getElementById('fileInput').addEventListener('change', async (e) => {
    const files = Array.from(e.target.files);
    if (files.length === 0) return;

    docs = files.map((f, i) => {
        const meta = extractMeta(f.name);
        return { id: Date.now() + i, file: f, name: f.name, ...meta };
    });

    renderTable();
    document.getElementById('exportBtn').disabled = false;
});

function renderTable() {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';

    docs.forEach((doc, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:#4b5563;">${idx + 1}</td>
            <td class="mc-filename-cell" title="${escapeHtml(doc.name)}">${escapeHtml(doc.name)}</td>
            <td><input type="text" class="mc-cell-input" value="${escapeHtml(doc.title)}" onchange="updateDoc(${doc.id}, 'title', this.value)"></td>
            <td><input type="text" class="mc-cell-input" value="${escapeHtml(doc.author)}" onchange="updateDoc(${doc.id}, 'author', this.value)"></td>
            <td><input type="text" class="mc-cell-input" value="${escapeHtml(doc.year)}" onchange="updateDoc(${doc.id}, 'year', this.value)"></td>
            <td><input type="text" class="mc-cell-input" value="${escapeHtml(doc.category)}" onchange="updateDoc(${doc.id}, 'category', this.value)"></td>
            <td><textarea class="mc-cell-input" rows="1" onchange="updateDoc(${doc.id}, 'notes', this.value)">${escapeHtml(doc.notes)}</textarea></td>
            <td><button type="button" onclick="removeDoc(${doc.id})" class="mc-remove-btn">মুছো</button></td>
        `;
        tbody.appendChild(tr);
    });
}

function updateDoc(id, field, value) {
    const doc = docs.find(d => d.id === id);
    if (doc) doc[field] = value;
}

function removeDoc(id) {
    docs = docs.filter(d => d.id !== id);
    renderTable();
    if (docs.length === 0) document.getElementById('exportBtn').disabled = true;
}

function resetAll() {
    docs = [];
    document.getElementById('fileInput').value = '';
    renderTable();
    document.getElementById('exportBtn').disabled = true;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function exportCSV() {
    const headers = ['ফাইলের নাম','শিরোনাম','লেখক','বছর','ক্যাটাগরি','নোট'];
    const rows = docs.map(d => [d.name, d.title, d.author, d.year, d.category, d.notes]);
    const csvContent = [
        headers.join(','),
        ...rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `word_metadata_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}
</script>
</body>
</html>