"""Local protocol fixtures. No external traffic or real credentials."""
import base64, hashlib, hmac, json, re, socketserver, threading, uuid, ssl
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs
from email import policy
from email.parser import BytesParser
tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain('/tests/certs/server.crt','/tests/certs/server.key')
files={}; sessions={}; messages=[]; modes={}; lock=threading.RLock()
def drop_hash(data):
    return hashlib.sha256(b''.join(hashlib.sha256(data[i:i+4194304]).digest() for i in range(0,len(data),4194304))).hexdigest()
def meta(key,data,ident=None):
    return {'.tag':'file','id':ident or 'id:'+key,'rev':'1','name':key.rsplit('/',1)[-1], 'size':len(data),'content_hash':drop_hash(data),'md5Checksum':hashlib.md5(data).hexdigest()}
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self,status=200,data=None,headers=None,raw=None):
        body=raw if raw is not None else json.dumps(data or {}).encode(); self.send_response(status)
        for k,v in (headers or {}).items(): self.send_header(k,v)
        self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_HEAD(self): self.handle_request()
    def do_GET(self): self.handle_request()
    def do_POST(self): self.handle_request()
    def do_PUT(self): self.handle_request()
    def handle_request(self):
        with lock:
            try: self.route()
            except Exception as exc: self.reply(400,{'fixture_error':str(exc)})
    def route(self):
        u=urlparse(self.path); path=u.path; q=parse_qs(u.query); body=self.rfile.read(int(self.headers.get('Content-Length',0)))
        if path.startswith('/links/'):
            code={'/links/gone':410,'/links/blocked':403,'/links/head-not-supported':405 if self.command=='HEAD' else 200,'/links/redirect-private':302}.get(path,200)
            return self.reply(code,headers={'Location':'http://169.254.169.254/latest/meta-data/'} if code==302 else {})
        if path=='/control': modes.update(json.loads(body)); return self.reply()
        if path=='/messages': return self.reply(data={'messages':messages})
        if path in ['/oauth2/token','/token']:
            args=parse_qs(body.decode()); assert args.get('client_id')==['test-client'] and args.get('client_secret')==['test-secret']
            if modes.get('oauth_fail'): return self.reply(401)
            return self.reply(data={'access_token':'test-access-token','refresh_token':'test-refresh','expires_in':3600})
        if path.startswith('/2/files/') or path.startswith('/drive/') or path.startswith('/upload/') or path.startswith('/sessions/') or path.startswith('/gmail/'):
            assert self.headers.get('Authorization')=='Bearer test-access-token'
        if path.startswith('/2/files/'):
            route=path.removeprefix('/2/files/'); args=json.loads(self.headers.get('Dropbox-API-Arg') or body)
            if route=='upload_session/start':
                sid=uuid.uuid4().hex; sessions[sid]=b''; return self.reply(data={'session_id':sid})
            if route.startswith('upload_session/'):
                cur=args['cursor']; sid=cur['session_id']
                if sid not in sessions: return self.reply(409,{'error':{'.tag':'lookup_failed'}})
                current=sessions[sid]
                if cur['offset']!=len(current): return self.reply(409,{'error':{'.tag':'incorrect_offset','correct_offset':len(current)}})
                sessions[sid]+=body
                if route=='upload_session/finish':
                    key=args['commit']['path']; assert args['commit']['mode']=='add'; assert key not in files
                    files[key]=sessions.pop(sid); return self.reply(data=meta(key,files[key]))
                return self.reply(raw=b'null')
            if route in ['list_folder','list_folder/continue']:
                prefix=args.get('path','')+'/'; return self.reply(data={'entries':[meta(k,v) for k,v in files.items() if k.startswith(prefix)],'has_more':False,'cursor':''})
            key=args['path'].removeprefix('id:')
            if key not in files: return self.reply(409)
            if route=='get_metadata': return self.reply(data=meta(key,files[key]))
            if route=='download': return self.download(files[key])
        if path=='/drive/v3/files/generateIds': return self.reply(data={'ids':[uuid.uuid4().hex]})
        if path=='/drive/v3/files':
            query=q.get('q',[''])[0]; name=re.search(r"name = '([^']+)'",query)
            out=[dict(meta(k,v['body'],k),name=v['name']) for k,v in files.items() if isinstance(v,dict) and (not name or v['name']==name[1])]
            return self.reply(data={'files':out})
        if path=='/upload/drive/v3/files':
            data=json.loads(body); ident=data['id']; sid=uuid.uuid4().hex; sessions[sid]={'body':b'','meta':data}
            return self.reply(headers={'Location':'http://mock:8080/sessions/'+sid})
        if path.startswith('/sessions/'):
            sid=path.split('/')[-1]
            if sid not in sessions: return self.reply(404)
            session=sessions[sid]; cr=self.headers['Content-Range']
            if cr.startswith('bytes */'): return self.reply(308,headers={'Range':'bytes=0-'+str(len(session['body'])-1)} if session['body'] else {})
            match=re.fullmatch(r'bytes (\d+)-(\d+)/(\d+)',cr)
            start,end,total=map(int,match.groups()); assert start==len(session['body']) and end-start+1==len(body)
            session['body']+=body
            if end+1<total: return self.reply(308,headers={'Range':'bytes=0-'+str(end)})
            ident=session['meta']['id']; files[ident]={'name':session['meta']['name'],'body':session['body']}; del sessions[sid]
            return self.reply(data=meta(ident,files[ident]['body'],ident))
        if path.startswith('/drive/v3/files/'):
            ident=path.split('/')[-1]
            if ident not in files: return self.reply(404)
            if 'alt' in q: return self.download(files[ident]['body'])
            return self.reply(data=meta(ident,files[ident]['body'],ident))
        if path in ['/emails','/v2/email/outbound-emails','/gmail/v1/users/me/messages/send']:
            data=json.loads(body)
            if path=='/emails':
                assert self.headers['Authorization']=='Bearer test-resend'; assert self.headers.get('Idempotency-Key'); assert data['to']
            elif path.startswith('/v2/'):
                auth=self.headers['Authorization']; match=re.fullmatch(r'AWS4-HMAC-SHA256 Credential=([^/]+)/([^,]+), SignedHeaders=([^,]+), Signature=(.+)',auth)
                access,scope,signed,sig=match.groups(); assert access=='test-access'; day,region,service,end=scope.split('/'); assert service=='ses'
                canonical=''.join(k+':'+self.headers[k].strip()+'\n' for k in signed.split(';'))
                req='POST\n'+path+'\n\n'+canonical+'\n'+signed+'\n'+hashlib.sha256(body).hexdigest()
                key=hmac.new(b'AWS4test-secret',day.encode(),hashlib.sha256).digest()
                for part in [region,service,end]: key=hmac.new(key,part.encode(),hashlib.sha256).digest()
                actual=hmac.new(key,('AWS4-HMAC-SHA256\n'+self.headers['x-amz-date']+'\n'+scope+'\n'+hashlib.sha256(req.encode()).hexdigest()).encode(),hashlib.sha256).hexdigest(); assert hmac.compare_digest(actual,sig)
                raw=base64.b64decode(data['Content']['Raw']['Data']); assert b'Subject:' in raw
            else:
                raw=base64.urlsafe_b64decode(data['raw']+'='*(-len(data['raw'])%4)); assert b'Subject:' in raw
            failure=modes.get('mail_status',0)
            if failure: return self.reply(int(failure),{'error':'fixture failure, intentionally not logged'})
            messages.append({'path':path,'data':data}); ident=uuid.uuid4().hex
            return self.reply(data={'MessageId' if path.startswith('/v2/') else 'id':ident})
        return self.reply(404)
    def download(self,body):
        match=re.fullmatch(r'bytes=(\d+)-(\d+)',self.headers['Range']); start,end=map(int,match.groups()); output=body[start:end+1]
        if modes.get('corrupt_download') and output: output=b'X'+output[1:]
        self.reply(206,headers={'Content-Range':f'bytes {start}-{end}/{len(body)}'},raw=output)
