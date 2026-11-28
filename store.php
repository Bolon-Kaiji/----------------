<?php
// বই সংরক্ষণ: books.json-এ শুধু বইয়ের তথ্য (হালকা), অধ্যায়ের লেখা আলাদা ফাইলে (bookdata/<id>.json)।
// ১০০+ বই হলেও হোমপেজ দ্রুত থাকে; শুধু যে বই পড়া হচ্ছে তারটাই লোড হয়।
const BOOKS_INDEX = __DIR__ . '/books.json';
const BOOKS_TRASH = __DIR__ . '/trash.json';
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

function loadTrash() {
    storeInit();
    if (!file_exists(BOOKS_TRASH)) return [];
    $trash = json_decode(file_get_contents(BOOKS_TRASH), true);
    return is_array($trash) ? $trash : [];
}

function saveTrash($trash) {
    storeWrite(BOOKS_TRASH, $trash);
}

function loadChapters($id) {
    $f = storeChaptersFile($id);
    $ch = file_exists($f) ? json_decode(file_get_contents($f), true) : null;
    return is_array($ch) ? $ch : [];
}

function storeClearCache($id) {
    $safeId = preg_replace('/[^A-Za-z0-9_]/', '', (string)$id);
    if ($safeId === '' || !is_dir(BOOKS_DATA_DIR)) return;
    foreach (glob(BOOKS_DATA_DIR . '/' . $safeId . '_render_*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob(BOOKS_DATA_DIR . '/' . $safeId . '_search_*') ?: [] as $f) {
        @unlink($f);
    }
}

function saveChapters($id, $chapters) {
    storeInit();
    storeWrite(storeChaptersFile($id), array_values($chapters));
    storeClearCache($id);
}

// নতুন বই যোগ: তথ্য index-এ, লেখা আলাদা ফাইলে
function addBook($id, $meta, $chapters) {
    $books = loadBooks();
    $books[$id] = $meta;
    saveChapters($id, $chapters);
    saveBooks($books);
}

function storeNormalizeRelPath($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    $path = ltrim($path, './');
    return ltrim($path, '/');
}

function storeSafeUnlink($baseDir, $relPath, $stillReferenced = []) {
    $norm = storeNormalizeRelPath($relPath);
    if ($norm === '' || strpos($norm, '..') !== false) return false;
    // শুধুমাত্র uploads/ ফোল্ডারের ভেতরের ফাইল মোছা হবে
    if (strpos($norm, 'uploads/') !== 0) return false;
    if (isset($stillReferenced[$norm])) return false;

    $uploadsRoot = realpath($baseDir . '/uploads');
    $abs = realpath($baseDir . '/' . $norm);
    if ($uploadsRoot === false || $abs === false) return false;
    if (strpos($abs, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) return false;
    if (!is_file($abs)) return false;

    return @unlink($abs);
}

function storeDeleteDirRecursive($dir, $allowedParentDir) {
    $parentReal = realpath($allowedParentDir);
    $dirReal = realpath($dir);
    if ($parentReal === false || $dirReal === false) return 0;
    if (strpos($dirReal, $parentReal . DIRECTORY_SEPARATOR) !== 0) return 0;
    if (!is_dir($dirReal)) return 0;

    $deleted = 0;
    $items = @scandir($dirReal);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemPath = $dirReal . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $deleted += storeDeleteDirRecursive($itemPath, $parentReal);
            } elseif (is_file($itemPath) || is_link($itemPath)) {
                if (@unlink($itemPath)) $deleted++;
            }
        }
    }
    @rmdir($dirReal);
    return $deleted;
}

// রিসাইকেল বিন: বইটি তালিকা থেকে সরিয়ে trash.json-এ হুবহু সংরক্ষণ করা (কোনো ফাইল বা ডেটা মোছা হয় না)
function softDeleteBook($id) {
    $id = (string)$id;
    $books = loadBooks();
    if ($id === '' || !isset($books[$id])) {
        return ['ok' => false, 'title' => ''];
    }

    $book = $books[$id];
    $title = (string)($book['title'] ?? $id);
    unset($books[$id]);

    $trash = loadTrash();
    $trash[$id] = [
        'id' => $id,
        'title' => $title,
        'author' => (string)($book['author'] ?? ''),
        'deleted_at' => date('Y-m-d H:i:s'),
        'meta' => $book,
    ];

    saveTrash($trash);
    saveBooks($books);
    return ['ok' => true, 'title' => $title];
}

