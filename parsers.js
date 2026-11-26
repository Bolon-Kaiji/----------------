/*
=======================================================================================
  BOOK VIEWER MODULE: parsers | book-viewer-preview/parsers.js
  Dependency-free. Reads .docx (ZIP + the browser's built-in DecompressionStream),
  Markdown and plain text, and returns a blocks array consumed by book-viewer.js.

  block = { kind: 'heading', level, runs }
        | { kind: 'para',   runs, align? }
        | { kind: 'list',   ordered, items: [runs, ...] }
        | { kind: 'quote',  runs }
        | { kind: 'table',  rows: [[runs, ...], ...], headerRows? }
        | { kind: 'hr' }
  run   = { text, bold?, italic?, underline?, strike?, code?, color?, link? }
=======================================================================================
*/
(function () {
  'use strict';

  var W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

  /* ---------- inline (markdown) ---------- */

  function parseInline(text) {
    var runs = [];
    var re = /(\*\*|__)([\s\S]+?)\1|(\*|_)([\s\S]+?)\3|`([^`]+)`|\[([^\]]+)\]\(([^)]+)\)/g;
    var last = 0;
    var m;
    while ((m = re.exec(text))) {
      if (m.index > last) runs.push({ text: text.slice(last, m.index) });
      if (m[1]) runs.push({ text: m[2], bold: true });
      else if (m[3]) runs.push({ text: m[4], italic: true });
      else if (m[5] != null) runs.push({ text: m[5], code: true });
      else if (m[6] != null) runs.push({ text: m[6], link: m[7] });
      last = re.lastIndex;
    }
    if (last < text.length) runs.push({ text: text.slice(last) });
    if (!runs.length) runs.push({ text: text });
    return runs;
  }

  function runsText(runs) {
    return runs.map(function (r) { return r.text; }).join('');
  }

  function isTableSep(s) {
    s = String(s);
    return s.indexOf('|') !== -1 && s.indexOf('-') !== -1 && /^[\s|:\-]+$/.test(s);
  }
  function splitTableRow(line) {
    var s = String(line).trim().replace(/^\|/, '').replace(/\|$/, '');
    return s.split('|').map(function (c) { return parseInline(c.trim()); });
  }

  /* ---------- markdown blocks ---------- */

  function parseMarkdown(text) {
    var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
    var blocks = [];
    var i = 0;
    function blank(s) { return /^\s*$/.test(s); }
    while (i < lines.length) {
      var line = lines[i];
      if (blank(line)) { i++; continue; }

      var hm = line.match(/^(#{1,6})\s+(.*)$/);
      if (hm) { blocks.push({ kind: 'heading', level: hm[1].length, runs: parseInline(hm[2].trim()) }); i++; continue; }

      if (/^(\*\s*){3,}$/.test(line) || /^(-\s*){3,}$/.test(line) || /^(_\s*){3,}$/.test(line)) {
        blocks.push({ kind: 'hr' }); i++; continue;
      }

      if (line.indexOf('|') !== -1 && i + 1 < lines.length && isTableSep(lines[i + 1])) {
        var rows = [splitTableRow(line)];
        i += 2;
        while (i < lines.length && !blank(lines[i]) && lines[i].indexOf('|') !== -1) { rows.push(splitTableRow(lines[i])); i++; }
        blocks.push({ kind: 'table', rows: rows, headerRows: 1 });
        continue;
      }

      if (/^>\s?/.test(line)) {
        var quote = [];
        while (i < lines.length && /^>\s?/.test(lines[i])) { quote.push(lines[i].replace(/^>\s?/, '')); i++; }
        blocks.push({ kind: 'quote', runs: parseInline(quote.join(' ').trim()) });
        continue;
      }

      if (/^\s*[-*+]\s+/.test(line)) {
        var ul = [];
        while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) { ul.push(parseInline(lines[i].replace(/^\s*[-*+]\s+/, ''))); i++; }
        blocks.push({ kind: 'list', ordered: false, items: ul });
        continue;
      }

      if (/^\s*\d+[.)]\s+/.test(line)) {
        var ol = [];
        while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) { ol.push(parseInline(lines[i].replace(/^\s*\d+[.)]\s+/, ''))); i++; }
        blocks.push({ kind: 'list', ordered: true, items: ol });
        continue;
      }

      var buf = [line];
      i++;
      while (i < lines.length && !blank(lines[i]) &&
             !/^(#{1,6})\s+/.test(lines[i]) &&
             !/^\s*[-*+]\s+/.test(lines[i]) &&
             !/^\s*\d+[.)]\s+/.test(lines[i]) &&
             !/^>\s?/.test(lines[i])) {
        buf.push(lines[i]); i++;
      }
      blocks.push({ kind: 'para', runs: parseInline(buf.join(' ').trim()) });
    }
    return blocks;
  }

  /* ---------- plain text blocks ---------- */

  function parsePlainText(text) {
    var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
    var blocks = [];
    var para = [];
    var headingRe = /^\s*(?:অধ্যায়|পরিচ্ছেদ|অনুচ্ছেদ|পাঠ|পরিশিষ্ট|chapter|part|section|appendix)(?=\s|$|[:—\-–]).{0,80}$/i;
    function flush() {
      if (para.length) { blocks.push({ kind: 'para', runs: parseInline(para.join(' ').trim()) }); para = []; }
    }
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (/^\s*$/.test(line)) { flush(); continue; }
      if (line.trim().length <= 80 && headingRe.test(line)) {
        flush();
        blocks.push({ kind: 'heading', level: 2, runs: [{ text: line.trim() }] });
        continue;
      }
      para.push(line.trim());
    }
    flush();
    return blocks;
  }

  /* ---------- auto: pick parser by filename / content ---------- */

  function parseAuto(text, filename) {
    var name = String(filename || '').toLowerCase();
    var hasMdHead = /(^|\n)#{1,6}\s+/.test(text);
    if (/\.(md|markdown)$/.test(name)) return parseMarkdown(text);
    if (/\.(txt|text)$/.test(name)) return hasMdHead ? parseMarkdown(text) : parsePlainText(text);
    return hasMdHead ? parseMarkdown(text) : parsePlainText(text);
  }

  /* ---------- ZIP reader (read one entry from a .docx) ---------- */

  function readZipEntry(buffer, targetName) {
    var dv = new DataView(buffer);
    var u8 = new Uint8Array(buffer);
    var eocd = -1;
    var min = Math.max(0, buffer.byteLength - 65557);
    for (var p = buffer.byteLength - 22; p >= min; p--) {
      if (dv.getUint32(p, true) === 0x06054b50) { eocd = p; break; }
    }
    if (eocd < 0) return Promise.reject(new Error('not-a-zip'));

    var total = dv.getUint16(eocd + 10, true);
    var ptr = dv.getUint32(eocd + 16, true);
    var found = null;

    for (var n = 0; n < total; n++) {
      if (dv.getUint32(ptr, true) !== 0x02014b50) break;
      var method = dv.getUint16(ptr + 10, true);
      var compSize = dv.getUint32(ptr + 20, true);
      var fnLen = dv.getUint16(ptr + 28, true);
      var exLen = dv.getUint16(ptr + 30, true);
      var cmLen = dv.getUint16(ptr + 32, true);
      var lho = dv.getUint32(ptr + 42, true);
      var name = new TextDecoder('utf-8').decode(u8.subarray(ptr + 46, ptr + 46 + fnLen));
      if (name === targetName) { found = { method: method, compSize: compSize, lho: lho }; break; }
      ptr += 46 + fnLen + exLen + cmLen;
    }
    if (!found) return Promise.reject(new Error('entry-not-found'));

    var lfnLen = dv.getUint16(found.lho + 26, true);
    var lexLen = dv.getUint16(found.lho + 28, true);
    var dataStart = found.lho + 30 + lfnLen + lexLen;
    var comp = u8.subarray(dataStart, dataStart + found.compSize);

    if (found.method === 0) return Promise.resolve(comp);
    if (found.method === 8) {
      if (typeof DecompressionStream === 'undefined') return Promise.reject(new Error('no-decompression'));
      var ds = new DecompressionStream('deflate-raw');
      var stream = new Blob([comp]).stream().pipeThrough(ds);
      return new Response(stream).arrayBuffer().then(function (ab) { return new Uint8Array(ab); });
    }
    return Promise.reject(new Error('unsupported-compression'));
  }

  /* ---------- DOCX helpers ---------- */

  function firstEl(parent, tag) {
    if (!parent || !parent.getElementsByTagNameNS) return null;
    return parent.getElementsByTagNameNS(W, tag)[0] || null;
  }
  function attrVal(el) {
    if (!el) return null;
    return el.getAttributeNS(W, 'val') != null ? el.getAttributeNS(W, 'val')
      : el.getAttribute('w:val') != null ? el.getAttribute('w:val')
      : el.getAttribute('val');
  }
  function hasBool(rPr, tag) {
    var el = firstEl(rPr, tag);
    if (!el) return false;
    var v = attrVal(el);
    return !(v === '0' || v === 'false' || v === 'none');
  }
  function hasEl(rPr, tag) { return !!firstEl(rPr, tag); }

  function runText(r) {
    var out = '';
    (function walk(node) {
      var kids = node.childNodes;
      for (var k = 0; k < kids.length; k++) {
        var child = kids[k];
        if (child.nodeType !== 1) continue;
        var ln = child.localName;
        if (ln === 't') { out += child.textContent; continue; }
        if (ln === 'tab') { out += '\t'; continue; }
        if (ln === 'br' || ln === 'cr') { out += '\n'; continue; }
        if (ln === 'noBreakHyphen') { out += '-'; continue; }
        if (ln === 'drawing' || ln === 'pict' || ln === 'object' || ln === 'rPr') continue;
        walk(child);
      }
    })(r);
    return out;
  }

  function collectRuns(p) {
    var runs = [];
    var rEls = p.getElementsByTagNameNS(W, 'r');
    for (var i = 0; i < rEls.length; i++) {
      var r = rEls[i];
      var text = runText(r);
      if (!text) continue;
      var rPr = firstEl(r, 'rPr');
      var uEl = firstEl(rPr, 'u');
      var color = attrVal(firstEl(rPr, 'color'));
      runs.push({
        text: text,
        bold: hasBool(rPr, 'b'),
        italic: hasBool(rPr, 'i'),
        underline: hasEl(rPr, 'u') && attrVal(uEl) !== 'none',
        strike: hasBool(rPr, 'strike'),
        color: color && /^[0-9a-fA-F]{6}$/.test(color) ? '#' + color : null
      });
    }
    return runs;
  }

  function styleLevel(style) {
    if (!style) return 0;
    var m = /^Heading([1-6])$/i.exec(style);
    if (m) return parseInt(m[1], 10);
    if (/^Title$/i.test(style)) return 1;
    if (/^Subtitle$/i.test(style)) return 2;
    return 0;
  }

  function loadNumbering(buffer) {
    return readZipEntry(buffer, 'word/numbering.xml').then(function (bytes) {
      var xml = new TextDecoder('utf-8').decode(bytes);
      var doc = new DOMParser().parseFromString(xml, 'application/xml');
      var map = {};
      var abstractFmt = {};
      var abs = doc.getElementsByTagNameNS(W, 'abstractNum');
      for (var a = 0; a < abs.length; a++) {
        var absId = abs[a].getAttributeNS(W, 'abstractNumId') || abs[a].getAttribute('w:abstractNumId');
        var lvl = abs[a].getElementsByTagNameNS(W, 'lvl')[0];
        var fmt = attrVal(firstEl(lvl, 'numFmt')) || 'bullet';
        abstractFmt[absId] = fmt;
      }
      var nums = doc.getElementsByTagNameNS(W, 'num');
      for (var n = 0; n < nums.length; n++) {
        var numId = nums[n].getAttributeNS(W, 'numId') || nums[n].getAttribute('w:numId');
        var ref = firstEl(nums[n], 'abstractNumId');
        var absId2 = attrVal(ref);
        var fmt2 = abstractFmt[absId2] || 'bullet';
        map[numId] = (fmt2 && fmt2 !== 'bullet' && fmt2 !== 'none') ? 'ordered' : 'unordered';
      }
      return map;
    }).catch(function () { return {}; });
  }

  /* ---------- DOCX tables ---------- */

  function elemKids(node) {
    var out = [], kids = node && node.childNodes ? node.childNodes : [];
    for (var k = 0; k < kids.length; k++) if (kids[k].nodeType === 1) out.push(kids[k]);
    return out;
  }
  function collectRows(tbl) {
    var out = [];
    var kids = elemKids(tbl);
    for (var k = 0; k < kids.length; k++) {
      var c = kids[k];
      if (c.localName === 'tr') out.push(c);
      else if (c.localName === 'sdt' || c.localName === 'sdtContent') out = out.concat(collectRows(c));
    }
    return out;
  }
  function collectCells(tr) {
    var out = [];
    var kids = elemKids(tr);
    for (var k = 0; k < kids.length; k++) {
      var c = kids[k];
      if (c.localName === 'tc') out.push(c);
      else if (c.localName === 'sdt' || c.localName === 'sdtContent') out = out.concat(collectCells(c));
    }
    return out;
  }
  function cellRuns(tc) {
    var ps = tc.getElementsByTagNameNS(W, 'p');
    var runs = [];
    for (var q = 0; q < ps.length; q++) {
      var pr = collectRuns(ps[q]);
      if (!pr.length) continue;
      if (runs.length) runs.push({ text: ' ' });
      runs = runs.concat(pr);
    }
    return runs;
  }
  function parseTableRows(tbl) {
    var trs = collectRows(tbl), rows = [];
    for (var t = 0; t < trs.length; t++) {
      var cells = collectCells(trs[t]), rowRuns = [];
      for (var ci = 0; ci < cells.length; ci++) rowRuns.push(cellRuns(cells[ci]));
      rows.push(rowRuns);
    }
    return rows;
  }
  function tableHeaderRows(tbl) {
    var trs = collectRows(tbl), n = 0;
    for (var t = 0; t < trs.length; t++) {
      var trPr = firstEl(trs[t], 'trPr');
      if (trPr && firstEl(trPr, 'tblHeader')) n++; else break;
    }
    return n;
  }

  /* ---------- DOCX paragraphs / tables (in document order) ---------- */

  function handlePara(p, blocks, state, numbering) {
    var runs = collectRuns(p);
    var trimmed = runsText(runs).trim();

    var numPr = firstEl(p, 'numPr');
    if (numPr) {
      var numId = attrVal(firstEl(numPr, 'numId'));
      var ordered = numId != null ? numbering[numId] === 'ordered' : false;
      if (state.list && state.list.ordered === ordered) state.list.items.push(runs);
      else { state.list = { kind: 'list', ordered: ordered, items: [runs] }; blocks.push(state.list); }
      return;
    }

    state.list = null;
    var level = styleLevel(attrVal(firstEl(p, 'pStyle')));
    if (level && trimmed) { blocks.push({ kind: 'heading', level: level, runs: runs }); return; }
    if (trimmed) {
      var align = attrVal(firstEl(p, 'jc'));
      blocks.push({ kind: 'para', runs: runs, align: align || null });
    }
  }
  function walkBlocks(node, blocks, state, numbering) {
    var kids = elemKids(node);
    for (var k = 0; k < kids.length; k++) {
      var c = kids[k];
      var ln = c.localName;
      if (ln === 'p') handlePara(c, blocks, state, numbering);
      else if (ln === 'tbl') {
        var rows = parseTableRows(c);
        var hr = tableHeaderRows(c);
        if (!hr && rows.length > 1) hr = 1;
        blocks.push({ kind: 'table', rows: rows, headerRows: hr });
        state.list = null;
      } else if (ln !== 'sectPr') walkBlocks(c, blocks, state, numbering);
    }
  }

  function parseDocumentXml(xml, numbering) {
    var doc = new DOMParser().parseFromString(xml, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length) throw new Error('xml-parse');
    var body = doc.getElementsByTagNameNS(W, 'body')[0] || doc.documentElement;
    var blocks = [];
    walkBlocks(body, blocks, { list: null }, numbering || {});
    return blocks;
  }

  /* ---------- public: .docx ---------- */

  function parseDocx(arrayBuffer) {
    return loadNumbering(arrayBuffer).then(function (numbering) {
      return readZipEntry(arrayBuffer, 'word/document.xml').then(function (bytes) {
        var xml = new TextDecoder('utf-8').decode(bytes);
        return parseDocumentXml(xml, numbering || {});
      });
    });
  }

  window.MSBookParsers = {
    parseDocx: parseDocx,
    parseMarkdown: parseMarkdown,
    parsePlainText: parsePlainText,
    parseAuto: parseAuto
  };
})();