#!/usr/bin/env python3
"""Local lint and isolated checks; no store, database or Cloud connection."""
import pathlib
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[1]
files = [p for p in root.rglob("*") if p.is_file() and ".git" not in p.relative_to(root).parts]
for p in files:
    if p.is_symlink():
        raise RuntimeError("Symlink forbidden: " + str(p.relative_to(root)))
    if p.suffix == ".php":
        result = subprocess.run(["php", "-l", str(p)], capture_output=True, text=True, timeout=30)
        if result.returncode:
            sys.exit(result.stdout + result.stderr)
for test in ["neurocheckout-connector/tests/community_session_projection_test.php","tests/security_boundaries.php"]:
    subprocess.run(["php", str(root / test)], cwd=root, check=True, timeout=60)
print("All PHP files linted; isolated tests passed. Real platform integration is not covered.")

