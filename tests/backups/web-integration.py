#!/usr/bin/env python3
"""Exercise the authenticated HTTP API against the disposable Compose site."""
import http.cookiejar, urllib.request, urllib.parse, urllib.error, json, re, os, zipfile, io, hashlib
base = 'http://localhost:18765'
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
client.open(base + '/wp-login.php').read()
login = urllib.parse.urlencode({'log':'backup-admin','pwd':'local-backup-test-password','wp-submit':'Log In','redirect_to':base+'/wp-admin/tools.php?page=terrible-backups','testcookie':'1'}).encode()
html = client.open(base+'/wp-login.php', login).read().decode()
config = json.loads(re.search(r'var TerribleBackups = (\{[^\n]+\});', html).group(1))
nonce = config['nonce']
def call(op, data=None, raw=None, query=None, nonce_override=None):
    params = {'action':'terrible_backups','op':op}; params.update(query or {})
    req = urllib.request.Request(base+'/wp-admin/admin-ajax.php?'+urllib.parse.urlencode(params), data=raw if raw is not None else json.dumps(data or {}).encode(), headers={'Content-Type':'application/octet-stream' if raw is not None else 'application/json','X-Terrible-Backups-Nonce':nonce if nonce_override is None else nonce_override})
    response = client.open(req)
    result = json.load(response)
    assert result['success'], result
    return result['data']
def check(value, label):
    assert value, label
    print('PASS:', label, flush=True)
try:
    call('list', nonce_override='wrong')
    raise AssertionError('accepted bad nonce')
except urllib.error.HTTPError as e:
    check(e.code == 403, 'CSRF nonce required')
try:
    urllib.request.urlopen(base+'/wp-admin/admin-ajax.php?action=terrible_backups&op=list', data=b'{}')
    raise AssertionError('accepted anonymous call')
except urllib.error.HTTPError as e:
    check(e.code in (400,403), 'anonymous requests denied')
payload = os.urandom(2*1024*1024)
manifest = {'format':'terrible-backups','version':1,'created':'2026-10-10T00:00:00Z','home':base,'siteurl':base,'prefix':'wp_','wordpress':'test','parts':['uploads'],'files':[{'name':'uploads/http-upload.bin','size':len(payload),'sha256':hashlib.sha256(payload).hexdigest()}],'tables':[]}
archive = io.BytesIO()
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_STORED) as z:
    z.writestr('manifest.json',json.dumps(manifest)); z.writestr('uploads/http-upload.bin',payload)
content=archive.getvalue()
j = call('upload',{'size':len(content)})
try:
    call('chunk', raw=content, query={'id':j['id'],'offset':0})
    raise AssertionError('large request unexpectedly accepted')
except urllib.error.HTTPError as e:
    check(e.code==413,'Apache rejects a single 2 MiB upload with its 64 KiB request limit')
for offset in range(0,len(content),16384):
    j=call('chunk',raw=content[offset:offset+16384],query={'id':j['id'],'offset':offset})
check(j['phase']=='ready','same 2 MiB archive uploads successfully in 16 KiB chunks')
preview=call('inspect',{'id':j['id']}); check(preview['expanded_size']==len(payload),'HTTP upload archive validates')
j=call('restore',{'id':j['id'],'parts':['uploads'],'url':''})
while j['phase'] not in ('done','failed','recovery'):
    j=call('step',{'id':j['id']})
check(j['phase']=='done','restore completes across authenticated HTTP requests and maintenance mode')
result=client.open(base+'/wp-content/uploads/http-upload.bin').read()
check(hashlib.sha256(result).digest()==hashlib.sha256(payload).digest(),'restored upload is served with identical bytes')
print('ALL HTTP CHECKS PASSED', flush=True)
