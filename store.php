<?php
// বই সংরক্ষণ: books.json-এ শুধু বইয়ের তথ্য (হালকা), অধ্যায়ের লেখা আলাদা ফাইলে (bookdata/<id>.json)।
// ১০০+ বই হলেও হোমপেজ দ্রুত থাকে; শুধু যে বই পড়া হচ্ছে তারটাই লোড হয়।
const BOOKS_INDEX = __DIR__ . '/books.json';
const BOOKS_DATA_DIR = __DIR__ . '/bookdata';

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
