<?php
// ===== পেমেন্ট সিস্টেম (সব ধরণের সংগ্রহের জন্য এক্সটেনসিবল) =====
// কিছুদিন পর চূড়ান্ত করার সময় শুধু payMethods() এর নম্বর/নোট বদলালেই হবে।

function payMethods() {
    return [
        ['key' => 'bkash',  'name' => 'বিকাশ',            'kind' => 'mobile', 'target' => '০১৭০০-০০০০০০', 'note' => 'নম্বরটি পাঠিয়ে ট্রানজেকশন আইডি (TrxID) লিখুন'],
        ['key' => 'nagad',  'name' => 'নগদ',             'kind' => 'mobile', 'target' => '০১৭০০-০০০০০০', 'note' => 'নম্বরটি পাঠিয়ে ট্রানজেকশন আইডি লিখুন'],
        ['key' => 'rocket', 'name' => 'রকেট',            'kind' => 'mobile', 'target' => '০১৭০০-০০০০০০', 'note' => 'ডায়াল *322# করে পাঠিয়ে ট্রানজেকশন আইডি লিখুন'],
        ['key' => 'bank',   'name' => 'ব্যাংক ট্রান্সফার', 'kind' => 'bank',   'target' => 'ব্যাংক: X / শাখা: X / অ্যাকাউন্ট: X / রাউটিং: X', 'note' => 'টাকা পাঠিয়ে ট্রানজেকশন/রেফারেন্স নম্বর লিখুন'],
        ['key' => 'card',   'name' => 'কার্ড / অনলাইন',   'kind' => 'card',   'target' => '', 'note' => 'শীঘ্রই চালু হবে (গেটওয়ে যুক্ত হলে সক্রিয় হবে)'],
        ['key' => 'paypal', 'name' => 'PayPal',           'kind' => 'card',   'target' => '', 'note' => 'শীঘ্রই চালু হবে'],
        ['key' => 'trust',  'name' => 'ট্রাস্ট (তাৎক্ষণিক আনলক)', 'kind' => 'trust', 'target' => '', 'note' => 'যাচাই ছাড়াই লিখা পরিমাণ তাৎক্ষণিক আনলক হবে'],
    ];
}

function payFile($all) { return $all . '/payments.json'; }

function payLoad($all) {
    $f = payFile($all);
    if (!file_exists($f)) @file_put_contents($f, '[]');
    $d = json_decode(@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function paySave($all, $rows) {
    @file_put_contents(payFile($all), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function payApproved($all, $bookId, $visitor) {
    $sum = 0.0;
    foreach (payLoad($all) as $p) {
        if (($p['book_id'] ?? '') === $bookId && ($p['visitor'] ?? '') === $visitor && ($p['status'] ?? '') === 'approved') {
            $sum += (float)($p['amount'] ?? 0);
        }
    }
    return $sum;
}

function payPendingCount($all, $bookId, $visitor) {
    $n = 0;
    foreach (payLoad($all) as $p) {
        if (($p['book_id'] ?? '') === $bookId && ($p['visitor'] ?? '') === $visitor && ($p['status'] ?? '') === 'pending') $n++;
    }
    return $n;
}

function payVisitor() {
    if (!empty($_COOKIE['visitor'])) return $_COOKIE['visitor'];
    $v = bin2hex(random_bytes(8));
    setcookie('visitor', $v, time() + 31536000, '/');
    $_COOKIE['visitor'] = $v;
    return $v;
}

function payMethodName($key) {
    foreach (payMethods() as $m) if ($m['key'] === $key) return $m['name'];
    return $key;
}