// রিসাইকেল বিন থেকে বইটি হুবহু (একটি ডটও না হারিয়ে) ফিরিয়ে আনা
function restoreBook($id) {
    $id = (string)$id;
    $trash = loadTrash();
    if ($id === '' || !isset($trash[$id]) || empty($trash[$id]['meta']) || !is_array($trash[$id]['meta'])) {
        return ['ok' => false, 'title' => ''];
    }

    $entry = $trash[$id];
    $meta = $entry['meta'];
    $title = (string)($meta['title'] ?? $entry['title'] ?? $id);
    unset($trash[$id]);

    $books = loadBooks();
    $books[$id] = $meta;

    saveBooks($books);
    saveTrash($trash);
    return ['ok' => true, 'title' => $title];
}

// চিরতরে মোছা: শুধুমাত্র রিসাইকেল বিন থেকে নিশ্চিত করলে নির্দিষ্ট বইয়ের ফাইল ও ডেটা মোছা হবে
// (৫টি কাগজের ছবি, লেখকের ছবি, লোগো, কোড ও মূল ফোল্ডার কখনোই মোছা হবে না)
function deleteBook($id, $baseDir = __DIR__) {
    $id = (string)$id;
    $books = loadBooks();
    $trash = loadTrash();

    $book = null;
    if ($id !== '' && isset($trash[$id]['meta']) && is_array($trash[$id]['meta'])) {
        $book = $trash[$id]['meta'];
        unset($trash[$id]);
    } elseif ($id !== '' && isset($books[$id])) {
        $book = $books[$id];
        unset($books[$id]);
    } else {
        return ['ok' => false, 'deleted_files' => 0, 'title' => ''];
    }

    $title = (string)($book['title'] ?? $id);

    // সক্রিয় বই ও রিসাইকেল বিনের বাকি বইগুলোর ব্যবহৃত ফাইল সংগ্রহ করা
    $stillReferenced = [];
    $allRemaining = array_values($books);
    foreach ($trash as $t) {
        if (!empty($t['meta']) && is_array($t['meta'])) $allRemaining[] = $t['meta'];
    }
    foreach ($allRemaining as $other) {
        if (!is_array($other)) continue;
        foreach (['cover', 'author_img', 'full_book_file'] as $k) {
            if (!empty($other[$k])) {
                $stillReferenced[storeNormalizeRelPath($other[$k])] = true;
            }
        }
        if (!empty($other['papers']) && is_array($other['papers'])) {
            foreach ($other['papers'] as $p) {
                if (!empty($p['image'])) {
                    $stillReferenced[storeNormalizeRelPath($p['image'])] = true;
                }
            }
        }
        if (!empty($other['colophon']['logo'])) {
            $stillReferenced[storeNormalizeRelPath($other['colophon']['logo'])] = true;
        }
    }

    $deletedFiles = 0;
    // শুধুমাত্র ওই নির্দিষ্ট বইয়ের ডকুমেন্ট ফাইল ও কভার ছবি মোছা হবে;
    // ৫টি কাগজের ছবি (uploads/papers/*), লেখকের ছবি (uploads/authors/*) ও লোগো (samachar_logo.png) কখনোই মোছা হবে না।
    $candidates = [];
    foreach (['full_book_file', 'cover'] as $k) {
        if (!empty($book[$k])) $candidates[] = $book[$k];
    }

    foreach (array_unique($candidates) as $rel) {
        if (storeSafeUnlink($baseDir, $rel, $stillReferenced)) {
            $deletedFiles++;
        }
    }

    // docx থেকে নিষ্কাশিত বই-ভিত্তিক মিডিয়া ফোল্ডার: uploads/books/<book_id>/
    $safeId = preg_replace('/[^A-Za-z0-9_]/', '', $id);
    if ($safeId !== '') {
        $bookMediaDir = $baseDir . '/uploads/books/' . $safeId;
        if (is_dir($bookMediaDir)) {
            $deletedFiles += storeDeleteDirRecursive($bookMediaDir, $baseDir . '/uploads/books');
        }
    }

    // bookdata/<id>.json ও রেন্ডার/সার্চ ক্যাশ ফাইল মোছা
    $chaptersFile = storeChaptersFile($id);
    if (is_file($chaptersFile) && @unlink($chaptersFile)) {
        $deletedFiles++;
    }
    if ($safeId !== '' && is_dir(BOOKS_DATA_DIR)) {
        foreach (glob(BOOKS_DATA_DIR . '/' . $safeId . '_render_*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) $deletedFiles++;
        }
        foreach (glob(BOOKS_DATA_DIR . '/' . $safeId . '_search_*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) $deletedFiles++;
        }
    }

    saveBooks($books);
    saveTrash($trash);
    return ['ok' => true, 'deleted_files' => $deletedFiles, 'title' => $title];
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
