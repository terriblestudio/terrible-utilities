"""Exercise real HTTP authorization, forms, redirects, metadata, mail, and media."""
import base64, hashlib, http.cookiejar, json, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid, zlib, struct
BASE='http://localhost:18766'
def check(ok,label):
    assert ok,label
    print('PASS:',label,flush=True)
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(c,url,data=None): return c.open(url if url.startswith('http') else BASE+url,urllib.parse.urlencode(data).encode() if data is not None else None)
def login(c,user,password,code=''):
    request(c,'/wp-login.php').read()
    return request(c,'/wp-login.php',{'log':user,'pwd':password,'terrible_code':code,'wp-submit':'Log In','redirect_to':BASE+'/wp-admin/profile.php','testcookie':'1'}).read().decode()
def nonce(html): return re.search(r'name="_wpnonce" value="([^"]+)"',html)[1]
def action(c,slug,op,token,**data): return request(c,'/wp-admin/admin-post.php',{'action':slug,'op':op,'_wpnonce':token,**data}).read().decode()
def denied(c,slug):
    try: action(c,slug,'save','bad'); return False
    except urllib.error.HTTPError as exc: return exc.code in (400,403)
fixtures=json.loads(subprocess.check_output(['docker','compose','exec','-T','wordpress','cat','/tmp/http-fixtures.json']))
admin=client(); login(admin,'backup-admin','local-backup-test-password')
for slug in ['backups','redirects','media','login','metadata','links','revisions']:
    html=request(admin,'/wp-admin/tools.php?page=terrible-'+slug).read().decode()
    check(('<h1>'+{'media':'Replace media','metadata':'Search &amp; sharing','revisions':'Draft updates'}.get(slug,'Terrible '+slug.title())+'</h1>') in html and 'Fatal error' not in html,'authenticated '+slug+' screen renders')
    if slug!='backups': check(denied(admin,'terrible-'+slug),slug+' rejects invalid CSRF token')
html=request(admin,'/wp-admin/options-general.php?page=terrible-mail').read().decode()
check(all(v=='' for v in re.findall(r'type="password"[^>]*value="([^"]*)"',html)),'mail screen never renders saved secrets')
result=action(admin,'terrible_mail','test',nonce(html),to='browser@example.test'); check('Test accepted by the sending service' in result,'HTTP mail test submits through configured transport')
check(denied(admin,'terrible_mail'),'mail rejects invalid CSRF token')
subscriber=client(); login(subscriber,'http-subscriber','http-test-password')
for slug in ['terrible_mail','terrible-redirects','terrible-media','terrible-login','terrible-metadata','terrible-links','terrible-revisions']:
    check(denied(subscriber,slug),slug+' rejects subscriber administration')
html=request(admin,'/wp-admin/tools.php?page=terrible-redirects').read().decode(); action(admin,'terrible-redirects','save',nonce(html),source='/http-old/',target='/?p=1',status='301')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
try: urllib.request.build_opener(NoRedirect).open(BASE+'/http-old/?private=value'); raise AssertionError('redirect absent')
except urllib.error.HTTPError as e: check(e.code==301 and e.headers['Location']==BASE+'/?p=1','public redirect applies intended status and drops incoming query')
public=client(); html=request(public,'/?p='+str(fixtures['post'])).read().decode(); check('property="og:title" content="Metadata &quot;quoted&quot; title"' in html and 'content="A &lt;script&gt; label &amp; description."' in html,'public metadata is rendered and escaped')
factor=client(); html=login(factor,'http-factor','http-factor-password'); check('Enter a valid authenticator' in html,'real login refuses password-only enrolled account')
html=login(factor,'http-factor','http-factor-password',fixtures['factor_code']); check('Terrible Login' in html and 'Status: enabled' in html,'real login accepts an unused recovery code')
again=client(); html=login(again,'http-factor','http-factor-password',fixtures['factor_code']); check('Enter a valid authenticator' in html,'recovery code replay refused over HTTP')
# Upload a small PNG through the actual media replacement endpoint.
def png():
    def chunk(kind,data): return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',20,30,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\x00'+b'\xff\x00\x00'*20)*30))+chunk(b'IEND',b'')
old=request(public,fixtures['media_url']).read(); html=request(admin,'/wp-admin/tools.php?page=terrible-media&id='+str(fixtures['media'])).read().decode(); boundary=uuid.uuid4().hex; body=b''
for key,value in {'action':'terrible-media','op':'replace','_wpnonce':nonce(html),'id':fixtures['media']}.items(): body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
body+=f'--{boundary}\r\nContent-Disposition: form-data; name="replacement"; filename="http-media.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png()+f'\r\n--{boundary}--\r\n'.encode()
result=admin.open(urllib.request.Request(BASE+'/wp-admin/admin-post.php',body,headers={'Content-Type':'multipart/form-data; boundary='+boundary})).read().decode(); check('File replaced.' in result,'media replacement accepts actual multipart upload')
check(request(public,fixtures['media_url']).read()!=old,'replacement bytes served at original URL'); html=request(admin,'/wp-admin/tools.php?page=terrible-media&id='+str(fixtures['media'])).read().decode(); action(admin,'terrible-media','undo',nonce(html),id=fixtures['media']); check(request(public,fixtures['media_url']).read()==old,'HTTP media undo serves original bytes')
print('ALL HTTP CHECKS PASSED',flush=True)
# OAuth uses single-use, user-bound state and fixed callback URLs.
import html as html_module
class CookieNoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
# Keep the same administrator cookies while observing provider redirects.
cookies=next(h.cookiejar for h in admin.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
no_redirect=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies),CookieNoRedirect)
for product,screen,needle,callback in [
    ('Backups','/wp-admin/tools.php?page=terrible-backups','action=terrible_backups_connect','terrible_backups_oauth'),
    ('Mail','/wp-admin/options-general.php?page=terrible-mail&transport=gmail','action=terrible_mail_connect','terrible_mail_oauth')]:
    source=request(admin,screen).read().decode(); href=next(html_module.unescape(url) for url in re.findall(r'href="([^"]+)"',source) if needle in html_module.unescape(url))
    try: no_redirect.open(href); raise AssertionError('OAuth redirect missing')
    except urllib.error.HTTPError as e:
        check(e.code==302,product+' OAuth starts with a redirect'); target=e.headers['Location']; args=urllib.parse.parse_qs(urllib.parse.urlparse(target).query); state=args['state'][0]
        check(args['redirect_uri'][0]==BASE+'/wp-admin/admin-post.php?action='+callback,product+' OAuth callback fixed to this site')
    url=BASE+'/wp-admin/admin-post.php?'+urllib.parse.urlencode({'action':callback,'state':state,'code':'test-code'})
    try: subscriber.open(url); raise AssertionError('subscriber callback accepted')
    except urllib.error.HTTPError as e: check(e.code==403,product+' OAuth denies a different unprivileged user')
    response=admin.open(url).read().decode(); check('Terrible '+product in response,product+' OAuth callback connects using local token endpoint')
    try: replay=admin.open(url).read().decode()
    except urllib.error.HTTPError as e: replay=e.read().decode()
    check('expired' in replay.lower(),product+' OAuth state cannot be replayed')
print('ALL OAUTH CHECKS PASSED',flush=True)
