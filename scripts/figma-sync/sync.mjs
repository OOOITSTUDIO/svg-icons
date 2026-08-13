#!/usr/bin/env node

/**
 * Синхронизация SVG-иконок из Figma.
 *
 * Ожидаемое именование компонентов в Figma:
 *   style/category/icon-name
 * например:
 *   linear/users/user
 *   bold/social/telegram
 *
 * Допускаются разделители " / " и "\" — они нормализуются в "/".
 *
 * Env:
 *   FIGMA_TOKEN       — Personal Access Token (обязательно)
 *   FIGMA_FILE_KEY    — ключ файла из URL Figma (обязательно)
 *   FIGMA_PAGE_NAME   — страница (опционально, иначе из config.json)
 *   FIGMA_NODE_ID     — id узла/фрейма вместо поиска по имени страницы
 *
 * Flags:
 *   --dry-run         — не писать файлы, только отчёт
 *   --keep-orphans    — не удалять локальные SVG, которых больше нет в Figma
 */

import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');

const args = new Set(process.argv.slice(2));
const dryRun = args.has('--dry-run');
const keepOrphans = args.has('--keep-orphans');

const config = JSON.parse(
  await readFile(path.join(__dirname, 'config.json'), 'utf8')
);

const token = requiredEnv('FIGMA_TOKEN');
const fileKey = requiredEnv('FIGMA_FILE_KEY');
const pageName = process.env.FIGMA_PAGE_NAME || config.pageName || null;
const nodeId = process.env.FIGMA_NODE_ID || null;
const iconsDir = path.resolve(ROOT, config.iconsDir || 'icons');
const colors = (config.colorsToCurrentColor || ['#1C274C']).map(normalizeHex);
const strokeWidthVar = normalizeCssVarName(
  config.strokeWidthVar || '--icon-stroke-width'
);
const batchSize = config.exportBatchSize || 50;
const nodeTypes = new Set(config.nodeTypes || ['COMPONENT']);

main().catch((error) => {
  console.error(error.message || error);
  process.exit(1);
});

async function main() {
  console.log(`Figma file: ${fileKey}`);
  console.log(`Icons dir:  ${iconsDir}`);
  if (dryRun) {
    console.log('Mode: dry-run');
  }

  const roots = await loadRoots();
  const icons = collectIcons(roots);

  if (icons.length === 0) {
    throw new Error(
      'No exportable icons found. Check page/node and naming: style/category/name'
    );
  }

  console.log(`Found icons: ${icons.length}`);

  const exported = await exportSvgs(icons);
  const stats = {
    written: 0,
    unchanged: 0,
    skipped: 0,
    removed: 0,
  };

  const syncedPaths = new Set();

  for (const icon of icons) {
    const svgUrl = exported[icon.id];
    if (!svgUrl) {
      console.warn(`Skip (no export URL): ${icon.relPath}`);
      stats.skipped += 1;
      continue;
    }

    let svg = await fetchText(svgUrl);
    svg = applyCurrentColor(svg, colors);
    svg = applyStrokeWidthVar(svg, strokeWidthVar);
    svg = finalizeSvg(svg);

    const absPath = path.join(iconsDir, `${icon.relPath}.svg`);
    syncedPaths.add(path.normalize(absPath));

    const previous = await readIfExists(absPath);
    if (previous !== null && hash(previous) === hash(svg)) {
      stats.unchanged += 1;
      continue;
    }

    if (!dryRun) {
      await mkdir(path.dirname(absPath), { recursive: true });
      await writeFile(absPath, svg, 'utf8');
    }

    console.log(`${previous === null ? 'ADD ' : 'UPD '} ${icon.relPath}.svg`);
    stats.written += 1;
  }

  if (!keepOrphans) {
    const existing = await listLocalSvgFiles(iconsDir);
    for (const file of existing) {
      if (syncedPaths.has(path.normalize(file))) {
        continue;
      }

      const rel = toPosix(path.relative(iconsDir, file));
      if (!dryRun) {
        await rm(file);
      }
      console.log(`DEL  ${rel}`);
      stats.removed += 1;
    }
  }

  console.log(
    [
      '',
      'Done.',
      `written=${stats.written}`,
      `unchanged=${stats.unchanged}`,
      `skipped=${stats.skipped}`,
      `removed=${stats.removed}`,
      `changed=${stats.written + stats.removed}`,
    ].join('\n')
  );

  if (process.env.GITHUB_OUTPUT) {
    const changed = stats.written + stats.removed > 0 ? 'true' : 'false';
    await writeFile(
      process.env.GITHUB_OUTPUT,
      `changed=${changed}\nwritten=${stats.written}\nremoved=${stats.removed}\n`,
      { flag: 'a' }
    );
  }
}

