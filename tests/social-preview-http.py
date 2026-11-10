import base64, http.cookiejar, json, re, subprocess, sys, urllib.error, urllib.parse, urllib.request

base=sys.argv[1] if len(sys.argv)>1 else 'http://localhost:18766'
post=sys.argv[2]
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(c,path,data=None): return c.open(base+path,urllib.parse.urlencode(data).encode() if data is not None else None)
def login(c,user,password):
    request(c,'/wp-login.php').read()
    request(c,'/wp-login.php',{'log':user,'pwd':password,'wp-submit':'Log In','redirect_to':base+'/wp-admin/','testcookie':'1'}).read()
def rejected(c,nonce):
    try: request(c,'/wp-admin/admin-ajax.php',{'action':'terrible_social_images_preview','post':post,'nonce':nonce,'title':'A private draft'}); return False
    except urllib.error.HTTPError as exc: return exc.code in (400,403)
admin=client(); login(admin,sys.argv[3],sys.argv[4])
page=request(admin,'/wp-admin/post.php?post='+post+'&action=edit').read().decode()
match=re.search(r'class="button tsi-preview"[^>]*data-nonce="([^"]+)"',page)
assert match, 'preview control and nonce missing from editor'
nonce=match.group(1)
assert rejected(client(),nonce), 'anonymous preview was accepted'
if len(sys.argv)>5:
    subscriber=client(); login(subscriber,sys.argv[5],sys.argv[6]); assert rejected(subscriber,nonce), 'subscriber preview was accepted'
assert rejected(admin,'wrong'), 'invalid preview nonce was accepted'
response=request(admin,'/wp-admin/admin-ajax.php',{'action':'terrible_social_images_preview','post':post,'nonce':nonce,'title':'An unsaved preview title'})
data=json.load(response); assert data['success'] and base64.b64decode(data['data']['image'].split(',',1)[1]).startswith(b'\xff\xd8')
assert 'no-cache' in response.headers.get('Cache-Control',''), 'preview response can be cached'
print('PASS preview HTTP authentication, nonce, JPEG and no-cache')
settings=request(admin,'/wp-admin/admin.php?page=terrible-social-images').read().decode()
bulk=re.search(r'id="tsi-bulk"[^>]*data-nonce="([^"]+)"',settings); assert bulk, 'bulk button and nonce missing'
def bulk_call(c, token, cursor):
    return json.load(request(c,'/wp-admin/admin-ajax.php',{'action':'terrible_social_images_bulk','nonce':token,'cursor':cursor}))
try: bulk_call(client(),bulk.group(1),0); raise AssertionError('anonymous bulk accepted')
except urllib.error.HTTPError as exc: assert exc.code in (400,403)
try: bulk_call(admin,'wrong',0); raise AssertionError('invalid bulk nonce accepted')
except urllib.error.HTTPError as exc: assert exc.code in (400,403)
batch=bulk_call(admin,bulk.group(1),0); assert batch['success'] and batch['data']['count']<=1 and batch['data']['cursor']>=0
if batch['data']['more']:
    next_batch=bulk_call(admin,bulk.group(1),batch['data']['cursor']); assert next_batch['success'] and next_batch['data']['cursor']>batch['data']['cursor']
print('PASS bulk authorization, bounded batches and advancing cursor')
