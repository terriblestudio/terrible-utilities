from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import FTPServer
import os
os.makedirs('/data/nested/backups', exist_ok=True)
authorizer = DummyAuthorizer()
authorizer.add_user('terrible-test', 'terrible-test-password', '/data', perm='elradfmwMT')
handler = FTPHandler
handler.authorizer = authorizer
FTPServer(('0.0.0.0', 21), handler).serve_forever()
