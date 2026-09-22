#!/usr/bin/env python3
"""
Exports or synchronizes new songs and verses from database.sqlite into songs_catalog.json.
Maintains 100% compatibility with the Sunday School Taranim catalog format.
"""

import sqlite3
import json
import os

DB_PATH = os.path.join(os.path.dirname(__file__), 'database.sqlite')
CATALOG_PATH = os.path.join(os.path.dirname(__file__), 'songs_catalog.json')

def sync_catalog():
    if not os.path.exists(DB_PATH):
        print(f"❌ Error: Database file not found at {DB_PATH}")
        return

    print(f"📖 Connecting to SQLite database: {DB_PATH}...")
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    c = conn.cursor()

    existing_catalog = []
    existing_ids = set()
    if os.path.exists(CATALOG_PATH):
        try:
            with open(CATALOG_PATH, 'r', encoding='utf-8') as f:
                existing_catalog = json.load(f)
                existing_ids = {s['id'] for s in existing_catalog if 'id' in s}
                print(f"📂 Loaded existing catalog with {len(existing_catalog)} songs.")
        except Exception as e:
            print(f"⚠️ Warning loading existing catalog: {e}")

    c.execute("SELECT id, item_id, title, notes, media_url FROM songs ORDER BY id ASC")
    all_db_songs = c.fetchall()
    print(f"📊 Total songs in database.sqlite: {len(all_db_songs)}")

    new_songs_to_add = [s for s in all_db_songs if s['id'] not in existing_ids]
    print(f"✨ New songs to append into catalog: {len(new_songs_to_add)}")

    if not new_songs_to_add:
        print("✅ Catalog is already up to date with database.sqlite!")
        conn.close()
        return len(existing_catalog)

    # Scale mapping
    c.execute("SELECT song, scale FROM song_scales")
    scale_map = {row['song']: row['scale'] for row in c.fetchall()}

    added_list = []
    for s_row in new_songs_to_add:
        song_id = s_row['id']
        item_id = s_row['item_id']

        # Repetitions
        c.execute("""
            SELECT r.start_segment, r.end_segment, r.opening_position, r.closing_position, r.repetitions
            FROM repetitions r
            JOIN segments sg ON sg.id = r.start_segment
            JOIN slides sl ON sl.id = sg.slide
            JOIN verses v ON v.id = sl.verse
            WHERE v.item_id = ?
        """, (item_id,))
        rep_rows = c.fetchall()
        rep_map = {}
        for r in rep_rows:
            s_id = r['start_segment']
            e_id = r['end_segment']
            op = r['opening_position']
            cp = r['closing_position']
            cnt = r['repetitions']
            rep_map.setdefault(s_id, {'starts': [], 'ends': []})['starts'].append({'pos': op, 'cnt': cnt})
            rep_map.setdefault(e_id, {'starts': [], 'ends': []})['ends'].append({'pos': cp, 'cnt': cnt})

        c.execute("SELECT id, type FROM verses WHERE item_id = ? ORDER BY id ASC", (item_id,))
        verses_rows = c.fetchall()
        verses_data = []
        for v in verses_rows:
            c.execute("SELECT id, heading FROM slides WHERE verse = ? ORDER BY id ASC", (v['id'],))
            slides_rows = c.fetchall()
            slides_data = []
            for sl in slides_rows:
                c.execute("SELECT id, content FROM segments WHERE slide = ? ORDER BY id ASC", (sl['id'],))
                seg_rows = c.fetchall()
                lines = []
                for seg in seg_rows:
                    seg_id = seg['id']
                    orig = (seg['content'] or '').strip()
                    txt = orig
                    prefix = ''
                    suffix = ''
                    if seg_id in rep_map:
                        for st in rep_map[seg_id]['starts']:
                            pos = st['pos']
                            if 0 < pos < len(orig):
                                txt = orig[:pos] + '(' + orig[pos:]
                            else:
                                prefix += '('
                        for en in rep_map[seg_id]['ends']:
                            pos = en['pos']
                            cnt = en['cnt']
                            if 0 < pos < len(orig):
                                txt = orig[:pos] + ')' + str(cnt) + orig[pos:]
                            else:
                                suffix += ')' + str(cnt)
                    lines.append(prefix + txt + suffix)
                slides_data.append({
                    'id': sl['id'],
                    'heading': sl['heading'],
                    'lines': lines,
                    'text': '\n'.join(lines)
                })
            verses_data.append({
                'id': v['id'],
                'type': v['type'],
                'slides': slides_data
            })

        song_obj = {
            'id': song_id,
            'item_id': item_id,
            'title': s_row['title'],
            'notes': s_row['notes'] or '',
            'media_url': s_row['media_url'] or '',
            'verses': verses_data
        }
        if song_id in scale_map:
            song_obj['scale_id'] = scale_map[song_id]
        added_list.append(song_obj)

    final_catalog = existing_catalog + added_list
    final_catalog.sort(key=lambda s: s.get('id', 0))

    print(f"💾 Saving updated catalog ({len(final_catalog)} songs) to {CATALOG_PATH}...")
    with open(CATALOG_PATH, 'w', encoding='utf-8') as f:
        json.dump(final_catalog, f, ensure_ascii=False, indent=2)

    new_size = os.path.getsize(CATALOG_PATH)
    print(f"🎉 Success! Catalog now has {len(final_catalog)} songs. File size: {new_size:,} bytes.")
    conn.close()
    return len(final_catalog), new_size

if __name__ == '__main__':
    sync_catalog()
