#!/usr/bin/env node
/**
 * Sync Masjidal prayer times into assets/data/prayer-times.json
 * Run: node scripts/sync-prayer-times.mjs
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const MASJID_ID = '5AvP54KX';
const WIDGET_URL = 'https://timing.athanplus.com/masjid/widgets/embed?theme=1&masjid_id=' + MASJID_ID;
const outDir = join(dirname(fileURLToPath(import.meta.url)), '../assets/data');
mkdirSync(outDir, { recursive: true });
const OUT = join(outDir, 'prayer-times.json');

function normalizeTime(value) {
  return value.replace(/\s+/g, ' ').trim();
}

function stripTags(html) {
  return normalizeTime(html.replace(/<[^>]+>/g, ' '));
}

function extractTableDiv(html, index) {
  const startMarker = `id="table_div_${index}"`;
  const endMarker = `id="table_div_${index + 1}"`;
  const start = html.indexOf(startMarker);
  if (start === -1) return null;
  const end = html.indexOf(endMarker, start);
  return end === -1 ? html.slice(start) : html.slice(start, end);
}

function extractTop(html, index) {
  const match = html.match(new RegExp(`id="top_${index}"[\\s\\S]*?<h2>([^<]+)<\\/h2>\\s*<p>([^<]+)<\\/p>`));
  return {
    date: match ? normalizeTime(match[1]) : '',
    hijri: match ? normalizeTime(match[2]) : '',
  };
}

function parseDay(html, index) {
  const tableHtml = extractTableDiv(html, index);
  if (!tableHtml) return null;

  const { date, hijri } = extractTop(html, index);
  const timings = {};
  const rowRegex = /<tr[^>]*>([\s\S]*?)<\/tr>/g;
  let row;
  while ((row = rowRegex.exec(tableHtml)) !== null) {
    const cells = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => stripTags(m[1]));
    if (!cells.length || /first name/i.test(cells[0])) continue;

    const name = cells[0].replace(/\s+/g, ' ').trim();
    if (!name) continue;

    if (cells.length >= 3) {
      timings[name] = { starts: cells[1], iqamah: cells[2] || cells[1] };
    } else if (cells.length === 2) {
      timings[name] = { starts: cells[1], iqamah: cells[1] };
    }
  }

  if (!Object.keys(timings).length) return null;

  const jumuah = [];
  const jumuahBlock = tableHtml.match(/<ul class="testing-sec">([\s\S]*?)<\/ul>/);
  if (jumuahBlock) {
    const itemRegex = /<li>\s*<b>([^<]+)<\/b>\s*<p[^>]*>([\s\S]*?)<\/p>\s*<\/li>/g;
    let item;
    while ((item = itemRegex.exec(jumuahBlock[1])) !== null) {
      jumuah.push({
        time: normalizeTime(item[1]),
        label: stripTags(item[2]),
      });
    }
  }

  return { date, hijri, timings, jumuah };
}

function parseWidget(html) {
  const days = [];
  for (let index = 0; index < 7; index += 1) {
    const day = parseDay(html, index);
    if (!day) break;
    days.push(day);
  }

  if (!days.length) throw new Error('table_div_0 not found');
  const today = days[0];

  return {
    source: 'masjidal',
    masjid_id: MASJID_ID,
    widget_url: WIDGET_URL,
    date: today.date,
    hijri: today.hijri,
    timings: today.timings,
    jumuah: today.jumuah,
    days,
    updated_at: new Date().toISOString(),
  };
}

const response = await fetch(WIDGET_URL, {
  headers: { 'User-Agent': 'DurhamIslamicCentre/1.0' },
});
if (!response.ok) throw new Error(`Fetch failed: ${response.status}`);
const html = await response.text();
const payload = parseWidget(html);
writeFileSync(OUT, `${JSON.stringify(payload, null, 2)}\n`);
console.log(`Saved ${OUT}`);
console.log(JSON.stringify(payload, null, 2));
