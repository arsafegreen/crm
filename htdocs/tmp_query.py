import sqlite3, json
conn = sqlite3.connect('storage/database.sqlite')
cur = conn.cursor()
cur.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'whatsapp_%' ORDER BY name")
print(json.dumps([row[0] for row in cur.fetchall()]))