async function loadRoots() {
  if (nodeId) {
    const normalizedNodeId = normalizeNodeId(nodeId);
    const encoded = encodeURIComponent(normalizedNodeId);
    const data = await figma(`/files/${fileKey}/nodes?ids=${encoded}`);
    const node = data.nodes?.[normalizedNodeId]?.document;
    if (!node) {
      throw new Error(`FIGMA_NODE_ID not found: ${nodeId}`);
    }
    return [node];
  }

  const file = await figma(`/files/${fileKey}?depth=1`);
  const pages = file.document?.children || [];

  if (!pageName) {
    return pages;
  }

  const page = pages.find(
    (child) => child.type === 'CANVAS' && child.name.trim() === pageName
  );

  if (!page) {
    const names = pages
      .filter((child) => child.type === 'CANVAS')
      .map((child) => child.name)
      .join(', ');
    throw new Error(`Page "${pageName}" not found. Available: ${names || '—'}`);
  }

  const encoded = encodeURIComponent(page.id);
  const deep = await figma(`/files/${fileKey}/nodes?ids=${encoded}`);
  const document = deep.nodes?.[page.id]?.document;
  if (!document) {
    throw new Error(`Unable to load page tree: ${pageName}`);
  }

  return [document];
}

function collectIcons(roots) {
  const icons = [];
  const seen = new Map();

  for (const root of roots) {
    walk(root, (node) => {
      if (!nodeTypes.has(node.type)) {
        return;
      }

      pushIcon(icons, seen, node);
    });
  }

  icons.sort((a, b) => a.relPath.localeCompare(b.relPath));
  return icons;
}

function pushIcon(icons, seen, node) {
  const relPath = iconPathFromName(node.name);
  if (!relPath) {
    console.warn(`Skip (bad name): [${node.type}] ${node.name}`);
    return;
  }

  if (seen.has(relPath)) {
    console.warn(`Skip (duplicate path): ${relPath} (${node.name})`);
    return;
  }

  seen.set(relPath, node.id);
  icons.push({ id: node.id, name: node.name, relPath });
}

function iconPathFromName(name) {
  const raw = String(name || '')
    .trim()
    .replace(/\\/g, '/')
    .replace(/\s*\/\s*/g, '/')
    .replace(/^\/+|\/+$/g, '');

  const parts = raw
    .split('/')
    .map((part) => slugify(part))
    .filter(Boolean);

  if (parts.length !== 3) {
    return null;
  }

  if (parts.some((part) => part === '.' || part === '..')) {
    return null;
  }

  const [style, category, iconName] = parts;

  return [category, style, iconName].join('/');
}

function slugify(value) {
  return value
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[^a-zA-Z0-9._-]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase();
}

async function exportSvgs(icons) {
  const result = {};

  for (let i = 0; i < icons.length; i += batchSize) {
    const chunk = icons.slice(i, i + batchSize);
    const ids = chunk.map((icon) => icon.id).join(',');
    const data = await figma(
      `/images/${fileKey}?ids=${encodeURIComponent(ids)}&format=svg`
    );

    if (data.err) {
      throw new Error(`Figma export error: ${data.err}`);
    }

    Object.assign(result, data.images || {});
    console.log(
      `Exported batch ${Math.floor(i / batchSize) + 1}/${Math.ceil(icons.length / batchSize)}`
    );
  }

  return result;
}

