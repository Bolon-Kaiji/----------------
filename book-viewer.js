/*
=======================================================================================
  BOOK VIEWER TEMPLATE: engine | book-viewer-preview/book-viewer.js
  ----------------------------------------------------------------------------
  জেনেরিক ইঞ্জিন। দুটি উপাদান:
    • book-data.js    → সূচিপত্র (window.BOOK_DATA)
    • book-content.js → প্রতিটি শিরোনামের নিচের পাঠ (window.BOOK_CONTENT)

  ডান পাশে প্রতিটি শিরোনামের নিচে সংশ্লিষ্ট পাঠ দেখানো হয় (সূচির সাথে মিলিয়ে)।
  অথবা .docx/.txt/.md আপলোড করলে সেটির অংশগুলো এই সূচির সাথে স্বয়ংক্রিয়ভাবে মিলিয়ে
  দেখানো হয়; না মিললে পুরো ফাইলটি নিজের শিরোনামসহ দেখানো হয়।
=======================================================================================
*/
(function () {
  'use strict';

  var P = window.MSBookParsers;
  var CHUNK = 200;
  var STORE_THEME = 'bv-theme';

  var refs = {};
  var state = { heads: [], tocLinks: {}, pendingId: null, spyTick: false, resizeTimer: null, _active: null };

  /* ---------- DOM helpers ---------- */

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v === true ? '' : v);
    });
    if (kids != null) {
      (Array.isArray(kids) ? kids : [kids]).forEach(function (c) {
        if (c == null) return;
        n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      });
    }
    return n;
  }
  function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
  function yieldNow() { return new Promise(function (r) { setTimeout(r, 0); }); }
  function norm(s) { return String(s == null ? '' : s).normalize('NFC').toLowerCase().replace(/[^\p{L}\p{M}\p{N}]+/gu, ''); }

  /* ---------- runs / blocks -> DOM ---------- */

  function buildRuns(runs) {
    var frag = document.createDocumentFragment();
    (runs || []).forEach(function (r) {
      if (r.text == null) return;
      var node = document.createTextNode(r.text);
      if (r.link) { var a = el('a', { href: r.link, target: '_blank', rel: 'noopener' }); a.appendChild(node); node = a; }
      if (r.code) { var code = el('code'); code.appendChild(node); node = code; }
      if (r.bold || r.italic || r.underline || r.strike || r.color) {
        var span = el('span');
        if (r.bold) span.style.fontWeight = '700';
        if (r.italic) span.style.fontStyle = 'italic';
        if (r.underline) span.style.textDecoration = (span.style.textDecoration ? span.style.textDecoration + ' ' : '') + 'underline';
        if (r.strike) span.style.textDecoration = (span.style.textDecoration ? span.style.textDecoration + ' ' : '') + 'line-through';
        if (r.color) span.style.color = r.color;
        span.appendChild(node); node = span;
      }
      frag.appendChild(node);
    });
    return frag;
  }

  function tableNode(rows, headerRows, fromShort) {
    var table = el('table', { class: 'bv-table' });
    var thead = null, tbody = null;
    var head = headerRows || 0;
    (rows || []).forEach(function (row, ri) {
      var tr = el('tr');
      var isHead = ri < head;
      (row || []).forEach(function (cellRuns) {
        var cell = el(isHead ? 'th' : 'td');
        cell.appendChild(buildRuns(fromShort ? fromRuns(cellRuns) : cellRuns));
        tr.appendChild(cell);
      });
      if (isHead) { if (!thead) { thead = el('thead'); table.appendChild(thead); } thead.appendChild(tr); }
      else { if (!tbody) { tbody = el('tbody'); table.appendChild(tbody); } tbody.appendChild(tr); }
    });
    var wrap = el('div', { class: 'bv-table-wrap' });
    wrap.appendChild(table);
    return wrap;
  }

  function blockToNode(block, id) {
    switch (block.kind) {
      case 'heading': {
        var lvl = Math.max(1, Math.min(6, block.level || 2));
        var h = el('h' + lvl);
        if (id) h.id = id;
        h.appendChild(buildRuns(block.runs)); return h;
      }
      case 'list': {
        var list = el(block.ordered ? 'ol' : 'ul');
        (block.items || []).forEach(function (itemRuns) {
          var li = el('li'); li.appendChild(buildRuns(itemRuns)); list.appendChild(li);
        });
        return list;
      }
      case 'quote': {
        var bq = el('blockquote'); var p = el('p'); p.appendChild(buildRuns(block.runs)); bq.appendChild(p); return bq;
      }
      case 'table': {
        return tableNode(block.rows, block.headerRows || 0, false);
      }
      case 'hr': return el('hr');
      default: {
        var para = el('p');
        if (block.align === 'center') para.style.textAlign = 'center';
        else if (block.align === 'right' || block.align === 'end') para.style.textAlign = 'right';
        para.appendChild(buildRuns(block.runs)); return para;
      }
    }
  }

  function blocksToTocEntries(blocks) {
    var entries = [];
    for (var i = 0; i < blocks.length; i++) {
      if (blocks[i].kind === 'heading') {
        var lvl = Math.max(1, Math.min(6, blocks[i].level || 2));
        var text = (blocks[i].runs || []).map(function (r) { return r.text; }).join('').trim();
        if (text) entries.push({ id: 'bv-h-' + i, level: lvl, type: typeOf(text) || typeForLevel(lvl), text: text });
      }
    }
    return entries;
  }

  /* ---------- book-data.js -> মডেল ---------- */

  function cleanLine(line) { return String(line).replace(/\s*#\s*[০-৯0-9]+\s*$/, '').trim(); }

  /* সূচিপত্রে অধ্যায়ের নিচের শিরোনামগুলো শুধু বাংলায় দেখানোর সহায়ক।
     ইংরেজি/আরবি লিপির অংশ ও বন্ধনী-ভিত্তিক অনুবাদ বাদ দেয়; বাংলা অংশ অপরিবর্তিত থাকে। */
  function toBengaliOnly(text) {
    var s = String(text == null ? '' : text);
    var nonBn = /[A-Za-z\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF]/;
    var paren = /\(([^()]*)\)/g;
    var prev;
    do {
      prev = s;
      s = s.replace(paren, function (m, inner) { return nonBn.test(inner) ? '' : m; });
    } while (s !== prev);
    s = s.replace(/[A-Za-z][A-Za-z'’\-]*/g, '');
    s = s.replace(/[\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF]/g, '');
    s = s.replace(/\(\s*\)/g, '');
    s = s.replace(/[‘’]{2,}/g, '').replace(/['"]{2,}/g, '');
    s = s.replace(/\s*\/\s*\/\s*/g, '/');
    s = s.replace(/^[\s/]+|[\s/]+$/g, '');
    s = s.replace(/\s{2,}/g, ' ').trim();
    return s;
  }

  function levelOf(text) {
    if (/পরিচ্ছদ/.test(text)) return 1;
    if (/^[০-৯0-9]{1,3}\s*\/\s*[০-৯0-9]{1,3}/.test(text)) return 1;
    if (/^[০-৯0-9]{2,3}[.।]?\s/.test(text)) return 1;
    if (/^[০-৯0-9]{1,2}[.।]\s/.test(text)) return 3;
    return 2;
  }

  /* বিশেষ ধরনের সূচি-শিরোনাম চেনা (টীকা / তথ্যসূত্র / আরো পড়ুন / বহিঃসংযোগ) */
  function typeOf(text) {
    var t = String(text || '');
    if (/আর[োও]\s*পড়ুন/.test(t)) return 'readmore';
    if (/বহিঃসংযোগ/.test(t)) return 'extlink';
    if (/তথ্যসূত্র/.test(t)) return 'source';
    if (/টীকা|টীপ্পনী/.test(t)) return 'note';
    return null;
  }
  function typeForLevel(lvl) {
    return lvl === 0 ? 'chapter' : lvl === 1 ? 'part' : lvl === 3 ? 'sub' : 'section';
  }

  function buildModel(data) {
    data = data || {};
    var items = [];
    var idx = 0;
    (data.chapters || []).forEach(function (ch) {
      items.push({ id: 'bv-h-' + (idx++), level: 0, type: 'chapter', text: ch.title || '', chapter: true });
      (ch.lines || []).forEach(function (line) {
        var t = cleanLine(line);
        if (!t) return;
        var lvl = levelOf(t);
        items.push({ id: 'bv-h-' + (idx++), level: lvl, type: typeOf(t) || typeForLevel(lvl), text: t, display: toBengaliOnly(t) });
      });
    });
    /* পরিশিষ্ট/অনুসন্ধান — সূচির একেবারে নিচে, শীর্ষ-স্তরে (leaf: collapse-তীর নেই) */
    var appendixBlocks = [];
    (data.appendix || []).forEach(function (ap) {
      var id = 'bv-h-' + (idx++);
      items.push({ id: id, level: 0, type: 'appendix', text: ap.title || '', display: toBengaliOnly(ap.title || ''), leaf: true });
      appendixBlocks.push({ id: id, block: ap.block });
    });
    return { title: data.title || 'বুক ভিউয়ার', items: items, appendixBlocks: appendixBlocks };
  }

  /* ---------- সূচিপত্র (TOC) ---------- */

  function directSub(li) {
    for (var i = 0; i < li.children.length; i++) if (li.children[i].tagName === 'UL') return li.children[i];
    return null;
  }

  function renderToc(entries) {
    var root = refs.tocList;
    if (!root) return;
    clear(root);
    state.tocLinks = {};
    if (!entries.length) { root.appendChild(el('li', { class: 'bv-toc-empty', text: 'কোনো শিরোনাম পাওয়া যায়নি।' })); return; }
    var stack = [{ level: -1, ul: root, li: null, isRoot: true }];
    var topLevel = entries[0].level;
    for (var q = 1; q < entries.length; q++) if (entries[q].level < topLevel) topLevel = entries[q].level;
    entries.forEach(function (e) {
      while (stack.length > 1 && e.level <= stack[stack.length - 1].level) stack.pop();
      var top = stack[stack.length - 1];
      var lvlClass = e.type === 'appendix' ? ' bv-lvl-appendix'
        : e.level === 0 ? ' bv-lvl-chapter'
        : e.level === 1 ? ' bv-lvl-part'
        : e.level === 3 ? ' bv-lvl-sub' : '';
      var typeClass = ' bv-t-' + (e.type || typeForLevel(e.level));
      var isGroup = e.level === topLevel && !e.leaf;
      var a = el('a', { href: '#' + e.id, 'data-bv-id': e.id, class: 'bv-toc-link' + lvlClass + typeClass + (isGroup ? ' bv-toc-group' : '') });
      a.appendChild(el('span', { class: 'bv-toc-text', text: e.display != null ? e.display : e.text }));
      var li = el('li', isGroup ? { class: 'bv-toc-group-li' } : null); li.appendChild(a);
      if (top.isRoot || e.level <= top.level) {
        top.ul.appendChild(li);
        stack.push({ level: e.level, ul: top.ul, li: li, isRoot: false });
      } else {
        var sub = top.li ? directSub(top.li) : null;
        if (!sub) { sub = el('ul'); if (top.li) top.li.appendChild(sub); }
        sub.appendChild(li);
        stack.push({ level: e.level, ul: sub, li: li, isRoot: false });
      }
    });

    /* শুধু অধ্যায়গুলো (সর্বোচ্চ স্তর) কলাপ্স/এক্সপ্যান্ড গ্রুপ;
       বাকি শিরোনামগুলো তার অধীনে — ডিফল্টভাবে সংকুচিত (শুধু অধ্যায় দেখা যায়) */
    Array.prototype.forEach.call(root.querySelectorAll('li.bv-toc-group-li'), function (li) {
      var sub = directSub(li);
      if (!sub) return;
      var a = li.firstElementChild;
      if (!a || a.tagName !== 'A') a = li.querySelector('a');
      if (!a) return;
      var caret = el('span', { class: 'bv-toc-caret', role: 'button', tabindex: '0', 'aria-expanded': 'false', 'aria-label': 'সম্প্রসারণ/সংকোচন' });
      caret.appendChild(document.createTextNode('▸'));
      sub.classList.add('bv-collapsed');
      function toggleCaret(ev) {
        if (ev) { ev.preventDefault(); ev.stopPropagation(); }
        var collapsed = sub.classList.toggle('bv-collapsed');
        caret.setAttribute('aria-expanded', String(!collapsed));
        caret.textContent = collapsed ? '▸' : '▾';
      }
      caret.addEventListener('click', toggleCaret);
      caret.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Spacebar') toggleCaret(ev);
      });
      a.insertBefore(caret, a.firstChild);
    });

    Array.prototype.forEach.call(root.querySelectorAll('a[data-bv-id]'), function (a) {
      state.tocLinks[a.getAttribute('data-bv-id')] = a;
    });
  }

  /* ---------- book-content.js লুকআপ ---------- */

  function contentIndex() {
    var map = window.BOOK_CONTENT || {};
    var idx = { exact: map, norm: {} };
    Object.keys(map).forEach(function (k) { idx.norm[norm(k)] = map[k]; });
    return idx;
  }
  function lookupContent(text, idx) {
    idx = idx || contentIndex();
    if (idx.exact && idx.exact[text] != null) return idx.exact[text];
    var n = norm(text);
    if (n && idx.norm[n] != null) return idx.norm[n];
    return null;
  }
  function parseContent(md) { try { return P.parseMarkdown(String(md)) || []; } catch (e) { return []; } }

  /* ---------- আপলোড করা ফাইল: সূচির সাথে মেলানো ---------- */

  function blockText(b) {
    return (b.runs || []).map(function (r) { return r.text; }).join('').trim();
  }

  function leadingBn(s) { var m = /^[\u0980-\u09FF]+/.exec(s || ''); return m ? m[0].length : 0; }

  /* prefix মিল তখনই যখন সংগতিপূর্ণ অংশ (বাংলা শিরোনামের অন্তত ৬০% বা ৬ অক্ষর) মিলবে */
  function strongPrefix(bt, target, bnLen, raw) {
    if (!bt || !target) return false;
    if (raw.trim().length > 120) return false;
    var common;
    if (target.indexOf(bt) === 0) common = bt.length;
    else if (bt.indexOf(target) === 0) common = target.length;
    else return false;
    return common >= Math.max(6, Math.ceil(bnLen * 0.6));
  }

  function isHeadingMatch(block, target, bt) {
    var raw = blockText(block);
    if (bt == null) bt = norm(raw);
    if (!bt || !target) return false;
    if (bt === target) return true;
    return strongPrefix(bt, target, leadingBn(target), raw);
  }

  /* প্রতিটি সূচি-শিরোনামের প্রথম উপস্থাপনা (আগের মিলের পর) খুঁজে বের করে
     ক্রমানুসারে পাঠ ভাগ করে — মাঝখানে কোনো শিরোনাম অনুপস্থিত থাকলেও সামনে এগোয়।
     অগ্রাধিকার: (১) exact, (২) bold prefix, (৩) non-bold prefix। */
  function assignContent(blocks, entries) {
    var norms = new Array(blocks.length);
    var bolds = new Array(blocks.length);
    for (var i = 0; i < blocks.length; i++) { norms[i] = norm(blockText(blocks[i])); bolds[i] = !!blocks[i].bold; }

    var matches = [];
    var lastIdx = -1;
    for (var e = 0; e < entries.length; e++) {
      var target = norm(entries[e].text);
      if (!target) continue;
      var bnLen = leadingBn(target);
      var found = -1, j, raw;
      for (j = lastIdx + 1; j < blocks.length; j++) { if (norms[j] === target) { found = j; break; } }
      if (found < 0) {
        for (j = lastIdx + 1; j < blocks.length; j++) {
          raw = blockText(blocks[j]);
          if (bolds[j] && strongPrefix(norms[j], target, bnLen, raw)) { found = j; break; }
        }
      }
      if (found < 0) {
        for (j = lastIdx + 1; j < blocks.length; j++) {
          if (bolds[j] || blocks[j].kind === 'heading') continue;
          if (strongPrefix(norms[j], target, bnLen, blockText(blocks[j]))) { found = j; break; }
        }
      }
      if (found >= 0) { matches.push({ e: e, i: found }); lastIdx = found; }
    }

    var map = {};
    for (var m = 0; m < matches.length; m++) {
      var start = matches[m].i;
      var end = (m + 1 < matches.length) ? matches[m + 1].i : blocks.length;
      map[entries[matches[m].e].id] = blocks.slice(start + 1, end);
    }
    map['__preamble'] = blocks.slice(0, matches.length ? matches[0].i : blocks.length);
    return { map: map, matched: matches.length, matches: matches };
  }

  /* ---------- অগ্রগতি ও অবস্থা ---------- */

  function setStatus(text, isErr) {
    if (!refs.status) return;
    refs.status.textContent = text || '';
    refs.status.className = 'bv-status' + (isErr ? ' err' : '');
  }
  function showProgress(show) { if (refs.progress) refs.progress.classList.toggle('show', !!show); }
  function setProgress(pct, label) {
    if (refs.progressBar) refs.progressBar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    if (refs.progressLabel && label != null) refs.progressLabel.textContent = label;
  }

  /* ---------- scroll-spy ---------- */

  function computeOffsets() {
    var hs = refs.article.querySelectorAll('h1[id],h2[id],h3[id],h4[id],h5[id],h6[id]');
    state.heads = [];
    var pageY = window.pageYOffset || 0;
    for (var i = 0; i < hs.length; i++) state.heads.push({ id: hs[i].id, top: hs[i].getBoundingClientRect().top + pageY });
  }
  function updateActive() {
    if (!state.heads.length) return;
    var y = (window.pageYOffset || 0) + 24;
    var lo = 0, hi = state.heads.length - 1, ans = state.heads[0].id;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (state.heads[mid].top <= y) { ans = state.heads[mid].id; lo = mid + 1; } else hi = mid - 1;
    }
    setActive(ans);
  }
  function setActive(id) {
    if (state._active === id) return;
    state._active = id;
    Object.keys(state.tocLinks).forEach(function (k) { state.tocLinks[k].classList.toggle('active', k === id); });
    var a = state.tocLinks[id];
    if (a && a.offsetParent !== null && refs.tocBody) {
      var ar = a.getBoundingClientRect(), br = refs.tocBody.getBoundingClientRect();
      refs.tocBody.scrollTop += (ar.top - br.top) - refs.tocBody.clientHeight / 2 + ar.height / 2;
    }
  }
  function onScroll() {
    if (state.spyTick) return;
    state.spyTick = true;
    requestAnimationFrame(function () { state.spyTick = false; updateActive(); });
  }
  function mountSpy() {
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', function () {
      clearTimeout(state.resizeTimer);
      state.resizeTimer = setTimeout(function () { computeOffsets(); updateActive(); }, 150);
    });
  }
  function scrollToId(id) {
    var target = document.getElementById(id);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      state.pendingId = null;
      try { if (history.replaceState) history.replaceState(null, '', '#' + id); } catch (e) {}
    } else state.pendingId = id;
  }
  function flushPending() {
    if (!state.pendingId) return;
    if (document.getElementById(state.pendingId)) {
      var id = state.pendingId; state.pendingId = null;
      requestAnimationFrame(function () { scrollToId(id); });
    }
  }
  function afterRender() { computeOffsets(); setActive(state.heads.length ? state.heads[0].id : null); updateActive(); }

  /* ---------- রেন্ডারার (চাঙ্কে) ---------- */

  function headingNode(op) {
    var tag = op.level === 0 ? 'h2' : op.level === 1 ? 'h3' : op.level === 3 ? 'h5' : 'h4';
    var cls = op.level === 0 ? 'bv-o-chapter' : op.level === 1 ? 'bv-o-part' : op.level === 3 ? 'bv-o-sub' : 'bv-o-sec';
    var h = el(tag, { class: 'bv-outline ' + cls, id: op.id });
    h.appendChild(el('span', { class: 'bv-outline-text', text: op.text }));
    return h;
  }

  /* ---------- মিথোস্কোপিডিয়া-ধাঁচের তথ্যছক (infobox): প্রচ্ছদ + লেখক ---------- */

  function infoboxNode() {
    var D = window.BOOK_DATA || {};
    var cover = D.cover || null;
    var author = D.author || null;
    if (!cover && !author) return null;

    var box = el('aside', { class: 'bv-infobox', 'aria-label': 'বইয়ের তথ্য' });

    if (cover) {
      var fig = el('figure', { class: 'bv-ib-cover' });
      fig.appendChild(el('img', { src: cover, alt: D.coverAlt || (D.title || 'প্রচ্ছদ') }));
      if (D.coverCaption) fig.appendChild(el('figcaption', { text: D.coverCaption }));
      box.appendChild(fig);
    }

    if (author) {
      var row = el('div', { class: 'bv-ib-author' });
      row.appendChild(el('img', { class: 'bv-ib-author-photo', src: author, alt: D.authorName || 'লেখক' }));
      var meta = el('div', { class: 'bv-ib-author-meta' });
      if (D.authorRole) meta.appendChild(el('div', { class: 'bv-ib-label', text: D.authorRole }));
      if (D.authorName) meta.appendChild(el('div', { class: 'bv-ib-author-name', text: D.authorName }));
      row.appendChild(meta);
      box.appendChild(row);
    }

    var colophon = D.colophon;
    if (colophon && colophon.rows && colophon.rows.length) {
      var cbox = el('div', { class: 'bv-ib-colophon' });
      if (colophon.title) cbox.appendChild(el('div', { class: 'bv-colophon-title', text: colophon.title }));
      var list = el('div', { class: 'bv-colophon-list' });
      colophon.rows.forEach(function (r) {
        var rw = el('div', { class: 'bv-colophon-row' });
        rw.appendChild(el('div', { class: 'bv-colophon-label', text: r.label }));
        var val = el('div', { class: 'bv-colophon-value' });
        var lines = Array.isArray(r.value) ? r.value : [r.value];
        lines.forEach(function (ln) { val.appendChild(el('span', { class: 'bv-colophon-line', text: ln })); });
        rw.appendChild(val);
        list.appendChild(rw);
      });
      cbox.appendChild(list);
      box.appendChild(cbox);
    }

    return box;
  }

  function renderOps(ops, meta) {
    meta = meta || {};
    clear(refs.article);
    if (meta.banner) {
      refs.article.appendChild(el('div', { class: 'bv-banner',
        text: 'ডান পাশে এখনো পাঠ যুক্ত করা হয়নি। book-content.js-এ লেখা যোগ করুন, অথবা বইয়ের ফাইল আপলোড করুন।' }));
    }
    if (meta.title) {
      var t = el('h1', { class: 'bv-book-title' });
      t.appendChild(el('span', { text: meta.title }));
      refs.article.appendChild(t);
    }
    var ib = infoboxNode();
    if (ib) refs.article.appendChild(ib);
    var total = ops.length, i = 0, cur = null, cidx = 0;
    setProgress(0, 'প্রস্তুত হচ্ছে…'); showProgress(true); setStatus('লোড হচ্ছে…');
    function step() {
      var end = Math.min(i + 40, total);
      for (; i < end; i++) {
        var op = ops[i];
        if (op.chapter) { cur = el('section', { class: 'bv-chapter' }); refs.article.appendChild(cur); }
        var host = cur || refs.article;
        if (op.h) host.appendChild(headingNode(op));
        else if (op.blocks) { for (var k = 0; k < op.blocks.length; k++) host.appendChild(blockToNode(op.blocks[k], 'bv-c-' + (cidx++))); }
      }
      setProgress(total ? (i / total) * 100 : 100, 'লোড হচ্ছে… ' + i + ' / ' + total);
      flushPending();
      return i < total ? yieldNow().then(step) : Promise.resolve();
    }
    return step().then(function () {
      showProgress(false);
      setStatus(meta.name || meta.title || 'বই');
      afterRender();
    });
  }

  /* ---------- মডেল লোড (book-data.js + book-content.js) ---------- */

  /* ---------- রৈখিক (linear) বই: সব অনুচ্ছেদ ক্রম অনুযায়ী, শিরোনামে অংকর ---------- */

  function fromRuns(runs) {
    return (runs || []).map(function (r) {
      return { text: r.t != null ? r.t : (r.text || ''), bold: !!r.b, italic: !!r.i, underline: !!r.u, color: r.c || null };
    });
  }

  function linearBlockToNode(b) {
    if (b.k === 'l') {
      var list = el(b.o ? 'ol' : 'ul');
      (b.t || []).forEach(function (itemRuns) { var li = el('li'); li.appendChild(buildRuns(fromRuns(itemRuns))); list.appendChild(li); });
      return list;
    }
    if (b.k === 'q') { var bq = el('blockquote'); var qp = el('p'); qp.appendChild(buildRuns(fromRuns(b.r))); bq.appendChild(qp); return bq; }
    if (b.k === 'table') { return tableNode(b.rows, b.headerRows || 0, true); }
    var para = el('p');
    if (b.a === 'center') para.style.textAlign = 'center';
    else if (b.a === 'right' || b.a === 'end') para.style.textAlign = 'right';
    para.appendChild(buildRuns(fromRuns(b.r)));
    return para;
  }

  /* অধ্যায় (স্তর ০) শিরোনামের অ্যাংকর book-content.js-এ থাকে না, ফলে সূচিতে
     অধ্যায়ে ক্লিক দিলে পরের উপ-শিরোনামে চলে যেত। প্রতিটি অধ্যায়ের শিরোনাম-ব্লক
     তার প্রথম অ্যাংকরযুক্ত অধীশ্বরের ঠিক আগে খুঁজে বের করে অ্যাংকর যোগ করা হয়। */
  function resolveChapterAnchors(model, blocks, anchors) {
    var n = blocks.length;
    var norms = new Array(n);
    for (var i = 0; i < n; i++) norms[i] = norm(blockText(blocks[i]));
    var anchorOf = {};
    Object.keys(anchors).forEach(function (id) { anchorOf[id] = anchors[id]; });
    var items = model.items;
    var at = {};
    for (var c = 0; c < items.length; c++) {
      var it = items[c];
      if (!it.chapter || anchorOf[it.id] != null) continue;
      var target = norm(it.text);
      if (!target) continue;
      var childBi = -1, k, a;
      for (k = c + 1; k < items.length; k++) {
        if (items[k].chapter) break;
        a = anchorOf[items[k].id];
        if (a != null) { childBi = a; break; }
      }
      var found = -1, j;
      if (childBi > 0) {
        for (j = childBi - 1; j >= 0; j--) { if (norms[j] === target) { found = j; break; } }
      }
      if (found < 0) {
        var start = 0;
        for (var p = c - 1; p >= 0; p--) { var pa = anchorOf[items[p].id]; if (pa != null) { start = pa + 1; break; } }
        for (j = start; j < n; j++) { if (norms[j] === target) { found = j; break; } }
      }
      if (found >= 0 && at[found] == null) at[found] = it.id;
    }
    return at;
  }

  function renderLinear(model, book) {
    var blocks = book.blocks || [];
    var anchors = book.anchors || {};
    var at = {};
    Object.keys(anchors).forEach(function (id) { var bi = anchors[id]; if (at[bi] == null) at[bi] = id; });
    /* পরিশিষ্ট/অনুসন্ধান আইটেমের নিজস্ব অ্যাংকর (book-content.js-এর পরিবর্তন ছাড়াই) */
    (model.appendixBlocks || []).forEach(function (ab) {
      if (ab.block != null && at[ab.block] == null) at[ab.block] = ab.id;
    });
    /* অধ্যায় শিরোনামের অনুপস্থিত অ্যাংকর টেক্সট মিলিয়ে যোগ */
    var chapterAt = resolveChapterAnchors(model, blocks, anchors);
    Object.keys(chapterAt).forEach(function (bi) { if (at[bi] == null) at[bi] = chapterAt[bi]; });
    var levelById = {};
    model.items.forEach(function (it) { levelById[it.id] = it.level; });

    if (refs.title) refs.title.textContent = book.title || model.title || 'বুক ভিউয়ার';
    renderToc(model.items);
    clear(refs.article);
    if (model.title) {
      var t = el('h1', { class: 'bv-book-title' }); t.appendChild(el('span', { text: model.title })); refs.article.appendChild(t);
      if (book.subtitle) refs.article.appendChild(el('p', { class: 'bv-book-sub', text: book.subtitle }));
    }

    var ib = infoboxNode();
    if (ib) refs.article.appendChild(ib);

    if (!blocks.length) { renderDrop('কোনো পাঠ পাওয়া যায়নি।'); showProgress(false); setStatus('খালি'); return Promise.resolve(); }

    var total = blocks.length, i = 0;
    setProgress(0, 'প্রস্তুত হচ্ছে…'); showProgress(true); setStatus('লোড হচ্ছে…');
    function step() {
      var frag = document.createDocumentFragment();
      var end = Math.min(i + CHUNK, total);
      for (; i < end; i++) {
        var id = at[i];
        if (id) {
          var lvl = levelById[id]; if (lvl == null) lvl = 2;
          var tag = lvl === 0 ? 'h2' : lvl === 1 ? 'h3' : lvl === 3 ? 'h5' : 'h4';
          var cls = lvl === 0 ? 'bv-o-chapter' : lvl === 1 ? 'bv-o-part' : lvl === 3 ? 'bv-o-sub' : 'bv-o-sec';
          var h = el(tag, { class: 'bv-outline ' + cls, id: id });
          h.appendChild(buildRuns(fromRuns(blocks[i].r)));
          frag.appendChild(h);
        } else {
          frag.appendChild(linearBlockToNode(blocks[i]));
        }
      }
      refs.article.appendChild(frag);
      setProgress(total ? (i / total) * 100 : 100, 'লোড হচ্ছে… ' + i + ' / ' + total);
      flushPending();
      return i < total ? yieldNow().then(step) : Promise.resolve();
    }
    return step().then(function () {
      showProgress(false);
      setStatus(blocks.length + ' অনুচ্ছেদ');
      afterRender();
    });
  }

  function loadModel(data) {
    var model = buildModel(data);
    if (window.BOOK_CONTENT && window.BOOK_CONTENT.blocks) return renderLinear(model, window.BOOK_CONTENT);
    if (refs.title) refs.title.textContent = model.title || 'বুক ভিউয়ার';
    renderToc(model.items);
    var idx = contentIndex();
    var ops = [];
    var usedContent = false;
    var preRaw = window.BOOK_CONTENT ? window.BOOK_CONTENT['__preamble__'] : null;
    if (preRaw != null && String(preRaw).trim() !== '') {
      var preBlocks = parseContent(String(preRaw));
      if (preBlocks.length) { ops.push({ blocks: preBlocks }); usedContent = true; }
    }
    model.items.forEach(function (it) {
      if (it.chapter) { ops.push({ chapter: true, h: true, id: it.id, level: 0, text: it.text }); return; }
      ops.push({ h: true, id: it.id, level: it.level, text: it.text });
      var md = lookupContent(it.text, idx);
      if (md != null && String(md).trim() !== '') {
        var b = parseContent(String(md));
        if (b.length) { ops.push({ blocks: b }); usedContent = true; }
      }
    });
    return renderOps(ops, { title: model.title, banner: !usedContent });
  }

  /* ---------- আপলোড: সূচির সাথে মিলিয়ে লোড ---------- */

  function renderMatched(blocks, name) {
    if (!window.BOOK_DATA) return loadBlocks(blocks, { name: name });
    var model = buildModel(window.BOOK_DATA);
    var entries = model.items.filter(function (it) { return !it.chapter; })
      .map(function (it) { return { id: it.id, text: it.text }; });
    var assigned = assignContent(blocks, entries);
    if (!entries.length || assigned.matched < Math.min(3, entries.length)) return loadBlocks(blocks, { name: name });

    var idx = contentIndex();
    var ops = [];
    var pre = assigned.map['__preamble'];
    if (pre && pre.length) ops.push({ blocks: pre });

    model.items.forEach(function (it) {
      if (it.chapter) { ops.push({ chapter: true, h: true, id: it.id, level: 0, text: it.text }); return; }
      ops.push({ h: true, id: it.id, level: it.level, text: it.text });
      var content = assigned.map[it.id];
      if (!content || !content.length) {
        var md = lookupContent(it.text, idx);
        if (md != null && String(md).trim() !== '') content = parseContent(String(md));
      }
      if (content && content.length) ops.push({ blocks: content });
    });

    if (refs.title) refs.title.textContent = name || model.title;
    renderToc(model.items);
    return renderOps(ops, { name: name });
  }

  /* ---------- আপলোড (বিকল্প): নিজের শিরোনামসহ পূর্ণ রেন্ডার ---------- */

  function loadBlocks(blocks, meta) {
    blocks = blocks || [];
    renderToc(blocksToTocEntries(blocks));
    clear(refs.article);
    if (!blocks.length) {
      renderDrop('এই ফাইলে কোনো পাঠযোগ্য লেখা পাওয়া যায়নি।');
      showProgress(false); setStatus('খালি ফাইল');
      return Promise.resolve();
    }
    var total = blocks.length, i = 0;
    setProgress(0, 'প্রস্তুত হচ্ছে…'); showProgress(true); setStatus('লোড হচ্ছে…');
    function step() {
      var frag = document.createDocumentFragment();
      var end = Math.min(i + CHUNK, total);
      for (; i < end; i++) frag.appendChild(blockToNode(blocks[i], 'bv-h-' + i));
      refs.article.appendChild(frag);
      setProgress(total ? (i / total) * 100 : 100, 'লোড হচ্ছে… ' + i + ' / ' + total);
      flushPending();
      return i < total ? yieldNow().then(step) : Promise.resolve();
    }
    return step().then(function () {
      showProgress(false);
      var m = meta || {};
      setStatus(total + ' অংশ' + (m.name ? ' · ' + m.name : ''));
      afterRender();
    });
  }

  /* ---------- খালি / ড্রপ ---------- */

  function renderDrop(message) {
    clear(refs.article);
    var drop = el('div', { class: 'bv-drop' });
    drop.appendChild(el('h2', { text: 'বই বা ডকুমেন্ট খুলুন' }));
    if (message) drop.appendChild(el('p', { text: message }));
    drop.appendChild(el('p', { text: '.docx, .txt, .md — ফাইল টেনে আনুন অথবা' }));
    var pick = el('button', { type: 'button', class: 'bv-btn bv-btn-primary', text: 'ফাইল বাছুন' });
    pick.addEventListener('click', function () { refs.fileInput.click(); });
    drop.appendChild(pick);
    refs.article.appendChild(drop);
    state.heads = []; state.tocLinks = {};
  }

  /* ---------- ফাইল ---------- */

  function humanError(err) {
    var m = (err && err.message) || '';
    if (m === 'no-decompression') return 'এই ব্রাউজারে .docx পড়ার সুবিধা নেই। আধুনিক Chrome/Edge/Firefox ব্যবহার করুন।';
    if (m === 'not-a-zip' || m === 'entry-not-found') return 'ফাইলটি সঠিক .docx নয়।';
    if (m === 'unsupported-compression') return 'এই .docx ফাইলের সংকোচন পদ্ধতি সমর্থিত নয়।';
    return 'ফাইলটি পড়া যায়নি: ' + (m || 'অজানা ত্রুটি');
  }

  function loadFile(file) {
    if (!file) return;
    var name = file.name || 'ডকুমেন্ট';
    if (refs.title) refs.title.textContent = name;
    setStatus('পড়া হচ্ছে…'); showProgress(true); setProgress(5, 'ফাইল খোলা হচ্ছে…');
    function done(blocks) { return window.BOOK_DATA ? renderMatched(blocks, name) : loadBlocks(blocks, { name: name }); }

    if (/\.docx$/i.test(name)) {
      var reader = new FileReader();
      reader.onerror = function () { showProgress(false); setStatus('ফাইল পড়া যায়নি', true); };
      reader.onload = function () {
        P.parseDocx(reader.result).then(done).catch(function (err) {
          showProgress(false); setStatus(humanError(err), true); renderDrop(humanError(err));
        });
      };
      reader.readAsArrayBuffer(file);
      return;
    }
    var fr = new FileReader();
    fr.onerror = function () { showProgress(false); setStatus('ফাইল পড়া যায়নি', true); };
    fr.onload = function () {
      var blocks;
      try { blocks = P.parseAuto(String(fr.result || ''), name); }
      catch (e) { showProgress(false); setStatus('পার্স করা যায়নি', true); return; }
      done(blocks);
    };
    fr.readAsText(file, 'utf-8');
  }
  function loadFiles(list) { if (list && list.length) loadFile(list[0]); }

  /* ---------- ইভেন্ট ---------- */

  function wire() {
    refs.root = document.querySelector('.bv');
    if (!refs.root || refs.article) return;
    refs.article = refs.root.querySelector('[data-bv-article]');
    refs.tocList = refs.root.querySelector('[data-bv-toc-list]');
    refs.tocBody = refs.root.querySelector('[data-bv-toc-body]');
    refs.progress = refs.root.querySelector('[data-bv-progress]');
    refs.progressBar = refs.root.querySelector('[data-bv-progress-bar]');
    refs.progressLabel = refs.root.querySelector('[data-bv-progress-label]');
    refs.status = refs.root.querySelector('[data-bv-status]');
    refs.fileInput = refs.root.querySelector('[data-bv-file]');
    refs.theme = refs.root.querySelector('[data-bv-theme-select]');
    refs.title = refs.root.querySelector('[data-bv-title]');
    refs.tocToggle = refs.root.querySelector('[data-bv-toc-toggle]');
    refs.toc = refs.root.querySelector('[data-bv-toc]');

    Array.prototype.forEach.call(refs.root.querySelectorAll('[data-bv-pick]'), function (btn) {
      btn.addEventListener('click', function () { refs.fileInput.click(); });
    });
    if (refs.fileInput) refs.fileInput.addEventListener('change', function () { loadFiles(refs.fileInput.files); refs.fileInput.value = ''; });

    ['dragenter', 'dragover'].forEach(function (ev) {
      refs.root.addEventListener(ev, function (e) { e.preventDefault(); refs.root.classList.add('drop-on'); });
    });
    refs.root.addEventListener('dragleave', function (e) { if (e.target === refs.root) refs.root.classList.remove('drop-on'); });
    refs.root.addEventListener('drop', function (e) { e.preventDefault(); refs.root.classList.remove('drop-on'); if (e.dataTransfer && e.dataTransfer.files) loadFiles(e.dataTransfer.files); });

    if (refs.theme) {
      var saved = null; try { saved = localStorage.getItem(STORE_THEME); } catch (e) {}
      if (saved) { refs.theme.value = saved; refs.root.setAttribute('data-bv-theme', saved); }
      refs.theme.addEventListener('change', function () {
        refs.root.setAttribute('data-bv-theme', refs.theme.value);
        try { localStorage.setItem(STORE_THEME, refs.theme.value); } catch (e) {}
      });
    }

    if (refs.tocToggle && refs.toc) {
      refs.tocToggle.addEventListener('click', function () {
        var collapsed = refs.toc.classList.toggle('collapsed');
        refs.tocToggle.setAttribute('aria-expanded', String(!collapsed));
        refs.tocToggle.textContent = collapsed ? '▸' : '▾';
      });
      if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) { refs.toc.classList.add('collapsed'); refs.tocToggle.textContent = '▸'; }
    }

    if (refs.tocList) refs.tocList.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[data-bv-id]') : null;
      if (!a) return;
      e.preventDefault();
      var id = a.getAttribute('data-bv-id');
      if (!document.getElementById(id)) {
        var links = Array.prototype.slice.call(refs.tocList.querySelectorAll('a[data-bv-id]'));
        var at = links.indexOf(a);
        for (var k = at + 1; k < links.length; k++) {
          if (document.getElementById(links[k].getAttribute('data-bv-id'))) { id = links[k].getAttribute('data-bv-id'); break; }
        }
      }
      scrollToId(id);
    });

    mountSpy();
  }

  function init() {
    wire();
    if (!refs.root || !refs.article) return;
    if (window.BOOK_DATA) { loadModel(window.BOOK_DATA); return; }
    renderDrop();
  }

  window.MSBookViewer = {
    loadModel: function (data) { if (!refs.article) wire(); return loadModel(data); },
    loadBlocks: function (blocks, meta) { if (!refs.article) wire(); return loadBlocks(blocks, meta || {}); },
    loadText: function (text, name) {
      if (!refs.article) wire();
      var blocks; try { blocks = P.parseAuto(String(text), name); } catch (e) { blocks = []; }
      if (refs.title) refs.title.textContent = name || 'বুক ভিউয়ার';
      return window.BOOK_DATA ? renderMatched(blocks, name) : loadBlocks(blocks, { name: name || '' });
    },
    loadDocx: function (buffer, name) {
      if (!refs.article) wire();
      if (refs.title) refs.title.textContent = name || 'বুক ভিউয়ার';
      return P.parseDocx(buffer).then(function (blocks) { return window.BOOK_DATA ? renderMatched(blocks, name) : loadBlocks(blocks, { name: name || '' }); });
    },
    loadFiles: function (files) { if (!refs.article) wire(); loadFiles(files); },
    loadFile: function (file) { if (!refs.article) wire(); loadFile(file); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();