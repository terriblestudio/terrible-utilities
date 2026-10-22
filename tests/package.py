from pathlib import Path
import hashlib,re,zipfile
root=Path(__file__).resolve().parent.parent
out=root/'dist'; out.mkdir(exist_ok=True)
for plugin in sorted(root.glob('terrible-*')):
    main=plugin/(plugin.name+'.php')
    if not main.is_file(): continue
    version=re.search(r'\* Version: (\S+)',main.read_text())[1]
    dest=out/(plugin.name+'-'+version+'.zip')
    with zipfile.ZipFile(dest,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for p in sorted(plugin.rglob('*')):
            if not p.is_file() or p.is_symlink(): continue
            info=zipfile.ZipInfo(p.relative_to(root).as_posix(),date_time=(2026,10,11,0,0,0)); info.compress_type=zipfile.ZIP_DEFLATED; info.external_attr=0o100644<<16
            z.writestr(info,p.read_bytes())
    digest=hashlib.sha256(dest.read_bytes()).hexdigest(); dest.with_suffix('.zip.sha256').write_text(digest+'  '+dest.name+'\n'); print(dest.name+' '+digest)
