#!/usr/bin/env python3
"""Build only distributable files and enforce release/header version agreement."""
import argparse
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'corally-wordpress-manager'


def build(tag=None):
    source = (ROOT / 'corally-wp-developer.php').read_text()
    def header(name):
        match = re.search(r'^ \* ' + re.escape(name) + r': (.+)$', source, re.M)
        if not match:
            raise SystemExit(f'Missing plugin header: {name}')
        return match[1].strip()

    version = header('Version')
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise SystemExit('Version must be MAJOR.MINOR.PATCH')
    if tag and tag != 'v' + version:
        raise SystemExit(f'Tag {tag} does not match plugin version v{version}')
    if f"define('CORALLY_WPM_VERSION', '{version}');" not in source:
        raise SystemExit('Runtime version does not match the plugin header')
    readme = (ROOT / 'readme.txt').read_text()
    if not re.search(r'^Stable tag: ' + re.escape(version) + r'$', readme, re.M):
        raise SystemExit('Readme stable tag does not match the plugin header')
    manifest = dict(version=version, requires=header('Requires at least'), requires_php=header('Requires PHP'))
    dist = ROOT / 'dist'
    dist.mkdir(exist_ok=True)
    files = [ROOT / name for name in ('corally-wp-developer.php', 'README.md', 'readme.txt')]
    files += sorted((ROOT / 'includes').glob('*.php'))
    with ZipFile(dist / f'{SLUG}.zip', 'w', ZIP_DEFLATED) as archive:
        for file in files:
            archive.write(file, f'{SLUG}/{file.relative_to(ROOT).as_posix()}')
    (dist / f'{SLUG}.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(f'Built {dist / (SLUG + ".zip")} ({version})')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--tag')
    build(parser.parse_args().tag)
