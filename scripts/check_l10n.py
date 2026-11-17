#!/usr/bin/env python3
"""Check every advertised DocuSeal translation catalog against source keys."""

import glob
import json
import re
import sys
from pathlib import Path

keys = set()
patterns = [
    (re.compile(r"t\('docuseal_nc_app',\s*'((?:[^'\\]|\\.)+)'"),
     ['src/**/*.vue', 'src/**/*.js']),
    (re.compile(r"->t\('((?:[^'\\]|\\.)+)'"), ['lib/**/*.php']),
]
for pattern, paths in patterns:
    for source_glob in paths:
        for filename in glob.glob(source_glob, recursive=True):
            source = Path(filename).read_text(encoding='utf-8')
            keys.update(value.replace("\\'", "'") for value in pattern.findall(source))

print('TOTAL SOURCE KEYS:', len(keys))
failed = False
for filename in sorted(glob.glob('l10n/*.json')):
    catalog = json.loads(Path(filename).read_text(encoding='utf-8'))['translations']
    missing = sorted(keys - set(catalog))
    empty = sorted(key for key in keys if key in catalog and not str(catalog[key]).strip())
    generated = Path(filename).with_suffix('.js')
    print(f'{filename}: {len(catalog)} entries; {len(missing)} missing; {len(empty)} empty')
    for key in missing:
        print('  MISSING:', repr(key))
    for key in empty:
        print('  EMPTY:', repr(key))
    if not generated.is_file():
        print('  GENERATED BUNDLE MISSING:', generated)
        failed = True
    if missing or empty:
        failed = True

sys.exit(1 if failed else 0)
