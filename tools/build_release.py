"""Build an installable DokuWiki archive from an explicit runtime allowlist."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib

root=Path(__file__).resolve().parents[1]
files=['plugin.info.txt','helper.php','syntax.php','script.js','style.css',
       'vendor/vis-network.min.js','vendor/LICENSE-MIT','LICENSE','README.md','THIRD_PARTY.md','CHANGELOG.md']
target=root/'dist'/'graphify.zip'
target.parent.mkdir(exist_ok=True)
with ZipFile(target,'w',ZIP_DEFLATED) as archive:
    for name in files:
        archive.write(root/name,'graphify/'+name)
print(target.name, hashlib.sha256(target.read_bytes()).hexdigest(), len(files),'files')
