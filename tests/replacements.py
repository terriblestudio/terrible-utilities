"""Real HTTP qualification of optional VS Contact Form in the disposable demo."""
import urllib.request,urllib.parse,http.cookiejar,subprocess,re,time,json
from html.parser import HTMLParser
BASE='http://localhost:18766'
def wp(code):return subprocess.check_output(['docker','compose','exec','-T','-u','www-data','wordpress','wp','eval',code],text=True).strip()
def check(ok,msg):assert ok,msg;print('PASS:',msg,flush=True)
page=subprocess.check_output(['docker','compose','exec','-T','wordpress','cat','/tmp/contact-demo-id'],text=True).strip()
url=BASE+'/?page_id='+page
c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
class Fields(HTMLParser):
 def __init__(self):super().__init__();self.values={}
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag in ('input','button') and a.get('name'):self.values[a['name']]=a.get('value','')
def form():
 h=c.open(url).read().decode();p=Fields();p.feed(h);return h,p.values
def count():return int(wp("echo wp_count_posts('submission')->pending;"))
def submit(d):
 h=c.open(url,urllib.parse.urlencode(d).encode()).read().decode()
 redirect=re.search(r'<script>window.location="([^"]+)"</script>',h)
 if redirect:
  target=redirect[1].replace('&amp;','&');assert urllib.parse.urlsplit(target).netloc==urllib.parse.urlsplit(BASE).netloc and urllib.parse.urlsplit(target).scheme=='http'
  h=c.open(target).read().decode()
 return h
h,d=form();check('vscf-form' in h and 'for="vscf_email"' in h,'replacement form renders with associated labels')
def value(field,text):d['vscf_'+field+'_'+page]=text
value('name','Demo Visitor');value('email','invalid');value('subject','Demo request');value('message','Please send more information.');value('privacy','yes')
time.sleep(6);before=count();reply=submit(d);check('Please enter a valid email' in reply and 'Please send more information.' in reply and count()==before,'invalid form preserves input and creates no entry')
h,d=form();value('name','Demo Visitor');value('email','visitor@example.test');value('subject','Demo request');value('message','Please send more information.');value('privacy','yes');time.sleep(6)
reply=submit(d);check('Thank you!' in reply and count()==before+1,'valid form saves an inbox entry and submits local SMTP notification')
# Mail rejection must retain the locally accepted submission.
wp("$c=TerribleMail\\Settings::get();update_option('replacement_test_mail',TerribleMail\\Secrets::seal($c),false);$c['providers']['smtp']['port']=1;TerribleMail\\Settings::save($c);")
try:
 h,d=form();value('name','Demo Failure Visitor');value('email','visitor@example.test');value('subject','Notification failure');value('message','Please keep this message if mail fails.');value('privacy','yes');time.sleep(6);before=count();reply=submit(d)
 check(count()==before+1 and 'Error: could not send form' in reply,'SMTP failure retains inbox entry and does not claim successful notification')
finally:wp("TerribleMail\\Settings::save(TerribleMail\\Secrets::open(get_option('replacement_test_mail')));delete_option('replacement_test_mail');")
print('ALL REPLACEMENT CHECKS PASSED',flush=True)
