from pathlib import Path
root=Path(__file__).resolve().parent.parent
for plugin in list(root.glob('terrible-*')):
    if not (plugin/(plugin.name+'.php')).is_file() or plugin.name.endswith('-integrations'): continue
    dest=plugin/'shared';dest.mkdir(exist_ok=True)
    for source in (root/'shared').iterdir():
        if source.is_file(): (dest/source.name).write_bytes(source.read_bytes())
