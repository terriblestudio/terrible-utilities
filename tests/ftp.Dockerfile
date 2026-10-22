FROM python:3.12-alpine
RUN pip install --no-cache-dir pyftpdlib==2.1.0 pyopenssl
COPY tests/ftp-server.py /server.py
CMD ["python", "/server.py"]
