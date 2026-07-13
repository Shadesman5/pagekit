"""MkDocs hook — copy quality-snapshot.json into the built site."""

from __future__ import annotations

import shutil
from pathlib import Path


def on_post_build(config, **kwargs) -> None:
    repo_root = Path(config.config_file_path).resolve().parent.parent
    src = repo_root / ".github" / "quality" / "quality-snapshot.json"
    dest = Path(config.site_dir) / "quality-snapshot.json"
    if src.is_file():
        shutil.copy2(src, dest)
