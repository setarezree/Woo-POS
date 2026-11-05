"""Build an installable plugin ZIP without local tooling or test fixtures."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[1]
target = root / 'dist' / 'universal-woocommerce-pos.zip'
target.parent.mkdir(exist_ok=True)
files = [root / name for name in (
    'universal-woocommerce-pos.php', 'index.php', 'uninstall.php',
    'readme.txt', 'README.md', 'LICENSE',
)]
for directory in ('includes', 'languages', 'docs', 'assets', 'templates'):
    files.extend(path for path in (root / directory).rglob('*') if path.is_file())
with ZipFile(target, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(files):
        archive.write(path, 'universal-woocommerce-pos/' + path.relative_to(root).as_posix())
with ZipFile(target) as archive:
    if archive.testzip() is not None:
        raise RuntimeError('ZIP integrity check failed')
print(target)
