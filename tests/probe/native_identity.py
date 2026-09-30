"""Read-only source/build identity for native candidate evidence."""
import hashlib
from pathlib import Path
import subprocess


def source_manifest():
    root = Path(__file__).resolve().parents[2]
    files = sorted([*root.joinpath('src').rglob('*.php'), root / 'bulk-catalogue-manager-for-woocommerce.php', root / 'assets/build/index.js', root / 'assets/build/index.asset.php'])
    hashes = {str(file.relative_to(root)): hashlib.sha256(file.read_bytes()).hexdigest() for file in files}
    joined = '\n'.join(name + ':' + digest for name, digest in hashes.items())
    return {'kind': 'local source-mounted candidate; actual native editor comparator', 'head': subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip(), 'source_build_sha256': hashlib.sha256(joined.encode()).hexdigest(), 'files': hashes}
