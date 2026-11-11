#!/usr/bin/env python3
"""Consistent SQLite backup/restore for an offline isolated WordPress fixture only."""
import argparse, hashlib, pathlib, sqlite3, subprocess
parser = argparse.ArgumentParser()
parser.add_argument('operation', choices=['backup','restore'])
parser.add_argument('--wordpress', type=pathlib.Path, required=True)
parser.add_argument('--backup', type=pathlib.Path, required=True)
args = parser.parse_args()
config = args.wordpress.resolve() / 'wp-content/database/db-path.php'
if not config.is_file(): raise SystemExit('Not a SQLite WordPress fixture')
database = pathlib.Path(subprocess.check_output(['php','-r','echo require $argv[1];',str(config)],text=True))
if args.operation == 'backup':
    args.backup.parent.mkdir(parents=True,exist_ok=True)
    with sqlite3.connect(database) as source, sqlite3.connect(args.backup) as target:
        source.backup(target)
    digest=hashlib.sha256(args.backup.read_bytes()).hexdigest()
    args.backup.with_suffix(args.backup.suffix+'.sha256').write_text(digest+'\n')
else:
    expected=args.backup.with_suffix(args.backup.suffix+'.sha256').read_text().strip()
    actual=hashlib.sha256(args.backup.read_bytes()).hexdigest()
    if expected != actual: raise SystemExit('Backup checksum mismatch')
    with sqlite3.connect(args.backup) as source, sqlite3.connect(database) as target:
        source.backup(target)
with sqlite3.connect(database) as db:
    if db.execute('PRAGMA integrity_check').fetchone()[0] != 'ok': raise SystemExit('Integrity check failed')
print(args.operation+' completed; SQLite integrity_check passed')
