#!/usr/bin/env python3
"""
Simple HTTP file server for downloading files from current directory
Usage: python file_server.py [port]
"""

import http.server
import socketserver
import os
import sys
from urllib.parse import urlparse, unquote
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Configuration
PORT = int(os.getenv("FILE_SERVER_PORT", "8000"))
DIRECTORY = os.getenv("FILE_SERVER_DIRECTORY", ".")

class FileServerHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

    def do_GET(self):
        # Parse the URL path
        parsed_path = urlparse(self.path)
        path = unquote(parsed_path.path)
        
        # If root path, show directory listing
        if path == '/':
            self.send_response(200)
            self.send_header('Content-type', 'text/html; charset=utf-8')
            self.end_headers()
            
            # Generate directory listing
            html = self.generate_directory_listing()
            self.wfile.write(html.encode('utf-8'))
        else:
            # Serve the requested file
            super().do_GET()
    
    def generate_directory_listing(self):
        """Generate HTML listing of files in current directory"""
        files = []
        for item in os.listdir('.'):
            if os.path.isfile(item):
                size = os.path.getsize(item)
                files.append((item, size))
        
        html = f"""
<!DOCTYPE html>
<html>
<head>
    <title>File Server - {os.getcwd()}</title>
    <style>
        body {{ font-family: Arial, sans-serif; margin: 20px; }}
        h1 {{ color: #333; }}
        .file-list {{ border-collapse: collapse; width: 100%; }}
        .file-list th, .file-list td {{ 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }}
        .file-list th {{ background-color: #f2f2f2; }}
        .file-list tr:nth-child(even) {{ background-color: #f9f9f9; }}
        .file-list tr:hover {{ background-color: #f5f5f5; }}
        a {{ color: #0066cc; text-decoration: none; }}
        a:hover {{ text-decoration: underline; }}
        .size {{ text-align: right; }}
    </style>
</head>
<body>
    <h1>File Server</h1>
    <p><strong>Directory:</strong> {os.getcwd()}</p>
    <p><strong>Server:</strong> {self.server.server_address[0]}:{self.server.server_address[1]}</p>
    
    <h2>Available Files:</h2>
    <table class="file-list">
        <tr>
            <th>Filename</th>
            <th class="size">Size (bytes)</th>
        </tr>
"""
        
        for filename, size in sorted(files):
            html += f"""
        <tr>
            <td><a href="/{filename}">{filename}</a></td>
            <td class="size">{size:,}</td>
        </tr>
"""
        
        html += """
    </table>
</body>
</html>
"""
        return html

def main():
    # Create server
    with socketserver.TCPServer(("0.0.0.0", PORT), FileServerHandler) as httpd:
        print(f"File server started on http://0.0.0.0:{PORT}")
        print(f"Serving files from: {DIRECTORY}")
        print("Press Ctrl+C to stop the server")
        
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nServer stopped.")

if __name__ == "__main__":
    main() 