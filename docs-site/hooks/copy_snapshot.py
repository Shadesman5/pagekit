"""MkDocs hook — copy quality-snapshot.json and conductor metrics into the built site."""

from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path


def _copy_tree(src: Path, dest: Path) -> None:
    if not src.is_dir():
        return
    dest.mkdir(parents=True, exist_ok=True)
    shutil.copytree(src, dest, dirs_exist_ok=True)


def _sync_roadmap_snapshot(repo_root: Path) -> None:
    script = repo_root / ".github" / "conductor" / "sync-roadmap-snapshot.mjs"
    if not script.is_file():
        return
    subprocess.run(["node", str(script)], cwd=repo_root, check=False)


def _metrics_has_steps(path: Path) -> bool:
    index = path / "index.json"
    if not index.is_file():
        return False
    data = json.loads(index.read_text(encoding="utf-8"))
    return bool(data.get("steps"))


def _copy_assets(config) -> None:
    repo_root = Path(config.config_file_path).resolve().parent.parent
    docs_root = Path(config.config_file_path).resolve().parent
    site_dir = Path(config.site_dir)
    # on_pre_build runs before MkDocs creates site_dir (fresh CI / clean build).
    site_dir.mkdir(parents=True, exist_ok=True)

    _sync_roadmap_snapshot(repo_root)

    quality_src = repo_root / ".github" / "quality" / "quality-snapshot.json"
    quality_dest = site_dir / "quality-snapshot.json"
    if quality_src.is_file():
        shutil.copy2(quality_src, quality_dest)

    metrics_live = repo_root / ".github" / "conductor" / "metrics"
    metrics_demo = docs_root / "data" / "conductor-metrics"
    metrics_dest = site_dir / "conductor-metrics"

    if _metrics_has_steps(metrics_live):
        _copy_tree(metrics_live, metrics_dest)
    elif metrics_demo.is_dir() and _metrics_has_steps(metrics_demo):
        _copy_tree(metrics_demo, metrics_dest)
    elif metrics_live.is_dir():
        _copy_tree(metrics_live, metrics_dest)


def on_pre_build(config, **kwargs) -> None:
    _copy_assets(config)


def on_post_build(config, **kwargs) -> None:
    _copy_assets(config)


def on_serve(server, config, builder, **kwargs) -> None:
    # Initial copy + watch live metrics outside docs_dir (enrich/import won't trigger rebuild alone).
    _copy_assets(config)
    repo_root = Path(config.config_file_path).resolve().parent.parent
    metrics_live = repo_root / ".github" / "conductor" / "metrics"
    quality_dir = repo_root / ".github" / "quality"
    # Refresh site/conductor-metrics/ when metrics change during mkdocs serve.
    if metrics_live.is_dir():
        server.watch(str(metrics_live), lambda: _copy_assets(config))
    if quality_dir.is_dir():
        server.watch(str(quality_dir), lambda: _copy_assets(config))
