#!/usr/bin/env node

/**
 * Small Node wrapper so you can run the lookup from npm easily.
 *
 * Examples:
 *   node scripts/erp_pc_lookup.js --products=106030168070420,500050065080560
 *   node scripts/erp_pc_lookup.js --file=storage/app/erp_samples/product_codes.txt
 */

const { spawnSync } = require('node:child_process');

function parseArgs(argv) {
  const out = {};
  for (const a of argv) {
    if (!a.startsWith('--')) continue;
    const [k, ...rest] = a.slice(2).split('=');
    out[k] = rest.length ? rest.join('=') : true;
  }
  return out;
}

const args = parseArgs(process.argv.slice(2));

const artisanArgs = ['artisan', 'erp:lookup:productClassifications'];

if (args.products) artisanArgs.push(`--products=${args.products}`);
if (args.file) artisanArgs.push(`--file=${args.file}`);

// Defaults (can be overridden)
artisanArgs.push(`--limit=${args.limit ?? 200}`);
artisanArgs.push(`--offset=${args.offset ?? 0}`);
artisanArgs.push(`--max-pages=${args['max-pages'] ?? args.maxPages ?? 50}`);

if (args['no-dump']) artisanArgs.push('--no-dump');

const res = spawnSync('php', artisanArgs, { stdio: 'inherit', shell: false });
process.exit(res.status ?? 1);
