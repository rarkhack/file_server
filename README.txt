PHP File Server — Updated

FILES
- index.php: PHP backend and HTML
- style.css: styling
- app.js: multi-file queue, per-file progress and statuses
- php.ini: increased upload size settings
- README.txt: this guide

RUN IT
Install PHP 8.1+ (Kali/Ubuntu: sudo apt install php), then from this folder run:
  php -c php.ini -S 0.0.0.0:8000

Open:
  http://127.0.0.1:8000/

For another device on your LAN, use http://YOUR-COMPUTER-IP:8000/

IMPORTANT: Python's built-in http.server does not execute PHP. This command uses PHP's built-in development server. If Python must be the front-end server, configure a Python reverse proxy to this PHP server.

UPLOAD LIMITS
This php.ini sets upload_max_filesize=20G (maximum individual file), post_max_size=1024G (maximum total POST request), memory_limit=1G, and max_file_uploads=100.
Check the settings loaded by this server:
  php -c php.ini -i | grep -E 'upload_max_filesize|post_max_size|max_file_uploads'

The custom php.ini only applies when you start PHP with -c php.ini. Restart the server after changing settings.
If you use Apache, Nginx, a reverse proxy, or a hosting provider instead, its own request-size and timeout settings may also need changing.
The browser queue adds each new selection to existing files in the queue. Exact duplicate selections are ignored, and each file keeps its progress/status when you add more files. Files are uploaded individually; the browser shows transfer progress and, when PHP session upload progress is enabled and the web server streams request bodies without buffering, the app also displays bytes received by PHP. PHP's built-in server may only expose that session progress after the request finishes; in that case, the browser transfer indicator continues to work. The upload API returns HTTP 200 when all files succeed, 207 when a batch has mixed results, and 422 when all files fail. Clicking Upload skips files already marked successful and retries queued or failed files only. The file browser root is ./upload/; it does not list the project/server folder.

UPLOAD DESTINATION
Uploaded files are stored in ./upload/ and the browser only lists/downloads files from that directory. If an uploaded file has the same name as an existing file, the server compares SHA-256 checksums. If contents match, the incoming duplicate is discarded and marked successful. If contents differ, the new file is saved under a non-conflicting name such as report (1).pdf; the existing file is never overwritten.

SECURITY
No authentication is included. Anyone who can reach the server may browse/download files in this directory and upload into upload/. Use only on a trusted network and do not expose directly to the public internet.
