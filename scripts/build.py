#!/usr/bin/env python3
"""Build reproducible local plugin and delivery ZIPs with SHA-256 checksums."""
import hashlib
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
required_assets = (
    'cart-cat/assets/icon.svg',
    'cart-cat/assets/fonts/fa-duotone-900.woff2',
    'cart-cat/assets/fonts/custom-icons.woff2',
)
missing_assets = [name for name in required_assets if not (ROOT / name).is_file()]
if missing_assets:
    raise SystemExit('Supply the licensed assets listed in README.md before building: ' + ', '.join(missing_assets))
version = re.search(r'\* Version: ([0-9.]+)', (ROOT / 'cart-cat/cart-cat.php').read_text()).group(1)
dist = ROOT / 'dist'
dist.mkdir(exist_ok=True)

def archive(target, files):
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zipped:
        for file, name in sorted(files, key=lambda item: item[1]):
            if file.is_symlink():
                raise RuntimeError(f'Symlinks are not package inputs: {file}')
            info = zipfile.ZipInfo(name, date_time=(2026, 9, 10, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            zipped.writestr(info, file.read_bytes())
    with zipfile.ZipFile(target) as zipped:
        assert zipped.testzip() is None
        assert all(not name.startswith('/') and '..' not in pathlib.PurePosixPath(name).parts for name in zipped.namelist())

plugin = dist / f'cart-cat-{version}.zip'
files = [(file, file.relative_to(ROOT).as_posix()) for file in (ROOT / 'cart-cat').rglob('*') if file.is_file() and not any(part.startswith('.') for part in file.relative_to(ROOT).parts)]
archive(plugin, files)
inputs = [(plugin, plugin.name)] + files
for folder in ('compat', 'tests', 'scripts', 'docs'):
    inputs.extend((file, file.relative_to(ROOT).as_posix()) for file in (ROOT / folder).rglob('*') if file.is_file() and '__pycache__' not in file.parts and not any(part.startswith('.') for part in file.relative_to(ROOT).parts))
for name in ('README.md', 'CONTRACTS.md'):
    inputs.append((ROOT / name, name))
# Private release evidence is present only in the maintainer's local workspace.
if (ROOT / 'VALIDATION.md').is_file():
    inputs.append((ROOT / 'VALIDATION.md', 'VALIDATION.md'))
delivery = dist / f'cart-cat-{version}-delivery.zip'
archive(delivery, inputs)
checksums = '\n'.join(f'{hashlib.sha256(file.read_bytes()).hexdigest()}  {file.name}' for file in (plugin, delivery)) + '\n'
(dist / 'SHA256SUMS').write_text(checksums)
print(checksums, end='')
print(f'Plugin ZIP: {plugin.stat().st_size:,} bytes; delivery ZIP: {delivery.stat().st_size:,} bytes.')
