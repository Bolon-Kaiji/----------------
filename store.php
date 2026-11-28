<?php
// বই সংরক্ষণ: books.json-এ শুধু বইয়ের তথ্য (হালকা), অধ্যায়ের লেখা আলাদা ফাইলে (bookdata/<id>.json)।
// ১০০+ বই হলেও হোমপেজ দ্রুত থাকে; শুধু যে বই পড়া হচ্ছে তারটাই লোড হয়।
const BOOKS_INDEX = __DIR__ . '/books.json';
const BOOKS_DATA_DIR = __DIR__ . '/bookdata';
const FONTS_CONFIG = __DIR__ . '/fonts.json';

function storeWrite($file, $data) {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    rename($tmp, $file);
}

function storeChaptersFile($id) {
    return BOOKS_DATA_DIR . '/' . preg_replace('/[^A-Za-z0-9_]/', '', $id) . '.json';
}

function storeInit() {
    if (!is_dir(BOOKS_DATA_DIR)) mkdir(BOOKS_DATA_DIR, 0777, true);
    $ht = BOOKS_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
}

// সব বইয়ের তথ্য (অধ্যায় ছাড়া)। পুরনো ভারী books.json থাকলে নিজে থেকেই ভাগ করে নেয়।
function loadBooks() {
    storeInit();
    if (!file_exists(BOOKS_INDEX)) { storeWrite(BOOKS_INDEX, []); return []; }
    $books = json_decode(file_get_contents(BOOKS_INDEX), true);
    if (!is_array($books)) return [];
    $migrated = false;
    foreach ($books as $id => $b) {
        if (isset($b['chapters'])) {
            storeWrite(storeChaptersFile($id), $b['chapters']);
            unset($books[$id]['chapters']);
            $migrated = true;
        }
    }
    if ($migrated) storeWrite(BOOKS_INDEX, $books);
    return $books;
}

function saveBooks($books) {
    storeWrite(BOOKS_INDEX, $books);
}

function loadChapters($id) {
    $f = storeChaptersFile($id);
    $ch = file_exists($f) ? json_decode(file_get_contents($f), true) : null;
    return is_array($ch) ? $ch : [];
}

function saveChapters($id, $chapters) {
    storeInit();
    storeWrite(storeChaptersFile($id), array_values($chapters));
}

// নতুন বই যোগ: তথ্য index-এ, লেখা আলাদা ফাইলে
function addBook($id, $meta, $chapters) {
    $books = loadBooks();
    $books[$id] = $meta;
    saveChapters($id, $chapters);
    saveBooks($books);
}

function loadFontConfig($book = null) {
    $cfg = [];
    if (file_exists(FONTS_CONFIG)) {
        $decoded = json_decode(file_get_contents(FONTS_CONFIG), true);
        if (is_array($decoded)) $cfg = $decoded;
    }
    if (is_array($book) && !empty($book['fonts']) && is_array($book['fonts'])) {
        foreach ($book['fonts'] as $key => $val) {
            if (is_string($val) && $val !== '') {
                $cfg[$key] = array_merge(is_array($cfg[$key] ?? null) ? $cfg[$key] : [], ['family' => $val]);
            } elseif (is_array($val)) {
                $cfg[$key] = array_merge(is_array($cfg[$key] ?? null) ? $cfg[$key] : [], $val);
            }
        }
    }
    return $cfg;
}

function formatCssFontName($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $generics = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace'];
    if (in_array(strtolower($name), $generics, true)) return $name;
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $name) . "'";
}

function renderBookFontCss($book = null) {
    $cfg = loadFontConfig($book);
    $css = '';
    $vars = [];
    $stack = [];

    foreach (['ar', 'en', 'bn'] as $lang) {
        $entry = $cfg[$lang] ?? null;
        if (!is_array($entry) || empty($entry['family'])) continue;
        $family = (string)$entry['family'];
        $quoted = formatCssFontName($family);
        $vars["--font-{$lang}"] = $quoted;
        $stack[] = $quoted;

        if (!empty($entry['fallbacks']) && is_array($entry['fallbacks'])) {
            foreach ($entry['fallbacks'] as $fb) {
                $qfb = formatCssFontName($fb);
                if ($qfb !== '' && !in_array($qfb, $stack, true)) $stack[] = $qfb;
            }
        }

        $srcParts = [];
        if (!empty($entry['local']) && is_array($entry['local'])) {
            foreach ($entry['local'] as $loc) {
                $srcParts[] = "local('" . str_replace("'", "\\'", (string)$loc) . "')";
            }
        }
        if (!empty($entry['sources']) && is_array($entry['sources'])) {
            foreach ($entry['sources'] as $s) {
                if (empty($s['url'])) continue;
                $url = htmlspecialchars((string)$s['url'], ENT_QUOTES, 'UTF-8');
                $fmt = !empty($s['format']) ? " format('" . preg_replace('/[^a-z0-9_-]/i', '', (string)$s['format']) . "')" : '';
                $srcParts[] = "url('{$url}'){$fmt}";
            }
        }
        if (!empty($srcParts)) {
            $css .= "@font-face {\n";
            $css .= "    font-family: {$quoted};\n";
            $css .= "    src: " . implode(",\n         ", $srcParts) . ";\n";
            if (!empty($entry['weight'])) $css .= "    font-weight: " . $entry['weight'] . ";\n";
            if (!empty($entry['style'])) $css .= "    font-style: " . $entry['style'] . ";\n";
            if (!empty($entry['display'])) $css .= "    font-display: " . $entry['display'] . ";\n";
            if (!empty($entry['unicodeRange'])) $css .= "    unicode-range: " . $entry['unicodeRange'] . ";\n";
            $css .= "}\n";
        }
    }

    if (!empty($cfg['fallbacks']) && is_array($cfg['fallbacks'])) {
        foreach ($cfg['fallbacks'] as $fb) {
            $qfb = formatCssFontName($fb);
            if ($qfb !== '' && !in_array($qfb, $stack, true)) $stack[] = $qfb;
        }
    }
    if (empty($stack)) $stack = ['serif'];

    $css .= ":root {\n";
    foreach ($vars as $k => $v) {
        $css .= "    {$k}: {$v};\n";
    }
    $css .= "    --font-book: " . implode(', ', $stack) . ";\n";
    $css .= "}\n";
    return $css;
}