function applyCurrentColor(svg, hexColors) {
  let result = svg;

  for (const hex of hexColors) {
    const short = hex.length === 7 ? compactHex(hex) : null;
    const variants = [hex, hex.toUpperCase(), hex.toLowerCase()];
    if (short) {
      variants.push(short, short.toUpperCase(), short.toLowerCase());
    }

    for (const variant of new Set(variants)) {
      const escaped = variant.replace('#', '\\#');
      result = result.replace(
        new RegExp(`(stroke|fill)\\s*=\\s*["']${escaped}["']`, 'gi'),
        '$1="currentColor"'
      );
      result = result.replace(
        new RegExp(`((?:stroke|fill)\\s*:\\s*)${escaped}\\b`, 'gi'),
        '$1currentColor'
      );
    }
  }

  return result;
}

/**
 * stroke-width="1.5" → stroke-width="var(--icon-stroke-width, 1.5)"
 * Уже обёрнутые в var(...) не трогает (идемпотентно при повторном sync).
 */
function applyStrokeWidthVar(svg, cssVarName) {
  let result = svg.replace(
    /stroke-width\s*=\s*(["'])([^"']+)\1/gi,
    (match, quote, rawValue) => {
      const value = rawValue.trim();
      if (!value || /^var\s*\(/i.test(value)) {
        return match;
      }

      return `stroke-width=${quote}var(${cssVarName}, ${value})${quote}`;
    }
  );

  result = result.replace(
    /stroke-width\s*:\s*([^;}"']+)/gi,
    (match, rawValue) => {
      const value = rawValue.trim();
      if (!value || /^var\s*\(/i.test(value)) {
        return match;
      }

      return `stroke-width: var(${cssVarName}, ${value})`;
    }
  );

  return result;
}

function normalizeCssVarName(name) {
  const value = String(name || '').trim();
  if (!value) {
    throw new Error('strokeWidthVar must not be empty');
  }

  return value.startsWith('--') ? value : `--${value}`;
}

function finalizeSvg(svg) {
  return `${svg.replace(/\r\n/g, '\n').trim()}\n`;
}

function compactHex(hex) {
  const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!match) {
    return null;
  }

  const [r, g, b] = [match[1], match[2], match[3]];
  if (r[0] === r[1] && g[0] === g[1] && b[0] === b[1]) {
    return `#${r[0]}${g[0]}${b[0]}`;
  }
  return null;
}

function normalizeHex(value) {
  const hex = String(value).trim();
  if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) {
    throw new Error(`Invalid color in config: ${value}`);
  }

  if (hex.length === 4) {
    return `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`.toLowerCase();
  }

  return hex.toLowerCase();
}

async function listLocalSvgFiles(dir) {
  const files = [];

  async function walkDir(current) {
    let entries;
    try {
      entries = await readdir(current, { withFileTypes: true });
    } catch (error) {
      if (error.code === 'ENOENT') {
        return;
      }
      throw error;
    }

    for (const entry of entries) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) {
        await walkDir(full);
      } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.svg')) {
        files.push(full);
      }
    }
  }

  await walkDir(dir);
  return files;
}

function walk(node, visit) {
  visit(node);
  for (const child of node.children || []) {
    walk(child, visit);
  }
}

async function figma(pathname) {
  const response = await fetch(`https://api.figma.com/v1${pathname}`, {
    headers: { 'X-Figma-Token': token },
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = data.err || data.message || response.statusText;
    throw new Error(`Figma API ${response.status}: ${message}`);
  }

  return data;
}

async function fetchText(url) {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Download failed ${response.status}: ${url}`);
  }
  return response.text();
}

async function readIfExists(filePath) {
  try {
    return await readFile(filePath, 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT') {
      return null;
    }
    throw error;
  }
}

function hash(value) {
  return createHash('sha256').update(value).digest('hex');
}

function normalizeNodeId(id) {
  return String(id).replaceAll('-', ':');
}

function toPosix(value) {
  return value.split(path.sep).join('/');
}

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing env ${name}`);
  }
  return value;
}
