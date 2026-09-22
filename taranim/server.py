#!/usr/bin/env python3
import http.server
import socketserver
import json
import sqlite3
import urllib.parse
import urllib.request
import os
import re
import threading
import time
import socket

PORT = 8080
DB_PATH = os.path.join(os.path.dirname(__file__), 'database.sqlite')
PLAYLISTS_FILE = os.path.join(os.path.dirname(__file__), 'playlists.json')

def get_lan_ip():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(('10.255.255.255', 1))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return '127.0.0.1'

LIVE_EVENT = threading.Event()
CONNECTED_DEVICES = {}
REMOTE_SESSIONS = {}

if not os.path.exists(PLAYLISTS_FILE):
    with open(PLAYLISTS_FILE, 'w', encoding='utf-8') as f:
        json.dump([], f)

LIVE_STATE = {
    'type': 'PRESENT_LINE',
    'text': '',
    'songTitle': '',
    'font': "'Alexandria', sans-serif",
    'fontSize': 54,
    'chroma': 'black',
    'isBlank': False,
    'textColor': '#ffffff',
    'strokeWidth': 0,
    'strokeColor': '#000000',
    'shadowBlur': 0,
    'pos': {'left': 960, 'top': 540}
}

FRANCO_MAPPINGS = [
    ("shabahak", "شبهك"), ("yasou3", "يسوع"), ("kirolos", "كيرلس"),
    ("3ajel", "عجل"), ("rabby", "ربي"), ("7abib", "حبيب"),
    ("allah", "الله"), ("alla", "الله"), ("3ashan", "عشان"), ("3shan", "عشان"),
    ("alhan", "ألحان"), ("tarnima", "ترنيمة"), ("taranim", "ترانيم"),
    ("3a", "عا"), ("3i", "عي"), ("3u", "عو"), ("3", "ع"),
    ("7a", "حا"), ("7i", "حي"), ("7u", "حو"), ("7", "ح"),
    ("5", "خ"), ("2", "أ"), ("kh", "خ"), ("sh", "ش"),
    ("th", "ث"), ("gh", "غ"), ("ou", "و"), ("oo", "و"),
    ("ee", "ي"), ("y", "ي"), ("g", "ج"), ("k", "ك"),
    ("c", "ك"), ("q", "ق"), ("z", "ز"), ("s", "س"),
    ("t", "ت"), ("d", "د"), ("r", "ر"), ("f", "ف"),
    ("l", "ل"), ("m", "م"), ("n", "ن"), ("h", "ه"),
    ("w", "و"), ("b", "ب"), ("p", "ب"), ("v", "ف"),
    ("i", "ي"), ("e", "ي"), ("o", "و"), ("u", "و"),
    ("a", "ا")
]

def normalize_arabic(text):
    if not text:
        return ""
    text = str(text)
    text = re.sub(r'[أإآٱ]', 'ا', text)
    text = re.sub(r'[ىئ]', 'ي', text)
    text = re.sub(r'ة', 'ه', text)
    text = re.sub(r'ؤ', 'و', text)
    text = re.sub(r'[\u064B-\u0652]', '', text)
    return text.strip()

def franco_to_arabic(text):
    if not text:
        return ""
    s = text.lower().strip()
    if not re.search(r'[a-z0-9]', s):
        return ""
    for f, a in FRANCO_MAPPINGS:
        s = s.replace(f, a)
    return s

def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def sync_online_database():
    time.sleep(5)
    print("Checking for online songs database updates...")
    try:
        req = urllib.request.Request(
            'https://raw.githubusercontent.com/tashbe7na/database/main/latest.json',
            headers={'User-Agent': 'Mozilla/5.0'}
        )
        with urllib.request.urlopen(req, timeout=10) as response:
            if response.status == 200:
                data = json.loads(response.read().decode('utf-8'))
                if isinstance(data, list):
                    conn = get_db()
                    cursor = conn.cursor()
                    inserted = 0
                    for song in data:
                        cursor.execute('SELECT id FROM songs WHERE title = ?;', (song.get('title'),))
                        if not cursor.fetchone():
                            cursor.execute(
                                'INSERT INTO songs (title, notes) VALUES (?, ?);',
                                (song.get('title'), song.get('notes', ''))
                            )
                            inserted += 1
                    conn.commit()
                    conn.close()
                    if inserted > 0:
                        print(f"Auto-synced {inserted} new songs into local database!")
    except Exception as e:
        print("Local database active.")

class SundaySchoolTaranimHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        serve_dir = os.path.dirname(__file__)
        super().__init__(*args, directory=serve_dir, **kwargs)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        query = urllib.parse.parse_qs(parsed.query)

        if path.startswith('/api/') or path == '/api.php':
            self.handle_api_get(path, query)
        else:
            super().do_GET()

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        parsed_query = urllib.parse.parse_qs(parsed.query)
        action = parsed_query.get('action', [''])[0]
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else '{}'
        try:
            data = json.loads(body)
        except Exception:
            data = {}

        client_ip = self.client_address[0]

        if path in ('/api/hotspot_ping',) or action == 'hotspot_ping':
            CONNECTED_DEVICES[client_ip] = {
                'ip': client_ip,
                'name': data.get('clientName', 'لوحة تحكم'),
                'type': 'CONTROL_PANEL',
                'userAgent': data.get('userAgent', self.headers.get('User-Agent', '')),
                'last_seen': time.time()
            }
            self.send_json({'success': True, 'client_ip': client_ip})
            return

        if path == '/api/live' or (path == '/api.php' and action == 'live') or path == '/api/hotspot_push':
            LIVE_STATE.update(data)
            LIVE_STATE['updated_at'] = time.time()
            LIVE_STATE['sender_ip'] = client_ip
            CONNECTED_DEVICES[client_ip] = {
                'ip': client_ip,
                'name': data.get('clientName', 'لوحة تحكم'),
                'type': 'CONTROL_PANEL',
                'userAgent': data.get('userAgent', self.headers.get('User-Agent', '')),
                'last_seen': time.time()
            }
            LIVE_EVENT.set()
            LIVE_EVENT.clear()
            self.send_json({'success': True, 'client_ip': client_ip})

        # Handle mobile remote actions in server.py
        clean_action = action
        if clean_action.startswith('remote_'):
            clean_action = clean_action[7:]
        if not clean_action and isinstance(data, dict) and 'action' in data:
            clean_action = str(data['action'])
            if clean_action.startswith('remote_'):
                clean_action = clean_action[7:]

        if clean_action in ('create_room', 'register_room'):
            import random
            room_id = data.get('roomId') or f"rm_{int(time.time()*1000)%1000000}"
            pin = str(data.get('roomPin') or data.get('pin') or random.randint(100000, 999999))
            host_key = data.get('hostKey') or f"hk_{int(time.time()*1000)}"
            if room_id not in REMOTE_SESSIONS:
                REMOTE_SESSIONS[room_id] = {
                    'roomId': room_id,
                    'roomPin': pin,
                    'hostKey': host_key,
                    'commands': [],
                    'clients': {},
                    'state': LIVE_STATE
                }
            self.send_json({
                'success': True,
                'roomId': room_id,
                'roomPin': pin,
                'hostKey': host_key
            })
            return

        elif clean_action == 'join_room':
            req_pin = str(data.get('pin', '')).strip()
            req_room_id = str(data.get('roomId', '')).strip()
            target_room = None
            if req_room_id and req_room_id in REMOTE_SESSIONS:
                target_room = REMOTE_SESSIONS[req_room_id]
            elif req_pin:
                for r in REMOTE_SESSIONS.values():
                    if str(r.get('roomPin')) == req_pin:
                        target_room = r
                        break
            if not target_room:
                target_room = {
                    'roomId': req_room_id or f"rm_{req_pin or 'live'}",
                    'roomPin': req_pin or '123456',
                    'hostKey': 'hk_auto',
                    'commands': [],
                    'clients': {},
                    'state': LIVE_STATE
                }
                REMOTE_SESSIONS[target_room['roomId']] = target_room

            token = f"ct_{int(time.time()*1000)}"
            target_room['clients'][token] = {
                'name': data.get('clientName', 'هاتف ريموت'),
                'ip': client_ip,
                'last_seen': time.time()
            }
            CONNECTED_DEVICES[client_ip] = {
                'ip': client_ip,
                'name': data.get('clientName', 'هاتف ريموت'),
                'type': 'REMOTE_PHONE',
                'userAgent': data.get('userAgent', self.headers.get('User-Agent', '')),
                'last_seen': time.time()
            }
            self.send_json({
                'success': True,
                'roomId': target_room['roomId'],
                'roomPin': target_room['roomPin'],
                'clientToken': token,
                'clientIp': client_ip,
                'state': target_room.get('state', LIVE_STATE)
            })
            return

        elif clean_action == 'send_command':
            req_room_id = str(data.get('roomId', '')).strip()
            cmd = data.get('command', {})
            cmd_id = cmd.get('id') or f"cmd_{int(time.time()*1000)}"
            cmd['id'] = cmd_id
            cmd['timestamp'] = cmd.get('timestamp') or time.time()
            for r in REMOTE_SESSIONS.values():
                if not req_room_id or r.get('roomId') == req_room_id or r.get('roomPin') == req_room_id:
                    r['commands'].append(cmd)
            self.send_json({'success': True, 'commandId': cmd_id})
            return

        elif clean_action == 'poll_commands':
            req_room_id = str(data.get('roomId', '')).strip()
            cmds = []
            client_count = 0
            for r in REMOTE_SESSIONS.values():
                if not req_room_id or r.get('roomId') == req_room_id:
                    cmds = list(r.get('commands', []))
                    r['commands'] = []
                    client_count = len(r.get('clients', {}))
                    break
            self.send_json({'success': True, 'commands': cmds, 'clientCount': client_count})
            return

        elif clean_action == 'push_state':
            st = data.get('state', {})
            LIVE_STATE.update(st)
            req_room_id = str(data.get('roomId', '')).strip()
            for r in REMOTE_SESSIONS.values():
                if not req_room_id or r.get('roomId') == req_room_id:
                    r['state'] = LIVE_STATE
            self.send_json({'success': True})
            return

        elif clean_action == 'get_state':
            self.send_json({'success': True, 'state': LIVE_STATE})
            return

        elif path == '/api/playlists':
            with open(PLAYLISTS_FILE, 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            self.send_json({'success': True})

        else:
            self.send_error(404, "Endpoint not found")

    def handle_api_get(self, path, query):
        conn = get_db()
        cursor = conn.cursor()
        client_ip = self.client_address[0]
        action = query.get('action', [''])[0]

        if path in ('/api/host_ip', '/api/hotspot_info') or action in ('host_ip', 'hotspot_info', 'get_my_ip'):
            conn.close()
            lan_ip = get_lan_ip()
            self.send_json({
                'success': True,
                'ip': client_ip,
                'lan_ip': lan_ip,
                'port': PORT,
                'url': f"http://{lan_ip}:{PORT}/index.html"
            })
            return

        elif path in ('/api/hotspot_devices',) or action == 'hotspot_devices':
            conn.close()
            now = time.time()
            devs = [
                d for d in CONNECTED_DEVICES.values()
                if (now - d['last_seen']) < 60
            ]
            self.send_json({'success': True, 'devices': devs})
            return

        elif path == '/api/live_wait':
            conn.close()
            since = float(query.get('since', [0])[0])
            if LIVE_STATE.get('updated_at', 0) <= since:
                LIVE_EVENT.wait(timeout=1.5)
            self.send_json(LIVE_STATE)
            return

        elif path == '/api/live' or action == 'live':
            conn.close()
            self.send_json(LIVE_STATE)
            return

        elif path == '/api/songs':
            q = query.get('q', [''])[0].strip()
            limit = int(query.get('limit', ['50'])[0])
            offset = int(query.get('offset', ['0'])[0])

            results = []

            if q:
                match = re.match(r'^([0-9]?\s*[\u0600-\u06FF]+)\s+([0-9]+)', q)
                if match:
                    b_name = match.group(1).strip()
                    c_num = int(match.group(2))

                    cursor.execute('''
                        SELECT id, book, title, abbr FROM books 
                        WHERE abbr = ? OR title = ? OR title LIKE ? OR abbr LIKE ?
                        ORDER BY 
                            (CASE WHEN abbr = ? THEN 1 ELSE 0 END) DESC,
                            (CASE WHEN title = ? THEN 1 ELSE 0 END) DESC,
                            (CASE WHEN abbr LIKE ? THEN 1 ELSE 0 END) DESC
                        LIMIT 1;
                    ''', (b_name, b_name, f'{b_name}%', f'{b_name}%', b_name, b_name, f'{b_name}%'))
                    
                    b_row = cursor.fetchone()
                    if b_row:
                        cursor.execute('''
                            SELECT c.id, c.item_id FROM chapters c 
                            JOIN bible_chapters bc ON bc.id = c.bible_chapter 
                            WHERE bc.book = ? AND bc.number = ?;
                        ''', (b_row['book'], c_num))
                        c_row = cursor.fetchone()
                        if c_row:
                            cursor.execute('''
                                SELECT sg.content FROM verses v 
                                JOIN slides sl ON sl.verse = v.id
                                JOIN segments sg ON sg.slide = sl.id
                                WHERE v.item_id = ? AND sg.content IS NOT NULL AND sg.content != ''
                                LIMIT 6;
                            ''', (c_row['item_id'],))
                            v_preview = [r[0] for r in cursor.fetchall()]
                            notes_text = "\n".join(v_preview) if v_preview else f'شاهد كتابي مقدس ({b_row["abbr"]} {c_num})'

                            results.append({
                                'id': f'bible_{c_row["id"]}',
                                'item_id': c_row['item_id'],
                                'title': f'📖 {b_row["title"]} - الأصحاح {c_num}',
                                'notes': notes_text,
                                'is_bible': True
                            })

            if q:
                q_norm = normalize_arabic(q)
                q_franco = franco_to_arabic(q)
                
                cursor.execute('''
                    SELECT s.id, s.item_id, s.title, s.media_url, 
                           GROUP_CONCAT(DISTINCT sg.content) as notes
                    FROM songs s
                    LEFT JOIN verses v ON v.item_id = s.item_id
                    LEFT JOIN slides sl ON sl.verse = v.id
                    LEFT JOIN segments sg ON sg.slide = sl.id
                    WHERE s.title LIKE ? OR sg.content LIKE ? 
                       OR s.title LIKE ? OR sg.content LIKE ?
                       OR (LENGTH(?) > 0 AND (s.title LIKE ? OR sg.content LIKE ?))
                    GROUP BY s.id
                    LIMIT ? OFFSET ?;
                ''', (
                    f'%{q}%', f'%{q}%',
                    f'%{q_norm}%', f'%{q_norm}%',
                    q_franco, f'%{q_franco}%', f'%{q_franco}%',
                    limit, offset
                ))
            else:
                cursor.execute('''
                    SELECT id, item_id, title, media_url, notes
                    FROM songs
                    ORDER BY id ASC
                    LIMIT ? OFFSET ?;
                ''', (limit, offset))

            songs = [dict(row) for row in cursor.fetchall()]
            results.extend(songs)

            conn.close()
            self.send_json({'songs': results, 'total': len(results), 'limit': limit, 'offset': offset})

        elif path.startswith('/api/song/'):
            raw_id = path.split('/')[-1]

            if raw_id.startswith('bible_'):
                chap_id = int(raw_id.replace('bible_', ''))
                cursor.execute('SELECT c.id, c.item_id, c.bible_chapter FROM chapters c WHERE c.id = ?;', (chap_id,))
                c_row = cursor.fetchone()
                if not c_row:
                    self.send_json({'error': 'Bible chapter not found'}, status=404)
                    conn.close()
                    return

                item_id = c_row['item_id']

                cursor.execute('''
                    SELECT b.title, b.abbr, bc.number FROM bible_chapters bc
                    JOIN books b ON b.book = bc.book
                    WHERE bc.id = ?;
                ''', (c_row['bible_chapter'],))
                meta = cursor.fetchone()
                title = f'📖 {meta["title"]} - الأصحاح {meta["number"]}' if meta else 'الشاهد الكتابي'

                cursor.execute('SELECT id, type FROM verses WHERE item_id = ? ORDER BY id;', (item_id,))
                verses = [dict(r) for r in cursor.fetchall()]

                for v in verses:
                    cursor.execute('SELECT id, heading FROM slides WHERE verse = ? ORDER BY id;', (v['id'],))
                    slides = [dict(r) for r in cursor.fetchall()]
                    for sl in slides:
                        cursor.execute('SELECT content FROM segments WHERE slide = ? ORDER BY id;', (sl['id'],))
                        sl['lines'] = [r[0] for r in cursor.fetchall() if r[0]]
                        sl['text'] = '\n'.join(sl['lines'])
                    v['slides'] = slides

                bible_data = {
                    'id': raw_id,
                    'item_id': item_id,
                    'title': title,
                    'verses': verses,
                    'chords': []
                }
                conn.close()
                self.send_json(bible_data)
                return

            else:
                try:
                    song_id = int(raw_id)
                except ValueError:
                    self.send_json({'error': 'Invalid ID'}, status=400)
                    conn.close()
                    return

                cursor.execute('SELECT id, item_id, title, media_url, notes FROM songs WHERE id = ?;', (song_id,))
                song_row = cursor.fetchone()
                if not song_row:
                    self.send_json({'error': 'Song not found'}, status=404)
                    conn.close()
                    return

                song = dict(song_row)

                cursor.execute('''
                    SELECT a.ar_name FROM song_authors sa 
                    JOIN authors a ON a.id = sa.author 
                    WHERE sa.song = ?;
                ''', (song_id,))
                song['authors'] = [r[0] for r in cursor.fetchall() if r[0]]

                cursor.execute('SELECT id, type FROM verses WHERE item_id = ? ORDER BY id;', (song['item_id'],))
                verses = [dict(r) for r in cursor.fetchall()]

                for v in verses:
                    cursor.execute('SELECT id, heading FROM slides WHERE verse = ? ORDER BY id;', (v['id'],))
                    slides = [dict(r) for r in cursor.fetchall()]
                    for sl in slides:
                        cursor.execute('SELECT content FROM segments WHERE slide = ? ORDER BY id;', (sl['id'],))
                        sl['lines'] = [r[0] for r in cursor.fetchall() if r[0]]
                        sl['text'] = '\n'.join(sl['lines'])
                    v['slides'] = slides

                song['verses'] = verses
                song['chords'] = []
                conn.close()
                self.send_json(song)

        else:
            conn.close()
            self.send_json({'error': 'Not found'}, status=404)

    def send_json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

if __name__ == '__main__':
    os.makedirs(os.path.join(os.path.dirname(__file__), 'public'), exist_ok=True)

    sync_thread = threading.Thread(target=sync_online_database, daemon=True)
    sync_thread.start()

    socketserver.TCPServer.allow_reuse_address = True
    lan_ip = get_lan_ip()
    with socketserver.TCPServer(("", PORT), SundaySchoolTaranimHandler) as httpd:
        print(f"🚀 Sunday School Taranim Server running at http://localhost:{PORT}")
        print(f"📡 Hotspot / LAN Control Panel URL: http://{lan_ip}:{PORT}/index.html")
        httpd.serve_forever()
