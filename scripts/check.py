#!/usr/bin/env python3
"""Syntax and whitespace checks; never loads WordPress or contacts a provider."""
import argparse
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--php', default=shutil.which('php') or 'php')
parser.add_argument('--node', default=shutil.which('node') or 'node')
args = parser.parse_args()
checks = 0
for base in ('cart-cat', 'tests', 'compat', 'scripts'):
    for file in sorted((ROOT / base).rglob('*')):
        if not file.is_file() or file.suffix not in ('.php', '.js', '.cjs', '.py', '.css'):
            continue
        source = file.read_text()
        if any(line.rstrip(' \t') != line for line in source.splitlines()):
            sys.exit(f'Trailing whitespace: {file.relative_to(ROOT)}')
        if file.suffix == '.php':
            result = subprocess.run([args.php, '-l', str(file)], capture_output=True, text=True)
        elif file.suffix in ('.js', '.cjs'):
            result = subprocess.run([args.node, '--check', str(file)], capture_output=True, text=True)
        elif file.suffix == '.py':
            compile(source, str(file), 'exec')
            checks += 1
            continue
        else:
            continue
        if result.returncode:
            sys.exit(result.stdout + result.stderr)
        checks += 1
print(f'{checks} syntax checks passed; source whitespace clean.')