class SmtpHandler(socketserver.StreamRequestHandler):
    def handle(self):
        def line(text): self.wfile.write((text+'\r\n').encode()); self.wfile.flush()
        line('220 local SMTP fixture'); recipients=[]; data=[]; sender=''
        while True:
            raw=self.rfile.readline()
            if not raw: return
            text=raw.decode().rstrip('\r\n'); cmd=text.split(' ',1)[0].upper()
            if cmd in ['EHLO','HELO']: line('250-fixture'); line('250-STARTTLS'); line('250 AUTH PLAIN LOGIN')
            elif cmd=='STARTTLS':
                line('220 Ready for TLS'); self.connection=tls.wrap_socket(self.connection,server_side=True); self.request=self.connection; self.rfile=self.connection.makefile('rb'); self.wfile=self.connection.makefile('wb')
            elif cmd=='AUTH':
                if 'LOGIN' in text:
                    line('334 VXNlcm5hbWU6'); user=self.rfile.readline().strip(); line('334 UGFzc3dvcmQ6'); password=self.rfile.readline().strip()
                    valid=base64.b64decode(user)==b'test-user' and base64.b64decode(password)==b'test-password'
                else:
                    value=text.split()[-1]; decoded=base64.b64decode(value); valid=decoded==b'\x00test-user\x00test-password'
                line('235 Authenticated' if valid else '535 Authentication failed')
            elif cmd=='MAIL': sender=text; line('250 OK')
            elif cmd=='RCPT':
                if 'reject@' in text: line('550 Unknown recipient')
                else: recipients.append(text); line('250 OK')
            elif cmd=='DATA':
                line('354 End with dot'); data=[]
                while True:
                    raw=self.rfile.readline()
                    if raw==b'.\r\n': break
                    if not raw: return
                    data.append(raw[1:] if raw.startswith(b'..') else raw)
                if modes.get('smtp_drop'): return
                if modes.get('smtp_reject'): line('451 Try later')
                else:
                    with lock: messages.append({'path':'smtp','raw':base64.b64encode(b''.join(data)).decode(),'recipients':recipients,'sender':sender})
                    line('250 OK queued as fixture-id')
            elif cmd=='RSET': recipients=[]; line('250 Reset')
            elif cmd=='QUIT': line('221 Bye'); return
            else: line('250 OK')
class SMTP(socketserver.ThreadingTCPServer): allow_reuse_address=True; daemon_threads=True
class SMTPS(SMTP):
    def get_request(self):
        sock,addr=super().get_request(); return tls.wrap_socket(sock,server_side=True),addr
threading.Thread(target=SMTPS(('0.0.0.0',1465),SmtpHandler).serve_forever,daemon=True).start()
threading.Thread(target=SMTP(('0.0.0.0',1025),SmtpHandler).serve_forever,daemon=True).start()
ThreadingHTTPServer(('0.0.0.0',8080),Handler).serve_forever()
