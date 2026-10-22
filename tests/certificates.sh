#!/bin/sh
set -eu
cd "$(dirname "$0")"
mkdir -p certs
chmod 700 certs
if [ -f certs/server.crt ] && openssl x509 -checkend 3600 -noout -in certs/server.crt >/dev/null 2>&1; then exit 0; fi
openssl req -x509 -newkey rsa:2048 -nodes -keyout certs/ca.key -out certs/ca.crt -days 2 -subj '/CN=Terrible Utilities Test CA' >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout certs/server.key -out certs/server.csr -subj '/CN=mock' >/dev/null 2>&1
printf 'subjectAltName=DNS:mock\nextendedKeyUsage=serverAuth\n' > certs/extensions.cnf
openssl x509 -req -in certs/server.csr -CA certs/ca.crt -CAkey certs/ca.key -CAcreateserial -out certs/server.crt -days 2 -extfile certs/extensions.cnf >/dev/null 2>&1
chmod 600 certs/*.key
