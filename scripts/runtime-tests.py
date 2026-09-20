#!/usr/bin/env python3
"""Run sequential fixtures on an already configured DISPOSABLE localhost WP site."""
import argparse
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-root', required=True)
parser.add_argument('--vole-source', help='Vinoshipper Vole source for route fixtures')
parser.add_argument('--email-branding-source', help='Stone Tower email branding source for invoice style fixtures')
parser.add_argument('--php', default=shutil.which('php') or 'php')
parser.add_argument('--wp', default=shutil.which('wp') or 'wp')
parser.add_argument('--log-dir', help='Optional directory for per-suite output')
args = parser.parse_args()
base = [args.php, args.wp, f'--path={pathlib.Path(args.wp_root).resolve()}']
probe = "if (!in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost','127.0.0.1'), true)) { throw new RuntimeException('Disposable localhost only.'); } echo 'Woo '.WC_VERSION.' / PHP '.PHP_VERSION.' / '.WC_Data_Store::load('order')->get_current_class_name().PHP_EOL;"
subprocess.run(base + ['eval', probe], check=True)
if args.log_dir:
    pathlib.Path(args.log_dir).mkdir(parents=True, exist_ok=True)
suites = ('runtime-quote.php', 'customer-catalog-runtime.php', 'catalog-cache-runtime.php', 'quote-pricing-runtime.php', 'fulfillment-runtime.php', 'order-lifecycle-runtime.php', 'notes-runtime.php', 'square-scope-runtime.php', 'square-itemization-runtime.php', 'square-inventory-runtime.php', 'payment-runtime.php', 'presentation-runtime.php', 'email-runtime.php', 'customer-page-runtime.php')
for name in suites:
    command = base + ['eval-file', str(ROOT / 'tests' / name)]
    if name == 'fulfillment-runtime.php' and args.vole_source:
        command.append(str(pathlib.Path(args.vole_source).resolve()))
    if name == 'email-runtime.php' and args.email_branding_source:
        command.append(str(pathlib.Path(args.email_branding_source).resolve()))
    result = subprocess.run(command, capture_output=True, text=True, cwd=ROOT)
    output = result.stdout + result.stderr
    if args.log_dir:
        (pathlib.Path(args.log_dir) / name.replace('.php', '.log')).write_text(output)
    if result.returncode:
        print(output, file=sys.stderr)
        sys.exit(result.returncode)
    print(output.strip(), flush=True)
if not args.vole_source:
    print('Vinoshipper route fixture skipped: no --vole-source supplied.')
print(f'All {len(suites)} runtime suites passed.')
